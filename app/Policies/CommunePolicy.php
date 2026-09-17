<?php

namespace App\Policies;

use App\Models\Commune;
use App\Models\User;

/**
 * Référentiel territorial : les 355 communes, rattachement du centre.
 *
 * Le référentiel se consulte largement mais ne se modifie qu'au niveau
 * national, par import (cadrage, section 2) : chaque référentiel dispose d'un
 * import avec aperçu avant enregistrement, pas d'une édition ligne à ligne.
 * C'est pourquoi create/update/delete sont refusés ici — l'écriture passe par
 * le module d'import, qui porte ses propres contrôles.
 */
class CommunePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'referentiel.consulter';
    }

    protected function modele(): string
    {
        return Commune::class;
    }

    public function create(User $utilisateur): bool
    {
        return false;
    }

    public function update(User $utilisateur, Commune $commune): bool
    {
        return false;
    }

    public function delete(User $utilisateur, Commune $commune): bool
    {
        return false;
    }
}
