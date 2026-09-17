<?php

namespace App\Models;

use App\Enums\EtatRemise;
use App\Enums\NiveauPerimetre;
use App\Enums\RolePnvb;
use App\Enums\StatutCompte;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * Compte de connexion.
 *
 * L'identifiant de connexion est le NUMÉRO DE TÉLÉPHONE (cadrage, section 6).
 * Le courriel est facultatif et n'est jamais bloquant.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use LogsActivity;
    use MarquageFictif;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'telephone', 'email', 'nom', 'prenoms', 'password',
        'statut_compte', 'doit_changer_mot_de_passe', 'region_id',
        'etat_remise', 'est_fictif',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'statut_compte' => StatutCompte::class,
            'etat_remise' => EtatRemise::class,
            'doit_changer_mot_de_passe' => 'boolean',
            'est_fictif' => 'boolean',
            'mot_de_passe_change_le' => 'datetime',
            'premiere_connexion_le' => 'datetime',
            'derniere_connexion_le' => 'datetime',
            // Fermeture de l'accès : borne des actions encore acceptées en rattrapage.
            'acces_ferme_le' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function volontaire(): HasOne
    {
        return $this->hasOne(Volontaire::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function remisesIdentifiants(): HasMany
    {
        return $this->hasMany(RemiseIdentifiants::class)->orderBy('rang');
    }

    public function consentements(): HasMany
    {
        return $this->hasMany(Consentement::class);
    }

    // ------------------------------------------------------------------
    // Identité et affichage
    // ------------------------------------------------------------------

    public function nomComplet(): string
    {
        return trim("{$this->prenoms} {$this->nom}");
    }

    /**
     * L'IDENTIFIANT DE CONNEXION est le numéro de téléphone (cadrage, section 6) :
     * c'est ce que l'agent saisit, et c'est la colonne sur laquelle porte
     * Auth::attempt() dans le contrôleur d'authentification.
     *
     * La clé d'identification technique reste `id` (comportement Laravel par
     * défaut, volontairement non redéfini) : Sanctum rattache ses jetons au
     * modèle par sa CLÉ PRIMAIRE (personal_access_tokens.tokenable_id). Aligner
     * getAuthIdentifierName() sur `telephone` désaligne ces deux notions et
     * casse la résolution du porteur de jeton.
     */
    public function identifiantDeConnexion(): string
    {
        return $this->telephone;
    }

    /**
     * La charte du volontaire est-elle acceptée pour la version en vigueur ?
     *
     * Le cadrage (section 8.4) conditionne à cette acceptation la collecte des
     * relevés de position : sans consentement tracé pour la version COURANTE,
     * aucun relevé n'est collecté. Une nouvelle version de la charte redemande
     * donc l'acceptation.
     */
    public function aAccepteLaCharte(?string $version = null): bool
    {
        $version ??= (string) Parametre::valeur('comptes.charte_version_courante', '2026.1');

        return $this->consentements()
            ->where('version_charte', $version)
            ->exists();
    }

    // ------------------------------------------------------------------
    // Périmètre
    // ------------------------------------------------------------------

    public function niveauPerimetre(): NiveauPerimetre
    {
        return NiveauPerimetre::pour($this);
    }

    public function estLectureSeule(): bool
    {
        return $this->hasRole(RolePnvb::Observateur->value);
    }

    /**
     * Identifiants des centres accessibles.
     *
     * Superviseur : les centres de ses unités de supervision en cours.
     * Opérateur et assistant : le centre de leur affectation en cours.
     */
    public function idsCentresAccessibles(): array
    {
        $volontaire = $this->volontaire;

        if (! $volontaire) {
            return [];
        }

        $centres = Affectation::query()
            ->where('volontaire_id', $volontaire->id)
            ->whereIn('statut', ['proposee', 'active'])
            ->pluck('centre_id')
            ->filter()
            ->all();

        $centresSupervises = UniteSupervision::query()
            ->where('volontaire_superviseur_id', $volontaire->id)
            ->get(['centre_principal_id', 'centre_secondaire_id'])
            ->flatMap(fn ($unite) => [$unite->centre_principal_id, $unite->centre_secondaire_id])
            ->filter()
            ->all();

        return array_values(array_unique([...$centres, ...$centresSupervises]));
    }

    /** Identifiants des sites accessibles : ceux des centres accessibles. */
    public function idsSitesAccessibles(): array
    {
        $centres = $this->idsCentresAccessibles();

        if ($centres === []) {
            return [];
        }

        return Site::query()->whereIn('centre_id', $centres)->pluck('id')->all();
    }

    /** La région du chef d'antenne, ou celle de l'affectation en cours. */
    public function idRegionAccessible(): ?int
    {
        if ($this->region_id) {
            return $this->region_id;
        }

        $centres = $this->idsCentresAccessibles();

        if ($centres === []) {
            return null;
        }

        return Centre::query()->whereIn('id', $centres)->value('region_id');
    }

    // ------------------------------------------------------------------
    // Journalisation
    // ------------------------------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['telephone', 'email', 'statut_compte', 'etat_remise', 'region_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('compte');
    }
}
