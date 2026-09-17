<?php

namespace App\Services\Sync\Types;

use App\Http\Requests\Incidents\DeclarerIncidentRequest;
use App\Models\Incident;
use App\Models\User;
use App\Services\Incidents\ServiceDeclarationIncident;
use App\Services\Sync\TypeSynchronisable;

/**
 * L'incident déclaré hors ligne.
 *
 * C'est le type qui justifie le mieux le mécanisme entier : un incident se
 * produit précisément là où le réseau manque, et souvent au pire moment. La
 * fiche doit pouvoir être remplie sur place, complètement, et partir seule dès
 * qu'une barre de réseau réapparaît.
 *
 * DEUX CONSÉQUENCES sur le traitement :
 *
 *  - l'idempotence tient à l'uuid de la fiche. Un incident rejoué ne crée pas
 *    un second numéro, et surtout NE RENOTIFIE PERSONNE : réveiller deux fois
 *    la hiérarchie pour le même fait est le plus sûr moyen de lui apprendre à
 *    ignorer le canal ;
 *
 *  - l'échéance d'escalade court à partir de l'arrivée sur le serveur, pas de
 *    l'heure du téléphone. Un incident survenu la veille dans une zone sans
 *    réseau ne doit pas être déjà « en retard de prise en charge » à la seconde
 *    où il arrive : personne n'aurait pu le prendre en charge plus tôt. L'heure
 *    des faits reste conservée dans survenu_le et horodatage_telephone.
 */
class SyncIncident extends TypeSynchronisable
{
    public function __construct(private readonly ServiceDeclarationIncident $service) {}

    public function cle(): string
    {
        return 'incident';
    }

    public function permission(): ?string
    {
        return 'incidents.declarer';
    }

    public function regles(): array
    {
        // Les règles du formulaire en ligne, telles quelles : une déclaration
        // hors ligne n'est jamais acceptée sur des critères plus larges.
        $regles = (new DeclarerIncidentRequest)->rules();
        unset($regles['uuid_client']); // exigé par le moteur lui-même

        return $regles;
    }

    public function messages(): array
    {
        return (new DeclarerIncidentRequest)->messages();
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $dejaLa = Incident::query()->where('uuid_client', $donnees['uuid_client'])->exists();

        $incident = $this->service->declarer($auteur, [...$donnees, 'canal' => 'mobile']);

        return ['id' => $incident->id, 'action' => $dejaLa ? 'existant' : 'cree'];
    }
}
