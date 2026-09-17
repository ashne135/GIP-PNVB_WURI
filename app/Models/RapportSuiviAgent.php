<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Enums\StatutPresence;
use App\Models\Concerns\AppliquePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Appréciation individuelle quotidienne d'un agent (cadrage v2, sections 9.2 C
 * et 9.3 D).
 *
 * ENCADREMENT OBLIGATOIRE, parce que cette ligne peut affecter le maintien
 * d'une personne dans le dispositif :
 *   - l'agent noté PEUT CONSULTER les appréciations le concernant ;
 *   - il peut y RÉPONDRE, et sa réponse n'est pas modifiable par le supérieur
 *     (table appreciations_reponses, séparée exprès) ;
 *   - toute modification APRÈS VISA est journalisée.
 *
 * La PRÉSENCE n'est pas ressaisie : elle vient de la feuille de présence du
 * jour, via ligne_presence_id.
 */
class RapportSuiviAgent extends Model
{
    use AppliquePerimetre;
    use LogsActivity;

    protected $table = 'rapport_suivi_agents';

    protected $fillable = [
        'rapport_id', 'volontaire_id', 'categorie_agent', 'ligne_presence_id',
        'presence', 'production', 'anomalies', 'observation',
        'modifie_apres_visa_le', 'modifie_par',
    ];

    protected function casts(): array
    {
        return [
            'anomalies' => 'array',
            // Reprise de la feuille de presence : meme enum que la feuille,
            // pour qu'aucun code n'ait a comparer des chaines a la main.
            'presence' => StatutPresence::class,
            'modifie_apres_visa_le' => 'datetime',
        ];
    }

    /** Anomalies proposées par le canevas client. */
    public const ANOMALIES = [
        'retard' => 'Retard',
        'absenteisme' => 'Absentéisme',
        'propos_discourtois' => 'Propos discourtois',
        'autre' => 'Autre',
    ];

    public const NIVEAUX_PRODUCTION = [
        'passable' => 'Passable',
        'peu_satisfaisant' => 'Peu satisfaisant',
        'satisfaisant' => 'Satisfaisant',
    ];

    public function rapport(): BelongsTo
    {
        return $this->belongsTo(RapportJournalier::class, 'rapport_id');
    }

    public function volontaire(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class);
    }

    public function lignePresence(): BelongsTo
    {
        return $this->belongsTo(LignePresence::class, 'ligne_presence_id');
    }

    /** Le droit de réponse de l'agent noté. */
    public function reponses(): HasMany
    {
        return $this->hasMany(AppreciationReponse::class, 'suivi_agent_id')->orderBy('repondu_le');
    }

    /** Une anomalie a-t-elle été signalée ? C'est ce qui ouvre le droit de réponse. */
    public function porteUneAnomalie(): bool
    {
        return ! empty($this->anomalies);
    }

    /**
     * Périmètre des appréciations.
     *
     * Au niveau « lui-même », l'agent voit DEUX choses : les appréciations qui
     * LE concernent — « pas de notation invisible » — et celles qu'il a portées
     * sur ses propres agents. Aux niveaux supérieurs, le périmètre suit celui
     * du rapport qui porte l'appréciation.
     */
    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::LuiMeme) {
            $volontaire = $utilisateur->volontaire;

            if (! $volontaire) {
                return $requete->whereRaw('1 = 0');
            }

            return $requete->where(function (Builder $q) use ($volontaire, $utilisateur) {
                $q->where('volontaire_id', $volontaire->id)
                    ->orWhereHas('rapport', fn (Builder $r) => $r->perimetre($utilisateur));
            });
        }

        return $requete->whereHas('rapport', fn (Builder $r) => $r->perimetre($utilisateur));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['production', 'anomalies', 'observation'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('appreciation');
    }
}
