<?php

namespace App\Services\Demonstration;

use App\Models\Centre;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Region;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Comptes\ServiceCycleDeVieCompte;
use Illuminate\Support\Facades\DB;

/**
 * Une vague de déploiement de démonstration, sur une région (cadrage,
 * Partie B, livrable 5).
 *
 * SIMPLIFICATION ASSUMÉE : l'AFFECTATION AUTOMATIQUE SOUS CONTRAINTES avec
 * tirage aléatoire reproductible (graine enregistrée, proximité géographique
 * des 2 centres d'un superviseur) est l'objet de la tâche 5 du cadrage, pas de
 * cette tâche 1. Ce générateur affecte donc de façon DÉTERMINISTE et directe
 * — utile pour développer et démontrer — sans prétendre appliquer l'algorithme
 * final. La table unites_supervision porte bien un booléen meme_commune et un
 * champ contrainte_respectee, posés ici à leur valeur réelle : rien n'est
 * maquillé, seul l'algorithme de tirage n'est pas encore celui de la tâche 5.
 *
 * Ce que fait ce générateur, dans l'ordre :
 *   1. Ouvre tous les centres fictifs de la région dans une vague ACTIVE.
 *   2. Apparie les centres deux par deux (même commune en priorité) et leur
 *      affecte un superviseur opérationnel disponible.
 *   3. Affecte à chaque centre son kit et un opérateur opérationnel disponible.
 *   4. Crée les tournées de site du centre : le kit couvre ses sites en
 *      séquence, à raison du paramètre affectation.duree_passage_site_jours.
 *   5. Affecte les assistants déjà rattachés à une localité de la région, sur
 *      le centre du premier site de leur localité.
 *   6. Ouvre l'accès de chaque agent engagé via le service de cycle de vie.
 */
class GenerateurVagueDemonstration
{
    public function __construct(
        private readonly ServiceCycleDeVieCompte $cycleDeVie = new ServiceCycleDeVieCompte,
    ) {
    }

    public function generer(): ?array
    {
        $region = Region::query()->where('code', config('pnvb.demonstration.region_vague', 'BAN'))->first();

        if (! $region) {
            return null;
        }

        $administrateur = User::query()->role('administrateur_national')->first()
            ?? User::query()->role('super_administrateur')->first();

        $centres = Centre::query()->where('region_id', $region->id)->where('est_fictif', true)->get();

        if ($centres->isEmpty() || ! $administrateur) {
            return null;
        }

        $graine = (int) config('pnvb.demonstration.graine', 20260911);
        mt_srand($graine);

        $dureeSite = Parametre::entier('affectation.duree_passage_site_jours', 3);
        $dateDebut = now()->startOfWeek();
        $dateFin = $dateDebut->copy()->addWeeks(8);

        $vague = VagueDeploiement::query()->create([
            'code' => "{$region->code}-".$dateDebut->format('Y').'-DEMO',
            'libelle' => "Vague de démonstration — {$region->nom}",
            'region_id' => $region->id,
            'date_debut_prevue' => $dateDebut->toDateString(),
            'date_fin_prevue' => $dateFin->toDateString(),
            'date_ouverture_reelle' => $dateDebut,
            'statut' => 'active',
            'graine_tirage' => $graine,
            'contraintes_tirage' => [
                'centres_par_superviseur' => Parametre::entier('affectation.centres_par_superviseur', 2),
                'distance_max_centres_km' => Parametre::decimal('affectation.distance_max_centres_km', 25),
                'note' => 'Affectation déterministe de démonstration — pas encore l\'algorithme de tirage sous '
                    .'contraintes de la tâche 5.',
            ],
            'cree_par' => $administrateur->id,
            'valide_par' => $administrateur->id,
            'valide_le' => now(),
            'est_fictif' => true,
        ]);

        $this->ouvrirCentres($vague, $centres);

        $volontairesEngages = [];

        $volontairesEngages = [
            ...$volontairesEngages,
            ...$this->affecterSuperviseurs($vague, $centres),
        ];
        $volontairesEngages = [
            ...$volontairesEngages,
            ...$this->affecterOperateursEtTournees($vague, $centres, $dureeSite),
        ];
        $volontairesEngages = [
            ...$volontairesEngages,
            ...$this->affecterAssistants($vague, $region),
        ];

        foreach (array_unique($volontairesEngages) as $idVolontaire) {
            $this->cycleDeVie->ouvrirPourAffectation(Volontaire::query()->find($idVolontaire));
        }

        return [
            'vague' => $vague->code,
            'region' => $region->nom,
            'centres' => $centres->count(),
            'agents_engages' => count(array_unique($volontairesEngages)),
        ];
    }

    private function ouvrirCentres(VagueDeploiement $vague, $centres): void
    {
        $lignes = $centres->map(fn ($centre) => [
            'vague_id' => $vague->id,
            'centre_id' => $centre->id,
            'date_ouverture' => $vague->date_debut_prevue,
            'statut' => 'ouvert',
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($lignes, 500) as $lot) {
            DB::table('vague_centres')->insert($lot);
        }

        Centre::query()->whereIn('id', $centres->pluck('id'))->update(['statut' => 'ouvert']);
    }

    /** @return int[] Identifiants des volontaires engagés. */
    private function affecterSuperviseurs(VagueDeploiement $vague, $centres): array
    {
        // Apparie les centres deux par deux, même commune en priorité — sans
        // coordonnées GPS dans le référentiel source, la proximité réelle en
        // kilomètres ne peut pas être calculée ; meme_commune reste le seul
        // critère de proximité disponible pour cette démonstration.
        $parCommune = $centres->groupBy('commune_id');
        $paires = [];
        $isoles = [];

        foreach ($parCommune as $groupe) {
            $liste = $groupe->values();

            for ($i = 0; $i + 1 < $liste->count(); $i += 2) {
                $paires[] = [$liste[$i], $liste[$i + 1], true];
            }

            if ($liste->count() % 2 === 1) {
                $isoles[] = $liste->last();
            }
        }

        for ($i = 0; $i + 1 < count($isoles); $i += 2) {
            $paires[] = [$isoles[$i], $isoles[$i + 1], false];
        }

        if (count($isoles) % 2 === 1) {
            $paires[] = [end($isoles), null, false];
        }

        $superviseurs = Volontaire::query()
            ->disponiblesPourTirage()
            ->categorie(\App\Enums\CategorieVolontaire::Superviseur)
            ->limit(count($paires))
            ->get();

        $engages = [];

        foreach ($paires as $index => [$centreA, $centreB, $memeCommune]) {
            $superviseur = $superviseurs->get($index);

            if (! $superviseur) {
                break;
            }

            $distance = $centreB ? $centreA->distanceKmVers($centreB) : null;
            $distanceMax = Parametre::decimal('affectation.distance_max_centres_km', 25);

            $unite = DB::table('unites_supervision')->insertGetId([
                'vague_id' => $vague->id,
                'volontaire_superviseur_id' => $superviseur->id,
                'centre_principal_id' => $centreA->id,
                'centre_secondaire_id' => $centreB?->id,
                'distance_km' => $distance,
                'meme_commune' => $memeCommune,
                'contrainte_respectee' => $memeCommune || $distance === null || $distance <= $distanceMax,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('affectations')->insert([
                'vague_id' => $vague->id,
                'volontaire_id' => $superviseur->id,
                'role_terrain' => 'superviseur',
                'unite_supervision_id' => $unite,
                'date_debut' => $vague->date_debut_prevue,
                'statut' => 'active',
                'origine' => 'tirage_auto',
                'rang_tirage' => $index + 1,
                'est_fictif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $engages[] = $superviseur->id;
        }

        return $engages;
    }

    /** @return int[] Identifiants des volontaires engagés. */
    private function affecterOperateursEtTournees(VagueDeploiement $vague, $centres, int $dureeSite): array
    {
        $operateurs = Volontaire::query()
            ->disponiblesPourTirage()
            ->categorie(\App\Enums\CategorieVolontaire::Operateur)
            ->limit($centres->count())
            ->get()
            ->values();

        $engages = [];
        $rangTirage = 0;

        foreach ($centres as $index => $centre) {
            $operateur = $operateurs->get($index);
            $kit = DB::table('kits')
                ->where('centre_courant_id', null)
                ->where('etat', 'fonctionnel')
                ->where('est_fictif', true)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('affectations')
                    ->whereColumn('affectations.kit_id', 'kits.id')
                    ->where('affectations.statut', 'active'))
                ->orderBy('id')
                ->first();

            if (! $operateur || ! $kit) {
                continue;
            }

            $idAffectation = DB::table('affectations')->insertGetId([
                'vague_id' => $vague->id,
                'volontaire_id' => $operateur->id,
                'role_terrain' => 'operateur',
                'centre_id' => $centre->id,
                'kit_id' => $kit->id,
                'date_debut' => $vague->date_debut_prevue,
                'statut' => 'active',
                'origine' => 'tirage_auto',
                'rang_tirage' => ++$rangTirage,
                'est_fictif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('kits')->where('id', $kit->id)->update([
                'volontaire_detenteur_id' => $operateur->id,
                'centre_courant_id' => $centre->id,
            ]);

            $this->genererTournees($vague, $centre, $kit->id, $idAffectation, $dureeSite);

            $engages[] = $operateur->id;
        }

        return $engages;
    }

    /**
     * Le kit couvre les sites de son centre EN SÉQUENCE (cadrage, section 4) :
     * un site après l'autre, jamais simultanément. Le premier passage est
     * EN COURS, les suivants PLANIFIÉS avec des dates calculées.
     */
    private function genererTournees(VagueDeploiement $vague, Centre $centre, int $idKit, int $idAffectation, int $dureeSite): void
    {
        $sites = DB::table('sites')->where('centre_id', $centre->id)->orderBy('ordre_tournee')->get(['id']);
        $date = \Illuminate\Support\Carbon::parse($vague->date_debut_prevue);
        $lignes = [];

        foreach ($sites as $rang => $site) {
            $debut = $date->copy();
            $fin = $debut->copy()->addDays($dureeSite - 1);

            $lignes[] = [
                'vague_id' => $vague->id,
                'centre_id' => $centre->id,
                'site_id' => $site->id,
                'kit_id' => $idKit,
                'affectation_operateur_id' => $idAffectation,
                'ordre' => $rang + 1,
                'date_debut' => $debut->toDateString(),
                'date_fin' => $fin->toDateString(),
                'statut' => $rang === 0 ? 'en_cours' : 'planifiee',
                'est_fictif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $date = $fin->copy()->addDay();
        }

        foreach (array_chunk($lignes, 500) as $lot) {
            DB::table('tournees_site')->insert($lot);
        }

        DB::table('sites')->where('centre_id', $centre->id)
            ->orderBy('ordre_tournee')
            ->limit(1)
            ->update(['statut' => 'ouvert']);
    }

    /** @return int[] Identifiants des volontaires engagés. */
    private function affecterAssistants(VagueDeploiement $vague, Region $region): array
    {
        $assistants = Volontaire::query()
            ->disponiblesPourTirage()
            ->categorie(\App\Enums\CategorieVolontaire::Assistant)
            ->whereHas('localite', fn ($q) => $q->where('region_id', $region->id))
            ->with('localite')
            ->get();

        $engages = [];
        $lignes = [];

        foreach ($assistants as $assistant) {
            $siteLocalite = DB::table('sites')->where('localite_id', $assistant->localite_id)->first();

            if (! $siteLocalite) {
                continue;
            }

            $lignes[] = [
                'vague_id' => $vague->id,
                'volontaire_id' => $assistant->id,
                'role_terrain' => 'assistant',
                'centre_id' => $siteLocalite->centre_id,
                'localite_id' => $assistant->localite_id,
                'date_debut' => $vague->date_debut_prevue,
                'statut' => 'active',
                'origine' => 'tirage_auto',
                'est_fictif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $engages[] = $assistant->id;
        }

        foreach (array_chunk($lignes, 500) as $lot) {
            DB::table('affectations')->insert($lot);
        }

        return $engages;
    }
}
