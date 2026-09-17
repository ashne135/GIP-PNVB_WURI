<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DROIT DE RÉPONSE de l'agent noté (cadrage v2, section 9).
 *
 * « Une anomalie signalée doit permettre à l'agent d'ajouter une OBSERVATION en
 * réponse, horodatée, NON MODIFIABLE PAR LE SUPÉRIEUR. »
 *
 * D'où une table séparée de rapport_suivi_agents : le supérieur écrit dans
 * l'une, l'agent dans l'autre. Aucune Policy ne donne au supérieur le droit
 * d'écrire ici, et le modèle refuse toute modification après coup — une réponse
 * s'ajoute, elle ne se réécrit pas.
 */
class AppreciationReponse extends Model
{
    protected $table = 'appreciations_reponses';

    public $timestamps = false;

    protected $fillable = [
        'uuid_client', 'suivi_agent_id', 'volontaire_id', 'reponse', 'repondu_le', 'lu_par_superieur_le',
    ];

    protected function casts(): array
    {
        return [
            'repondu_le' => 'datetime',
            'lu_par_superieur_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Le texte de la réponse est figé : seul l'accusé de lecture peut
        // évoluer. Sans ce verrou, « non modifiable » ne serait qu'une intention.
        static::updating(function (self $reponse) {
            if ($reponse->isDirty(['uuid_client', 'reponse', 'volontaire_id', 'suivi_agent_id', 'repondu_le'])) {
                throw new \DomainException(
                    "La réponse d'un agent à une appréciation ne peut pas être modifiée."
                );
            }
        });
    }

    public function suiviAgent(): BelongsTo
    {
        return $this->belongsTo(RapportSuiviAgent::class, 'suivi_agent_id');
    }

    public function volontaire(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class);
    }
}
