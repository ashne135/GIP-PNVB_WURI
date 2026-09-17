<?php

namespace App\Services\Agregats;

use App\Models\AgregatJourCentre;
use App\Models\AgregatJourRegion;
use App\Models\AgregatJourSite;
use App\Models\Centre;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RECALCUL DES AGRÉGATS D'UNE JOURNÉE (cadrage, sections 4 et 15).
 *
 * 12 294 sites et des rapports quotidiens interdisent toute agrégation à la
 * volée : le tableau de bord lit ces tables, jamais les tables de détail.
 *
 * TROIS RÈGLES NON NÉGOCIABLES, portées ici :
 *
 *  1. SEULS LES RAPPORTS VISÉS COMPTENT. Un brouillon ou un rapport rejeté
 *     n'alimente aucun chiffre. C'est la même règle que le pré-remplissage de
 *     la chaîne de visas : un chiffre non validé ne remonte pas. Sans cela, le
 *     tableau de bord national afficherait des productions que personne n'a
 *     encore contrôlées.
 *
 *  2. SEULE LA FEUILLE DE PRÉSENCE VALIDÉE FAIT FOI. Un brouillon de feuille ne
 *     produit aucun effectif : le signal d'arrivée n'est pas un pointage.
 *
 *  3. L'EFFECTIF DÉPLOYÉ NE S'ADDITIONNE JAMAIS ENTRE RÉGIONS. Ce sont les
 *     mêmes équipes qui tournent. Au niveau régional, c'est un effectif
 *     simultané ; au niveau national, c'est le PIC, donc un maximum — la somme
 *     donnerait un effectif imaginaire de plusieurs milliers d'agents.
 *
 * Le recalcul est IDEMPOTENT : rejouer une journée écrase ses agrégats sans
 * jamais les cumuler. C'est ce qui permet de rattraper une journée dont un
 * rapport a été visé en retard.
 */
class CalculateurAgregats
{
    /**
     * @return array{sites: int, centres: int, regions: int}
     */
    public function recalculerJournee(string $date): array
    {
        $sites = $this->recalculerSites($date);
        $centres = $this->recalculerCentres($date);
        $regions = $this->recalculerRegions($date);

        return ['sites' => $sites, 'centres' => $centres, 'regions' => $regions];
    }

    // ------------------------------------------------------------------
    // Niveau site
    // ------------------------------------------------------------------

    private function recalculerSites(string $date): int
    {
        $production = $this->productionParSite($date);
        $presence = $this->presenceParSite($date);
        $incidents = $this->incidentsParSite($date);

        // Un site n'entre dans les agrégats que s'il a produit quelque chose
        // ce jour-là : écrire une ligne à zéro pour 12 294 sites chaque jour
        // ferait grossir la table de 4,5 millions de lignes par an sans rien
        // apprendre à personne.
        $idsSites = collect($production->keys())
            ->merge($presence->keys())
            ->merge($incidents->keys())
            ->unique();

        if ($idsSites->isEmpty()) {
            return 0;
        }

        $rattachements = DB::table('sites')
            ->whereIn('id', $idsSites)
            ->pluck('centre_id', 'id');

        $regions = DB::table('centres')
            ->whereIn('id', $rattachements->values())
            ->pluck('region_id', 'id');

        $vagues = $this->vagueParSite($date, $idsSites->all());

        $ecrits = 0;

        foreach ($idsSites as $siteId) {
            $centreId = $rattachements[$siteId] ?? null;

            if (! $centreId) {
                continue;
            }

            $p = $production[$siteId] ?? null;
            $f = $presence[$siteId] ?? null;

            AgregatJourSite::query()->updateOrCreate(
                ['site_id' => $siteId, 'date_jour' => $date],
                [
                    'centre_id' => $centreId,
                    'region_id' => $regions[$centreId] ?? null,
                    'vague_id' => $vagues[$siteId] ?? null,
                    'nb_enregistres' => (int) ($p->enregistres ?? 0),
                    'nb_rejetes' => (int) ($p->rejetes ?? 0),
                    'effectif_attendu' => (int) ($f->attendu ?? 0),
                    'effectif_present' => (int) ($f->presents ?? 0),
                    'effectif_absent' => (int) ($f->absents ?? 0),
                    'taux_presence' => $this->pourcentage((int) ($f->presents ?? 0), (int) ($f->attendu ?? 0)),
                    'nb_incidents' => (int) ($incidents[$siteId] ?? 0),
                    'recalcule_le' => now(),
                ]
            );

            $ecrits++;
        }

        return $ecrits;
    }

    /**
     * La production d'un site : la somme des rapports d'opérateur DÉJÀ VISÉS.
     * Un rapport encore en brouillon ne compte pas — il n'a été contrôlé par
     * personne.
     */
    private function productionParSite(string $date)
    {
        return DB::table('rapports_journaliers as r')
            ->join('rapport_opk_production as p', 'p.rapport_id', '=', 'r.id')
            ->where('r.type', 'opk')
            ->whereIn('r.statut', ['vise', 'clos'])
            ->whereDate('r.date_rapport', $date)
            ->whereNotNull('r.site_id')
            ->groupBy('r.site_id')
            ->selectRaw('r.site_id, sum(p.enregistrements_realises) as enregistres, '
                .'sum(p.enregistrements_non_valides) as rejetes')
            ->get()
            ->keyBy('site_id');
    }

    /** Les effectifs d'un site : la feuille VALIDÉE du jour, et elle seule. */
    private function presenceParSite(string $date)
    {
        return DB::table('feuilles_presence as f')
            ->join('lignes_presence as l', 'l.feuille_presence_id', '=', 'f.id')
            ->whereIn('f.statut', ['validee', 'corrigee'])
            ->whereDate('f.date_presence', $date)
            ->groupBy('f.site_id')
            ->selectRaw('f.site_id, count(*) as attendu, '
                ."sum(case when l.statut = 'present' then 1 else 0 end) as presents, "
                ."sum(case when l.statut <> 'present' then 1 else 0 end) as absents")
            ->get()
            ->keyBy('site_id');
    }

    private function incidentsParSite(string $date)
    {
        return DB::table('incidents')
            ->whereNotNull('site_id')
            ->whereDate('declare_le', $date)
            ->groupBy('site_id')
            ->selectRaw('site_id, count(*) as nombre')
            ->pluck('nombre', 'site_id');
    }

    /** La vague qui couvrait chaque site ce jour-là, par sa tournée. */
    private function vagueParSite(string $date, array $idsSites): Collection
    {
        return DB::table('tournees_site')
            ->whereIn('site_id', $idsSites)
            ->whereDate('date_debut', '<=', $date)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date))
            ->pluck('vague_id', 'site_id');
    }

    // ------------------------------------------------------------------
    // Niveau centre
    // ------------------------------------------------------------------

    private function recalculerCentres(string $date): int
    {
        $parCentre = AgregatJourSite::query()
            ->whereDate('date_jour', $date)
            ->groupBy('centre_id', 'region_id')
            ->selectRaw('centre_id, region_id, '
                .'count(*) as nb_sites_actifs, '
                .'sum(nb_enregistres) as nb_enregistres, sum(nb_rejetes) as nb_rejetes, '
                .'sum(effectif_attendu) as effectif_attendu, '
                .'sum(effectif_present) as effectif_present, '
                .'sum(effectif_absent) as effectif_absent')
            ->get();

        $kits = $this->kitsActifsParCentre($date);
        $incidents = $this->incidentsOuvertsParCentre();
        $vagues = $this->vagueParCentre($date);

        foreach ($parCentre as $ligne) {
            AgregatJourCentre::query()->updateOrCreate(
                ['centre_id' => $ligne->centre_id, 'date_jour' => $date],
                [
                    'region_id' => $ligne->region_id,
                    'vague_id' => $vagues[$ligne->centre_id] ?? null,
                    'nb_sites_actifs' => (int) $ligne->nb_sites_actifs,
                    'nb_kits_actifs' => (int) ($kits[$ligne->centre_id] ?? 0),
                    'nb_enregistres' => (int) $ligne->nb_enregistres,
                    'nb_rejetes' => (int) $ligne->nb_rejetes,
                    'effectif_attendu' => (int) $ligne->effectif_attendu,
                    'effectif_present' => (int) $ligne->effectif_present,
                    'effectif_absent' => (int) $ligne->effectif_absent,
                    'taux_presence' => $this->pourcentage(
                        (int) $ligne->effectif_present, (int) $ligne->effectif_attendu
                    ),
                    'nb_incidents_ouverts' => (int) ($incidents[$ligne->centre_id] ?? 0),
                    'recalcule_le' => now(),
                ]
            );
        }

        return $parCentre->count();
    }

    private function kitsActifsParCentre(string $date): Collection
    {
        return DB::table('tournees_site')
            ->whereNotNull('kit_id')
            ->whereDate('date_debut', '<=', $date)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date))
            ->groupBy('centre_id')
            ->selectRaw('centre_id, count(distinct kit_id) as nombre')
            ->pluck('nombre', 'centre_id');
    }

    /** Les incidents ENCORE OUVERTS, quelle que soit leur date de déclaration. */
    private function incidentsOuvertsParCentre(): Collection
    {
        return DB::table('incidents')
            ->whereNotNull('centre_id')
            ->whereNotIn('statut', ['resolu', 'cloture'])
            ->groupBy('centre_id')
            ->selectRaw('centre_id, count(*) as nombre')
            ->pluck('nombre', 'centre_id');
    }

    private function vagueParCentre(string $date): Collection
    {
        return DB::table('vague_centres as vc')
            ->join('vagues_deploiement as v', 'v.id', '=', 'vc.vague_id')
            ->whereDate('v.date_debut_prevue', '<=', $date)
            ->where('vc.statut', '<>', 'ferme')
            ->pluck('vc.vague_id', 'vc.centre_id');
    }

    // ------------------------------------------------------------------
    // Niveau région
    // ------------------------------------------------------------------

    private function recalculerRegions(string $date): int
    {
        $parRegion = AgregatJourCentre::query()
            ->whereDate('date_jour', $date)
            ->groupBy('region_id')
            ->selectRaw('region_id, '
                .'count(*) as nb_centres_ouverts, sum(nb_sites_actifs) as nb_sites_couverts, '
                .'sum(nb_enregistres) as nb_enregistres, sum(nb_rejetes) as nb_rejetes, '
                .'sum(effectif_attendu) as effectif_attendu, sum(effectif_present) as effectif_present')
            ->get();

        $incidents = $this->incidentsOuvertsParRegionEtGravite();

        foreach ($parRegion as $ligne) {
            AgregatJourRegion::query()->updateOrCreate(
                ['region_id' => $ligne->region_id, 'date_jour' => $date],
                [
                    'nb_enregistres' => (int) $ligne->nb_enregistres,
                    'nb_rejetes' => (int) $ligne->nb_rejetes,
                    'nb_centres_ouverts' => (int) $ligne->nb_centres_ouverts,
                    'nb_sites_couverts' => (int) $ligne->nb_sites_couverts,
                    // SIMULTANÉ à l'intérieur d'une région : ce sont bien des
                    // personnes différentes présentes le même jour. C'est entre
                    // régions que la somme n'aurait aucun sens.
                    'effectif_deploye' => (int) $ligne->effectif_present,
                    'taux_presence' => $this->pourcentage(
                        (int) $ligne->effectif_present, (int) $ligne->effectif_attendu
                    ),
                    'nb_incidents_ouverts_par_gravite' => $incidents[$ligne->region_id] ?? [],
                    'recalcule_le' => now(),
                ]
            );
        }

        return $parRegion->count();
    }

    private function incidentsOuvertsParRegionEtGravite(): array
    {
        $lignes = DB::table('incidents')
            ->whereNotNull('region_id')
            ->whereNotIn('statut', ['resolu', 'cloture'])
            ->groupBy('region_id', 'gravite')
            ->selectRaw('region_id, gravite, count(*) as nombre')
            ->get();

        $parRegion = [];

        foreach ($lignes as $ligne) {
            $parRegion[$ligne->region_id]['niveau_'.$ligne->gravite] = (int) $ligne->nombre;
        }

        return $parRegion;
    }

    // ------------------------------------------------------------------

    /**
     * Un pourcentage à deux décimales, ou zéro quand il n'y a rien à rapporter.
     * Diviser par un dénominateur nul donnerait « NaN » sur un tableau de bord —
     * et un indicateur illisible est un indicateur qu'on cesse de regarder.
     */
    private function pourcentage(int $numerateur, int $denominateur): float
    {
        return $denominateur > 0 ? round($numerateur * 100 / $denominateur, 2) : 0.0;
    }
}
