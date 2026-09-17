<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paire « difficulté rencontrée / solution apportée », en NOMBRE LIBRE
 * (cadrage v2, section 9) — pas trois cases figées.
 */
class RapportDifficulte extends Model
{
    protected $table = 'rapport_difficultes';

    public $timestamps = false;

    protected $fillable = ['rapport_id', 'ordre', 'difficulte', 'solution'];

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }
}
