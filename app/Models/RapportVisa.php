<?php

namespace App\Models;

use App\Enums\ActeVisa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signature et visa électroniques (cadrage v2, section 9).
 *
 * « Chaque transition enregistre l'auteur, la date, l'heure et la position. »
 * Cette table est un JOURNAL : ses lignes ne se modifient pas, elles s'ajoutent.
 */
class RapportVisa extends Model
{
    protected $table = 'rapport_visas';

    public $timestamps = false;

    protected $fillable = [
        'rapport_id', 'acte', 'user_id', 'role_tenu', 'commentaire',
        'latitude', 'longitude', 'horodatage_telephone', 'effectue_le',
    ];

    protected function casts(): array
    {
        return [
            'acte' => ActeVisa::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'horodatage_telephone' => 'datetime',
            'effectue_le' => 'datetime',
        ];
    }

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
