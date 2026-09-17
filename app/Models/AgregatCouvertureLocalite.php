<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taux de couverture au grain de la localité.
 *
 * C'est l'indicateur d'avancement réel : « 62 % de la population de Bankui
 * enregistrée » plutôt que « 342 aujourd'hui ». Les niveaux commune, région et
 * national s'obtiennent par agrégation de cette table : un seul chemin de calcul,
 * donc un seul endroit où le taux peut être faux.
 */
class AgregatCouvertureLocalite extends Model
{
    protected $table = 'agregats_couverture_localite';

    public $timestamps = false;

    protected $fillable = [
        'localite_id', 'commune_id', 'region_id', 'population_cible',
        'cumul_enregistres', 'taux_couverture', 'nb_sites', 'nb_sites_couverts',
        'derniere_activite_le', 'recalcule_le',
    ];

    protected function casts(): array
    {
        return [
            'taux_couverture' => 'decimal:2',
            'derniere_activite_le' => 'date',
            'recalcule_le' => 'datetime',
        ];
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
