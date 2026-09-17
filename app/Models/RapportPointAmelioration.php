<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Liste libre de points à améliorer, commune aux trois rapports. */
class RapportPointAmelioration extends Model
{
    protected $table = 'rapport_points_amelioration';

    public $timestamps = false;

    protected $fillable = ['rapport_id', 'ordre', 'point'];

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }
}
