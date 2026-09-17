<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contrôle et qualité des données (cadrage v2, section 9.3 E).
 * Le TAUX DE CONFORMITÉ est une colonne générée en base : calculé, jamais saisi.
 */
class RapportSupQualite extends Model
{
    protected $table = 'rapport_sup_qualite';

    public $timestamps = false;

    protected $fillable = [
        'rapport_id', 'dossiers_controles', 'dossiers_conformes', 'dossiers_non_conformes',
        'doublons_detectes', 'erreurs_saisie', 'corrections_effectuees', 'incidents_majeurs',
    ];

    protected $guarded = ['taux_conformite'];

    protected function casts(): array
    {
        return ['taux_conformite' => 'decimal:2'];
    }

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }
}
