<?php

namespace App\Policies;

use App\Models\Province;
use App\Models\User;

/**
 * Référentiel territorial : les 34 provinces, niveau de regroupement.
 *
 * Le référentiel se consulte largement mais ne se modifie qu'au niveau
 * national, par import (cadrage, section 2) : chaque référentiel dispose d'un
 * import avec aperçu avant enregistrement, pas d'une édition ligne à ligne.
 * C'est pourquoi create/update/delete sont refusés ici — l'écriture passe par
 * le module d'import, qui porte ses propres contrôles.
 */
class ProvincePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'referentiel.consulter';
    }

    protected function modele(): string
    {
        return Province::class;
    }

    public function create(User $utilisateur): bool
    {
        return false;
    }

    public function update(User $utilisateur, Province $province): bool
    {
        return false;
    }

    public function delete(User $utilisateur, Province $province): bool
    {
        return false;
    }
}
