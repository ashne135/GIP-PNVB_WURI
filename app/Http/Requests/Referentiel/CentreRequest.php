<?php

namespace App\Http\Requests\Referentiel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CentreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creation = $this->isMethod('POST');

        return [
            // La commune ne change jamais : elle détermine le code, et le code
            // ne change jamais non plus.
            'commune_id' => [$creation ? 'required' : 'prohibited', 'integer', 'exists:communes,id'],
            'nom' => [$creation ? 'required' : 'sometimes', 'string', 'max:120'],
            'nombre_kits' => ['sometimes', 'integer', 'min:1', 'max:2'],
            'est_permanent' => ['sometimes', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'statut' => ['sometimes', Rule::in(['planifie', 'ouvert', 'ferme'])],
        ];
    }

    public function messages(): array
    {
        return [
            'commune_id.required' => 'Choisissez la commune du centre.',
            'commune_id.prohibited' => 'La commune d\'un centre ne peut pas changer : '
                .'son code en dépend, et les codes sont définitifs.',
            'commune_id.exists' => 'Cette commune est introuvable.',
            'nom.required' => 'Donnez un nom au centre.',
            'nombre_kits.max' => 'Un centre dispose de 1 ou 2 kits.',
            'nombre_kits.min' => 'Un centre dispose de 1 ou 2 kits.',
            'latitude.between' => 'La latitude doit être comprise entre -90 et 90.',
            'longitude.between' => 'La longitude doit être comprise entre -180 et 180.',
        ];
    }
}
