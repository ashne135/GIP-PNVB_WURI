<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Téléversement d'un référentiel.
 *
 * Aucun import partiel silencieux : rien n'est écrit tant que l'aperçu n'est pas
 * confirmé (cadrage, section 2). Le statut suit ce cycle, et les lignes lues sont
 * conservées dans import_lignes pour que l'aperçu survive à un rafraîchissement.
 */
class Import extends Model
{
    use LogsActivity;

    protected $fillable = [
        'type_referentiel', 'mode', 'fichier_nom', 'fichier_chemin', 'fichier_empreinte',
        'statut', 'lignes_total', 'lignes_valides', 'lignes_erreur', 'resume',
        'rapport_chemin', 'televerse_par', 'confirme_par', 'confirme_le',
    ];

    protected function casts(): array
    {
        return [
            'resume' => 'array',
            'confirme_le' => 'datetime',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ImportLigne::class);
    }

    public function lignesEnErreur(): HasMany
    {
        return $this->hasMany(ImportLigne::class)->where('valide', false);
    }

    public function televersePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'televerse_par');
    }

    public function confirmePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirme_par');
    }

    public function estConfirmable(): bool
    {
        return $this->statut === 'apercu_pret' && $this->lignes_valides > 0;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type_referentiel', 'mode', 'statut', 'lignes_valides', 'lignes_erreur'])
            ->useLogName('import');
    }
}
