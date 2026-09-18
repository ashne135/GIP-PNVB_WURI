<?php

namespace App\Http\Requests\Referentiel;

use App\Models\Parametre;
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
            // PLUS DE PLAFOND MÉTIER À 2 (décision du client, 18/09/2026) : un
            // centre peut recevoir autant de kits que le Programme en déploie.
            // La borne qui reste est TECHNIQUE — la colonne est un entier d'un
            // octet — et elle est dans un paramètre, pas dans ce fichier, pour
            // que le Programme puisse se fixer un plafond sans redéploiement.
            'nombre_kits' => ['sometimes', 'integer', 'min:1', 'max:'.Parametre::entier('affectation.kits_par_centre_max', 255)],
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
            'nombre_kits.max' => 'Le nombre de kits dépasse le plafond fixé en paramètres '
                .'(affectation.kits_par_centre_max).',
            'nombre_kits.min' => 'Un centre a au moins un kit.',
            'latitude.between' => 'La latitude doit être comprise entre -90 et 90.',
            'longitude.between' => 'La longitude doit être comprise entre -180 et 180.',
        ];
    }
}
