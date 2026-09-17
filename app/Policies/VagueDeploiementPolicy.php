<?php

namespace App\Policies;

use App\Enums\StatutVague;
use App\Models\User;
use App\Models\VagueDeploiement;

class VagueDeploiementPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'vagues.consulter';
    }

    protected function modele(): string
    {
        return VagueDeploiement::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'vagues.planifier');
    }

    /** Une vague active ou clôturée ne se replanifie pas. */
    public function update(User $utilisateur, VagueDeploiement $vague): bool
    {
        return $this->autoriser($utilisateur, 'vagues.planifier', $vague)
            && in_array($vague->statut, [StatutVague::Brouillon, StatutVague::Proposee], true);
    }

    /** Déclencher le tirage : uniquement sur une vague pas encore validée. */
    public function tirer(User $utilisateur, VagueDeploiement $vague): bool
    {
        return $this->autoriser($utilisateur, 'vagues.tirer', $vague)
            && in_array($vague->statut, [StatutVague::Brouillon, StatutVague::Proposee], true);
    }

    /**
     * Valider la proposition : rien n'est écrit ni notifié tant que la
     * proposition n'est pas validée (cadrage, section 7).
     */
    public function valider(User $utilisateur, VagueDeploiement $vague): bool
    {
        return $this->autoriser($utilisateur, 'vagues.valider', $vague)
            && $vague->statut === StatutVague::Proposee;
    }

    public function cloturer(User $utilisateur, VagueDeploiement $vague): bool
    {
        return $this->autoriser($utilisateur, 'vagues.cloturer', $vague)
            && $vague->statut === StatutVague::Active;
    }
}
