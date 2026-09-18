<?php

namespace App\Services\Affectation;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutAffectation;
use App\Enums\StatutVague;
use App\Models\Affectation;
use App\Models\FeuillePresence;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * LE PASSAGE DU KIT SUR UN SITE (cadrage, sections 4, 7 et 8).
 *
 * 12 294 sites pour 966 kits : un kit couvre les sites de SON centre en
 * séquence. C'est ce passage — et lui seul — qui dit sur quel site un opérateur
 * travaille un jour donné, à quel site se rattache la feuille de présence, et
 * quels agents y sont attendus.
 *
 * DEUX ACTES, VOLONTAIREMENT SÉPARÉS :
 *
 *   corriger()             le passage lui-même : site, dates, ordre, statut.
 *                          Le kit et son opérateur se déplacent ensemble.
 *   reaffecterOperateur()  un autre opérateur tient ce passage. Le lieu et les
 *                          dates ne bougent pas.
 *
 * LA RÈGLE QUI PRIME SUR TOUTES LES AUTRES : seule la feuille de présence
 * validée fait foi. Dès qu'un superviseur a validé une feuille sur ce passage,
 * la journée est attestée — la déplacer reviendrait à contredire après coup ce
 * qu'un responsable a signé. Le service refuse, et nomme les dates en cause.
 */
class ServiceTournees
{
    /**
     * PROGRAMMER UN PASSAGE (décision du client, 18/09/2026).
     *
     * Rien ne créait de passage pour une vraie vague : la génération n'existait
     * que dans le jeu de démonstration, si bien qu'après un tirage réel aucun
     * opérateur n'avait de site du jour — et le téléphone ne pouvait rattacher
     * ni signal d'arrivée ni feuille de présence.
     *
     * Le client a tranché pour la SAISIE : on programme les passages un par un,
     * plutôt que de laisser un automate décider où vont les équipes.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function creer(array $donnees, User $auteur): TourneeSite
    {
        $site = Site::query()->findOrFail($donnees['site_id']);
        $affectation = array_key_exists('affectation_operateur_id', $donnees)
            && $donnees['affectation_operateur_id'] !== null
                ? Affectation::query()->findOrFail($donnees['affectation_operateur_id'])
                : null;

        $vague = \App\Models\VagueDeploiement::query()->findOrFail($donnees['vague_id']);

        if ($vague->statut === StatutVague::Cloturee) {
            throw new \DomainException(
                "La vague « {$vague->code} » est clôturée : on n'y programme plus de passage."
            );
        }

        // Le centre n'est pas saisi : il découle du site. Un kit ne couvre que
        // les sites de son propre centre, et laisser choisir les deux ouvrirait
        // la porte à un passage incohérent.
        $centreId = (int) $site->centre_id;

        $ouvertDansLaVague = \Illuminate\Support\Facades\DB::table('vague_centres')
            ->where('vague_id', $vague->id)
            ->where('centre_id', $centreId)
            ->exists();

        if (! $ouvertDansLaVague) {
            throw new \DomainException(
                "Le centre du site {$site->code} n'est pas ouvert dans la vague « {$vague->code} »."
            );
        }

        if (TourneeSite::query()->where('vague_id', $vague->id)->where('site_id', $site->id)->exists()) {
            throw new \DomainException(
                "Le site {$site->code} est déjà couvert par un passage de cette vague."
            );
        }

        $debut = $donnees['date_debut'];
        $fin = $donnees['date_fin'] ?? null;

        if ($fin !== null && Carbon::parse($fin)->lt(Carbon::parse($debut))) {
            throw new \DomainException(
                'La date de fin précède la date de début : le passage se terminerait avant d’avoir commencé.'
            );
        }

        if ($affectation !== null) {
            $this->verifierOperateur($affectation, $centreId);
        }

        $tournee = TourneeSite::query()->create([
            'vague_id' => $vague->id,
            'centre_id' => $centreId,
            'site_id' => $site->id,
            'affectation_operateur_id' => $affectation?->id,
            // Le kit est celui de l'opérateur : il voyage avec lui, jamais seul.
            'kit_id' => $affectation?->kit_id,
            'ordre' => $donnees['ordre']
                ?? ((int) TourneeSite::query()->where('centre_id', $centreId)
                    ->where('vague_id', $vague->id)->max('ordre')) + 1,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'statut' => $donnees['statut'] ?? 'planifiee',
        ]);

        $tournee = $tournee->fresh(['site:id,code,nom', 'affectationOperateur.volontaire:id,user_id,matricule']);

        activity('tournee_site')
            ->causedBy($auteur)
            ->performedOn($tournee)
            ->withProperties([
                'vague' => $vague->code,
                'site' => $tournee->site?->code,
                'operateur' => $tournee->affectationOperateur?->volontaire?->matricule,
                'date_debut' => $debut,
                'date_fin' => $fin,
            ])
            ->log('Passage de kit programmé');

        return $tournee;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function corriger(TourneeSite $tournee, array $donnees, User $auteur): TourneeSite
    {
        $this->refuserSiVagueCloturee($tournee);
        $this->refuserSiAttestee($tournee, 'corriger ce passage');

        $site = array_key_exists('site_id', $donnees) && $donnees['site_id'] !== null
            ? Site::query()->findOrFail($donnees['site_id'])
            : $tournee->site;

        // Un kit couvre les sites de SON centre : le rattacher à un site d'un
        // autre centre casserait la tournée et le parc de kits en même temps.
        if ((int) $site->centre_id !== (int) $tournee->centre_id) {
            throw new \DomainException(
                "Le site {$site->code} n'appartient pas au centre de ce passage : "
                .'un kit ne couvre que les sites de son propre centre.'
            );
        }

        $debut = $donnees['date_debut'] ?? $tournee->date_debut?->toDateString();
        $fin = array_key_exists('date_fin', $donnees)
            ? $donnees['date_fin']
            : $tournee->date_fin?->toDateString();

        if ($fin !== null && Carbon::parse($fin)->lt(Carbon::parse($debut))) {
            throw new \DomainException(
                'La date de fin précède la date de début : le passage se terminerait avant d’avoir commencé.'
            );
        }

        // La base pose unique(vague_id, site_id) : un site n'est couvert qu'une
        // fois par vague. Sans ce contrôle, l'erreur remonterait en violation
        // d'index, illisible pour qui corrige une tournée.
        $dejaCouvert = TourneeSite::query()
            ->where('vague_id', $tournee->vague_id)
            ->where('site_id', $site->id)
            ->whereKeyNot($tournee->getKey())
            ->exists();

        if ($dejaCouvert) {
            throw new \DomainException(
                "Le site {$site->code} est déjà couvert par un autre passage de cette vague."
            );
        }

        $avant = [
            'site' => $tournee->site?->code,
            'date_debut' => $tournee->date_debut?->toDateString(),
            'date_fin' => $tournee->date_fin?->toDateString(),
            'ordre' => $tournee->ordre,
            'statut' => $tournee->statut,
        ];

        $tournee->update([
            'site_id' => $site->id,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'ordre' => $donnees['ordre'] ?? $tournee->ordre,
            'statut' => $donnees['statut'] ?? $tournee->statut,
        ]);

        $tournee = $tournee->fresh(['site:id,code,nom']);

        activity('tournee_site')
            ->causedBy($auteur)
            ->performedOn($tournee)
            ->withProperties([
                'avant' => $avant,
                'apres' => [
                    'site' => $tournee->site?->code,
                    'date_debut' => $tournee->date_debut?->toDateString(),
                    'date_fin' => $tournee->date_fin?->toDateString(),
                    'ordre' => $tournee->ordre,
                    'statut' => $tournee->statut,
                ],
            ])
            ->log('Passage de kit corrigé');

        return $tournee;
    }

    /**
     * Désigner l'opérateur qui tient ce passage.
     *
     * Passer null détache l'opérateur : le passage reste planifié, mais plus
     * personne ne le tient. C'est un état légitime — un kit sans porteur — et
     * il vaut mieux qu'il soit visible que masqué derrière un ancien nom.
     */
    public function reaffecterOperateur(
        TourneeSite $tournee,
        ?Affectation $affectation,
        User $auteur
    ): TourneeSite {
        $this->refuserSiVagueCloturee($tournee);
        $this->refuserSiAttestee($tournee, 'en changer l’opérateur');

        if ($affectation !== null) {
            $this->verifierOperateur($affectation, (int) $tournee->centre_id);
        }

        $ancien = $tournee->affectationOperateur?->volontaire?->matricule;

        $tournee->update(['affectation_operateur_id' => $affectation?->id]);

        $tournee = $tournee->fresh(['affectationOperateur.volontaire:id,user_id,matricule', 'site:id,code,nom']);

        activity('tournee_site')
            ->causedBy($auteur)
            ->performedOn($tournee)
            ->withProperties([
                'avant' => $ancien,
                'apres' => $tournee->affectationOperateur?->volontaire?->matricule,
                'site' => $tournee->site?->code,
            ])
            ->log('Opérateur d’un passage réaffecté');

        return $tournee;
    }

    /**
     * Les jours de ce passage déjà attestés par une feuille validée.
     *
     * « corrigee » compte autant que « validee » : une feuille corrigée par le
     * chef d'antenne reste une feuille signée.
     *
     * @return array<int, string>
     */
    public function journeesAttestees(TourneeSite $tournee): array
    {
        return FeuillePresence::query()
            ->where('tournee_site_id', $tournee->getKey())
            ->whereIn('statut', ['validee', 'corrigee'])
            ->orderBy('date_presence')
            ->pluck('date_presence')
            ->map(fn ($date) => Carbon::parse($date)->format('d/m/Y'))
            ->all();
    }

    /** L'opérateur qui tient un passage : de la bonne catégorie, actif, et du bon centre. */
    private function verifierOperateur(Affectation $affectation, int $centreId): void
    {
        // Les catégories sont étanches : un assistant ne tient jamais un kit.
        if ($affectation->role_terrain !== CategorieVolontaire::Operateur) {
            throw new \DomainException(
                'Seul un opérateur de kit peut tenir un passage : les catégories sont étanches.'
            );
        }

        if ($affectation->statut !== StatutAffectation::Active) {
            throw new \DomainException(
                "L'affectation de cet agent n'est pas active : elle ne peut pas tenir un passage."
            );
        }

        if ((int) $affectation->centre_id !== $centreId) {
            throw new \DomainException(
                "Cet opérateur n'est pas affecté au centre de ce passage."
            );
        }
    }

    private function refuserSiAttestee(TourneeSite $tournee, string $acte): void
    {
        $jours = $this->journeesAttestees($tournee);

        if ($jours === []) {
            return;
        }

        $extrait = implode(', ', array_slice($jours, 0, 4)).(count($jours) > 4 ? '…' : '');

        throw new \DomainException(
            "Ce passage porte déjà des feuilles de présence validées ({$extrait}) : "
            ."seule la feuille validée fait foi, {$acte} la contredirait."
        );
    }

    private function refuserSiVagueCloturee(TourneeSite $tournee): void
    {
        $vague = $tournee->vague;

        if ($vague?->statut === StatutVague::Cloturee) {
            throw new \DomainException(
                "La vague « {$vague->code} » est clôturée : ses passages ne se corrigent plus."
            );
        }
    }
}
