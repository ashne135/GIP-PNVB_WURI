<?php

namespace App\Services\Affectation;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutAffectation;
use App\Enums\StatutVague;
use App\Models\Centre;
use App\Models\Kit;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * AFFECTATION AUTOMATIQUE ALÉATOIRE SOUS CONTRAINTES (cadrage, section 7).
 *
 * REPRODUCTIBILITÉ — le point de vigilance de cette tâche.
 * « Le tirage utilise une graine enregistrée, pour être REPRODUCTIBLE et
 * auditable. On doit pouvoir rejouer et expliquer une affectation. »
 *
 * Trois précautions y concourent, et les trois sont nécessaires :
 *
 *  1. Le générateur est un objet ISOLÉ (Random\Randomizer sur Mt19937), et non
 *     mt_srand() : celui-ci pilote un état GLOBAL que n'importe quel appel à
 *     mt_rand() ailleurs dans la requête ferait dériver.
 *  2. Tout ensemble tiré est d'abord TRIÉ PAR IDENTIFIANT, puis mélangé. Sans
 *     tri préalable, l'ordre implicite renvoyé par la base — qui peut changer
 *     d'une exécution à l'autre — rendrait la graine inopérante.
 *  3. Les PARAMÈTRES du tirage sont figés sur la vague au moment du tirage.
 *     Rejouer six mois plus tard, après qu'un seuil a changé, doit redonner le
 *     même résultat.
 *
 * CE QUI N'EST PAS TIRÉ AU SORT : l'assistant. « Il est déjà rattaché à la
 * localité du site. L'algorithme le rattache au kit de son site. »
 */
class TirageAffectations
{
    public function __construct(
        private readonly AppariementCentres $appariement = new AppariementCentres,
    ) {
    }

    /**
     * Produit une PROPOSITION d'affectation. Les affectations sont écrites en
     * statut « proposée », jamais « active » : rien n'est notifié, aucun accès
     * n'est ouvert, et l'administrateur peut encore ajuster.
     */
    public function tirer(
        VagueDeploiement $vague,
        ?int $graine = null,
        ?User $auteur = null
    ): ResultatTirage
    {
        if (! in_array($vague->statut, [StatutVague::Brouillon, StatutVague::Proposee], true)) {
            throw new \DomainException(
                "Cette vague est « {$vague->statut->libelle()} » : le tirage ne peut plus être relancé."
            );
        }

        $graine ??= random_int(1, PHP_INT_MAX);
        $contraintes = $this->contraintesDuTirage();

        $centres = $this->centresOuverts($vague);

        if ($centres->isEmpty()) {
            throw new \DomainException(
                "Aucun centre n'est ouvert dans cette vague : ajoutez-en avant de lancer le tirage."
            );
        }

        return DB::transaction(function () use ($vague, $graine, $contraintes, $centres, $auteur) {
            // Un nouveau tirage efface le précédent : une proposition remplace
            // une proposition, elle ne s'y ajoute pas.
            $this->effacerPropositionPrecedente($vague);

            $tirage = new Randomizer(new Mt19937($graine));
            $resultat = new ResultatTirage($graine, $contraintes);

            $this->affecterSuperviseurs($vague, $centres, $tirage, $contraintes, $resultat);
            $this->affecterOperateurs($vague, $centres, $tirage, $resultat);
            $this->rattacherAssistants($vague, $centres, $resultat);

            $vague->update([
                'statut' => StatutVague::Proposee->value,
                'graine_tirage' => $graine,
                'contraintes_tirage' => $contraintes,
            ]);

            $resultat->effectifsRestants = $this->effectifsRestants();

            activity('vague')
                // Un tirage est REPRODUCTIBLE et IMPUTABLE : la graine dit ce
                // qui a été tiré, l'auteur dit qui l'a lancé. Sans le second,
                // « on doit pouvoir expliquer une affectation » reste à moitié
                // vrai.
                ->causedBy($auteur)
                ->performedOn($vague)
                ->withProperties([
                    'graine' => $graine,
                    'contraintes' => $contraintes,
                    'affectations' => $resultat->nombreAffectations(),
                    'anomalies' => count($resultat->anomalies),
                ])
                ->log("Tirage effectué avec la graine {$graine}");

            return $resultat;
        });
    }

    /**
     * Rejoue le tirage d'une vague avec SA graine, et compare au résultat
     * enregistré. C'est l'outil d'audit : « on doit pouvoir rejouer et
     * expliquer une affectation ».
     *
     * @return array{identique: bool, ecarts: array}
     */
    public function verifierReproductibilite(VagueDeploiement $vague): array
    {
        if (! $vague->graine_tirage) {
            throw new \DomainException("Cette vague n'a pas encore fait l'objet d'un tirage.");
        }

        $avant = $this->empreinteDesAffectations($vague);

        $this->tirer($vague, (int) $vague->graine_tirage);

        $apres = $this->empreinteDesAffectations($vague);

        $ecarts = [];

        foreach ($avant as $cle => $valeur) {
            if (($apres[$cle] ?? null) !== $valeur) {
                $ecarts[] = [
                    'volontaire' => $cle,
                    'avant' => $valeur,
                    'apres' => $apres[$cle] ?? null,
                ];
            }
        }

        return ['identique' => $ecarts === [] && count($avant) === count($apres), 'ecarts' => $ecarts];
    }

    // ------------------------------------------------------------------
    // Étapes du tirage
    // ------------------------------------------------------------------

    /**
     * 1 SUPERVISEUR = 2 CENTRES, géographiquement proches.
     * Les paires sont formées d'abord, les superviseurs tirés ensuite : c'est la
     * contrainte de proximité qui commande, pas l'ordre des agents.
     */
    private function affecterSuperviseurs(
        VagueDeploiement $vague,
        Collection $centres,
        Randomizer $tirage,
        array $contraintes,
        ResultatTirage $resultat
    ): void {
        $paires = $this->appariement->apparier(
            $centres,
            $tirage,
            (float) $contraintes['distance_max_centres_km']
        );

        $vivier = $this->vivier(CategorieVolontaire::Superviseur, $tirage);
        $rang = 0;

        foreach ($paires as $paire) {
            $superviseur = array_shift($vivier);

            if (! $superviseur) {
                $resultat->anomalies[] = [
                    'type' => 'vivier_insuffisant',
                    'message' => 'Plus aucun superviseur disponible : le centre '
                        .$paire['centre_a']->code.' reste sans supervision.',
                ];

                continue;
            }

            $unite = DB::table('unites_supervision')->insertGetId([
                'vague_id' => $vague->id,
                'volontaire_superviseur_id' => $superviseur->id,
                'centre_principal_id' => $paire['centre_a']->id,
                'centre_secondaire_id' => $paire['centre_b']?->id,
                'distance_km' => $paire['distance_km'],
                'meme_commune' => $paire['meme_commune'],
                'contrainte_respectee' => $paire['contrainte_respectee'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->creerAffectation($vague, $superviseur, [
                'role_terrain' => CategorieVolontaire::Superviseur->value,
                'unite_supervision_id' => $unite,
                'rang_tirage' => ++$rang,
            ]);

            $resultat->superviseurs[] = [
                'volontaire_id' => $superviseur->id,
                'matricule' => $superviseur->matricule,
                'centres' => array_values(array_filter([
                    $paire['centre_a']->code,
                    $paire['centre_b']?->code,
                ])),
                'meme_commune' => $paire['meme_commune'],
                'distance_km' => $paire['distance_km'],
                'contrainte_respectee' => $paire['contrainte_respectee'],
            ];

            if ($paire['motif']) {
                $resultat->anomalies[] = [
                    'type' => 'proximite',
                    'message' => $paire['motif'],
                ];
            }
        }
    }

    /** CHAQUE KIT OUVERT REÇOIT 1 OPK, pris dans le vivier opérationnel. */
    private function affecterOperateurs(
        VagueDeploiement $vague,
        Collection $centres,
        Randomizer $tirage,
        ResultatTirage $resultat
    ): void {
        $vivier = $this->vivier(CategorieVolontaire::Operateur, $tirage);
        $kits = Kit::query()
            ->disponibles()
            ->orderBy('id')
            ->get();
        $rang = 0;

        foreach ($centres as $centre) {
            // Autant d'opérateurs que le centre compte de kits — le plafond de
            // 2 a été levé le 18/09/2026, la boucle n'en supposait rien.
            for ($n = 0; $n < max(1, (int) $centre->nombre_kits); $n++) {
                $operateur = array_shift($vivier);

                if (! $operateur) {
                    $resultat->anomalies[] = [
                        'type' => 'vivier_insuffisant',
                        'message' => "Plus aucun opérateur disponible : un kit du centre "
                            ."{$centre->code} reste sans opérateur.",
                    ];

                    continue;
                }

                $kit = $kits->shift();

                $this->creerAffectation($vague, $operateur, [
                    'role_terrain' => CategorieVolontaire::Operateur->value,
                    'centre_id' => $centre->id,
                    'kit_id' => $kit?->id,
                    'rang_tirage' => ++$rang,
                ]);

                if (! $kit) {
                    $resultat->anomalies[] = [
                        'type' => 'kit_manquant',
                        'message' => "Aucun kit disponible pour l'opérateur {$operateur->matricule} "
                            ."au centre {$centre->code}.",
                    ];
                }

                $resultat->operateurs[] = [
                    'volontaire_id' => $operateur->id,
                    'matricule' => $operateur->matricule,
                    'centre' => $centre->code,
                    'kit' => $kit?->reference,
                ];
            }
        }
    }

    /**
     * L'ASSISTANT N'EST PAS TIRÉ AU SORT (cadrage, section 7) : il est déjà
     * rattaché à la localité du site, et l'algorithme le rattache au kit de son
     * site. Aucun aléa ici — c'est une jointure, pas un tirage.
     */
    private function rattacherAssistants(
        VagueDeploiement $vague,
        Collection $centres,
        ResultatTirage $resultat
    ): void {
        $localitesParCentre = Site::query()
            ->whereIn('centre_id', $centres->pluck('id'))
            ->get(['centre_id', 'localite_id'])
            ->groupBy('centre_id')
            ->map(fn ($sites) => $sites->pluck('localite_id')->unique()->all());

        foreach ($centres as $centre) {
            $localites = $localitesParCentre[$centre->id] ?? [];

            if ($localites === []) {
                continue;
            }

            $assistants = Volontaire::query()
                ->disponiblesPourTirage()
                ->categorie(CategorieVolontaire::Assistant)
                ->whereIn('localite_id', $localites)
                ->orderBy('id')
                ->get();

            foreach ($assistants as $assistant) {
                $this->creerAffectation($vague, $assistant, [
                    'role_terrain' => CategorieVolontaire::Assistant->value,
                    'centre_id' => $centre->id,
                    'localite_id' => $assistant->localite_id,
                ]);

                $resultat->assistants[] = [
                    'volontaire_id' => $assistant->id,
                    'matricule' => $assistant->matricule,
                    'centre' => $centre->code,
                    'localite_id' => $assistant->localite_id,
                ];
            }
        }

        // Un site sans assistant local ne peut pas fonctionner : c'est une
        // anomalie de couverture, à porter à la proposition.
        $sansAssistant = $centres->count() - collect($resultat->assistants)->pluck('centre')->unique()->count();

        if ($sansAssistant > 0) {
            $resultat->anomalies[] = [
                'type' => 'assistant_manquant',
                'message' => "{$sansAssistant} centres n'ont aucun A-OPK rattaché à leurs localités. "
                    .'Vérifiez le registre avant de valider.',
            ];
        }
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /**
     * Le vivier mobilisable, mélangé par le tirage.
     * Le tri par identifiant précède le mélange : c'est ce qui rend la graine
     * reproductible.
     *
     * @return Volontaire[]
     */
    private function vivier(CategorieVolontaire $categorie, Randomizer $tirage): array
    {
        $disponibles = Volontaire::query()
            ->disponiblesPourTirage()
            ->categorie($categorie)
            ->orderBy('id')
            ->get()
            ->all();

        return $tirage->shuffleArray($disponibles);
    }

    private function centresOuverts(VagueDeploiement $vague): Collection
    {
        return Centre::query()
            ->with('commune:id,province_id,nom')
            ->whereIn('id', DB::table('vague_centres')->where('vague_id', $vague->id)->pluck('centre_id'))
            ->orderBy('id')
            ->get();
    }

    private function creerAffectation(VagueDeploiement $vague, Volontaire $volontaire, array $donnees): void
    {
        DB::table('affectations')->insert([
            'vague_id' => $vague->id,
            'volontaire_id' => $volontaire->id,
            'date_debut' => $vague->date_debut_prevue,
            // PROPOSÉE, et non active : rien n'est notifié, aucun accès n'est
            // ouvert tant que l'administrateur n'a pas validé.
            'statut' => StatutAffectation::Proposee->value,
            'origine' => 'tirage_auto',
            'est_fictif' => false,
            'created_at' => now(),
            'updated_at' => now(),
            ...$donnees,
        ]);
    }

    /** Efface la proposition précédente, sans jamais toucher aux affectations actives. */
    private function effacerPropositionPrecedente(VagueDeploiement $vague): void
    {
        DB::table('affectations')
            ->where('vague_id', $vague->id)
            ->where('statut', StatutAffectation::Proposee->value)
            ->delete();

        DB::table('unites_supervision')->where('vague_id', $vague->id)->delete();
    }

    /** Photo des paramètres au moment du tirage, pour pouvoir le rejouer plus tard. */
    private function contraintesDuTirage(): array
    {
        return [
            'centres_par_superviseur' => Parametre::entier('affectation.centres_par_superviseur', 2),
            'distance_max_centres_km' => Parametre::decimal('affectation.distance_max_centres_km', 25),
            'kits_par_centre_max' => Parametre::entier('affectation.kits_par_centre_max', 2),
        ];
    }

    private function effectifsRestants(): array
    {
        $restants = [];

        foreach (CategorieVolontaire::cases() as $categorie) {
            $restants[$categorie->value] = Volontaire::query()
                ->disponiblesPourTirage()
                ->categorie($categorie)
                ->count();
        }

        return $restants;
    }

    /** @return array<string, string> matricule => ce à quoi il est affecté */
    private function empreinteDesAffectations(VagueDeploiement $vague): array
    {
        return DB::table('affectations')
            ->join('volontaires', 'volontaires.id', '=', 'affectations.volontaire_id')
            ->where('affectations.vague_id', $vague->id)
            ->orderBy('volontaires.matricule')
            ->get(['volontaires.matricule', 'affectations.role_terrain',
                'affectations.centre_id', 'affectations.rang_tirage'])
            ->mapWithKeys(fn ($a) => [
                $a->matricule => "{$a->role_terrain}|{$a->centre_id}|{$a->rang_tirage}",
            ])
            ->all();
    }
}
