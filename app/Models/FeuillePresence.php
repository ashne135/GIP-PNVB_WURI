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
 * La seule pièce qui fait foi (cadrage, section 8.3).
 *
 * UNE feuille par SITE et par JOUR, jamais une par centre : l'index unique
 * (site_id, date_presence) le garantit en base.
 *
 * Une feuille validée n'est plus modifiable par le superviseur. Toute correction
 * passe par le chef d'antenne régional et est journalisée.
 * Le superviseur ne se pointe pas lui-même : sa présence découle de la validation
 * de ses feuilles et des positions relevées à ce moment-là.
 */
class FeuillePresence extends Model
{
    use AppliquePerimetre;
    use LogsActivity;
    use MarquageFictif;

    protected $table = 'feuilles_presence';

    protected $fillable = [
        'uuid_client', 'site_id', 'centre_id', 'vague_id', 'tournee_site_id',
        'date_presence', 'statut', 'superviseur_id', 'valide_le',
        'latitude_superviseur', 'longitude_superviseur', 'distance_site_metres',
        'corrige_par', 'corrige_le', 'motif_correction', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'date_presence' => 'date',
            'valide_le' => 'datetime',
            'corrige_le' => 'datetime',
            'latitude_superviseur' => 'decimal:7',
            'longitude_superviseur' => 'decimal:7',
            'est_fictif' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function vague(): BelongsTo
    {
        return $this->belongsTo(VagueDeploiement::class, 'vague_id');
    }

    public function tournee(): BelongsTo
    {
        return $this->belongsTo(TourneeSite::class, 'tournee_site_id');
    }

    public function superviseur(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'superviseur_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LignePresence::class);
    }

    public function ecarts(): HasMany
    {
        return $this->hasMany(EcartPresence::class);
    }

    public function estValidee(): bool
    {
        return in_array($this->statut, ['validee', 'corrigee'], true);
    }

    /** Le superviseur ne peut plus toucher une feuille validée. */
    public function estModifiableParSuperviseur(): bool
    {
        return $this->statut === 'brouillon';
    }

    public function effectifPresent(): int
    {
        return $this->lignes->where('statut', 'present')->count();
    }

    public function effectifAbsent(): int
    {
        return $this->lignes->whereIn('statut', ['absent', 'absent_justifie'])->count();
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::LuiMeme) {
            $volontaire = $utilisateur->volontaire;

            return $volontaire
                ? $requete->whereHas('lignes', fn (Builder $q) => $q->where('volontaire_id', $volontaire->id))
                : $requete->whereRaw('1 = 0');
        }

        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereIn('centre_id', $utilisateur->idsCentresAccessibles());
        }

        $region = $utilisateur->idRegionAccessible();

        return $region
            ? $requete->whereHas('site', fn (Builder $q) => $q->where('region_id', $region))
            : $requete->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['statut', 'valide_le', 'corrige_par', 'motif_correction'])
            ->logOnlyDirty()
            ->useLogName('feuille_presence');
    }
}
