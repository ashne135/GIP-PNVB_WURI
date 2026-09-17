<?php

namespace App\Http\Requests\Administration;

use App\Enums\RolePnvb;
use App\Services\Comptes\ServiceComptesAdministration;
use App\Support\NormalisateurTelephone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création et modification d'un compte d'administration.
 *
 * LE TÉLÉPHONE NE CHANGE PAS après création : c'est l'identifiant de connexion,
 * et le journal suit un compte par lui. Un numéro erroné se corrige en fermant
 * le compte et en en créant un autre.
 */
class CompteAdministrationRequest extends FormRequest
{
    /**
     * Le droit est vérifié AVANT la validation : sans cela, un compte sans
     * droit recevrait « champ manquant » au lieu d'un refus.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('roles.attribuer');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('telephone')) {
            $this->merge([
                'telephone_saisi' => $this->input('telephone'),
                'telephone' => NormalisateurTelephone::normaliser($this->input('telephone')) ?? $this->input('telephone'),
            ]);
        }

        if ($this->has('email')) {
            $email = trim((string) $this->input('email'));
            $this->merge(['email' => $email === '' ? null : mb_strtolower($email)]);
        }
    }

    public function rules(): array
    {
        $creation = $this->isMethod('POST');
        $compte = $this->route('compte');
        $roles = array_map(fn (RolePnvb $r) => $r->value, ServiceComptesAdministration::ROLES);

        return [
            'nom' => ['required', 'string', 'max:80'],
            'prenoms' => ['required', 'string', 'max:120'],
            'telephone' => $creation
                ? ['required', 'string', 'regex:/^\+226\d{8}$/', Rule::unique('users', 'telephone')]
                : ['prohibited'],
            'email' => [
                'nullable', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($compte?->id),
            ],
            'role' => ['required', Rule::in($roles)],
            'region_id' => [
                'nullable', 'integer', 'exists:regions,id',
                Rule::requiredIf(fn () => in_array($this->input('role'), [
                    RolePnvb::ChefAntenneRegional->value,
                    RolePnvb::ControleurTerrain->value,
                ], true)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Indiquez le nom.',
            'prenoms.required' => 'Indiquez les prénoms.',
            'telephone.required' => 'Indiquez le numéro de téléphone : c\'est l\'identifiant de connexion.',
            'telephone.regex' => 'Ce numéro n\'est pas valide : 8 chiffres attendus, par exemple 70 12 34 56.',
            'telephone.unique' => 'Ce numéro est déjà utilisé par un autre compte.',
            'telephone.prohibited' => 'Le numéro d\'un compte ne change pas : fermez ce compte et créez-en un autre.',
            'email.email' => 'Cette adresse de courriel n\'est pas valide.',
            'email.unique' => 'Cette adresse de courriel est déjà utilisée par un autre compte.',
            'role.required' => 'Choisissez un rôle.',
            'role.in' => 'Ce rôle ne s\'attribue pas depuis cet écran.',
            'region_id.required' => 'Un chef d\'antenne ou un contrôleur terrain doit être rattaché à une région.',
            'region_id.exists' => 'Cette région est introuvable.',
        ];
    }
}
