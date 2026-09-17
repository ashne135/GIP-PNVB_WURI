<?php

namespace App\Services\Sync\Types;

use App\Enums\TypeMouvementKit;
use App\Http\Requests\Kits\DeclarerMouvementRequest;
use App\Models\Kit;
use App\Models\KitMouvement;
use App\Models\User;
use App\Services\Kits\ServiceMouvementsKit;
use App\Services\Sync\TypeSynchronisable;
use Illuminate\Support\Facades\Gate;

/**
 * Le mouvement de kit déclaré hors ligne.
 *
 * C'est le geste le plus physique de toute la plateforme : deux agents se
 * passent une mallette sur un site sans réseau, constatent son état et se
 * photographient mutuellement. La saisie doit suivre le geste, pas l'inverse.
 *
 * Le kit est désigné par sa RÉFÉRENCE, pas par son identifiant : c'est ce qui
 * est écrit sur la mallette, et le téléphone peut l'avoir lu sans jamais avoir
 * synchronisé le parc.
 *
 * Le type de mouvement s'appelle ici « type_mouvement » et non « type » : dans
 * un élément de lot, « type » désigne déjà le type synchronisable.
 */
class SyncMouvementKit extends TypeSynchronisable
{
    public function __construct(private readonly ServiceMouvementsKit $service) {}

    public function cle(): string
    {
        return 'mouvement_kit';
    }

    public function permission(): ?string
    {
        return 'kits.declarer_mouvement';
    }

    public function regles(): array
    {
        $regles = (new DeclarerMouvementRequest)->rules();

        // « uuid_client » est exigé par le moteur lui-même.
        unset($regles['uuid_client']);

        // COLLISION DE NOMS À ÉVITER : dans un élément de lot, la clé « type »
        // désigne déjà le type SYNCHRONISABLE — c'est elle qui aiguille vers ce
        // gestionnaire. Le type de MOUVEMENT prend donc un autre nom, sinon
        // l'un écraserait l'autre et aucun des deux ne serait lisible.
        $regleType = $regles['type'];
        unset($regles['type']);

        return $regles + [
            'type_mouvement' => $regleType,
            'kit_reference' => ['required', 'string', 'max:30', 'exists:kits,reference'],
        ];
    }

    public function messages(): array
    {
        return [
            'type_mouvement.required' => 'Précisez le type de mouvement.',
            'type_mouvement.in' => "Ce type de mouvement n'existe pas.",
            'kit_reference.required' => 'Indiquez la référence inscrite sur le kit.',
            'kit_reference.exists' => 'Aucun kit ne porte cette référence.',
            'etat_constate.in' => "L'état constaté doit être : bon, usagé, endommagé ou incomplet.",
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $dejaLa = KitMouvement::query()->where('uuid_client', $donnees['uuid_client'])->exists();

        $kit = Kit::query()->where('reference', $donnees['kit_reference'])->firstOrFail();

        if (Gate::forUser($auteur)->denies('declarerMouvement', $kit)) {
            throw new SyncDroitRefuse(
                "Le kit {$kit->reference} n'est pas dans votre périmètre."
            );
        }

        $mouvement = $this->service->declarer(
            $kit,
            TypeMouvementKit::from($donnees['type_mouvement']),
            $auteur,
            $donnees
        );

        return ['id' => $mouvement->id, 'action' => $dejaLa ? 'existant' : 'cree'];
    }
}
