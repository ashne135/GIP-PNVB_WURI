<?php

namespace App\Services\Agregats;

use App\Models\AgregatCouvertureLocalite;
use App\Models\AgregatMoisRegion;
use App\Models\Region;
use Illuminate\Support\Facades\DB;

/**
 * LE TAUX DE COUVERTURE (cadrage, section 15).
 *
 * C'est l'indicateur qui dit si la mission avance : combien de personnes
 * enregistrées, rapportées à la population à enregistrer. Son dénominateur
 * vient du fichier réel — la population de chacune des 7 432 localités — et
 * c'est ce qui le rend opposable plutôt que déclaratif.
 *
 * DEUX PRÉCAUTIONS :
 *
 *  - une localité SANS POPULATION CONNUE ne produit pas un taux de zéro, qui
 *    la ferait passer pour un échec : elle produit un taux nul et reste
 *    identifiable comme non mesurable ;
 *
 *  - un taux SUPÉRIEUR À 100 % n'est pas plafonné. Il signale soit une
 *    population sous-estimée, soit un problème de rattachement des sites —
 *    dans les deux cas, quelque chose que la coordination doit voir, pas
 *    quelque chose qu'il faut masquer.
 */
class CalculateurCouverture
{
    /** @return array{localites: int, regions_mois: int} */
    public function recalculer(?string $jusquau = null): array
    {
        $jusquau ??= now()->toDateString();

        return [
            'localites' => $this->recalculerLocalites($jusquau),
            'regions_mois' => $this->recalculerMoisRegions($jusquau),
        ];
    }

    /**
     * La couverture cumulée de chaque localité, depuis le début du projet.
     *
     * Le rattachement passe par le SITE : un site appartient à une localité
     * pour la population, et à un centre pour la supervision (cadrage,
     * section 2). C'est le premier rattachement qui sert ici.
     */
    private function recalculerLocalites(string $jusquau): int
    {
        $cumul = DB::table('agregats_jour_site as a')
            ->join('sites as s', 's.id', '=', 'a.site_id')
            ->whereDate('a.date_jour', '<=', $jusquau)
            ->groupBy('s.localite_id')
            ->selectRaw('s.localite_id, sum(a.nb_enregistres) as cumul, '
                .'max(a.date_jour) as derniere_activite, '
                .'count(distinct case when a.nb_enregistres > 0 then a.site_id end) as sites_couverts')
            ->get()
            ->keyBy('localite_id');

        if ($cumul->isEmpty()) {
            return 0;
        }

        $localites = DB::table('localites')
            ->whereIn('id', $cumul->keys())
            ->get(['id', 'commune_id', 'region_id', 'population_totale'])
            ->keyBy('id');

        $nbSites = DB::table('sites')
            ->whereIn('localite_id', $cumul->keys())
            ->groupBy('localite_id')
            ->selectRaw('localite_id, count(*) as nombre')
            ->pluck('nombre', 'localite_id');

        $ecrits = 0;

        foreach ($cumul as $localiteId => $ligne) {
            $localite = $localites[$localiteId] ?? null;

            if (! $localite) {
                continue;
            }

            $population = (int) $localite->population_totale;
            $enregistres = (int) $ligne->cumul;

            AgregatCouvertureLocalite::query()->updateOrCreate(
                ['localite_id' => $localiteId],
                [
                    'commune_id' => $localite->commune_id,
                    'region_id' => $localite->region_id,
                    // Copiée plutôt que jointe : le dénominateur d'un taux
                    // publié ne doit pas changer sous les pieds du lecteur si
                    // le référentiel est corrigé plus tard.
                    'population_cible' => $population,
                    'cumul_enregistres' => $enregistres,
                    'taux_couverture' => $population > 0
                        ? round($enregistres * 100 / $population, 2)
                        : 0,
                    'nb_sites' => (int) ($nbSites[$localiteId] ?? 0),
                    'nb_sites_couverts' => (int) $ligne->sites_couverts,
                    'derniere_activite_le' => $ligne->derniere_activite,
                    'recalcule_le' => now(),
                ]
            );

            $ecrits++;
        }

        return $ecrits;
    }

    /**
     * Le mois de chaque région, avec son cumul depuis le début du projet.
     *
     * Le cumul est recalculé de bout en bout plutôt qu'incrémenté : un rapport
     * visé en retard modifie un mois passé, et un cumul incrémental garderait
     * l'erreur pour toujours.
     */
    private function recalculerMoisRegions(string $jusquau): int
    {
        $mois = DB::table('agregats_jour_region')
            ->whereDate('date_jour', '<=', $jusquau)
            ->groupBy('region_id', DB::raw('year(date_jour)'), DB::raw('month(date_jour)'))
            ->selectRaw('region_id, year(date_jour) as annee, month(date_jour) as mois, '
                .'sum(nb_enregistres) as nb_enregistres, '
                .'count(distinct case when nb_enregistres > 0 then date_jour end) as jours_actifs')
            ->orderBy('region_id')
            ->orderBy('annee')
            ->orderBy('mois')
            ->get();

        $populations = Region::query()->pluck('population_totale', 'id');

        $cumuls = [];
        $ecrits = 0;

        foreach ($mois as $ligne) {
            $cumuls[$ligne->region_id] = ($cumuls[$ligne->region_id] ?? 0) + (int) $ligne->nb_enregistres;
            $population = (int) ($populations[$ligne->region_id] ?? 0);

            AgregatMoisRegion::query()->updateOrCreate(
                ['region_id' => $ligne->region_id, 'annee' => $ligne->annee, 'mois' => $ligne->mois],
                [
                    'nb_enregistres_mois' => (int) $ligne->nb_enregistres,
                    'cumul_depuis_debut' => $cumuls[$ligne->region_id],
                    'taux_couverture' => $population > 0
                        ? round($cumuls[$ligne->region_id] * 100 / $population, 2)
                        : 0,
                    'nb_jours_actifs' => (int) $ligne->jours_actifs,
                    'recalcule_le' => now(),
                ]
            );

            $ecrits++;
        }

        return $ecrits;
    }
}
