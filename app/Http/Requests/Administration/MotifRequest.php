<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

/** Un acte sur un compte d'administration qui doit dire pourquoi : fermeture, réouverture. */
class MotifRequest extends FormRequest
{
    /**
     * Le droit est vérifié AVANT la validation : sans cela, un compte sans
     * droit recevrait « champ manquant » au lieu d'un refus.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('roles.attribuer');
    }

    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'motif.required' => 'Indiquez le motif : il figurera au journal.',
            'motif.min' => 'Le motif est trop court : quelques mots suffisent, mais ils doivent dire pourquoi.',
        ];
    }
}
