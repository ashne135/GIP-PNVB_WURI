<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Marquage des données générées pour développer et démontrer.
 *
 * Tout enregistrement fictif porte est_fictif = true, pour pouvoir être purgé au
 * chargement du réel. L'import en mode « remplacer » ne supprime que ces lignes.
 */
trait MarquageFictif
{
    public function scopeFictifs(Builder $requete): Builder
    {
        return $requete->where('est_fictif', true);
    }

    public function scopeReels(Builder $requete): Builder
    {
        return $requete->where('est_fictif', false);
    }

    public function estFictif(): bool
    {
        return (bool) $this->est_fictif;
    }
}
