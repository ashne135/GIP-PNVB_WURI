<?php

namespace App\Services\Sync;

use App\Models\User;

/**
 * Un type de donnée que le mobile peut remonter hors ligne.
 *
 * Chaque type apporte trois choses, et rien d'autre :
 *   - sa CLÉ, celle que le téléphone met dans le champ « type » ;
 *   - ses RÈGLES de validation, appliquées avant tout accès à la base ;
 *   - son TRAITEMENT, qui délègue au service métier existant.
 *
 * RÈGLE STRUCTURANTE : un gestionnaire ne réimplémente JAMAIS une règle métier.
 * Il appelle le même service que le contrôleur HTTP. Sans cela, la
 * synchronisation deviendrait une porte dérobée où les règles seraient plus
 * faibles qu'en ligne — exactement ce que le cadrage interdit quand il dit de
 * ne jamais remplacer un contrôle serveur par un contrôle d'interface.
 */
abstract class TypeSynchronisable
{
    /** La clé attendue dans le champ « type » de chaque élément. */
    abstract public function cle(): string;

    /** Règles de validation des données de l'élément. */
    abstract public function regles(): array;

    /**
     * Applique l'élément. Le service métier appelé lève une DomainException si
     * une règle n'est pas satisfaite ; l'orchestrateur la transforme en rejet
     * motivé, sans faire échouer le reste du lot.
     *
     * @return array{id: int|null, action: string}
     */
    abstract public function traiter(User $auteur, array $donnees): array;

    /** Messages de validation en français, comme partout ailleurs. */
    public function messages(): array
    {
        return [];
    }

    /**
     * La permission requise, le cas échéant. Le droit est vérifié AVANT le
     * traitement : un lot ne contourne pas les Policies.
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * Ce type exige-t-il un uuid client ? Les relevés de position, produits en
     * continu et jamais restitués, font exception : leur idempotence tient à
     * l'unicité de l'horodatage, pas à un identifiant porté par le téléphone.
     */
    public function exigeUuidClient(): bool
    {
        return true;
    }
}
