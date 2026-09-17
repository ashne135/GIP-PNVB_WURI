<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Enums\StatutVague;
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
 * Une région, une période, une équipe, une liste de centres ouverts.
 *
 * La graine du tirage est enregistrée : on doit pouvoir REJOUER et EXPLIQUER une
 * affectation (cadrage, section 7). contraintes_tirage garde une photo des
 * paramètres au moment du tirage, pour que la relecture reste fidèle même si un
 * paramètre change plus tard.
 */
class VagueDeploiement extends Model
{
    use AppliquePerimetre;
    use LogsActivity;
    use MarquageFictif;

    protected $table = 'vagues_deploiement';

    protected $fillable = [
        'code', 'libelle', 'region_id', 'date_debut_prevue', 'date_fin_prevue',
        'date_ouverture_reelle', 'date_cloture_reelle', 'statut', 'graine_tirage',
        'contraintes_tirage', 'objectif_enregistrements_par_kit_jour',
        'cree_par', 'valide_par', 'valide_le', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'objectif_enregistrements_par_kit_jour' => 'integer',
            'statut' => StatutVague::class,
            'date_debut_prevue' => 'date',
            'date_fin_prevue' => 'date',
            'date_ouverture_reelle' => 'datetime',
            'date_cloture_reelle' => 'datetime',
            'valide_le' => 'datetime',
            'contraintes_tirage' => 'array',
            'est_fictif' => 'boolean',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * L'administrateur qui a planifié la vague.
     *
     * Relation manquante jusqu'ici alors que VaguesController::index la
     * chargeait : la liste des vagues répondait une erreur 500. Elle se
     * sérialise en `cree_par` et recouvre alors la clé étrangère du même nom.
     */
    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function centres(): BelongsToMany
    {
        return $this->belongsToMany(Centre::class, 'vague_centres', 'vague_id', 'centre_id')
            ->withPivot(['date_ouverture', 'date_fermeture', 'statut'])
            ->withTimestamps();
    }

    public function vagueCentres(): HasMany
    {
        return $this->hasMany(VagueCentre::class, 'vague_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class, 'vague_id');
    }

    public function unitesSupervision(): HasMany
    {
        return $this->hasMany(UniteSupervision::class, 'vague_id');
    }

    public function tournees(): HasMany
    {
        return $this->hasMany(TourneeSite::class, 'vague_id');
    }

    public function scopeActives(Builder $requete): Builder
    {
        return $requete->where('statut', StatutVague::Active->value);
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

        $volontaire = $utilisateur->volontaire;

        if (! $volontaire) {
            return $requete->whereRaw('1 = 0');
        }

        return $requete->whereHas(
            'affectations',
            fn (Builder $q) => $q->where('volontaire_id', $volontaire->id)
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['statut', 'graine_tirage', 'valide_par', 'valide_le'])
            ->logOnlyDirty()
            ->useLogName('vague');
    }
}
