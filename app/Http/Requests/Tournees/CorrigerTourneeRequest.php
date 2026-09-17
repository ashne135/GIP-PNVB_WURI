<?php

namespace App\Http\Requests\Tournees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correction d'un passage de kit sur un site.
 *
 * TOUS LES CHAMPS SONT « sometimes » : on corrige ce qu'on veut corriger, et
 * ce qui n'est pas envoyé ne bouge pas. Le service distingue « champ absent »
 * de « champ à null » — c'est ainsi qu'on peut rouvrir un passage sans date de
 * fin sans l'effacer par mégarde à chaque enregistrement.
 *
 * Les règles de métier — site du même centre, feuille déjà validée, vague
 * clôturée — ne sont PAS ici : elles appartiennent au service, qui les applique
 * aussi aux appels internes.
 */
class CorrigerTourneeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'date_debut' => ['sometimes', 'date'],
            'date_fin' => ['sometimes', 'nullable', 'date'],
            'ordre' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'statut' => ['sometimes', Rule::in(['planifiee', 'en_cours', 'terminee', 'reportee'])],
        ];
    }

    public function messages(): array
    {
        return [
            'site_id.exists' => 'Ce site n’existe pas.',
            'date_debut.date' => 'La date de début n’est pas une date valide.',
            'date_fin.date' => 'La date de fin n’est pas une date valide.',
            'ordre.min' => 'Le rang dans la tournée commence à 1.',
            'statut.in' => 'Statut inconnu : planifiée, en cours, terminée ou reportée.',
        ];
    }
}
