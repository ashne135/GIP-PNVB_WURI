<?php

namespace App\Http\Requests\Affectations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Déplacement d'un agent déployé, ou de l'équipe d'un centre.
 *
 * LE MOTIF EST OBLIGATOIRE, comme pour un remplacement. Un agent déplacé sans
 * motif est inexploitable plus tard : six mois après, personne ne saura si
 * c'était un renfort, une sanction ou une erreur de tirage corrigée.
 */
class DeplacerAffectationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'centre_destination_id' => ['required', 'integer', 'exists:centres,id'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'centre_destination_id.required' => 'Choisissez le centre d’accueil.',
            'centre_destination_id.exists' => 'Ce centre n’existe pas.',
            'motif.required' => 'Indiquez le motif du déplacement : il sera lu bien après votre décision.',
            'motif.min' => 'Le motif est trop court pour être compris plus tard.',
        ];
    }
}
