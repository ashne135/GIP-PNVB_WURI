<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accusé de lecture d'une alerte par un utilisateur. */
class AlerteLecture extends Model
{
    protected $table = 'alerte_lectures';

    public $timestamps = false;

    protected $fillable = ['alerte_id', 'user_id', 'lu_le'];

    protected function casts(): array
    {
        return ['lu_le' => 'datetime'];
    }

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
