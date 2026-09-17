<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Activités de la journée d'un A-OPK (cadrage v2, section 9.1).
 *
 * L'A-OPK N'ENREGISTRE PERSONNE : il tient l'accueil du site — réception et
 * transmission des justificatifs, enregistrement et reversement des plaintes,
 * estimation de l'affluence. Aucune colonne de production ici.
 *
 * Chaque activité porte deux colonnes : PRÉVU et RÉALISÉ.
 */
class RapportAopkActivites extends Model
{
    protected $table = 'rapport_aopk_activites';

    public $timestamps = false;

    protected $fillable = [
        'rapport_id',
        'affluence_prevue', 'affluence_realisee',
        'justificatifs_recus_prevu', 'justificatifs_recus_realise',
        'justificatifs_transmis_prevu', 'justificatifs_transmis_realise',
        'plaintes_enregistrees_prevu', 'plaintes_enregistrees_realise',
        'plaintes_reversees_prevu', 'plaintes_reversees_realise',
    ];

    /** Bornes indicatives des paliers d'affluence, telles que données par le client. */
    public const PALIERS_AFFLUENCE = [
        'faible' => '1 à 25',
        'moyen' => '25 à 50',
        'eleve' => '50 à 100',
    ];

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }
}
