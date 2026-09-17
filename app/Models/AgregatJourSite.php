<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agrégat quotidien au grain du site.
 *
 * Table d'agrégat pré-calculé : le tableau de bord la lit, jamais la table de
 * détail. Recalculée par un job planifié et à chaque synchronisation.
 */
class AgregatJourSite extends Model
{
    protected $table = 'agregats_jour_site';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'centre_id',
        'vague_id',
        'region_id',
        'date_jour',
        'nb_enregistres',
        'nb_rejetes',
        'effectif_attendu',
        'effectif_present',
        'effectif_absent',
        'taux_presence',
        'nb_incidents',
        'recalcule_le',
    ];

    protected function casts(): array
    {
        return ['recalcule_le' => 'datetime'];
    }
}
