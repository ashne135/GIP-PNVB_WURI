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
 * Unité de supervision et de consolidation des rapports.
 *
 * Rattaché à la COMMUNE : 966 centres pour 7 453 localités, et la codification
 * <RÉGION>-<COMMUNE>-C<nnn> ne porte aucune localité.
 * Un centre dispose d’un ou plusieurs kits ; un superviseur couvre 2 centres.
 */
class Centre extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $fillable = [
        'commune_id', 'region_id', 'code', 'nom', 'nombre_kits',
        'est_permanent', 'latitude', 'longitude', 'statut', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'est_permanent' => 'boolean',
            'est_fictif' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class)->orderBy('ordre_tournee');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class);
    }

    public function tournees(): HasMany
    {
        return $this->hasMany(TourneeSite::class)->orderBy('ordre');
    }

    /**
     * Tous les rapports journaliers rattachés au centre, les trois niveaux
     * confondus (cadrage v2, section 9). Filtrer par `type` pour n'obtenir que
     * ceux du superviseur.
     */
    public function rapports(): HasMany
    {
        return $this->hasMany(RapportJournalier::class);
    }

    /**
     * Distance à vol d'oiseau vers un autre centre, en kilomètres.
     * Sert au contrôle de proximité des 2 centres d'un même superviseur :
     * un tirage purement aléatoire donnerait deux centres distants de 200 km.
     */
    public function distanceKmVers(self $autre): ?float
    {
        if (! $this->latitude || ! $this->longitude || ! $autre->latitude || ! $autre->longitude) {
            return null;
        }

        $rayonTerre = 6371.0;
        $dLat = deg2rad((float) $autre->latitude - (float) $this->latitude);
        $dLon = deg2rad((float) $autre->longitude - (float) $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $this->latitude)) * cos(deg2rad((float) $autre->latitude))
            * sin($dLon / 2) ** 2;

        return round($rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
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

        return $requete->whereIn('id', $utilisateur->idsCentresAccessibles());
    }
}
