<?php

namespace App\Policies;

use App\Models\Centre;
use App\Models\User;

class CentrePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'referentiel.consulter';
    }

    protected function modele(): string
    {
        return Centre::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'referentiel.modifier');
    }

    public function update(User $utilisateur, Centre $centre): bool
    {
        return $this->autoriser($utilisateur, 'referentiel.modifier', $centre);
    }

    /**
     * Un centre ne se supprime pas : il porte l'historique des rapports, des
     * présences et des affectations. Il se ferme (statut), il ne disparaît pas.
     */
    public function delete(User $utilisateur, Centre $centre): bool
    {
        return false;
    }
}
