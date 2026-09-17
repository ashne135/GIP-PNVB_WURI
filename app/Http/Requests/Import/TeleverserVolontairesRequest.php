<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeleverserVolontairesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fichier' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
            'type' => ['required', Rule::in(['volontaires_retenus', 'volontaires_reserve'])],
            'mode' => ['required', Rule::in(['completer', 'remplacer'])],
        ];
    }

    public function messages(): array
    {
        return [
            'fichier.required' => 'Choisissez le fichier à importer.',
            'fichier.mimes' => 'Le fichier doit être un classeur Excel (.xlsx, .xls) ou un fichier CSV.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'type.required' => "Précisez s'il s'agit des retenus ou de la liste d'attente.",
            'type.in' => "Le type doit être « volontaires_retenus » ou « volontaires_reserve ».",
            'mode.required' => 'Choisissez le mode : compléter les données existantes, ou remplacer le jeu de démonstration.',
            'mode.in' => 'Le mode doit être « completer » ou « remplacer ».',
        ];
    }
}
