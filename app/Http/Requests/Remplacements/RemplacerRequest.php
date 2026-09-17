<?php

namespace App\Http\Requests\Remplacements;

use App\Enums\MotifReserve;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Déclaration d'un remplacement (cadrage, section 6).
 *
 * Le MOTIF est obligatoire — c'est une règle du cadrage, pas une commodité :
 * un agent qui quitte le dispositif doit pouvoir être distingué de celui qu'on
 * écarte, six mois plus tard, quand la question se posera.
 */
class RemplacerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'affectation_id' => ['required', 'integer', 'exists:affectations,id'],
            'volontaire_entrant_id' => ['required', 'integer', 'exists:volontaires,id'],
            'motif' => [
                'required',
                Rule::in(array_map(fn (MotifReserve $m) => $m->value, MotifReserve::motifsRemplacement())),
            ],
            'commentaire' => ['nullable', 'string', 'max:1000'],

            // Exigé par le service dès que l'agent sortant détient un kit :
            // la règle dépend de l'état de la base, pas du formulaire.
            'etat_kit_constate' => ['nullable', Rule::in(['bon', 'usage', 'endommage', 'incomplet'])],
            'photo_source' => ['nullable', 'string', 'max:255'],
            'photo_destination' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'affectation_id.required' => "Choisissez l'affectation à remplacer.",
            'affectation_id.exists' => 'Cette affectation est introuvable.',
            'volontaire_entrant_id.required' => 'Choisissez le réserviste qui prend la relève.',
            'volontaire_entrant_id.exists' => 'Ce volontaire est introuvable.',
            'motif.required' => 'Indiquez le motif du remplacement : désistement, abandon, '
                .'indisponibilité ou performance.',
            'motif.in' => 'Le motif doit être : désistement, abandon, indisponibilité ou performance.',
            'etat_kit_constate.in' => "L'état du kit doit être : bon, usagé, endommagé ou incomplet.",
        ];
    }
}
