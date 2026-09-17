<?php

namespace App\Policies;

use App\Models\Import;
use App\Models\User;

/**
 * Imports de référentiels.
 *
 * L'import des retenus et des réservistes appartient à l'ADMINISTRATEUR NATIONAL
 * (cadrage, section 5, acteur 5). Les imports sont nationaux par nature : ce
 * modèle n'a donc pas de scope de périmètre, et la Policy ne vérifie que le
 * droit — dit explicitement plutôt que laissé implicite.
 */
class ImportPolicy
{
    public function viewAny(User $utilisateur): bool
    {
        return $utilisateur->can('volontaires.importer')
            || $utilisateur->can('referentiel.importer');
    }

    public function view(User $utilisateur, Import $import): bool
    {
        return $this->viewAny($utilisateur);
    }

    public function create(User $utilisateur): bool
    {
        return $utilisateur->can('volontaires.importer');
    }

    /** Confirmer, c'est écrire dans le registre : même droit que créer. */
    public function confirmer(User $utilisateur, Import $import): bool
    {
        return $utilisateur->can('volontaires.importer');
    }

    public function delete(User $utilisateur, Import $import): bool
    {
        return $utilisateur->can('volontaires.importer') && $import->statut !== 'applique';
    }
}
