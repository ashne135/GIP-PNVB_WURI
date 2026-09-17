<?php

namespace App\Services\Sync\Types;

use App\Models\User;
use App\Services\Presence\ServiceRelevesPosition;
use App\Services\Sync\TypeSynchronisable;

/**
 * Les relevés de rapprochement mis en tampon par le téléphone.
 *
 * DEUX PARTICULARITÉS, toutes deux voulues par le cadrage (section 8.4) :
 *
 *  1. Un relevé ÉCARTÉ n'est pas une erreur. Hors heures de service, hors
 *     mission, sans consentement à la charte, ou trop rapproché du précédent :
 *     le serveur le jette, et c'est le comportement attendu. Le lot le compte
 *     donc comme ACCEPTÉ — traité — avec l'action « ignore » et son motif
 *     technique. Le rejeter ferait réessayer le téléphone sans fin sur une
 *     donnée que le serveur ne veut délibérément pas.
 *
 *  2. Aucun uuid client n'est exigé : ces relevés ne sont jamais restitués ni
 *     référencés. Leur idempotence tient à la règle de fréquence, qui écarte
 *     d'office un doublon rejoué.
 */
class SyncRelevePosition extends TypeSynchronisable
{
    public function __construct(private readonly ServiceRelevesPosition $service) {}

    public function cle(): string
    {
        return 'releve_position';
    }

    public function exigeUuidClient(): bool
    {
        return false;
    }

    public function regles(): array
    {
        return [
            'horodatage' => ['required', 'date'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $volontaire = $auteur->volontaire;

        if (! $volontaire) {
            throw new \DomainException("Ce compte n'est rattaché à aucune fiche de volontaire.");
        }

        $resultat = $this->service->enregistrer($volontaire, $donnees);

        return [
            'id' => null,
            // Le motif technique sert au diagnostic du client mobile ; il n'est
            // jamais présenté à l'agent, à qui ces relevés ne s'adressent pas.
            'action' => $resultat['enregistre'] ? 'cree' : 'ignore:'.$resultat['motif'],
        ];
    }
}
