<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le bouton « JE SUIS ARRIVÉ » (cadrage, section 8.1).
 *
 * L'agent SIGNALE sa présence, il ne pointe pas. Ce signal n'a AUCUNE VALEUR
 * ADMINISTRATIVE : il sert à alimenter la carte temps réel du superviseur.
 * Seule la feuille de présence validée fait foi.
 */
class SignalArrivee extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $table = 'signaux_arrivee';

    protected $fillable = [
        'uuid_client', 'volontaire_id', 'affectation_id', 'site_id', 'vague_id',
        'type', 'horodatage_telephone', 'recu_le', 'latitude', 'longitude',
        'precision_gps', 'distance_site_metres', 'dans_zone', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'horodatage_telephone' => 'datetime',
            'recu_le' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'dans_zone' => 'boolean',
            'est_fictif' => 'boolean',
        ];
    }

    public function volontaire(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(Affectation::class);
    }

    /**
     * Couleur du marqueur sur la carte du superviseur :
     * vert dans la zone, orange hors zone, gris si aucun signal.
     */
    public function couleurCarte(): string
    {
        return $this->dans_zone ? 'vert' : 'orange';
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::LuiMeme) {
            $volontaire = $utilisateur->volontaire;

            return $volontaire
                ? $requete->where('volontaire_id', $volontaire->id)
                : $requete->whereRaw('1 = 0');
        }

        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereIn('site_id', $utilisateur->idsSitesAccessibles());
        }

        $region = $utilisateur->idRegionAccessible();

        return $region
            ? $requete->whereHas('site', fn (Builder $q) => $q->where('region_id', $region))
            : $requete->whereRaw('1 = 0');
    }
}
