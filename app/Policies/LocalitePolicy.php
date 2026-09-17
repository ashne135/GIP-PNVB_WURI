<?php

namespace App\Policies;

use App\Models\Localite;
use App\Models\User;

/**
 * Référentiel territorial : les 7 453 localités, qui portent la population cible.
 *
 * Le référentiel se consulte largement mais ne se modifie qu'au niveau
 * national, par import (cadrage, section 2) : chaque référentiel dispose d'un
 * import avec aperçu avant enregistrement, pas d'une édition ligne à ligne.
 * C'est pourquoi create/update/delete sont refusés ici — l'écriture passe par
 * le module d'import, qui porte ses propres contrôles.
 */
class LocalitePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'referentiel.consulter';
    }

    protected function modele(): string
    {
        return Localite::class;
    }

    public function create(User $utilisateur): bool
    {
        return false;
    }

    public function update(User $utilisateur, Localite $localite): bool
    {
        return false;
    }

    public function delete(User $utilisateur, Localite $localite): bool
    {
        return false;
    }
}
