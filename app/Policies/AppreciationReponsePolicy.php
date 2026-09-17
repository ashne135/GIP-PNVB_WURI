<?php

namespace App\Policies;

use App\Models\AppreciationReponse;
use App\Models\User;

/**
 * Droit de réponse de l'agent noté (cadrage v2, section 9).
 *
 * « Une observation en réponse, horodatée, NON MODIFIABLE PAR LE SUPÉRIEUR. »
 *
 * Cette Policy est volontairement restrictive : personne ne modifie une
 * réponse, pas même son auteur. Une réponse s'ajoute, elle ne se réécrit pas —
 * sinon elle perdrait sa valeur de trace dans un litige.
 */
class AppreciationReponsePolicy
{
    public function viewAny(User $utilisateur): bool
    {
        return $utilisateur->can('appreciations.consulter_les_miennes')
            || $utilisateur->can('appreciations.consulter_equipe');
    }

    public function view(User $utilisateur, AppreciationReponse $reponse): bool
    {
        if ($this->estSonAuteur($utilisateur, $reponse)) {
            return true;
        }

        return $utilisateur->can('appreciations.consulter_equipe')
            && AppreciationReponse::query()
                ->whereKey($reponse->getKey())
                ->whereHas('suiviAgent.rapport', fn ($q) => $q->perimetre($utilisateur))
                ->exists();
    }

    public function create(User $utilisateur): bool
    {
        return $utilisateur->can('appreciations.repondre');
    }

    /** Ni le supérieur, ni l'agent : une réponse est définitive. */
    public function update(User $utilisateur, AppreciationReponse $reponse): bool
    {
        return false;
    }

    public function delete(User $utilisateur, AppreciationReponse $reponse): bool
    {
        return false;
    }

    private function estSonAuteur(User $utilisateur, AppreciationReponse $reponse): bool
    {
        return $utilisateur->volontaire !== null
            && $reponse->volontaire_id === $utilisateur->volontaire->id;
    }
}
