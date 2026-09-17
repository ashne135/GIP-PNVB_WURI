<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un appel groupé POST /api/sync.
 *
 * Sans cette trace, un agent qui affirme « j'ai envoyé mon rapport » n'est ni
 * vérifiable ni contredit.
 */
class SyncLot extends Model
{
    protected $table = 'sync_lots';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'uuid_lot', 'recu_le', 'nb_elements', 'nb_acceptes',
        'nb_rejetes', 'detail', 'duree_ms',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'recu_le' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
