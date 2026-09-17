<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acceptation de la charte du volontaire.
 *
 * Le cadrage (section 8.4) est explicite : discret ne veut pas dire caché. Sans
 * consentement enregistré pour la version courante de la charte, aucun relevé de
 * position n'est collecté pour cet agent.
 */
class Consentement extends Model
{
    protected $fillable = [
        'user_id', 'version_charte', 'accepte_le', 'adresse_ip', 'agent_utilisateur',
    ];

    protected function casts(): array
    {
        return ['accepte_le' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
