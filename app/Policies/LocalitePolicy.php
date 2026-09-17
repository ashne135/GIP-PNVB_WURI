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
 *
 * DÉROGATION (décision du client, 17/09/2026) : la correction ligne à ligne
 * est désormais permise à l'administration nationale, par le droit
 * referentiel.modifier_territoire, avec aperçu des effets avant enregistrement
 * (ServiceTerritoire). La suppression reste interdite.
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
        return $this->peut($utilisateur, 'referentiel.modifier_territoire');
    }

    public function update(User $utilisateur, Localite $localite): bool
    {
        return $this->autoriser($utilisateur, 'referentiel.modifier_territoire', $localite);
    }

    public function delete(User $utilisateur, Localite $localite): bool
    {
        return false;
    }
}
