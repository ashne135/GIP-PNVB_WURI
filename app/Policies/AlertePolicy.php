<?php

namespace App\Policies;

use App\Models\Alerte;
use App\Models\User;

class AlertePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'alertes.consulter';
    }

    protected function modele(): string
    {
        return Alerte::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'alertes.publier');
    }
}
