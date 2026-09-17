<?php

namespace App\Services\Exports;

/**
 * UNE SOURCE D'EXPORT : ce qu'un fichier contient, et comment on l'obtient.
 *
 * Chaque source répond à trois questions et rien de plus : comment elle
 * s'appelle, quelles colonnes elle écrit, et quelles lignes elle rend pour un
 * périmètre et une période. Le service de production, lui, ne sait rien du
 * métier : il enveloppe, écrit le fichier et range la ligne en base.
 *
 * C'est ce qui permet d'ajouter un contenu par une classe, sans toucher ni au
 * planificateur, ni au contrôleur, ni à l'écran — le même principe que le
 * registre de synchronisation.
 */
interface SourceExport
{
    /** La clé stockée en base : rapports, presences, incidents… */
    public function cle(): string;

    /** L'intitulé porté par le fichier, lisible par qui l'ouvre. */
    public function libelle(): string;

    /** @return array<int, string> */
    public function colonnes(): array;

    /**
     * Les lignes du fichier. `null` en région signifie « tout le pays ».
     *
     * @return iterable<int, array<int, string|int|float|null>>
     */
    public function lignes(?int $regionId, string $du, string $au): iterable;
}
