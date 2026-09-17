<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 1 superviseur, 2 centres, 2 à 4 kits.
 *
 * Les 2 centres d'un même superviseur doivent être géographiquement proches :
 * même commune si possible, sinon distance maximale paramétrable. Quand la
 * contrainte n'est pas satisfaite, contrainte_respectee passe à faux et la
 * proposition l'affiche avant validation — elle n'est pas écartée en silence.
 */
class UniteSupervision extends Model
{
    protected $table = 'unites_supervision';

    protected $fillable = [
        'vague_id', 'volontaire_superviseur_id', 'centre_principal_id',
        'centre_secondaire_id', 'distance_km', 'meme_commune', 'contrainte_respectee',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
            'meme_commune' => 'boolean',
            'contrainte_respectee' => 'boolean',
        ];
    }

    public function vague(): BelongsTo
    {
        return $this->belongsTo(VagueDeploiement::class, 'vague_id');
    }

    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'volontaire_superviseur_id');
    }

    public function centrePrincipal(): BelongsTo
    {
        return $this->belongsTo(Centre::class, 'centre_principal_id');
    }

    public function centreSecondaire(): BelongsTo
    {
        return $this->belongsTo(Centre::class, 'centre_secondaire_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class, 'unite_supervision_id');
    }

    /** Les identifiants des centres couverts, dans l'ordre. */
    public function idsCentres(): array
    {
        return array_values(array_filter([$this->centre_principal_id, $this->centre_secondaire_id]));
    }
}
