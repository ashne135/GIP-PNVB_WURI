<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Arrondissement — niveau territorial des communes URBAINES uniquement
 * (cadrage v2, section 2).
 *
 *     COMMUNE (urbaine) > ARRONDISSEMENT > LOCALITÉ
 *
 * Le niveau est FACULTATIF : dans une commune rurale, la localité est rattachée
 * directement à la commune et localites.arrondissement_id reste nul.
 */
class Arrondissement extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $fillable = [
        'commune_id', 'region_id', 'code', 'nom', 'numero',
        'population_hommes', 'population_femmes', 'population_totale', 'est_fictif',
    ];

    protected function casts(): array
    {
        return ['est_fictif' => 'boolean'];
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function localites(): HasMany
    {
        return $this->hasMany(Localite::class);
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereHas(
                'localites.sites',
                fn (Builder $q) => $q->whereIn('centre_id', $utilisateur->idsCentresAccessibles())
            );
        }

        $region = $utilisateur->idRegionAccessible();

        return $region ? $requete->where('region_id', $region) : $requete->whereRaw('1 = 0');
    }
}
