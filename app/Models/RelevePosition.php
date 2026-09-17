<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auto-pointage de rapprochement (cadrage, section 8.4).
 *
 * ENCADREMENT NON NÉGOCIABLE :
 *  - relevés limités aux HEURES DE SERVICE et aux JOURS D'AFFECTATION ACTIVE ;
 *  - fréquence basse et paramétrable, 30 minutes par défaut ;
 *  - conservation limitée et paramétrable, 90 jours par défaut, purge automatique ;
 *  - AUCUNE interface n'affiche la trace des déplacements d'une personne : seul
 *    l'écart constaté est exposé, et au seul chef d'antenne régional ;
 *  - toute consultation de ces données est journalisée.
 *
 * Ce modèle n'a donc volontairement AUCUN scope de périmètre : il n'est pas
 * exposé par l'API. Seul le job de rapprochement le lit.
 */
class RelevePosition extends Model
{
    protected $table = 'releves_position';

    public const UPDATED_AT = null;

    protected $fillable = [
        'volontaire_id', 'affectation_id', 'site_id_attendu', 'horodatage',
        'latitude', 'longitude', 'distance_metres', 'dans_zone', 'purge_prevue_le',
    ];

    protected function casts(): array
    {
        return [
            'horodatage' => 'datetime',
            'purge_prevue_le' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'dans_zone' => 'boolean',
        ];
    }

    public function volontaire(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class);
    }

    public function siteAttendu(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id_attendu');
    }

    /** Les relevés dont la date de purge est atteinte. */
    public function scopeAPurger(Builder $requete): Builder
    {
        return $requete->whereDate('purge_prevue_le', '<=', now()->toDateString());
    }
}
