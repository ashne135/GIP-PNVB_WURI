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
 * Les 12 294 sites d'enregistrement.
 *
 * Double rattachement : la LOCALITÉ donne la population (dénominateur du taux de
 * couverture), le CENTRE donne la chaîne de supervision et de consolidation.
 *
 * Un site n'est PAS une localité : une localité accueille plusieurs sites, au
 * prorata de sa population.
 */
class Site extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $fillable = [
        'centre_id', 'localite_id', 'region_id', 'code', 'nom',
        'latitude', 'longitude', 'rayon_zone_metres', 'ordre_tournee',
        'statut', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'est_fictif' => 'boolean',
        ];
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function tournees(): HasMany
    {
        return $this->hasMany(TourneeSite::class);
    }

    public function feuillesPresence(): HasMany
    {
        return $this->hasMany(FeuillePresence::class);
    }

    /**
     * Les rapports journaliers produits sur ce site : ceux des A-OPK et des
     * opérateurs. Le rapport du superviseur, lui, porte sur le centre.
     */
    public function rapports(): HasMany
    {
        return $this->hasMany(RapportJournalier::class);
    }

    /**
     * Distance en mètres entre une position et le centre de la zone du site.
     * Sert au signal d'arrivée : dans la zone, ou hors zone.
     */
    public function distanceMetresDepuis(float $latitude, float $longitude): ?int
    {
        if (! $this->latitude || ! $this->longitude) {
            return null;
        }

        $rayonTerre = 6371000.0;
        $dLat = deg2rad($latitude - (float) $this->latitude);
        $dLon = deg2rad($longitude - (float) $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $this->latitude)) * cos(deg2rad($latitude))
            * sin($dLon / 2) ** 2;

        return (int) round($rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public function estDansLaZone(float $latitude, float $longitude): bool
    {
        $distance = $this->distanceMetresDepuis($latitude, $longitude);

        return $distance !== null && $distance <= (int) $this->rayon_zone_metres;
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::SaRegion) {
            $region = $utilisateur->idRegionAccessible();

            return $region ? $requete->where('region_id', $region) : $requete->whereRaw('1 = 0');
        }

        return $requete->whereIn('centre_id', $utilisateur->idsCentresAccessibles());
    }
}
