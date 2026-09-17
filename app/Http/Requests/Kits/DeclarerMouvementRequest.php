<?php

namespace App\Http\Requests\Kits;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Déclaration d'un mouvement de kit.
 *
 * `volontaire_source_id` N'Y FIGURE PAS, volontairement : la source d'un
 * mouvement est le détenteur que le parc connaît, jamais celui que le client
 * annonce. L'accepter ouvrirait la porte à un journal qui raconte autre chose
 * que ce qui s'est passé.
 */
class DeclarerMouvementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uuid_client' => ['nullable', 'uuid'],
            'type' => ['required', Rule::in([
                'remise', 'changement_site', 'transfert', 'restitution', 'panne', 'perte_vol',
            ])],
            'volontaire_destination_id' => ['nullable', 'integer', 'exists:volontaires,id'],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'etat_constate' => ['nullable', Rule::in(['bon', 'usage', 'endommage', 'incomplet'])],
            'circonstance' => ['nullable', Rule::in(['perte', 'vol'])],
            'commentaire' => ['nullable', 'string', 'max:2000'],
            'photo_source' => ['nullable', 'string', 'max:255'],
            'photo_destination' => ['nullable', 'string', 'max:255'],
            // Le téléphone annonce que les photos de constat suivront : elles
            // partent après les données (cadrage, section 11.8).
            'photos_a_suivre' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'horodatage_telephone' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Précisez le type de mouvement.',
            'type.in' => 'Ce type de mouvement n\'existe pas.',
            'etat_constate.in' => "L'état constaté doit être : bon, usagé, endommagé ou incomplet.",
        ];
    }
}
