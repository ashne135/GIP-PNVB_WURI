<?php

namespace App\Models;

use App\Enums\CategorieVolontaire;
use App\Enums\NiveauPerimetre;
use App\Enums\StatutAffectation;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Le passage daté d'un volontaire dans une vague.
 *
 * Ce n'est PAS un rattachement permanent : l'historique est conservé, jamais
 * écrasé. Un rapport de mars reste rattaché au site et à la vague où il a été
 * produit (cadrage, section 4).
 *
 * cle_unicite_active est une colonne générée en base : elle vaut volontaire_id
 * tant que le statut est « active », NULL sinon, et son index unique interdit
 * physiquement deux affectations actives pour le même agent.
 */
class Affectation extends Model
{
    use AppliquePerimetre;
    use LogsActivity;
    use MarquageFictif;

    protected $fillable = [
        'vague_id', 'volontaire_id', 'role_terrain', 'centre_id',
        'unite_supervision_id', 'localite_id', 'kit_id', 'date_debut', 'date_fin',
        'statut', 'origine', 'rang_tirage', 'affectation_remplacee_id', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'role_terrain' => CategorieVolontaire::class,
            'statut' => StatutAffectation::class,
            'date_debut' => 'date',
            'date_fin' => 'date',
            'est_fictif' => 'boolean',
        ];
    }

    public function vague(): BelongsTo
    {
        return $this->belongsTo(VagueDeploiement::class, 'vague_id');
    }

    public function volontaire(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class);
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function uniteSupervision(): BelongsTo
    {
        return $this->belongsTo(UniteSupervision::class);
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class);
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function affectationRemplacee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'affectation_remplacee_id');
    }

    public function scopeActives(Builder $requete): Builder
    {
        return $requete->where('statut', StatutAffectation::Active->value);
    }

    /**
     * L'affectation qui COUVRAIT une date — pas seulement celle en cours.
     *
     * Le téléphone envoie parfois des heures plus tard ce qui a été fait sans
     * réseau, et la vague a pu être clôturée entre-temps. Juger l'action au jour
     * où elle a été faite, et non au jour où elle arrive, est ce qui évite de
     * perdre la dernière journée d'un agent (cadrage, section 11).
     *
     * Une affectation terminée ou remplacée ne compte que pendant le délai de
     * rattrapage : au-delà, plus rien ne passe. Une affectation proposée ou
     * annulée ne couvre jamais rien — elle n'a jamais été une mission.
     */
    public function scopeCouvrant(Builder $requete, string $date): Builder
    {
        $finDuRattrapage = now()
            ->subDays(Parametre::entier('comptes.delai_rattrapage_jours', 7))
            ->toDateString();

        return $requete
            ->where(fn (Builder $q) => $q->whereNull('date_debut')->orWhereDate('date_debut', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date))
            ->where(fn (Builder $q) => $q->where('statut', StatutAffectation::Active->value)
                ->orWhere(fn (Builder $terminee) => $terminee
                    ->whereIn('statut', [StatutAffectation::Terminee->value, StatutAffectation::Remplacee->value])
                    ->whereDate('date_fin', '>=', $finDuRattrapage)));
    }

    /**
     * Deux affectations peuvent couvrir le même jour : un remplacement dans la
     * journée termine l'une et ouvre l'autre. L'active passe alors en premier.
     */
    public function scopeActiveDabord(Builder $requete): Builder
    {
        return $requete
            ->orderByRaw('statut = ? desc', [StatutAffectation::Active->value])
            ->orderByDesc('date_debut');
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

        /*
         * L'AFFECTATION D'UN SUPERVISEUR N'A PAS DE CENTRE : elle porte une
         * unité de supervision (deux centres), et centre_id reste nul. Filtrer
         * sur le seul centre_id excluait donc tous les superviseurs — un chef
         * d'antenne ne voyait aucun superviseur de sa propre région.
         *
         * On rattrape par l'unité au niveau des centres, et par la VAGUE au
         * niveau régional : une vague est régionale par nature, et c'est le
         * seul rattachement que les trois catégories partagent.
         */
        if ($niveau === NiveauPerimetre::SesCentres) {
            $centres = $utilisateur->idsCentresAccessibles();

            return $requete->where(fn (Builder $q) => $q
                ->whereIn('centre_id', $centres)
                ->orWhereHas('uniteSupervision', fn (Builder $unite) => $unite
                    ->whereIn('centre_principal_id', $centres)
                    ->orWhereIn('centre_secondaire_id', $centres)));
        }

        $region = $utilisateur->idRegionAccessible();

        return $region
            ? $requete->where(fn (Builder $q) => $q
                ->whereHas('centre', fn (Builder $c) => $c->where('region_id', $region))
                ->orWhereHas('vague', fn (Builder $v) => $v->where('region_id', $region)))
            : $requete->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['statut', 'centre_id', 'kit_id', 'origine'])
            ->logOnlyDirty()
            ->useLogName('affectation');
    }
}
