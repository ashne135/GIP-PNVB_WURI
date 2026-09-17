<?php

namespace App\Policies;

use App\Models\Arrondissement;
use App\Models\User;

/**
 * Arrondissement — niveau territorial des communes urbaines (cadrage v2).
 * Comme le reste du référentiel, il se consulte largement mais ne se modifie
 * que par import, avec aperçu avant enregistrement.
 */
class ArrondissementPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'referentiel.consulter';
    }

    protected function modele(): string
    {
        return Arrondissement::class;
    }

    public function create(User $utilisateur): bool
    {
        return false;
    }

    public function update(User $utilisateur, Arrondissement $arrondissement): bool
    {
        return false;
    }

    public function delete(User $utilisateur, Arrondissement $arrondissement): bool
    {
        return false;
    }
}
