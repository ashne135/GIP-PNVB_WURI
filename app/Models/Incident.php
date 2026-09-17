<?php

namespace App\Models;

use App\Enums\GraviteIncident;
use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Fiche d'incident, canevas client repris intégralement (sections A à J).
 *
 * Aucun champ n'a été ajouté au canevas fourni. Le niveau de gravité pilote la
 * matrice de notification et le délai d'escalade, tous deux paramétrables.
 */
class Incident extends Model
{
    use AppliquePerimetre;
    use LogsActivity;
    use MarquageFictif;

    protected $fillable = [
        'uuid_client', 'numero', 'declarant_user_id', 'declarant_telephone', 'declare_le',
        'canal', 'site_id', 'centre_id', 'localite_id', 'commune_id', 'province_id', 'region_id',
        'latitude_site', 'longitude_site', 'latitude_declarant', 'longitude_declarant',
        'deja_signale', 'type_lieu', 'lieu_precision', 'encore_sur_les_lieux',
        'survenu_le', 'recit', 'personnes_concernees', 'toujours_en_cours', 'danger_immediat',
        'nb_personnes_affectees', 'gravite', 'preuves', 'mesures_precisions',
        'statut', 'responsable_traitement_user_id', 'pris_en_charge_le', 'mesures_correctives',
        'resolu_le', 'rapport_cloture', 'echeance_escalade', 'niveau_escalade',
        'derniere_escalade_le', 'horodatage_telephone', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'gravite' => GraviteIncident::class,
            'preuves' => 'array',
            'declare_le' => 'datetime',
            'survenu_le' => 'datetime',
            'pris_en_charge_le' => 'datetime',
            'resolu_le' => 'datetime',
            'echeance_escalade' => 'datetime',
            'derniere_escalade_le' => 'datetime',
            'horodatage_telephone' => 'datetime',
            'deja_signale' => 'boolean',
            'encore_sur_les_lieux' => 'boolean',
            'danger_immediat' => 'boolean',
            'latitude_site' => 'decimal:7',
            'longitude_site' => 'decimal:7',
            'latitude_declarant' => 'decimal:7',
            'longitude_declarant' => 'decimal:7',
            'est_fictif' => 'boolean',
        ];
    }

    // ---------------- Relations ----------------

    public function declarant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declarant_user_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_traitement_user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function natures(): BelongsToMany
    {
        return $this->belongsToMany(NatureIncident::class, 'incident_nature', 'incident_id', 'nature_incident_id');
    }

    public function impacts(): BelongsToMany
    {
        return $this->belongsToMany(ImpactIncident::class, 'incident_impact', 'incident_id', 'impact_incident_id');
    }

    public function mesures(): BelongsToMany
    {
        return $this->belongsToMany(MesureIncident::class, 'incident_mesure', 'incident_id', 'mesure_incident_id');
    }

    public function personnesInformees(): BelongsToMany
    {
        return $this->belongsToMany(
            DestinataireIncident::class,
            'incident_destinataire',
            'incident_id',
            'destinataire_incident_id'
        );
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class)->orderBy('effectue_le');
    }

    public function alertes(): HasMany
    {
        return $this->hasMany(Alerte::class);
    }

    /** Les photos prises sur place, arrivées après la fiche. */
    public function piecesJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class)->orderBy('deposee_le');
    }

    // ---------------- Escalade ----------------

    public function estPrisEnCharge(): bool
    {
        return $this->statut !== 'nouveau';
    }

    public function estEnRetardDePriseEnCharge(): bool
    {
        return ! $this->estPrisEnCharge()
            && $this->echeance_escalade !== null
            && $this->echeance_escalade->isPast();
    }

    public function scopeAEscalader(Builder $requete): Builder
    {
        return $requete->where('statut', 'nouveau')
            ->whereNotNull('echeance_escalade')
            ->where('echeance_escalade', '<=', now());
    }

    public function scopeOuverts(Builder $requete): Builder
    {
        return $requete->whereNotIn('statut', ['resolu', 'cloture']);
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::LuiMeme) {
            return $requete->where('declarant_user_id', $utilisateur->id);
        }

        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->where(function (Builder $q) use ($utilisateur) {
                $q->whereIn('centre_id', $utilisateur->idsCentresAccessibles())
                    ->orWhere('declarant_user_id', $utilisateur->id);
            });
        }

        $region = $utilisateur->idRegionAccessible();

        return $region ? $requete->where('region_id', $region) : $requete->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['statut', 'gravite', 'responsable_traitement_user_id', 'niveau_escalade'])
            ->logOnlyDirty()
            ->useLogName('incident');
    }
}
