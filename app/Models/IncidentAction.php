<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traçabilité de toutes les actions sur un incident, jusqu'à la clôture.
 * user_id nul signifie une action de l'acteur SYSTÈME (notification, escalade).
 */
class IncidentAction extends Model
{
    protected $table = 'incident_actions';

    public $timestamps = false;

    protected $fillable = [
        'incident_id', 'user_id', 'type_action', 'ancien_statut',
        'nouveau_statut', 'commentaire', 'destinataires', 'effectue_le',
    ];

    protected function casts(): array
    {
        return [
            'destinataires' => 'array',
            'effectue_le' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
