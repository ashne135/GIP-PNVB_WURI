<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'une correction apportée à une valeur PRÉ-REMPLIE.
 *
 * Le cadrage (section 9, règles transverses) autorise le supérieur à corriger
 * un chiffre agrégé depuis le niveau inférieur, mais impose que la correction
 * soit tracée : valeur d'origine, valeur corrigée, motif, auteur. Sans cela,
 * un écart entre deux niveaux devient inexplicable.
 */
class RapportCorrection extends Model
{
    protected $table = 'rapport_corrections';

    public $timestamps = false;

    protected $fillable = [
        'rapport_id', 'champ', 'valeur_origine', 'valeur_corrigee',
        'motif', 'corrige_par', 'corrige_le',
    ];

    protected function casts(): array
    {
        return ['corrige_le' => 'datetime'];
    }

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }

    public function corrigePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrige_par');
    }
}
