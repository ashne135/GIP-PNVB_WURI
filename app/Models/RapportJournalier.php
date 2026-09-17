<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Enums\StatutRapport;
use App\Enums\TypeRapport;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Rapport journalier — table mère des TROIS niveaux (cadrage v2, section 9).
 *
 *     A-OPK  ──signe──►  visa OPK
 *     OPK    ──signe──►  visa SUPERVISEUR DE CENTRE
 *     SUPERVISEUR ──signe──►  visa CONTRÔLEUR TERRAIN / CHEF ARV
 *
 * Le bloc d'identification — région, province, commune, site, matricule,
 * supérieur — est PRÉ-REMPLI depuis l'affectation en cours. L'agent ne saisit
 * aucun élément d'identification : il les vérifie.
 */
class RapportJournalier extends Model
{
    use AppliquePerimetre;
    use LogsActivity;
    use MarquageFictif;

    protected $table = 'rapports_journaliers';

    protected $fillable = [
        'uuid_client', 'type', 'date_rapport', 'auteur_volontaire_id', 'affectation_id',
        'vague_id', 'site_id', 'centre_id', 'region_id', 'tournee_site_id',
        'superieur_volontaire_id', 'superieur_user_id', 'carv_rattachement',
        'statut', 'heure_arrivee', 'heure_depart', 'horodatage_telephone',
        'soumis_le', 'vise_le', 'motif_rejet', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeRapport::class,
            'statut' => StatutRapport::class,
            'date_rapport' => 'date',
            'horodatage_telephone' => 'datetime',
            'soumis_le' => 'datetime',
            'vise_le' => 'datetime',
            'est_fictif' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------
    // Bloc d'identification
    // ------------------------------------------------------------------

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'auteur_volontaire_id');
    }

    public function superieur(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'superieur_volontaire_id');
    }

    public function superieurUtilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superieur_user_id');
    }

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(Affectation::class);
    }

    public function vague(): BelongsTo
    {
        return $this->belongsTo(VagueDeploiement::class, 'vague_id');
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

    // ------------------------------------------------------------------
    // Contenu, selon le niveau
    // ------------------------------------------------------------------

    public function activitesAopk(): HasOne
    {
        return $this->hasOne(RapportAopkActivites::class, 'rapport_id');
    }

    public function productionOpk(): HasOne
    {
        return $this->hasOne(RapportOpkProduction::class, 'rapport_id');
    }

    public function evolution(): HasOne
    {
        return $this->hasOne(RapportSupEvolution::class, 'rapport_id');
    }

    public function qualite(): HasOne
    {
        return $this->hasOne(RapportSupQualite::class, 'rapport_id');
    }

    public function logistique(): HasMany
    {
        return $this->hasMany(RapportSupLogistique::class, 'rapport_id')->orderBy('ordre');
    }

    // ------------------------------------------------------------------
    // Blocs libres, communs aux trois niveaux
    // ------------------------------------------------------------------

    public function difficultes(): HasMany
    {
        return $this->hasMany(RapportDifficulte::class, 'rapport_id')->orderBy('ordre');
    }

    public function pointsAmelioration(): HasMany
    {
        return $this->hasMany(RapportPointAmelioration::class, 'rapport_id')->orderBy('ordre');
    }

    public function suiviAgents(): HasMany
    {
        return $this->hasMany(RapportSuiviAgent::class, 'rapport_id');
    }

    public function visas(): HasMany
    {
        return $this->hasMany(RapportVisa::class, 'rapport_id')->orderBy('effectue_le');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(RapportCorrection::class, 'rapport_id');
    }

    // ------------------------------------------------------------------
    // Cycle de vie
    // ------------------------------------------------------------------

    /** « Un rapport visé n'est plus modifiable par son auteur. » */
    public function estModifiableParAuteur(): bool
    {
        return $this->statut->modifiableParAuteur();
    }

    public function attendUnVisa(): bool
    {
        return $this->statut->attendUnVisa();
    }

    /** Seuls les rapports VISÉS alimentent le pré-remplissage du niveau supérieur. */
    public function scopeVises(Builder $requete): Builder
    {
        return $requete->whereIn('statut', [StatutRapport::Vise->value, StatutRapport::Clos->value]);
    }

    public function scopeDuType(Builder $requete, TypeRapport $type): Builder
    {
        return $requete->where('type', $type->value);
    }

    public function scopeDuJour(Builder $requete, string $date): Builder
    {
        return $requete->whereDate('date_rapport', $date);
    }

    /** Les rapports que cet utilisateur doit viser. */
    public function scopeAViserPar(Builder $requete, User $utilisateur): Builder
    {
        $volontaire = $utilisateur->volontaire;

        return $requete
            ->where('statut', StatutRapport::Soumis->value)
            ->where(function (Builder $q) use ($utilisateur, $volontaire) {
                $q->where('superieur_user_id', $utilisateur->id);

                if ($volontaire) {
                    $q->orWhere('superieur_volontaire_id', $volontaire->id);
                }
            });
    }

    // ------------------------------------------------------------------
    // Périmètre
    // ------------------------------------------------------------------

    /**
     * Au niveau « lui-même », un agent voit ses propres rapports ET CEUX QU'IL
     * DOIT VISER : le cadrage donne à l'opérateur un périmètre qui inclut
     * « les A-OPK rattachés à son kit » (section 5, acteur 2). Sans cela, il ne
     * pourrait pas viser les rapports dont il est responsable.
     */
    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::LuiMeme) {
            $volontaire = $utilisateur->volontaire;

            if (! $volontaire) {
                return $requete->whereRaw('1 = 0');
            }

            return $requete->where(function (Builder $q) use ($volontaire, $utilisateur) {
                $q->where('auteur_volontaire_id', $volontaire->id)
                    ->orWhere('superieur_volontaire_id', $volontaire->id)
                    ->orWhere('superieur_user_id', $utilisateur->id);
            });
        }

        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereIn('centre_id', $utilisateur->idsCentresAccessibles());
        }

        $region = $utilisateur->idRegionAccessible();

        return $region ? $requete->where('region_id', $region) : $requete->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['statut', 'soumis_le', 'vise_le', 'motif_rejet'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('rapport');
    }
}
