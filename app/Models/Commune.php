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
 * Les 355 communes. C'est ici, et non à la localité, que le CENTRE est rattaché.
 *
 * population_localites est la somme recalculée des localités : elle rend visible
 * l'écart d'agrégation du fichier source au lieu de le laisser se propager
 * silencieusement dans le taux de couverture.
 */
class Commune extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $fillable = [
        'province_id', 'region_id', 'code', 'nom', 'type',
        'population_hommes', 'population_femmes', 'population_totale',
        'population_localites', 'est_zone_defis_securitaires', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'est_zone_defis_securitaires' => 'boolean',
            'est_fictif' => 'boolean',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function localites(): HasMany
    {
        return $this->hasMany(Localite::class);
    }

    public function centres(): HasMany
    {
        return $this->hasMany(Centre::class);
    }

    /** Écart entre la population déclarée et la somme des localités. */
    public function ecartPopulation(): int
    {
        return (int) $this->population_localites - (int) $this->population_totale;
    }

    public function scopeUrbaines(Builder $requete): Builder
    {
        return $requete->where('type', 'urbaine');
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereHas(
                'centres',
                fn (Builder $q) => $q->whereIn('id', $utilisateur->idsCentresAccessibles())
            );
        }

        $region = $utilisateur->idRegionAccessible();

        return $region ? $requete->where('region_id', $region) : $requete->whereRaw('1 = 0');
    }
}
