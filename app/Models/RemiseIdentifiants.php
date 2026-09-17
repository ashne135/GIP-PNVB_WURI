<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une tentative de remise des identifiants, dans la cascade
 * courriel (rang 1) > SMS (rang 2) > bordereau signé en formation (rang 3).
 *
 * Le mot de passe temporaire n'est JAMAIS stocké : seule la trace de l'envoi
 * l'est.
 */
class RemiseIdentifiants extends Model
{
    protected $table = 'remises_identifiants';

    protected $fillable = [
        'user_id', 'canal', 'rang', 'statut', 'destinataire',
        'session_formation', 'erreur', 'envoye_le', 'remis_le', 'remis_par',
    ];

    protected function casts(): array
    {
        return [
            'envoye_le' => 'datetime',
            'remis_le' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remis_par');
    }
}
