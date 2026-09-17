<?php

namespace App\Http\Requests\Volontaires;

use App\Enums\NiveauEtude;
use App\Support\NormalisateurTelephone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correction d'une fiche de volontaire.
 *
 * NI LA CATÉGORIE NI LE MATRICULE : la première est étanche, le second figure
 * sur des documents déjà remis. Les deux sont refusés explicitement, pour que
 * l'écran reçoive une phrase et non un silence.
 */
class ModifierVolontaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('telephone')) {
            $this->merge([
                'telephone' => NormalisateurTelephone::normaliser($this->input('telephone'))
                    ?? $this->input('telephone'),
            ]);
        }

        if ($this->has('email')) {
            $email = trim((string) $this->input('email'));
            $this->merge(['email' => $email === '' ? null : mb_strtolower($email)]);
        }
    }

    public function rules(): array
    {
        $volontaire = $this->route('volontaire');
        $userId = $volontaire?->user_id;

        return [
            'nom' => ['sometimes', 'string', 'max:80'],
            'prenoms' => ['sometimes', 'string', 'max:120'],
            'telephone' => [
                'sometimes', 'string', 'regex:/^\+226\d{8}$/',
                Rule::unique('users', 'telephone')->ignore($userId),
            ],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($userId)],

            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'lieu_naissance' => ['nullable', 'string', 'max:120'],
            'numero_cnib' => ['nullable', 'string', 'max:30', Rule::unique('volontaires', 'numero_cnib')->ignore($volontaire?->id)],
            'date_etablissement_cnib' => ['nullable', 'date', 'before_or_equal:today'],

            'niveau_etude' => ['nullable', Rule::enum(NiveauEtude::class)],
            'diplome' => ['nullable', 'string', 'max:150'],

            'localite_id' => ['nullable', 'integer', 'exists:localites,id'],
            'region_origine_id' => ['nullable', 'integer', 'exists:regions,id'],

            'categorie' => ['prohibited'],
            'matricule' => ['prohibited'],
            'statut' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'telephone.regex' => 'Ce numéro n\'est pas valide : 8 chiffres attendus, par exemple 70 12 34 56.',
            'telephone.unique' => 'Ce numéro est déjà utilisé par un autre compte.',
            'email.unique' => 'Cette adresse de courriel est déjà utilisée par un autre compte.',
            'numero_cnib.unique' => 'Ce numéro de pièce d\'identité est déjà enregistré pour un autre volontaire.',
            'date_etablissement_cnib.before_or_equal' => 'La date d\'établissement de la pièce ne peut pas être dans le futur.',
            'date_naissance.before' => 'La date de naissance doit être dans le passé.',
            'categorie.prohibited' => 'Les trois catégories sont étanches : la catégorie d\'un volontaire ne change jamais.',
            'matricule.prohibited' => 'Le matricule ne change pas : il figure sur des documents déjà remis.',
            'statut.prohibited' => 'Le statut suit le cycle de vie : passez par le retrait ou la réintégration.',
        ];
    }
}
