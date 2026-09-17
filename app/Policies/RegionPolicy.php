<?php

namespace App\Policies;

use App\Models\Region;
use App\Models\User;

/**
 * Référentiel territorial : les 12 régions, dénominateur des indicateurs nationaux.
 *
 * Le référentiel se consulte largement mais ne se modifie qu'au niveau
 * national, par import (cadrage, section 2) : chaque référentiel dispose d'un
 * import avec aperçu avant enregistrement, pas d'une édition ligne à ligne.
 * C'est pourquoi create/update/delete sont refusés ici — l'écriture passe par
 * le module d'import, qui porte ses propres contrôles.
 */
class RegionPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'referentiel.consulter';
    }

    protected function modele(): string
    {
        return Region::class;
    }

    public function create(User $utilisateur): bool
    {
        return false;
    }

    public function update(User $utilisateur, Region $region): bool
    {
        return false;
    }

    public function delete(User $utilisateur, Region $region): bool
    {
        return false;
    }
}
