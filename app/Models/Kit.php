<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un kit d'enregistrement.
 *
 * LE KIT EST RATTACHÉ À UN AGENT, PAS À UN SITE : il suit la personne. Lors d'un
 * redéploiement d'une région à une autre, l'opérateur emporte son kit
 * (cadrage, sections 13 et 15).
 */
class Kit extends Model
{
    use AppliquePerimetre;
    use LogsActivity;
    use MarquageFictif;

    protected $fillable = [
        'reference', 'composition', 'etat', 'volontaire_detenteur_id',
        'centre_courant_id', 'site_courant_id', 'est_permanent_zone_defis', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'composition' => 'array',
            'est_permanent_zone_defis' => 'boolean',
            'est_fictif' => 'boolean',
        ];
    }

    public function detenteur(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'volontaire_detenteur_id');
    }

    public function centreCourant(): BelongsTo
    {
        return $this->belongsTo(Centre::class, 'centre_courant_id');
    }

    public function siteCourant(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_courant_id');
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(KitMouvement::class)->orderByDesc('effectue_le');
    }

    public function scopeDisponibles(Builder $requete): Builder
    {
        return $requete->where('etat', 'fonctionnel')->whereNull('volontaire_detenteur_id');
    }

    /** Le kit est-il soldé, c'est-à-dire restitué, transféré ou déclaré perdu ? */
    public function estSolde(): bool
    {
        return $this->volontaire_detenteur_id === null
            || in_array($this->etat, ['perdu', 'vole', 'reforme'], true);
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::LuiMeme) {
            $volontaire = $utilisateur->volontaire;

            return $volontaire
                ? $requete->where('volontaire_detenteur_id', $volontaire->id)
                : $requete->whereRaw('1 = 0');
        }

        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereIn('centre_courant_id', $utilisateur->idsCentresAccessibles());
        }

        $region = $utilisateur->idRegionAccessible();

        return $region
            ? $requete->whereHas('centreCourant', fn (Builder $q) => $q->where('region_id', $region))
            : $requete->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['etat', 'volontaire_detenteur_id', 'centre_courant_id', 'site_courant_id'])
            ->logOnlyDirty()
            ->useLogName('kit');
    }
}
