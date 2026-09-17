<?php

namespace App\Models;

use App\Enums\MotifReserve;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Mobilisation d'un réserviste en remplacement d'un agent défaillant.
 *
 * Le motif est OBLIGATOIRE, et le transfert du kit l'est aussi dès lors que
 * l'agent sortant en détenait un (cadrage, section 6).
 */
class Remplacement extends Model
{
    use LogsActivity;

    protected $fillable = [
        'vague_id', 'volontaire_sortant_id', 'volontaire_entrant_id',
        'affectation_sortante_id', 'affectation_entrante_id', 'motif',
        'commentaire', 'kit_mouvement_id', 'decide_par', 'decide_le',
    ];

    protected function casts(): array
    {
        return [
            'motif' => MotifReserve::class,
            'decide_le' => 'datetime',
        ];
    }

    public function vague(): BelongsTo
    {
        return $this->belongsTo(VagueDeploiement::class, 'vague_id');
    }

    public function sortant(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'volontaire_sortant_id');
    }

    public function entrant(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'volontaire_entrant_id');
    }

    public function affectationSortante(): BelongsTo
    {
        return $this->belongsTo(Affectation::class, 'affectation_sortante_id');
    }

    public function affectationEntrante(): BelongsTo
    {
        return $this->belongsTo(Affectation::class, 'affectation_entrante_id');
    }

    public function kitMouvement(): BelongsTo
    {
        return $this->belongsTo(KitMouvement::class, 'kit_mouvement_id');
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['motif', 'volontaire_sortant_id', 'volontaire_entrant_id'])
            ->useLogName('remplacement');
    }
}
