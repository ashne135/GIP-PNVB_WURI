<?php

namespace App\Http\Requests\Referentiel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creation = $this->isMethod('POST');

        return [
            'centre_id' => [$creation ? 'required' : 'prohibited', 'integer', 'exists:centres,id'],
            'localite_id' => [$creation ? 'required' : 'sometimes', 'integer', 'exists:localites,id'],
            'nom' => [$creation ? 'required' : 'sometimes', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'rayon_zone_metres' => ['sometimes', 'integer', 'min:50', 'max:5000'],
            'ordre_tournee' => ['sometimes', 'integer', 'min:1'],
            'statut' => ['sometimes', Rule::in(['planifie', 'ouvert', 'couvert', 'ferme'])],
        ];
    }

    public function messages(): array
    {
        return [
            'centre_id.required' => 'Choisissez le centre auquel rattacher ce site.',
            'centre_id.prohibited' => 'Le centre d\'un site ne peut pas changer : son code en dépend.',
            'localite_id.required' => 'Choisissez la localité du site : '
                .'c\'est sa population qui sert de dénominateur au taux de couverture.',
            'nom.required' => 'Donnez un nom au site.',
            'rayon_zone_metres.min' => 'Le rayon de la zone doit valoir au moins 50 mètres.',
            'rayon_zone_metres.max' => 'Le rayon de la zone ne peut pas dépasser 5 000 mètres.',
        ];
    }
}
