<?php

namespace App\Http\Requests\Tournees;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Désignation de l'opérateur qui tient un passage.
 *
 * « present » et non « required » : envoyer null DÉTACHE volontairement
 * l'opérateur — un kit sans porteur est un état réel, qu'il vaut mieux voir
 * qu'ignorer. Omettre le champ, en revanche, est une erreur d'appel : on ne
 * devine pas si l'intention était de détacher ou de ne rien changer.
 */
class ReaffecterOperateurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'affectation_operateur_id' => ['present', 'nullable', 'integer', 'exists:affectations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'affectation_operateur_id.present' => 'Indiquez l’opérateur qui tient ce passage, ou null pour le détacher.',
            'affectation_operateur_id.exists' => 'Cette affectation n’existe pas.',
        ];
    }
}
