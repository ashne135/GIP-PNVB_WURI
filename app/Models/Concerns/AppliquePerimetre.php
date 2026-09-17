<?php

namespace App\Models\Concerns;

use App\Enums\NiveauPerimetre;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scope de PÉRIMÈTRE, obligatoire sur tout modèle exposé par l'API.
 *
 * Le cadrage (section 5) impose deux mécanismes DISTINCTS et tous les deux
 * obligatoires : le DROIT (spatie/laravel-permission et les Policies) dit ce
 * qu'on peut faire, le PÉRIMÈTRE dit sur quelles données. Masquer un bouton
 * dans React ne sécurise rien.
 *
 * Sans utilisateur, le scope ne renvoie RIEN plutôt que tout : une requête mal
 * câblée échoue de façon visible au lieu de fuiter la base entière.
 */
trait AppliquePerimetre
{
    public function scopePerimetre(Builder $requete, ?User $utilisateur = null): Builder
    {
        $utilisateur ??= auth()->user();

        if (! $utilisateur instanceof User) {
            return $requete->whereRaw('1 = 0');
        }

        $niveau = NiveauPerimetre::pour($utilisateur);

        if ($niveau === NiveauPerimetre::National) {
            return $requete;
        }

        return $this->restreindrePerimetre($requete, $utilisateur, $niveau);
    }

    /**
     * Restriction propre à chaque modèle, pour les trois niveaux autres que
     * national. Chaque modèle exposé DOIT l'implémenter : c'est volontairement
     * une méthode abstraite, pour qu'un oubli casse au chargement de la classe
     * et non silencieusement à l'exécution.
     */
    abstract protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder;
}
