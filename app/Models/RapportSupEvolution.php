<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Évolution journalière du centre (cadrage v2, section 9.3 C).
 * Les chiffres sont PRÉ-REMPLIS depuis les rapports OPK déjà visés.
 */
class RapportSupEvolution extends Model
{
    protected $table = 'rapport_sup_evolution';

    public $timestamps = false;

    protected $fillable = [
        'rapport_id', 'personnes_enregistrees', 'dossiers_valides', 'dossiers_a_reprendre',
    ];

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }
}
