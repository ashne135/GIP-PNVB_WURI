<?php

namespace App\Models;

use App\Enums\CategorieVolontaire;
use App\Enums\MotifReserve;
use App\Enums\NiveauEtude;
use App\Enums\NiveauPerimetre;
use App\Enums\StatutVolontaire;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Fiche métier d'un volontaire.
 *
 * La catégorie est IMMUABLE : les trois catégories sont étanches et un assistant
 * ne peut pas être promu opérateur (cadrage, sections 3 et 15). Le garde-fou est
 * posé dans le modèle lui-même, pour qu'aucun appel — contrôleur, commande,
 * seeder ou tinker — ne puisse contourner la règle.
 */
class Volontaire extends Model
{
    use AppliquePerimetre;
    use HasFactory;
    use LogsActivity;
    use MarquageFictif;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'matricule', 'numero_cnib', 'date_etablissement_cnib',
        'categorie', 'statut', 'localite_id', 'region_origine_id',
        'sexe', 'date_naissance', 'lieu_naissance', 'motif_reserve',
        'date_entree_reserve', 'import_id', 'qualifie_par', 'qualifie_le', 'est_fictif',
        'niveau_etude', 'diplome', 'derogation_niveau_motif', 'derogation_niveau_par',
        'derogation_niveau_le', 'motif_retrait', 'retire_par', 'retire_le',
    ];

    /**
     * Le N° CNIB est une DONNÉE D'IDENTITÉ SENSIBLE (cadrage v2, section 6) :
     * il ne sort jamais dans une sérialisation par défaut. Les seuls chemins
     * qui l'exposent en clair sont numeroCnibPour() et l'écran de l'import,
     * tous deux réservés à l'administrateur national et au super administrateur.
     */
    protected $hidden = ['numero_cnib'];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieVolontaire::class,
            'statut' => StatutVolontaire::class,
            'motif_reserve' => MotifReserve::class,
            'date_naissance' => 'date',
            'date_etablissement_cnib' => 'date',
            'date_entree_reserve' => 'datetime',
            'qualifie_le' => 'datetime',
            'niveau_etude' => NiveauEtude::class,
            'derogation_niveau_le' => 'datetime',
            'retire_le' => 'datetime',
            'est_fictif' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Verrou d'étanchéité des catégories.
         *
         * Le cadrage v2 introduit l'état « à qualifier » : la colonne Profil est
         * vide dans le fichier des retenus, et la catégorie est alors nulle à
         * l'import. Il faut donc distinguer DEUX mouvements :
         *
         *   NULL → valeur   QUALIFICATION. Autorisée, une seule fois, par
         *                   l'administrateur national avant toute affectation.
         *   valeur → autre  INTERDIT POUR TOUJOURS. « Un assistant ne peut pas
         *                   être promu opérateur » (sections 3 et 15).
         *
         * Le verrou vit dans le modèle, et non dans un contrôleur : aucune
         * écriture — commande, seeder, tinker — ne peut le contourner.
         */
        static::updating(function (self $volontaire) {
            if (! $volontaire->isDirty('categorie')) {
                return;
            }

            // getRawOriginal, et non getOriginal : la colonne est castée en
            // enum, et getOriginal renverrait l'objet — impossible à
            // interpoler dans le message, ce qui masquerait cette exception
            // derrière une Error.
            $categorieAvant = $volontaire->getRawOriginal('categorie');

            if ($categorieAvant === null) {
                return;
            }

            throw new \DomainException(
                "La catégorie d'un volontaire ne peut pas être modifiée : "
                ."les trois catégories sont étanches. Fiche {$volontaire->matricule}, "
                ."catégorie actuelle « {$categorieAvant} »."
            );
        });
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class);
    }

    public function regionOrigine(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_origine_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class);
    }

    public function affectationActive(): HasOne
    {
        return $this->hasOne(Affectation::class)->where('statut', 'active');
    }

    public function kit(): HasOne
    {
        return $this->hasOne(Kit::class, 'volontaire_detenteur_id');
    }

    public function unitesSupervision(): HasMany
    {
        return $this->hasMany(UniteSupervision::class, 'volontaire_superviseur_id');
    }

    // ------------------------------------------------------------------
    // Scopes métier
    // ------------------------------------------------------------------

    public function scopeCategorie(Builder $requete, CategorieVolontaire $categorie): Builder
    {
        return $requete->where('categorie', $categorie->value);
    }

    /** Vivier mobilisable pour un tirage : opérationnel et libre de toute vague. */
    public function scopeDisponiblesPourTirage(Builder $requete): Builder
    {
        return $requete
            ->where('statut', StatutVolontaire::Operationnel->value)
            ->whereDoesntHave('affectations', fn (Builder $q) => $q->whereIn('statut', ['proposee', 'active']));
    }

    // ------------------------------------------------------------------
    // Périmètre
    // ------------------------------------------------------------------

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        return match ($niveau) {
            NiveauPerimetre::LuiMeme => $requete->where('user_id', $utilisateur->id),
            NiveauPerimetre::SesCentres => $requete->whereHas(
                'affectations',
                fn (Builder $q) => $q->whereIn('centre_id', $utilisateur->idsCentresAccessibles())
            ),
            NiveauPerimetre::SaRegion => $requete->whereHas(
                'affectations.centre',
                fn (Builder $q) => $q->where('region_id', $utilisateur->idRegionAccessible())
            ),
            NiveauPerimetre::National => $requete,
        };
    }

    // ------------------------------------------------------------------
    // Identité sensible et qualification
    // ------------------------------------------------------------------

    /**
     * Le N° CNIB masqué, forme affichable par défaut : B1********.
     * C'est ce que voit tout le monde sauf l'administrateur national et le DSI.
     */
    public function numeroCnibMasque(): ?string
    {
        if (! $this->numero_cnib) {
            return null;
        }

        return mb_substr($this->numero_cnib, 0, 2).str_repeat('*', max(0, mb_strlen($this->numero_cnib) - 2));
    }

    /**
     * Le N° CNIB en clair, UNIQUEMENT pour qui a le droit de le voir.
     * Toute lecture en clair est journalisée : c'est une consultation sensible
     * au sens du cadrage (section 14).
     */
    public function numeroCnibPour(?User $utilisateur): ?string
    {
        if (! $utilisateur || ! $this->numero_cnib) {
            return $this->numeroCnibMasque();
        }

        $habilite = $utilisateur->hasAnyRole([
            \App\Enums\RolePnvb::AdministrateurNational->value,
            \App\Enums\RolePnvb::SuperAdministrateur->value,
        ]);

        if (! $habilite) {
            return $this->numeroCnibMasque();
        }

        activity('identite')
            ->causedBy($utilisateur)
            ->performedOn($this)
            ->log('Consultation du numéro CNIB en clair');

        return $this->numero_cnib;
    }

    /** La fiche attend-elle encore l'attribution d'un profil ? */
    public function estAQualifier(): bool
    {
        return $this->categorie === null;
    }

    public function scopeAQualifier(Builder $requete): Builder
    {
        return $requete->whereNull('categorie');
    }

    /**
     * Attribue le profil d'une fiche importée sans colonne Profil.
     * Ne peut s'appliquer qu'une fois : le verrou de booted() refuse tout
     * changement ultérieur.
     */
    public function qualifier(
        CategorieVolontaire $categorie,
        User $auteur,
        ?int $localiteId = null,
        ?string $motifDerogation = null
    ): void {
        if (! $this->estAQualifier()) {
            throw new \DomainException(
                "Cette fiche porte déjà la catégorie « {$this->categorie->value} » : "
                .'les catégories sont étanches, elle ne peut pas être requalifiée.'
            );
        }

        $derogation = $this->exigerNiveau($categorie, $motifDerogation);

        /*
         * L'A-OPK est rattaché EN PERMANENCE à sa localité (cadrage, section 4) :
         * sans elle, il ne pourra jamais être affecté, puisque l'algorithme le
         * rattache au kit de SON site.
         *
         * Une fiche arrivée « à qualifier » n'a le plus souvent pas de
         * territoire non plus — les colonnes territoriales sont vides dans le
         * même fichier. C'est donc ici, au moment où l'on découvre que l'agent
         * est un A-OPK, que la localité devient exigible.
         */
        $localiteRetenue = $localiteId ?? $this->localite_id;

        if ($categorie === CategorieVolontaire::Assistant && $localiteRetenue === null) {
            throw new \DomainException(
                "Un A-OPK est rattaché en permanence à sa localité : précisez-la pour "
                ."la fiche {$this->matricule} avant de la qualifier."
            );
        }

        // Le matricule provisoire (PNVB-AQU…) laisse place au matricule
        // définitif, dont le préfixe rend la catégorie lisible sur le terrain.
        // Il change UNE FOIS, avant toute impression de bordereau : aucun
        // document papier ne porte encore l'ancien.
        $this->update([
            'categorie' => $categorie->value,
            'matricule' => $this->prochainMatricule($categorie),
            'localite_id' => $categorie === CategorieVolontaire::Assistant ? $localiteRetenue : null,
            'qualifie_par' => $auteur->id,
            'qualifie_le' => now(),
            ...($derogation ? [
                'derogation_niveau_motif' => $derogation,
                'derogation_niveau_par' => $auteur->id,
                'derogation_niveau_le' => now(),
            ] : []),
        ]);

        $this->user->assignRole($categorie->role()->value);

        activity('volontaire')
            ->causedBy($auteur)
            ->performedOn($this)
            ->withProperties(array_filter([
                'categorie' => $categorie->value,
                'niveau_etude' => $this->niveau_etude?->value,
                'derogation' => $derogation,
            ]))
            ->log($derogation
                ? "Profil attribué PAR DÉROGATION : {$categorie->libelle()}"
                : "Profil attribué : {$categorie->libelle()}");
    }

    /**
     * LE NIVEAU D'ÉTUDE COMMANDE LE PROFIL (décision du client, 17/09/2026) :
     * 4ème pour un A-OPK, BAC pour un opérateur, Licence pour un superviseur.
     *
     * Le refus est la règle ; la DÉROGATION est possible, mais elle doit être
     * MOTIVÉE et elle reste inscrite sur la fiche. Un niveau ABSENT n'est pas
     * une dérogation tacite : il se renseigne d'abord.
     *
     * @return string|null Le motif de dérogation retenu, s'il en a fallu une.
     */
    public function exigerNiveau(CategorieVolontaire $categorie, ?string $motifDerogation = null): ?string
    {
        $minimum = NiveauEtude::minimumPour($categorie);
        $motif = trim((string) $motifDerogation);

        if ($this->niveau_etude === null) {
            if ($motif === '') {
                throw new \DomainException(
                    "Le niveau d'étude de la fiche {$this->matricule} n'est pas renseigné : "
                    ."un {$categorie->libelle()} exige au moins « {$minimum->libelle()} ». "
                    .'Renseignez le niveau, ou accordez une dérogation motivée.'
                );
            }

            return $motif;
        }

        if ($this->niveau_etude->atteint($minimum)) {
            return null;
        }

        if ($motif === '') {
            throw new \DomainException(
                "Niveau insuffisant pour la fiche {$this->matricule} : "
                ."« {$this->niveau_etude->libelle()} » alors qu'un {$categorie->libelle()} "
                ."exige au moins « {$minimum->libelle()} ». Une dérogation motivée reste possible."
            );
        }

        return $motif;
    }

    /**
     * RETIRER une fiche du dispositif (décision du client, 17/09/2026) : elle
     * sort des listes et des tirages, son accès se ferme — mais ses feuilles de
     * présence et ses rapports restent, ce sont des pièces qui font foi. Le
     * geste est réversible, et son motif reste sur la fiche.
     */
    public function retirer(string $motif, User $auteur): void
    {
        if ($this->statut === StatutVolontaire::Retire) {
            throw new \DomainException("La fiche {$this->matricule} est déjà retirée.");
        }

        if ($this->affectations()->whereIn('statut', ['proposee', 'active'])->exists()) {
            throw new \DomainException(
                "La fiche {$this->matricule} est engagée dans une vague : "
                .'remplacez l\'agent avant de le retirer.'
            );
        }

        $this->update([
            'statut' => StatutVolontaire::Retire->value,
            'motif_retrait' => $motif,
            'retire_par' => $auteur->id,
            'retire_le' => now(),
        ]);

        activity('volontaire')
            ->causedBy($auteur)
            ->performedOn($this)
            ->withProperties(['motif' => $motif])
            ->log('Volontaire retiré du dispositif');
    }

    /** Le retrait se défait : la fiche revient en réserve, jamais directement sur le terrain. */
    public function reintegrer(string $motif, User $auteur): void
    {
        if ($this->statut !== StatutVolontaire::Retire) {
            throw new \DomainException("La fiche {$this->matricule} n'est pas retirée.");
        }

        $this->update([
            'statut' => StatutVolontaire::Reserve->value,
            'motif_reserve' => MotifReserve::NonMobilise->value,
            'motif_retrait' => null,
            'retire_par' => null,
            'retire_le' => null,
        ]);

        activity('volontaire')
            ->causedBy($auteur)
            ->performedOn($this)
            ->withProperties(['motif' => $motif])
            ->log('Volontaire réintégré, en réserve');
    }

    /** Le prochain matricule libre pour une catégorie donnée. */
    private function prochainMatricule(CategorieVolontaire $categorie): string
    {
        $prefixe = $categorie->prefixeMatricule();

        $dernier = static::query()
            ->where('matricule', 'like', "PNVB-{$prefixe}%")
            ->orderByDesc('matricule')
            ->value('matricule');

        return sprintf('PNVB-%s%06d', $prefixe, ($dernier ? (int) substr($dernier, -6) : 0) + 1);
    }

    /** Les appréciations portées sur cet agent (cadrage v2, section 9). */
    public function appreciations(): HasMany
    {
        return $this->hasMany(RapportSuiviAgent::class)->latest();
    }

    /** Les rapports journaliers que cet agent a produits. */
    public function rapports(): HasMany
    {
        return $this->hasMany(RapportJournalier::class, 'auteur_volontaire_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['statut', 'motif_reserve', 'localite_id', 'categorie', 'niveau_etude', 'diplome'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('volontaire');
    }
}
