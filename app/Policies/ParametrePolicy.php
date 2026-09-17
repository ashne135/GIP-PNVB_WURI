<?php

namespace App\Policies;

use App\Models\Parametre;
use App\Models\User;

/**
 * Paramètres du dispositif.
 *
 * SÉPARATION DES POUVOIRS (cadrage, section 5, acteur 6) : l'administrateur
 * national CONSULTE les paramètres, seul le super administrateur (DSI) les
 * MODIFIE. Les seuils du dispositif — nombre de kits, délai d'escalade,
 * distance maximale — ne relèvent pas de l'exploitation courante.
 *
 * Ce modèle n'a pas de scope de périmètre : les paramètres sont nationaux par
 * nature. La Policy ne vérifie donc que le droit, et le dit explicitement.
 */
class ParametrePolicy
{
    public function viewAny(User $utilisateur): bool
    {
        return $utilisateur->can('parametres.consulter');
    }

    public function view(User $utilisateur, Parametre $parametre): bool
    {
        return $utilisateur->can('parametres.consulter');
    }

    public function update(User $utilisateur, Parametre $parametre): bool
    {
        return $utilisateur->can('parametres.modifier');
    }

    public function create(User $utilisateur): bool
    {
        return false;
    }

    public function delete(User $utilisateur, Parametre $parametre): bool
    {
        return false;
    }
}
