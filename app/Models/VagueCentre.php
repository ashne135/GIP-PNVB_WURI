<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un centre ouvert dans une vague, avec ses dates d'ouverture et de fermeture. */
class VagueCentre extends Model
{
    protected $table = 'vague_centres';

    protected $fillable = [
        'vague_id', 'centre_id', 'date_ouverture', 'date_fermeture', 'statut',
    ];

    protected function casts(): array
    {
        return [
            'date_ouverture' => 'date',
            'date_fermeture' => 'date',
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
}
