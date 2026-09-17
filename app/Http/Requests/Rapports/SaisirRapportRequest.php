<?php

namespace App\Http\Requests\Rapports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saisie du contenu d'un rapport journalier.
 *
 * AUCUNE colonne CALCULÉE n'est acceptée ici : ni l'écart, ni le taux de
 * réalisation, ni le taux de conformité. Le cadrage les veut calculés et
 * jamais saisis ; les absenter des règles, c'est les refuser à l'entrée.
 */
class SaisirRapportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'heure_arrivee' => ['nullable', 'date_format:H:i'],
            'heure_depart' => ['nullable', 'date_format:H:i'],
            'carv_rattachement' => ['nullable', 'string', 'max:120'],
            'horodatage_telephone' => ['nullable', 'date'],

            // 9.1 — A-OPK
            'activites' => ['nullable', 'array'],
            'activites.affluence_prevue' => ['nullable', Rule::in(['faible', 'moyen', 'eleve'])],
            'activites.affluence_realisee' => ['nullable', Rule::in(['faible', 'moyen', 'eleve'])],
            'activites.justificatifs_recus_prevu' => ['nullable', 'integer', 'min:0'],
            'activites.justificatifs_recus_realise' => ['nullable', 'integer', 'min:0'],
            'activites.justificatifs_transmis_prevu' => ['nullable', 'integer', 'min:0'],
            'activites.justificatifs_transmis_realise' => ['nullable', 'integer', 'min:0'],
            'activites.plaintes_enregistrees_prevu' => ['nullable', 'integer', 'min:0'],
            'activites.plaintes_enregistrees_realise' => ['nullable', 'integer', 'min:0'],
            'activites.plaintes_reversees_prevu' => ['nullable', 'integer', 'min:0'],
            'activites.plaintes_reversees_realise' => ['nullable', 'integer', 'min:0'],

            // 9.2 — Opérateur de kit
            'production' => ['nullable', 'array'],
            'production.enregistrements_realises' => ['nullable', 'integer', 'min:0'],
            'production.recepisses_transmis' => ['nullable', 'integer', 'min:0'],
            'production.enregistrements_non_valides' => ['nullable', 'integer', 'min:0'],
            'production.motif_non_valides' => ['nullable', 'string', 'max:2000'],
            'production.etat_kit' => ['nullable', Rule::in(['fonctionnel', 'panne_partielle', 'panne_totale'])],

            // 9.3 — Superviseur de centre
            'evolution' => ['nullable', 'array'],
            'evolution.personnes_enregistrees' => ['nullable', 'integer', 'min:0'],
            'evolution.dossiers_valides' => ['nullable', 'integer', 'min:0'],
            'evolution.dossiers_a_reprendre' => ['nullable', 'integer', 'min:0'],
            'qualite' => ['nullable', 'array'],
            'qualite.dossiers_controles' => ['nullable', 'integer', 'min:0'],
            'qualite.dossiers_conformes' => ['nullable', 'integer', 'min:0'],
            'qualite.dossiers_non_conformes' => ['nullable', 'integer', 'min:0'],
            'qualite.doublons_detectes' => ['nullable', 'integer', 'min:0'],
            'qualite.erreurs_saisie' => ['nullable', 'integer', 'min:0'],
            'qualite.corrections_effectuees' => ['nullable', 'integer', 'min:0'],
            'qualite.incidents_majeurs' => ['nullable', 'integer', 'min:0'],
            'logistique' => ['nullable', 'array'],
            'logistique.*.ressource' => ['required', 'string', 'max:80'],
            'logistique.*.disponible' => ['nullable', 'integer', 'min:0'],
            'logistique.*.fonctionnelle' => ['nullable', 'integer', 'min:0'],
            'logistique.*.besoin' => ['nullable', 'integer', 'min:0'],
            'logistique.*.observation' => ['nullable', 'string', 'max:255'],

            // Blocs libres, communs aux trois rapports
            'difficultes' => ['nullable', 'array'],
            'difficultes.*.difficulte' => ['required', 'string', 'max:2000'],
            'difficultes.*.solution' => ['nullable', 'string', 'max:2000'],
            'points_amelioration' => ['nullable', 'array'],
            'points_amelioration.*' => ['nullable', 'string', 'max:1000'],

            // Suivi des équipes — la PRÉSENCE n'y figure pas : elle vient de la
            // feuille de présence du jour, et n'est jamais ressaisie.
            'suivi_agents' => ['nullable', 'array'],
            'suivi_agents.*.volontaire_id' => ['required', 'integer', 'exists:volontaires,id'],
            'suivi_agents.*.production' => ['nullable',
                Rule::in(['passable', 'peu_satisfaisant', 'satisfaisant'])],
            'suivi_agents.*.anomalies' => ['nullable', 'array'],
            'suivi_agents.*.anomalies.*' => [
                Rule::in(['retard', 'absenteisme', 'propos_discourtois', 'autre']),
            ],
            'suivi_agents.*.observation' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'heure_arrivee.date_format' => "L'heure d'arrivée doit être au format 08:30.",
            'heure_depart.date_format' => "L'heure de départ doit être au format 17:30.",
            'difficultes.*.difficulte.required' => 'Décrivez la difficulté rencontrée.',
            'logistique.*.ressource.required' => 'Nommez la ressource concernée.',
            'suivi_agents.*.production.in' => "L'appréciation doit être : passable, "
                .'peu satisfaisant ou satisfaisant.',
        ];
    }
}
