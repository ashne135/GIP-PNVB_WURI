<?php

namespace App\Http\Requests\Volontaires;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attribution des profils EN LOT (cadrage v2, section 6).
 *
 * « L'administrateur national dispose d'un écran pour attribuer les profils en
 * lot avant affectation. »
 */
class QualifierEnLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qualifications' => ['required', 'array', 'min:1', 'max:500'],
            'qualifications.*.volontaire_id' => ['required', 'integer', 'exists:volontaires,id'],
            'qualifications.*.categorie' => ['required', Rule::in(['superviseur', 'operateur', 'assistant'])],
            // Obligatoire pour un A-OPK, ignorée pour les deux autres : la règle
            // dépend de la catégorie de CHAQUE ligne, elle est donc vérifiée par
            // le modèle plutôt que par une règle de validation globale.
            'qualifications.*.localite_id' => ['nullable', 'integer', 'exists:localites,id'],
            // La DÉROGATION au niveau d'étude exigé : facultative, mais elle
            // doit dire pourquoi. Elle reste inscrite sur la fiche.
            'qualifications.*.motif_derogation' => ['nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'qualifications.required' => 'Choisissez au moins une fiche à qualifier.',
            'qualifications.max' => 'Vous ne pouvez pas qualifier plus de 500 fiches à la fois.',
            'qualifications.*.volontaire_id.exists' => 'Une des fiches sélectionnées est introuvable.',
            'qualifications.*.categorie.required' => 'Choisissez un profil pour chaque fiche.',
            'qualifications.*.categorie.in' => 'Le profil doit être superviseur, operateur ou assistant.',
            'qualifications.*.localite_id.exists' => 'La localité choisie est introuvable.',
            'qualifications.*.motif_derogation.min' => 'Une dérogation au niveau d\'étude exigé doit être expliquée.',
        ];
    }
}
