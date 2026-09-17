<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'referentiel.consulter';
    }

    protected function modele(): string
    {
        return Site::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'referentiel.modifier');
    }

    public function update(User $utilisateur, Site $site): bool
    {
        return $this->autoriser($utilisateur, 'referentiel.modifier', $site);
    }

    public function delete(User $utilisateur, Site $site): bool
    {
        return false;
    }
}
