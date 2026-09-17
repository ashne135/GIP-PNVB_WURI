<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Section C : nature de l'incident, cases à cocher multiples
 *
 * Nomenclature en table plutôt qu'en JSON : statistiques par valeur, libellés
 * modifiables sans redéploiement, et traductions mooré et dioula prévues dès
 * l'architecture.
 */
class NatureIncident extends Model
{
    protected $table = 'natures_incident';

    protected $fillable = ['code', 'libelle', 'libelle_moore', 'libelle_dioula', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function incidents(): BelongsToMany
    {
        // Le pivot porte un nom court (incident_nature, incident_impact…) :
        // sans le nommer, Eloquent cherche une table qui n'existe pas.
        return $this->belongsToMany(Incident::class, 'incident_nature', 'nature_incident_id', 'incident_id');
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
