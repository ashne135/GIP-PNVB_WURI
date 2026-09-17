<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Le passage daté d'un kit sur un site.
 *
 * 12 294 sites pour 966 kits : un kit ne peut pas tenir 12 sites à la fois, il
 * couvre les sites de son centre EN SÉQUENCE. C'est cette table qui dit sur quel
 * site le kit se trouve un jour donné, et donc à quel site se rattache la feuille
 * de présence et le rapport journalier de ce jour.
 */
class TourneeSite extends Model
{
    use AppliquePerimetre;

    protected $table = 'tournees_site';

    protected $fillable = [
        'vague_id', 'centre_id', 'site_id', 'kit_id', 'affectation_operateur_id',
        'ordre', 'date_debut', 'date_fin', 'statut', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'est_fictif' => 'boolean',
        ];
    }

    public function vague(): BelongsTo
    {
        return $this->belongsTo(VagueDeploiement::class, 'vague_id');
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function affectationOperateur(): BelongsTo
    {
        return $this->belongsTo(Affectation::class, 'affectation_operateur_id');
    }

    public function feuillesPresence(): HasMany
    {
        return $this->hasMany(FeuillePresence::class, 'tournee_site_id');
    }

    /** La tournée couvrant une date donnée. */
    public function scopeCouvrant(Builder $requete, string $date): Builder
    {
        return $requete->whereDate('date_debut', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date);
            });
    }

    /**
     * Le passage suit le CENTRE, qui porte lui-même la région.
     *
     * Un opérateur ne voit que les passages qu'il tient : l'écran des tournées
     * n'est pas un historique des déplacements d'un agent, et la table n'a pas
     * à en devenir un.
     */
    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::LuiMeme) {
            $volontaire = $utilisateur->volontaire;

            return $volontaire
                ? $requete->whereHas(
                    'affectationOperateur',
                    fn (Builder $q) => $q->where('volontaire_id', $volontaire->id)
                )
                : $requete->whereRaw('1 = 0');
        }

        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereIn('centre_id', $utilisateur->idsCentresAccessibles());
        }

        $region = $utilisateur->idRegionAccessible();

        return $region
            ? $requete->whereHas('centre', fn (Builder $q) => $q->where('region_id', $region))
            : $requete->whereRaw('1 = 0');
    }
}
