<?php

namespace App\Services\Agregats;

use App\Enums\NiveauPerimetre;
use App\Models\AgregatCouvertureLocalite;
use App\Models\AgregatJourCentre;
use App\Models\AgregatJourRegion;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LES CHIFFRES DU TABLEAU DE BORD (cadrage, section 15).
 *
 * Ce service ne calcule rien : il LIT les agrégats. Toute agrégation à la volée
 * sur 12 294 sites rendrait le tableau de bord inutilisable au bout de trois
 * semaines de collecte.
 *
 * LA RÈGLE QUI GOUVERNE TOUT LE RESTE : l'effectif déployé ne s'additionne
 * JAMAIS entre régions. Ce sont les mêmes équipes qui tournent d'une région à
 * l'autre. Un indicateur national qui sommerait les douze régions annoncerait
 * plusieurs milliers d'agents là où il y en a 2 415 — et ce chiffre finirait
 * dans un rapport officiel.
 *
 * Toute méthode qui expose un effectif national rend donc un PIC SIMULTANÉ,
 * accompagné de la date à laquelle il a été atteint : sans cette date, le
 * lecteur ne peut pas savoir ce que le nombre veut dire.
 */
class ServiceTableauBord
{
    /**
     * La synthèse du jour, cadrée sur le périmètre de l'utilisateur.
     *
     * Un chef d'antenne voit sa région ; un administrateur national voit les
     * douze, sans que leurs effectifs soient additionnés.
     */
    public function synthese(User $utilisateur, string $date): array
    {
        $regions = $this->regionsAccessibles($utilisateur);

        $lignes = AgregatJourRegion::query()
            ->whereDate('date_jour', $date)
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            ->with('region:id,code,nom,population_totale')
            ->get();

        $national = $regions === null;

        return [
            'date' => $date,
            'portee' => $national ? 'nationale' : 'regionale',
            'enregistrements' => [
                // Les enregistrements, EUX, s'additionnent : ce sont des
                // personnes différentes dans chaque région.
                'du_jour' => (int) $lignes->sum('nb_enregistres'),
                'rejetes_du_jour' => (int) $lignes->sum('nb_rejetes'),
                'cumul' => $this->cumulEnregistrements($regions),
            ],
            'deploiement' => [
                'centres_ouverts' => (int) $lignes->sum('nb_centres_ouverts'),
                'sites_couverts' => (int) $lignes->sum('nb_sites_couverts'),
                // LE PIC, jamais la somme.
                'effectif_simultane' => $this->effectifSimultane($lignes, $national),
                'taux_presence' => $this->moyennePonderee($lignes, $date, $regions),
            ],
            'incidents_ouverts' => $this->incidentsParGravite($lignes),
            'couverture' => $this->couvertureGlobale($regions),
            'regions' => $lignes->map(fn (AgregatJourRegion $ligne) => [
                'region_id' => $ligne->region_id,
                'code' => $ligne->region?->code,
                'nom' => $ligne->region?->nom,
                'enregistrements' => (int) $ligne->nb_enregistres,
                'centres_ouverts' => (int) $ligne->nb_centres_ouverts,
                'sites_couverts' => (int) $ligne->nb_sites_couverts,
                'effectif_deploye' => (int) $ligne->effectif_deploye,
                'taux_presence' => (float) $ligne->taux_presence,
            ])->sortByDesc('enregistrements')->values(),
        ];
    }

    /**
     * L'EFFECTIF SIMULTANÉ.
     *
     * Au niveau d'une région, c'est la somme de ses sites : des personnes
     * différentes, présentes le même jour. Au niveau national, c'est le PIC —
     * le plus grand effectif régional du jour — et la région qui l'atteint est
     * nommée, pour que le nombre soit interprétable.
     */
    private function effectifSimultane(Collection $lignes, bool $national): array
    {
        if ($lignes->isEmpty()) {
            return ['valeur' => 0, 'nature' => $national ? 'pic_regional' : 'somme_des_sites'];
        }

        if (! $national) {
            return [
                'valeur' => (int) $lignes->sum('effectif_deploye'),
                'nature' => 'somme_des_sites',
                'explication' => 'Somme des effectifs présents sur les sites de la région : '
                    .'des personnes différentes, le même jour.',
            ];
        }

        $pic = $lignes->sortByDesc('effectif_deploye')->first();

        return [
            'valeur' => (int) $pic->effectif_deploye,
            'nature' => 'pic_regional',
            'region' => $pic->region?->nom,
            'explication' => 'Effectif le plus élevé atteint dans une région ce jour-là. '
                .'Les régions ne sont jamais additionnées : ce sont les mêmes équipes '
                .'qui tournent de l\'une à l\'autre.',
        ];
    }

    /** L'évolution jour par jour, pour la courbe du tableau de bord. */
    public function evolution(User $utilisateur, string $du, string $au): array
    {
        $regions = $this->regionsAccessibles($utilisateur);

        $jours = AgregatJourRegion::query()
            ->whereBetween('date_jour', [$du, $au])
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            ->groupBy('date_jour')
            ->selectRaw('date_jour, sum(nb_enregistres) as enregistrements, '
                .'sum(nb_rejetes) as rejetes, sum(nb_sites_couverts) as sites_couverts, '
                // max() et non sum() : le pic du jour, pas un cumul entre régions.
                .'max(effectif_deploye) as pic_effectif_regional')
            ->orderBy('date_jour')
            ->get();

        $cumul = 0;

        return [
            'du' => $du,
            'au' => $au,
            'jours' => $jours->map(function ($jour) use (&$cumul) {
                $cumul += (int) $jour->enregistrements;

                return [
                    // AAAA-MM-JJ, jamais une date-heure : le client renvoie ce jour
                    // tel quel comme filtre (?date=), et la forme ISO sérialisée par
                    // défaut — « 2026-09-11T00:00:00.000000Z » — ne retrouve aucune
                    // journée en base.
                    'date' => \Illuminate\Support\Carbon::parse($jour->date_jour)->toDateString(),
                    'enregistrements' => (int) $jour->enregistrements,
                    'rejetes' => (int) $jour->rejetes,
                    'cumul' => $cumul,
                    'sites_couverts' => (int) $jour->sites_couverts,
                    'pic_effectif_regional' => (int) $jour->pic_effectif_regional,
                ];
            })->values(),
        ];
    }

    /**
     * Le taux de couverture par région — le fond de carte du tableau de bord.
     *
     * Les contours GeoJSON sont rendus tels qu'ils sont en base : vides tant
     * que le client ne les a pas fournis. Le taux reste lisible sans eux.
     */
    public function couvertureParRegion(User $utilisateur): array
    {
        $regions = $this->regionsAccessibles($utilisateur);

        $cumuls = DB::table('agregats_couverture_localite')
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            ->groupBy('region_id')
            ->selectRaw('region_id, sum(cumul_enregistres) as cumul, '
                .'sum(population_cible) as population_couverte, '
                .'count(*) as localites_actives')
            ->get()
            ->keyBy('region_id');

        return Region::query()
            ->when($regions !== null, fn ($q) => $q->whereIn('id', $regions))
            ->orderBy('nom')
            ->get(['id', 'code', 'nom', 'population_totale', 'nombre_sites_alloues', 'contour_geojson'])
            ->map(function (Region $region) use ($cumuls) {
                $ligne = $cumuls[$region->id] ?? null;
                $cumul = (int) ($ligne->cumul ?? 0);
                $population = (int) $region->population_totale;

                return [
                    'region_id' => $region->id,
                    'code' => $region->code,
                    'nom' => $region->nom,
                    'population' => $population,
                    'enregistres' => $cumul,
                    'taux_couverture' => $population > 0 ? round($cumul * 100 / $population, 2) : null,
                    'localites_actives' => (int) ($ligne->localites_actives ?? 0),
                    'sites_alloues' => (int) $region->nombre_sites_alloues,
                    // Nul tant que le client n'a pas fourni les contours : la
                    // carte se dessine alors sans aplats, pas du tout faux.
                    'contour_geojson' => $region->contour_geojson,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Les localités les moins couvertes — celles où il faut retourner.
     * C'est la liste qui sert à décider, bien plus qu'une moyenne nationale.
     */
    public function localitesEnRetard(User $utilisateur, int $limite = 50): array
    {
        $regions = $this->regionsAccessibles($utilisateur);

        return AgregatCouvertureLocalite::query()
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            // Une localité sans population connue n'est pas « en retard » :
            // elle n'est pas mesurable, et la mêler aux autres fausserait le
            // classement autant que la décision qui en découle.
            ->where('population_cible', '>', 0)
            ->with(['localite:id,nom,commune_id', 'commune:id,nom', 'region:id,code,nom'])
            ->orderBy('taux_couverture')
            ->orderByDesc('population_cible')
            ->limit($limite)
            ->get()
            ->map(fn (AgregatCouvertureLocalite $ligne) => [
                'localite' => $ligne->localite?->nom,
                'commune' => $ligne->commune?->nom,
                'region' => $ligne->region?->nom,
                'population_cible' => (int) $ligne->population_cible,
                'enregistres' => (int) $ligne->cumul_enregistres,
                'taux_couverture' => (float) $ligne->taux_couverture,
                'sites' => (int) $ligne->nb_sites,
                'sites_couverts' => (int) $ligne->nb_sites_couverts,
                'derniere_activite_le' => $ligne->derniere_activite_le?->toDateString(),
            ])
            ->all();
    }

    /** Le classement des centres du jour, pour le pilotage opérationnel. */
    public function centresDuJour(User $utilisateur, string $date): array
    {
        $regions = $this->regionsAccessibles($utilisateur);

        return AgregatJourCentre::query()
            ->whereDate('date_jour', $date)
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            ->with(['centre:id,code,nom,commune_id', 'centre.commune:id,nom', 'region:id,code,nom'])
            ->orderByDesc('nb_enregistres')
            ->get()
            ->map(fn (AgregatJourCentre $ligne) => [
                'centre' => $ligne->centre?->code,
                'nom' => $ligne->centre?->nom,
                'commune' => $ligne->centre?->commune?->nom,
                'region' => $ligne->region?->nom,
                'enregistrements' => (int) $ligne->nb_enregistres,
                'rejetes' => (int) $ligne->nb_rejetes,
                'sites_actifs' => (int) $ligne->nb_sites_actifs,
                'kits_actifs' => (int) $ligne->nb_kits_actifs,
                'effectif_present' => (int) $ligne->effectif_present,
                'effectif_attendu' => (int) $ligne->effectif_attendu,
                'taux_presence' => (float) $ligne->taux_presence,
                'incidents_ouverts' => (int) $ligne->nb_incidents_ouverts,
            ])
            ->all();
    }

    /**
     * CE QUI APPELLE UNE ACTION — la seconde moitié du tableau de bord.
     *
     * Les agrégats disent ce qui S'EST PASSÉ ; ces compteurs-ci disent ce qui
     * ATTEND QUELQU'UN : des fiches sans profil, des identifiants qui ne sont
     * pas arrivés, des kits qu'on n'a pas récupérés, des rapports à viser, des
     * écarts à examiner.
     *
     * Ils se lisent en direct, et non dans les agrégats de la nuit : une file
     * d'attente affichée avec un jour de retard fait agir trop tard.
     *
     * Chaque compteur porte le PÉRIMÈTRE de l'utilisateur, par le scope du
     * modèle concerné — jamais par une clause réécrite ici.
     */
    public function pilotage(User $utilisateur): array
    {
        return [
            'vague' => $this->vagueEnCours($utilisateur),
            'volontaires' => $this->fileDesVolontaires($utilisateur),
            'terrain' => $this->fileDuTerrain($utilisateur),
            'materiel' => $this->fileDuMateriel($utilisateur),
        ];
    }

    /**
     * La vague active : ce qui est déployé en ce moment, et par qui.
     *
     * Les effectifs sont ceux de CETTE vague : des personnes distinctes, sur
     * une même région — ils s'additionnent sans mentir. C'est l'effectif
     * NATIONAL qui ne s'additionne jamais, et il n'apparaît pas ici.
     */
    private function vagueEnCours(User $utilisateur): ?array
    {
        $regions = $this->regionsAccessibles($utilisateur);

        $vague = \App\Models\VagueDeploiement::query()
            ->where('statut', \App\Enums\StatutVague::Active->value)
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            ->with('region:id,code,nom')
            ->orderByDesc('date_debut_prevue')
            ->first();

        if (! $vague) {
            return null;
        }

        $parRole = \App\Models\Affectation::query()
            ->where('vague_id', $vague->id)
            ->where('statut', 'active')
            ->selectRaw('role_terrain, count(*) as nombre')
            ->groupBy('role_terrain')
            ->pluck('nombre', 'role_terrain');

        $centres = DB::table('vague_centres')->where('vague_id', $vague->id);

        return [
            'id' => $vague->id,
            'code' => $vague->code,
            'libelle' => $vague->libelle,
            'region' => $vague->region?->nom,
            'date_debut' => $vague->date_debut_prevue?->toDateString(),
            'date_fin' => $vague->date_fin_prevue?->toDateString(),
            'jours_restants' => $vague->date_fin_prevue
                ? (int) now()->startOfDay()->diffInDays($vague->date_fin_prevue, false)
                : null,
            'agents' => [
                'superviseur' => (int) ($parRole['superviseur'] ?? 0),
                'operateur' => (int) ($parRole['operateur'] ?? 0),
                'assistant' => (int) ($parRole['assistant'] ?? 0),
                'total' => (int) $parRole->sum(),
            ],
            'centres' => [
                'total' => (clone $centres)->count(),
                'ouverts' => (clone $centres)->where('statut', 'ouvert')->count(),
            ],
        ];
    }

    /** Les fiches et les accès qui attendent quelqu'un. */
    private function fileDesVolontaires(User $utilisateur): array
    {
        $comptes = \App\Models\User::query()
            ->whereHas('volontaire', fn ($q) => $q->perimetre($utilisateur));

        $parEtat = (clone $comptes)
            ->selectRaw('etat_remise, count(*) as nombre')
            ->groupBy('etat_remise')
            ->pluck('nombre', 'etat_remise');

        return [
            'a_qualifier' => \App\Models\Volontaire::query()->perimetre($utilisateur)->aQualifier()->count(),
            'sans_niveau' => \App\Models\Volontaire::query()->perimetre($utilisateur)
                ->whereNull('niveau_etude')->count(),
            'identifiants_non_remis' => (int) ($parEtat['non_envoye'] ?? 0) + (int) ($parEtat['echec'] ?? 0),
            'premiere_connexion_faite' => (int) ($parEtat['premiere_connexion_effectuee'] ?? 0),
            'acces_ouverts' => (clone $comptes)->where('statut_compte', 'actif')->count(),
        ];
    }

    /** Ce que le terrain a envoyé et qui attend une décision. */
    private function fileDuTerrain(User $utilisateur): array
    {
        $incidents = \App\Models\Incident::query()->perimetre($utilisateur)
            ->whereIn('statut', ['nouveau', 'pris_en_charge', 'en_cours']);

        return [
            'rapports_a_viser' => \App\Models\RapportJournalier::query()->aViserPar($utilisateur)->count(),
            'incidents_ouverts' => (clone $incidents)->count(),
            'incidents_critiques' => (clone $incidents)->where('gravite', 4)->count(),
            // Même condition que l'écran « incidents en retard » : une échéance
            // d'escalade dépassée, ou une escalade déjà déclenchée.
            'incidents_en_retard' => \App\Models\Incident::query()->perimetre($utilisateur)
                ->where('statut', 'nouveau')
                ->where(fn ($q) => $q->where('echeance_escalade', '<=', now())
                    ->orWhere('niveau_escalade', '>', 0))
                ->count(),
            'ecarts_ouverts' => \App\Models\EcartPresence::query()->perimetre($utilisateur)
                ->where('statut', 'ouvert')->count(),
            'alertes_non_lues' => \App\Models\Alerte::query()
                ->pour($utilisateur)
                ->enCours()
                ->whereDoesntHave('lecteurs', fn ($q) => $q->whereKey($utilisateur->id))
                ->count(),
        ];
    }

    /** Le parc : ce qui manque, et ce qu'on n'a pas récupéré. */
    private function fileDuMateriel(User $utilisateur): array
    {
        $kits = fn () => \App\Models\Kit::query()->perimetre($utilisateur);

        return [
            'total' => $kits()->count(),
            'disponibles' => $kits()->disponibles()->count(),
            'hors_service' => $kits()->whereIn('etat', ['panne', 'perdu', 'vole', 'reforme'])->count(),
            'non_restitues' => app(\App\Services\Kits\ServiceAlertesKits::class)
                ->kitsNonRestitues()
                ->filter(fn ($kit) => \App\Models\Kit::query()->perimetre($utilisateur)->whereKey($kit->id)->exists())
                ->count(),
        ];
    }

    // ------------------------------------------------------------------

    /**
     * LES SITES À PLACER SUR LA CARTE.
     *
     * Seuls les sites dont les coordonnées sont connues sont rendus, avec le
     * strict nécessaire pour un marqueur : 12 294 sites en fiches complètes
     * alourdiraient la carte sans rien lui apprendre. Le nombre de sites SANS
     * coordonnées est rendu aussi, pour que la carte dise ce qui lui manque
     * au lieu de paraître simplement vide.
     *
     * @return array{total_sites: int, localises: int, sites: array<int, array<string, mixed>>}
     */
    public function sitesCarte(User $utilisateur): array
    {
        $regions = $this->regionsAccessibles($utilisateur);
        $perimetre = fn () => DB::table('sites')
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions));

        $couverts = DB::table('agregats_jour_site')
            ->where('nb_enregistres', '>', 0)
            ->distinct()
            ->pluck('site_id')
            ->flip();

        $sites = $perimetre()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get(['id', 'code', 'nom', 'latitude', 'longitude'])
            ->map(fn ($site) => [
                'id' => $site->id,
                'code' => $site->code,
                'nom' => $site->nom,
                'lat' => (float) $site->latitude,
                'lng' => (float) $site->longitude,
                'couvert' => isset($couverts[$site->id]),
            ])
            ->all();

        return [
            'total_sites' => $perimetre()->count(),
            'localises' => count($sites),
            'sites' => $sites,
        ];
    }

    /**
     * Les régions que l'utilisateur peut voir, ou NULL pour « toutes ».
     *
     * Nul plutôt qu'un tableau des douze identifiants : cela laisse les
     * requêtes nationales sans clause superflue, et distingue explicitement le
     * périmètre national de celui d'un chef d'antenne.
     *
     * @return array<int, int>|null
     */
    private function regionsAccessibles(User $utilisateur): ?array
    {
        if ($utilisateur->niveauPerimetre() === NiveauPerimetre::National) {
            return null;
        }

        $region = $utilisateur->idRegionAccessible();

        return $region ? [$region] : [];
    }

    private function cumulEnregistrements(?array $regions): int
    {
        return (int) DB::table('agregats_couverture_localite')
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            ->sum('cumul_enregistres');
    }

    private function couvertureGlobale(?array $regions): array
    {
        $population = (int) Region::query()
            ->when($regions !== null, fn ($q) => $q->whereIn('id', $regions))
            ->sum('population_totale');

        $enregistres = $this->cumulEnregistrements($regions);

        return [
            'population_cible' => $population,
            'enregistres' => $enregistres,
            'taux' => $population > 0 ? round($enregistres * 100 / $population, 2) : null,
        ];
    }

    /**
     * Le taux de présence global est PONDÉRÉ par les effectifs attendus, pas
     * moyenné entre régions : une région de 40 agents ne pèse pas autant
     * qu'une région de 400.
     */
    private function moyennePonderee(Collection $lignes, string $date, ?array $regions): float
    {
        if ($lignes->isEmpty()) {
            return 0.0;
        }

        $totaux = AgregatJourCentre::query()
            ->whereDate('date_jour', $date)
            ->when($regions !== null, fn ($q) => $q->whereIn('region_id', $regions))
            ->selectRaw('sum(effectif_present) as presents, sum(effectif_attendu) as attendus')
            ->first();

        $attendus = (int) ($totaux->attendus ?? 0);

        return $attendus > 0 ? round((int) $totaux->presents * 100 / $attendus, 2) : 0.0;
    }

    private function incidentsParGravite(Collection $lignes): array
    {
        $total = ['niveau_1' => 0, 'niveau_2' => 0, 'niveau_3' => 0, 'niveau_4' => 0];

        foreach ($lignes as $ligne) {
            foreach ($ligne->nb_incidents_ouverts_par_gravite ?? [] as $niveau => $nombre) {
                $total[$niveau] = ($total[$niveau] ?? 0) + (int) $nombre;
            }
        }

        return $total + ['total' => array_sum($total)];
    }
}
