<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le canevas d'incident, sections A à I.
 *
 * LA SECTION J N'Y FIGURE PAS. Statut, responsable, mesures correctives et
 * rapport de clôture sont « réservés aux responsables habilités » : les absenter
 * des règles, c'est les refuser à l'entrée — un déclarant ne peut pas se
 * déclarer lui-même responsable du traitement de son incident.
 */
class DeclarerIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uuid_client' => ['required', 'uuid'],
            'canal' => ['nullable', Rule::in(['mobile', 'web'])],

            // A. Identification — le reste est déduit du site et du compte.
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
            'commune_id' => ['nullable', 'integer', 'exists:communes,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'latitude_declarant' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude_declarant' => ['nullable', 'numeric', 'between:-180,180'],
            'horodatage_telephone' => ['nullable', 'date'],
            'deja_signale' => ['nullable', 'boolean'],

            // B. Localisation
            'type_lieu' => ['nullable', Rule::in(['site', 'trajet_aller', 'trajet_retour', 'autre'])],
            'lieu_precision' => ['nullable', 'string', 'max:255'],
            'encore_sur_les_lieux' => ['nullable', 'boolean'],

            // C. Nature — cases multiples
            'natures' => ['required', 'array', 'min:1'],
            'natures.*' => ['integer', 'exists:natures_incident,id'],

            // D. Description
            'survenu_le' => ['nullable', 'date'],
            'recit' => ['required', 'string', 'min:10', 'max:5000'],
            'personnes_concernees' => ['nullable', 'string', 'max:2000'],
            'toujours_en_cours' => ['nullable', Rule::in(['oui', 'non', 'inconnu'])],
            'danger_immediat' => ['nullable', 'boolean'],

            // E. Impact — cases multiples
            'impacts' => ['nullable', 'array'],
            'impacts.*' => ['integer', 'exists:impacts_incident,id'],
            'nb_personnes_affectees' => ['nullable', 'integer', 'min:0', 'max:65000'],

            // F. Gravité — un seul choix
            'gravite' => ['required', 'integer', 'between:1,4'],

            // G. Preuves
            'preuves' => ['nullable', 'array'],
            'preuves.*' => [Rule::in(['photo', 'video', 'document', 'capture', 'aucun'])],

            // H. Mesures immédiates — cases multiples
            'mesures' => ['nullable', 'array'],
            'mesures.*' => ['integer', 'exists:mesures_incident,id'],
            'mesures_precisions' => ['nullable', 'string', 'max:2000'],

            // I. Personnes informées — cases multiples
            'personnes_informees' => ['nullable', 'array'],
            'personnes_informees.*' => ['integer', 'exists:destinataires_incident,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'uuid_client.required' => "L'identifiant de la fiche est manquant.",
            'natures.required' => "Indiquez au moins une nature d'incident.",
            'recit.required' => "Racontez ce qui s'est passé.",
            'recit.min' => 'Le récit est trop court pour être exploitable : quelques phrases suffisent.',
            'gravite.required' => 'Indiquez le niveau de gravité, de 1 à 4.',
            'gravite.between' => 'Le niveau de gravité va de 1 (mineur) à 4 (critique).',
        ];
    }
}
