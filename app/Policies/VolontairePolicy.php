<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Volontaire;

class VolontairePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'volontaires.consulter';
    }

    protected function modele(): string
    {
        return Volontaire::class;
    }

    public function update(User $utilisateur, Volontaire $volontaire): bool
    {
        return $this->autoriser($utilisateur, 'volontaires.modifier', $volontaire);
    }

    /**
     * LA CATÉGORIE NE CHANGE JAMAIS (cadrage, sections 3 et 15) : un assistant
     * ne peut pas être promu opérateur. Aucun rôle, pas même le super
     * administrateur, ne porte cette capacité — elle n'existe pas.
     *
     * Le modèle Volontaire lève déjà une exception si la colonne est modifiée ;
     * cette méthode rend le refus explicite au niveau de l'autorisation, pour
     * qu'un développeur qui cherche « qui peut changer la catégorie ? » trouve
     * la réponse ici.
     */
    public function changerCategorie(User $utilisateur, Volontaire $volontaire): bool
    {
        return false;
    }

    /** Mobiliser un réserviste en remplacement. */
    public function remplacer(User $utilisateur, Volontaire $volontaire): bool
    {
        return $this->autoriser($utilisateur, 'remplacements.decider', $volontaire);
    }
}
