<?php

namespace App\Models;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutPresence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de feuille de présence : un agent, un jour, un site.
 *
 * Pré-remplie avec les agents affectés au site, et avec l'état de leur signal
 * d'arrivée : couleur, heure et distance. Le motif est obligatoire pour toute
 * absence justifiée.
 */
class LignePresence extends Model
{
    protected $table = 'lignes_presence';

    protected $fillable = [
        'feuille_presence_id', 'volontaire_id', 'categorie', 'statut',
        'motif_absence', 'signal_arrivee_id', 'heure_arrivee_signalee',
        'distance_signalee', 'dans_zone', 'commentaire',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieVolontaire::class,
            'statut' => StatutPresence::class,
            'heure_arrivee_signalee' => 'datetime',
            'dans_zone' => 'boolean',
        ];
    }

    public function feuille(): BelongsTo
    {
        return $this->belongsTo(FeuillePresence::class, 'feuille_presence_id');
    }

    public function volontaire(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class);
    }

    public function signalArrivee(): BelongsTo
    {
        return $this->belongsTo(SignalArrivee::class, 'signal_arrivee_id');
    }

    /** Couleur reprise du signal d'arrivée : gris quand aucun signal n'a été émis. */
    public function couleurSignal(): string
    {
        if (! $this->signal_arrivee_id) {
            return 'gris';
        }

        return $this->dans_zone ? 'vert' : 'orange';
    }
}
