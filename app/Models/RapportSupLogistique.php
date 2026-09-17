<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Situation logistique, UNE LIGNE PAR RESSOURCE (cadrage v2, section 9.3 F),
 * avec les colonnes DISPONIBLE · FONCTIONNELLE · BESOIN.
 *
 * Les lignes sont ajoutables : la liste ci-dessous n'est qu'un point de départ
 * pré-rempli, pas une contrainte.
 */
class RapportSupLogistique extends Model
{
    protected $table = 'rapport_sup_logistique';

    public $timestamps = false;

    protected $fillable = [
        'rapport_id', 'ressource', 'disponible', 'fonctionnelle', 'besoin', 'observation', 'ordre',
    ];

    /** Ressources listées par le canevas client, dans l'ordre. */
    public const RESSOURCES_TYPE = [
        'Kits',
        'Tablettes et ordinateurs',
        'Consommables',
        'Connexion',
        'Électricité',
    ];

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }
}
