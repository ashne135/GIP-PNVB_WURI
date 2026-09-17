<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne du fichier importé, telle qu'elle a été lue, avec son verdict. */
class ImportLigne extends Model
{
    protected $table = 'import_lignes';

    public $timestamps = false;

    protected $fillable = [
        'import_id', 'numero_ligne', 'donnees', 'valide', 'motif_erreur', 'action',
    ];

    protected function casts(): array
    {
        return [
            'donnees' => 'array',
            'valide' => 'boolean',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
