<?php

namespace App\Http\Requests\Alertes;

use App\Enums\RolePnvb;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publication d'une alerte descendante.
 *
 * LE TYPE N'EST PAS DEMANDÉ : une alerte publiée par un humain est toujours
 * « descendante ». Les autres types — escalade d'incident, kit non restitué,
 * écart de présence — appartiennent au planificateur. Les accepter ici
 * laisserait un responsable fabriquer une alerte qui ressemblerait à une
 * détection automatique.
 *
 * LA CIBLE DÉPEND DE LA PORTÉE, et c'est le serveur qui l'impose : une alerte
 * régionale sans région, ou pour un centre sans centre, ne toucherait personne
 * tout en paraissant publiée.
 */
class PublierAlerteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titre' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
            'niveau' => ['required', Rule::in(['info', 'important', 'critique'])],
            'portee' => ['required', Rule::in(['nationale', 'regionale', 'centre', 'volontaire', 'role'])],

            'region_id' => ['nullable', 'required_if:portee,regionale', 'integer', 'exists:regions,id'],
            'centre_id' => ['nullable', 'required_if:portee,centre', 'integer', 'exists:centres,id'],
            'volontaire_id' => ['nullable', 'required_if:portee,volontaire', 'integer', 'exists:volontaires,id'],
            'role_cible' => [
                'nullable',
                'required_if:portee,role',
                Rule::in(array_map(
                    fn (RolePnvb $role) => $role->value,
                    // L'acteur SYSTÈME n'est pas une personne : on ne lui écrit pas.
                    array_filter(RolePnvb::cases(), fn (RolePnvb $role) => $role !== RolePnvb::Systeme)
                )),
            ],

            // Une alerte sans échéance reste affichée indéfiniment : c'est le
            // meilleur moyen de lui faire perdre toute valeur.
            'expire_le' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'titre.required' => 'Donnez un titre à cette alerte : c’est ce que les agents liront en premier.',
            'message.required' => 'Écrivez le message de l’alerte.',
            'niveau.required' => 'Choisissez le niveau : information, important ou critique.',
            'portee.required' => 'Choisissez qui doit recevoir cette alerte.',
            'region_id.required_if' => 'Choisissez la région destinataire.',
            'centre_id.required_if' => 'Choisissez le centre destinataire.',
            'volontaire_id.required_if' => 'Choisissez l’agent destinataire.',
            'role_cible.required_if' => 'Choisissez le rôle destinataire.',
            'expire_le.after' => 'L’échéance doit être dans le futur : une alerte déjà expirée ne s’afficherait jamais.',
        ];
    }
}
