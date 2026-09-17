<?php

namespace App\Http\Requests\Vagues;

use Illuminate\Foundation\Http\FormRequest;

class PlanifierVagueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:160'],
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            'date_debut_prevue' => ['required', 'date'],
            'date_fin_prevue' => ['required', 'date', 'after:date_debut_prevue'],
            'centres' => ['required', 'array', 'min:1'],
            'centres.*' => ['integer', 'exists:centres,id'],
            // Sans objectif, l'écart du rapport OPK ne peut pas être calculé
            // (cadrage v2, section 9.2). Il reste facultatif : une vague peut
            // être planifiée avant que l'objectif soit arbitré.
            'objectif_enregistrements_par_kit_jour' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'libelle.required' => 'Donnez un nom à cette vague.',
            'region_id.required' => 'Choisissez la région de déploiement.',
            'region_id.exists' => 'Cette région est introuvable.',
            'date_debut_prevue.required' => 'Indiquez la date de début.',
            'date_fin_prevue.required' => 'Indiquez la date de fin.',
            'date_fin_prevue.after' => 'La date de fin doit être postérieure à la date de début.',
            'centres.required' => 'Choisissez au moins un centre à ouvrir.',
            'centres.min' => 'Choisissez au moins un centre à ouvrir.',
        ];
    }
}
