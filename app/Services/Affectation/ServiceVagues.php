<?php

namespace App\Services\Affectation;

use App\Enums\StatutAffectation;
use App\Enums\StatutVague;
use App\Jobs\RecalculerAccesComptesJob;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Kit;
use App\Models\Region;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Comptes\ServiceCycleDeVieCompte;
use App\Services\Kits\ServiceAlertesKits;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'une vague de déploiement (cadrage, sections 4 et 7).
 *
 *   planifier → tirer (proposition) → VALIDER → clôturer
 *
 * LA VALIDATION EST LE POINT DE BASCULE. « Rien n'est écrit ni notifié tant que
 * la proposition n'est pas validée » : jusque-là les affectations existent en
 * statut « proposée », aucun accès n'est ouvert, aucun agent n'est prévenu.
 * C'est la validation qui les rend actives et déclenche l'ouverture des accès.
 *
 * LA CLÔTURE EST UN ÉVÉNEMENT MÉTIER : rapports finalisés, kits restitués ou
 * emportés, accès recalculés, équipe libérée.
 */
class ServiceVagues
{
    public function __construct(
        private readonly ServiceCycleDeVieCompte $cycleDeVie = new ServiceCycleDeVieCompte,
    ) {}

    /** Planifie une vague : une région, une période, une liste de centres. */
    public function planifier(array $donnees, array $idsCentres, User $auteur): VagueDeploiement
    {
        $region = Region::query()->findOrFail($donnees['region_id']);

        return DB::transaction(function () use ($donnees, $idsCentres, $auteur, $region) {
            $vague = VagueDeploiement::query()->create([
                'code' => $donnees['code'] ?? $this->prochainCode($region),
                'libelle' => $donnees['libelle'],
                'region_id' => $region->id,
                'date_debut_prevue' => $donnees['date_debut_prevue'],
                'date_fin_prevue' => $donnees['date_fin_prevue'],
                'objectif_enregistrements_par_kit_jour' => $donnees['objectif_enregistrements_par_kit_jour'] ?? null,
                'statut' => StatutVague::Brouillon->value,
                'cree_par' => $auteur->id,
            ]);

            $this->definirCentres($vague, $idsCentres);

            activity('vague')
                ->causedBy($auteur)
                ->performedOn($vague)
                ->withProperties(['centres' => count($idsCentres)])
                ->log("Vague planifiée sur {$region->nom}");

            return $vague->fresh();
        });
    }

    /** Remplace la liste des centres ouverts, tant que la vague n'est pas active. */
    public function definirCentres(VagueDeploiement $vague, array $idsCentres): void
    {
        if (in_array($vague->statut, [StatutVague::Active, StatutVague::Cloturee], true)) {
            throw new \DomainException(
                "Cette vague est « {$vague->statut->libelle()} » : sa liste de centres ne peut plus changer."
            );
        }

        // Les centres doivent appartenir à la région de la vague : une vague est
        // une région, une période, une équipe (cadrage, section 4).
        $horsRegion = Centre::query()
            ->whereIn('id', $idsCentres)
            ->where('region_id', '!=', $vague->region_id)
            ->pluck('code');

        if ($horsRegion->isNotEmpty()) {
            throw new \DomainException(
                'Ces centres ne sont pas dans la région de la vague : '.$horsRegion->implode(', ').'.'
            );
        }

        DB::table('vague_centres')->where('vague_id', $vague->id)->delete();

        $lignes = collect($idsCentres)->unique()->map(fn ($id) => [
            'vague_id' => $vague->id,
            'centre_id' => $id,
            'date_ouverture' => $vague->date_debut_prevue,
            'statut' => 'planifie',
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($lignes, 500) as $lot) {
            DB::table('vague_centres')->insert($lot);
        }
    }

    /**
     * Ajustement manuel d'une affectation AVANT validation.
     * « L'administrateur peut ajuster manuellement une affectation avant de
     * valider. » Après, c'est un remplacement — un autre acte, avec un motif.
     */
    public function ajusterAffectation(Affectation $affectation, Volontaire $remplacant, User $auteur): Affectation
    {
        if ($affectation->statut !== StatutAffectation::Proposee) {
            throw new \DomainException(
                "Cette affectation est « {$affectation->statut->libelle()} » : "
                .'elle ne peut plus être ajustée. Passez par un remplacement.'
            );
        }

        if ($remplacant->categorie !== $affectation->role_terrain) {
            throw new \DomainException(
                "Les catégories sont étanches : {$remplacant->matricule} ne peut pas tenir "
                ."un rôle de {$affectation->role_terrain->value}."
            );
        }

        $ancien = $affectation->volontaire;

        $affectation->update([
            'volontaire_id' => $remplacant->id,
            'origine' => 'ajustement_manuel',
        ]);

        activity('affectation')
            ->causedBy($auteur)
            ->performedOn($affectation)
            ->withProperties([
                'avant' => $ancien?->matricule,
                'apres' => $remplacant->matricule,
            ])
            ->log('Affectation ajustée manuellement avant validation');

        return $affectation->fresh();
    }

    /**
     * VALIDATION : la proposition devient la réalité.
     *
     * C'est ici, et seulement ici, que les affectations deviennent actives et
     * que les accès s'ouvrent. Le cycle de vie des comptes est appelé pour
     * chaque agent engagé — aucun changement d'accès n'est fait à la main.
     */
    public function valider(VagueDeploiement $vague, User $auteur): VagueDeploiement
    {
        if ($vague->statut !== StatutVague::Proposee) {
            throw new \DomainException(
                "Seule une proposition se valide. Cette vague est « {$vague->statut->libelle()} »."
            );
        }

        $affectations = Affectation::query()
            ->where('vague_id', $vague->id)
            ->where('statut', StatutAffectation::Proposee->value)
            ->get();

        if ($affectations->isEmpty()) {
            throw new \DomainException('Cette proposition ne contient aucune affectation.');
        }

        DB::transaction(function () use ($vague, $affectations, $auteur) {
            Affectation::query()
                ->whereIn('id', $affectations->pluck('id'))
                ->update(['statut' => StatutAffectation::Active->value]);

            DB::table('vague_centres')->where('vague_id', $vague->id)->update([
                'statut' => 'ouvert',
                'updated_at' => now(),
            ]);

            Centre::query()
                ->whereIn('id', DB::table('vague_centres')->where('vague_id', $vague->id)->pluck('centre_id'))
                ->update(['statut' => 'ouvert']);

            // Le kit suit l'agent : la validation acte la détention.
            foreach ($affectations->whereNotNull('kit_id') as $affectation) {
                Kit::query()->where('id', $affectation->kit_id)->update([
                    'volontaire_detenteur_id' => $affectation->volontaire_id,
                    'centre_courant_id' => $affectation->centre_id,
                ]);
            }

            $vague->update([
                'statut' => StatutVague::Active->value,
                'valide_par' => $auteur->id,
                'valide_le' => now(),
                'date_ouverture_reelle' => now(),
            ]);
        });

        // L'ouverture des accès passe par le service dédié, jamais par une
        // écriture directe : chaque changement d'état est journalisé.
        foreach ($affectations as $affectation) {
            $volontaire = Volontaire::query()->with('user')->find($affectation->volontaire_id);

            if ($volontaire) {
                $this->cycleDeVie->ouvrirPourAffectation($volontaire, $auteur);
            }
        }

        activity('vague')
            ->causedBy($auteur)
            ->performedOn($vague)
            ->withProperties(['affectations' => $affectations->count()])
            ->log('Proposition validée : la vague est active');

        return $vague->fresh();
    }

    /**
     * CLÔTURE : événement métier, pas un simple changement de statut.
     * Les accès sont recalculés selon la règle de chaque catégorie — l'opérateur
     * et le superviseur passent DISPONIBLE, l'assistant voit son accès FERMÉ.
     *
     * @return array{affectations: int, kits_non_restitues: int, alertes_kits_publiees: int}
     */
    public function cloturer(VagueDeploiement $vague, User $auteur): array
    {
        if ($vague->statut !== StatutVague::Active) {
            throw new \DomainException(
                "Seule une vague active se clôture. Celle-ci est « {$vague->statut->libelle()} »."
            );
        }

        $idsVolontaires = Affectation::query()
            ->where('vague_id', $vague->id)
            ->where('statut', StatutAffectation::Active->value)
            ->pluck('volontaire_id');

        // Un kit non restitué ni transféré déclenche une alerte (cadrage, 13).
        // Le décompte est pris AVANT la clôture, tant que les affectations sont
        // encore actives ; les alertes nominatives partent après, une fois les
        // affectations terminées — c'est cette date de fin qui les justifie.
        $kitsNonRestitues = Kit::query()
            ->whereIn('volontaire_detenteur_id', $idsVolontaires)
            ->count();

        DB::transaction(function () use ($vague) {
            Affectation::query()
                ->where('vague_id', $vague->id)
                ->where('statut', StatutAffectation::Active->value)
                ->update([
                    'statut' => StatutAffectation::Terminee->value,
                    'date_fin' => now()->toDateString(),
                ]);

            DB::table('vague_centres')->where('vague_id', $vague->id)->update([
                'statut' => 'ferme',
                'date_fermeture' => now()->toDateString(),
                'updated_at' => now(),
            ]);

            $vague->update([
                'statut' => StatutVague::Cloturee->value,
                'date_cloture_reelle' => now(),
            ]);
        });

        // Recalcul des accès : opérateurs et superviseurs en DISPONIBLE,
        // assistants FERMÉS. Le job le fait pour les seuls agents concernés.
        RecalculerAccesComptesJob::dispatchSync($idsVolontaires->all());

        // UNE ALERTE NOMINATIVE PAR KIT NON RENDU. Compter ne suffit pas :
        // « 14 kits non restitués » n'aide personne à aller les chercher. Le
        // délai de grâce vient des paramètres — on ne réclame pas un kit le
        // soir même de la clôture.
        $alertesKits = app(ServiceAlertesKits::class)->alerterNonRestitues($vague);

        activity('vague')
            ->causedBy($auteur)
            ->performedOn($vague)
            ->withProperties([
                'affectations_terminees' => $idsVolontaires->count(),
                'kits_non_restitues' => $kitsNonRestitues,
                'alertes_kits_publiees' => $alertesKits['signales'],
            ])
            ->log('Vague clôturée');

        return [
            'affectations' => $idsVolontaires->count(),
            'kits_non_restitues' => $kitsNonRestitues,
            'alertes_kits_publiees' => $alertesKits['signales'],
        ];
    }

    /** BAN-2026-V1, BAN-2026-V2… — repart du plus grand déjà attribué. */
    private function prochainCode(Region $region): string
    {
        $prefixe = $region->code.'-'.now()->format('Y').'-V';

        $dernier = VagueDeploiement::query()
            ->where('code', 'like', $prefixe.'%')
            ->orderByDesc('code')
            ->value('code');

        $numero = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

        return $prefixe.$numero;
    }
}
