<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat quotidien au grain du centre.
 *
 * Table d'agrégat pré-calculé : le tableau de bord la lit, jamais la table de
 * détail. Recalculée par un job planifié et à chaque synchronisation.
 */
class AgregatJourCentre extends Model
{
    protected $table = 'agregats_jour_centre';

    public $timestamps = false;

    protected $fillable = [
        'centre_id',
        'vague_id',
        'region_id',
        'date_jour',
        'nb_sites_actifs',
        'nb_kits_actifs',
        'nb_enregistres',
        'nb_rejetes',
        'effectif_attendu',
        'effectif_present',
        'effectif_absent',
        'taux_presence',
        'nb_incidents_ouverts',
        'recalcule_le',
    ];

    protected function casts(): array
    {
        return ['recalcule_le' => 'datetime'];
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
