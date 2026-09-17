<?php

namespace App\Policies;

use App\Models\Kit;
use App\Models\User;

class KitPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'kits.consulter';
    }

    protected function modele(): string
    {
        return Kit::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'kits.gerer');
    }

    public function update(User $utilisateur, Kit $kit): bool
    {
        return $this->autoriser($utilisateur, 'kits.gerer', $kit);
    }

    /** Déclarer un mouvement : remise, transfert, restitution, panne, perte. */
    public function declarerMouvement(User $utilisateur, Kit $kit): bool
    {
        return $this->autoriser($utilisateur, 'kits.declarer_mouvement', $kit);
    }
}
