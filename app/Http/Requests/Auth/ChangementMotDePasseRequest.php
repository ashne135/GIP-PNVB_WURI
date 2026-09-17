<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Changement de mot de passe, OBLIGATOIRE à la première connexion
 * (cadrage, section 6 : mot de passe initial à usage unique).
 */
class ChangementMotDePasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mot_de_passe_actuel' => ['required', 'string'],
            'nouveau_mot_de_passe' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'mot_de_passe_actuel.required' => 'Entrez votre mot de passe actuel.',
            'nouveau_mot_de_passe.required' => 'Choisissez un nouveau mot de passe.',
            'nouveau_mot_de_passe.confirmed' => 'Les deux mots de passe saisis ne sont pas identiques.',
            'nouveau_mot_de_passe.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'nouveau_mot_de_passe.letters' => 'Le nouveau mot de passe doit contenir au moins une lettre.',
            'nouveau_mot_de_passe.numbers' => 'Le nouveau mot de passe doit contenir au moins un chiffre.',
        ];
    }
}
