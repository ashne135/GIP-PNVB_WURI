<?php

namespace App\Http\Requests\Tournees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Programmer un passage de kit sur un site (décision du client, 18/09/2026 :
 * les passages se saisissent, ils ne se génèrent pas).
 *
 * LE CENTRE N'EST PAS DEMANDÉ : il découle du site. Le saisir séparément
 * permettrait de rattacher un passage à un centre qui n'est pas celui du site,
 * et casserait la tournée comme le parc de kits.
 *
 * Les règles de métier — centre ouvert dans la vague, site déjà couvert,
 * opérateur du bon centre — appartiennent au service, qui les applique aussi
 * aux appels internes.
 */
class ProgrammerTourneeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vague_id' => ['required', 'integer', 'exists:vagues_deploiement,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'affectation_operateur_id' => ['nullable', 'integer', 'exists:affectations,id'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['nullable', 'date'],
            'ordre' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'statut' => ['nullable', Rule::in(['planifiee', 'en_cours', 'terminee', 'reportee'])],
        ];
    }

    public function messages(): array
    {
        return [
            'vague_id.required' => 'Choisissez la vague de ce passage.',
            'vague_id.exists' => 'Cette vague n’existe pas.',
            'site_id.required' => 'Choisissez le site où le kit passe.',
            'site_id.exists' => 'Ce site n’existe pas.',
            'affectation_operateur_id.exists' => 'Cet opérateur n’est pas affecté à cette vague.',
            'date_debut.required' => 'Indiquez le premier jour du passage.',
            'date_debut.date' => 'La date de début n’est pas une date valide.',
            'date_fin.date' => 'La date de fin n’est pas une date valide.',
            'ordre.min' => 'Le rang dans la tournée commence à 1.',
            'statut.in' => 'Statut inconnu : planifiée, en cours, terminée ou reportée.',
        ];
    }
}
