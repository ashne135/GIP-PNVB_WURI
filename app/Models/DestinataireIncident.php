<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Section I : personne ou service informé
 *
 * Nomenclature en table plutôt qu'en JSON : statistiques par valeur, libellés
 * modifiables sans redéploiement, et traductions mooré et dioula prévues dès
 * l'architecture.
 */
class DestinataireIncident extends Model
{
    protected $table = 'destinataires_incident';

    protected $fillable = ['code', 'libelle', 'libelle_moore', 'libelle_dioula', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class);
    }

    public function scopeActifs(Builder $requete): Builder
    {
        return $requete->where('actif', true)->orderBy('ordre');
    }

    /** Libellé dans la langue demandée, avec repli sur le français. */
    public function libelleDans(string $langue): string
    {
        return match ($langue) {
            'moore' => $this->libelle_moore ?: $this->libelle,
            'dioula' => $this->libelle_dioula ?: $this->libelle,
            default => $this->libelle,
        };
    }
}
