<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Les localités : village, secteur ou quartier — le TYPE est porté par la
 * colonne type_localite (cadrage v2, section 2). Les formulaires affichent
 * les trois comme des lignes distinctes ; en base, c'est une seule table.
 *
 * C'est la localité qui porte la POPULATION, dénominateur du taux de couverture.
 * quota_sites_brut conserve le prorata intra-régional exact tel qu'il a été
 * calculé ; quota_sites porte le résultat entier après plancher de 1 site par
 * localité et répartition du solde régional aux plus forts restes.
 */
class Localite extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $fillable = [
        'commune_id', 'arrondissement_id', 'region_id', 'nom', 'type_localite',
        'population_hommes', 'population_femmes', 'population_totale',
        'quota_sites_brut', 'quota_sites', 'latitude', 'longitude', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'quota_sites_brut' => 'decimal:6',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'est_fictif' => 'boolean',
        ];
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * Facultatif : renseigné uniquement dans les communes urbaines découpées en
     * arrondissements (cadrage v2, section 2).
     */
    public function arrondissement(): BelongsTo
    {
        return $this->belongsTo(Arrondissement::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function volontairesAssistants(): HasMany
    {
        return $this->hasMany(Volontaire::class)->where('categorie', 'assistant');
    }

    public function couverture(): HasOne
    {
        return $this->hasOne(AgregatCouvertureLocalite::class);
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::SesCentres) {
            return $requete->whereHas(
                'sites',
                fn (Builder $q) => $q->whereIn('centre_id', $utilisateur->idsCentresAccessibles())
            );
        }

        if ($niveau === NiveauPerimetre::LuiMeme) {
            $localite = $utilisateur->volontaire?->localite_id;

            return $localite ? $requete->where('id', $localite) : $requete->whereRaw('1 = 0');
        }

        $region = $utilisateur->idRegionAccessible();

        return $region ? $requete->where('region_id', $region) : $requete->whereRaw('1 = 0');
    }
}
