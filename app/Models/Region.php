<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Les 12 régions. Le nombre de sites alloués vient du plan projet et sert de
 * total de contrôle à la répartition des sites entre les localités.
 */
class Region extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $fillable = [
        'code', 'nom', 'population_hommes', 'population_femmes',
        'population_totale', 'nombre_sites_alloues', 'contour_geojson', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'contour_geojson' => 'array',
            'est_fictif' => 'boolean',
        ];
    }

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class);
    }

    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class);
    }

    public function localites(): HasMany
    {
        return $this->hasMany(Localite::class);
    }

    public function centres(): HasMany
    {
        return $this->hasMany(Centre::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function vagues(): HasMany
    {
        return $this->hasMany(VagueDeploiement::class);
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        $region = $utilisateur->idRegionAccessible();

        return $region ? $requete->where('id', $region) : $requete->whereRaw('1 = 0');
    }
}
