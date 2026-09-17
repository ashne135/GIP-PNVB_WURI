<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat quotidien par région.
 *
 * effectif_deploye est un PIC SIMULTANÉ, jamais un cumul : ce sont les mêmes
 * équipes qui tournent d'une région à l'autre. Un indicateur national ne somme
 * JAMAIS cette colonne entre régions (cadrage, sections 4 et 15).
 */
class AgregatJourRegion extends Model
{
    protected $table = 'agregats_jour_region';

    public $timestamps = false;

    protected $fillable = [
        'region_id', 'date_jour', 'nb_enregistres', 'nb_rejetes',
        'nb_centres_ouverts', 'nb_sites_couverts', 'effectif_deploye',
        'taux_presence', 'nb_incidents_ouverts_par_gravite', 'recalcule_le',
    ];

    protected function casts(): array
    {
        return [
            'date_jour' => 'date',
            'nb_incidents_ouverts_par_gravite' => 'array',
            'recalcule_le' => 'datetime',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Effectif national d'un jour : le PLUS GRAND effectif régional, pas la somme.
     */
    public static function effectifNationalDuJour(string $date): int
    {
        return (int) static::query()->whereDate('date_jour', $date)->max('effectif_deploye');
    }
}
