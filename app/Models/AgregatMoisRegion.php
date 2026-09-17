<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agrégat mensuel par région, pour les courbes et les rapports.
 *
 * Table d'agrégat pré-calculé : le tableau de bord la lit, jamais la table de
 * détail. Recalculée par un job planifié et à chaque synchronisation.
 */
class AgregatMoisRegion extends Model
{
    protected $table = 'agregats_mois_region';

    public $timestamps = false;

    protected $fillable = [
        'region_id',
        'annee',
        'mois',
        'nb_enregistres_mois',
        'cumul_depuis_debut',
        'taux_couverture',
        'nb_jours_actifs',
        'recalcule_le',
    ];

    protected function casts(): array
    {
        return ['recalcule_le' => 'datetime'];
    }
}
