<?php

namespace App\Http\Requests\Auth;

use App\Support\NormalisateurTelephone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Connexion par NUMÉRO DE TÉLÉPHONE (cadrage, section 6).
 *
 * Le numéro est normalisé AVANT validation : l'agent saisit ce qu'il veut,
 * le serveur compare une forme canonique.
 */
class ConnexionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'telephone' => NormalisateurTelephone::normaliser($this->input('telephone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'telephone' => ['required', 'string', 'size:12'],
            'mot_de_passe' => ['required', 'string'],
            'nom_appareil' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** Messages en français simple : un agent de terrain doit les comprendre. */
    public function messages(): array
    {
        return [
            'telephone.required' => 'Entrez votre numéro de téléphone.',
            'telephone.size' => 'Ce numéro de téléphone n\'est pas valide. Exemple : 70 12 34 56.',
            'mot_de_passe.required' => 'Entrez votre mot de passe.',
        ];
    }

    public function attributes(): array
    {
        return [
            'telephone' => 'numéro de téléphone',
            'mot_de_passe' => 'mot de passe',
        ];
    }
}
