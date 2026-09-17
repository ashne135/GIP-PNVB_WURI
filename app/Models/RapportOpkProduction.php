<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Synthèse de production d'un opérateur de kit (cadrage v2, section 9.2).
 *
 * L'ÉCART et le TAUX sont des colonnes GÉNÉRÉES EN BASE : le cadrage impose
 * qu'ils soient calculés et jamais saisis. Les déclarer ici en `$guarded` ne
 * suffirait pas — une écriture directe par le query builder passerait outre.
 * Générées en base, elles ne peuvent pas diverger de leurs opérandes.
 *
 * nb : enregistrements_realises est une donnée DÉCLARÉE. La plateforme ne
 * réalise pas l'enregistrement et ne stocke aucune identité de citoyen.
 */
class RapportOpkProduction extends Model
{
    protected $table = 'rapport_opk_production';

    public $timestamps = false;

    protected $fillable = [
        'rapport_id', 'objectif_enregistrements', 'enregistrements_realises',
        'recepisses_transmis', 'enregistrements_non_valides', 'motif_non_valides', 'etat_kit',
    ];

    /** Colonnes générées : jamais écrites par l'application. */
    protected $guarded = ['ecart_enregistrements', 'taux_realisation'];

    protected function casts(): array
    {
        return ['taux_realisation' => 'decimal:2'];
    }

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }

    /** Un motif est exigé dès qu'un enregistrement n'a pas été validé. */
    public function motifManquant(): bool
    {
        return $this->enregistrements_non_valides > 0 && blank($this->motif_non_valides);
    }
}
