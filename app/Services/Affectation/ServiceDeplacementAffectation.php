<?php

namespace App\Services\Affectation;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutAffectation;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\FeuillePresence;
use App\Models\Kit;
use App\Models\TourneeSite;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DÉPLACER UN AGENT DÉJÀ DÉPLOYÉ (cadrage, sections 4, 6, 13 et 15).
 *
 * Le pendant exact de l'ajustement : celui-ci ne vaut qu'AVANT validation,
 * celui-là qu'une fois l'affectation ACTIVE. Les deux ne se recouvrent jamais.
 *
 * SEUL L'OPÉRATEUR SE DÉPLACE, et ce n'est pas une simplification :
 *
 *   l'A-OPK      est recruté localement et rattaché en permanence à sa
 *                localité. Le cadrage interdit de le redéployer.
 *   le SUPERVISEUR ne dépend pas d'un centre mais d'une unité de supervision
 *                qui en couvre deux : le déplacer reviendrait à défaire
 *                l'appariement de ses deux centres, un autre acte.
 *
 * TROIS CONSÉQUENCES SONT TRAITÉES, jamais laissées en suspens :
 *
 *  1. LE KIT SUIT SON PORTEUR. Il appartient à l'agent, pas au site : quand
 *     l'agent change de centre, le kit change avec lui.
 *  2. LES PASSAGES À VENIR de l'ancien centre perdent leur porteur, au lieu de
 *     désigner un agent qui n'y travaille plus.
 *  3. UNE JOURNÉE DÉJÀ ATTESTÉE bloque le déplacement. Seule la feuille
 *     validée fait foi : la contredire après coup est exclu.
 */
class ServiceDeplacementAffectation
{
    public function deplacer(
        Affectation $affectation,
        Centre $destination,
        string $motif,
        User $auteur
    ): Affectation {
        $this->verifier($affectation, $destination);

        $ancien = $affectation->centre;

        return DB::transaction(function () use ($affectation, $destination, $ancien, $motif, $auteur) {
            // Les passages encore à venir ne peuvent plus désigner cet agent :
            // il ne travaillera plus sur les sites de son ancien centre.
            $detaches = TourneeSite::query()
                ->where('affectation_operateur_id', $affectation->id)
                ->where(fn ($q) => $q
                    ->whereNull('date_fin')
                    ->orWhereDate('date_fin', '>=', now()->toDateString()))
                ->update(['affectation_operateur_id' => null]);

            // LE KIT SUIT SON PORTEUR. Le site courant est effacé : l'agent
            // n'est encore sur aucun site de son nouveau centre.
            $kit = Kit::query()
                ->where('volontaire_detenteur_id', $affectation->volontaire_id)
                ->first();

            $kit?->update([
                'centre_courant_id' => $destination->id,
                'site_courant_id' => null,
            ]);

            $affectation->update([
                'centre_id' => $destination->id,
                'origine' => 'ajustement_manuel',
            ]);

            activity('affectation')
                ->causedBy($auteur)
                ->performedOn($affectation)
                ->withProperties([
                    'avant' => $ancien?->code,
                    'apres' => $destination->code,
                    'motif' => $motif,
                    'passages_detaches' => $detaches,
                    'kit' => $kit?->reference,
                ])
                ->log("Agent déplacé vers le centre {$destination->code}");

            return $affectation->fresh([
                'centre:id,code,nom',
                'volontaire:id,user_id,matricule',
                'volontaire.user:id,nom,prenoms',
            ]);
        });
    }

    /**
     * Déplacer L'ÉQUIPE d'un centre.
     *
     * Concrètement : ses OPÉRATEURS actifs. Le décompte des agents restés sur
     * place est rendu à l'appelant pour que l'écran le dise — annoncer « équipe
     * déplacée » en laissant l'A-OPK derrière, sans le signaler, serait
     * mensonger.
     *
     * Tout passe dans UNE transaction : une équipe à moitié déplacée serait
     * pire que pas de déplacement du tout.
     *
     * @return array{deplaces: array<int, string>, assistants_restes: int}
     */
    public function deplacerEquipe(
        Centre $source,
        Centre $destination,
        string $motif,
        User $auteur
    ): array {
        $operateurs = $this->operateursDe($source);

        if ($operateurs->isEmpty()) {
            throw new \DomainException(
                "Aucun opérateur actif n'est affecté au centre {$source->code} : il n'y a pas d'équipe à déplacer."
            );
        }

        return DB::transaction(function () use ($operateurs, $source, $destination, $motif, $auteur) {
            $deplaces = [];

            foreach ($operateurs as $operateur) {
                $this->deplacer($operateur, $destination, $motif, $auteur);
                $deplaces[] = $operateur->volontaire?->matricule;
            }

            return [
                'deplaces' => array_filter($deplaces),
                'assistants_restes' => Affectation::query()
                    ->where('centre_id', $source->id)
                    ->where('statut', StatutAffectation::Active->value)
                    ->where('role_terrain', CategorieVolontaire::Assistant->value)
                    ->count(),
            ];
        });
    }

    /** Les opérateurs actifs d'un centre — ceux qui, eux, se déplacent. */
    public function operateursDe(Centre $centre)
    {
        return Affectation::query()
            ->where('centre_id', $centre->id)
            ->where('statut', StatutAffectation::Active->value)
            ->where('role_terrain', CategorieVolontaire::Operateur->value)
            ->with('volontaire:id,user_id,matricule')
            ->get();
    }

    private function verifier(Affectation $affectation, Centre $destination): void
    {
        if ($affectation->statut !== StatutAffectation::Active) {
            throw new \DomainException(
                "Cette affectation est « {$affectation->statut->libelle()} » : seule une affectation active se "
                .'déplace. Avant validation, ajustez la proposition ; après une fin de mission, passez par un '
                .'remplacement.'
            );
        }

        if ($affectation->role_terrain === CategorieVolontaire::Assistant) {
            throw new \DomainException(
                "L'assistant est recruté localement et rattaché en permanence à sa localité : "
                ."il n'est jamais redéployé."
            );
        }

        if ($affectation->role_terrain === CategorieVolontaire::Superviseur) {
            throw new \DomainException(
                "Un superviseur ne dépend pas d'un centre mais d'une unité de supervision, qui en couvre deux. "
                .'Le déplacer suppose de reconstituer son unité — ce n\'est pas le même acte.'
            );
        }

        if ((int) $affectation->centre_id === (int) $destination->id) {
            throw new \DomainException("Cet agent est déjà affecté au centre {$destination->code}.");
        }

        $dansLaVague = $affectation->vague?->centres()->whereKey($destination->id)->exists();

        if (! $dansLaVague) {
            throw new \DomainException(
                "Le centre {$destination->code} ne fait pas partie de cette vague : "
                ."l'agent s'y retrouverait sans aucun passage de kit."
            );
        }

        $attestees = $this->journeesAttestees($affectation);

        if ($attestees !== []) {
            $extrait = implode(', ', array_slice($attestees, 0, 4)).(count($attestees) > 4 ? '…' : '');

            throw new \DomainException(
                "Des journées de cet agent sont déjà attestées par une feuille validée ({$extrait}) : "
                .'seule la feuille validée fait foi, le déplacer la contredirait.'
            );
        }
    }

    /**
     * Les journées À VENIR de cet agent déjà signées par un superviseur.
     *
     * Le passé n'est pas en cause : les feuilles et rapports déjà produits
     * portent leur propre site et leur propre centre, et gardent leur valeur.
     * C'est d'aujourd'hui que le déplacement décide.
     *
     * @return array<int, string>
     */
    private function journeesAttestees(Affectation $affectation): array
    {
        $tournees = TourneeSite::query()
            ->where('affectation_operateur_id', $affectation->id)
            ->pluck('id');

        if ($tournees->isEmpty()) {
            return [];
        }

        return FeuillePresence::query()
            ->whereIn('tournee_site_id', $tournees)
            ->whereIn('statut', ['validee', 'corrigee'])
            ->whereDate('date_presence', '>=', now()->toDateString())
            ->orderBy('date_presence')
            ->pluck('date_presence')
            ->map(fn ($date) => Carbon::parse($date)->format('d/m/Y'))
            ->all();
    }
}
