<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;

/**
 * Lecture du fichier des retenus ou de la liste d'attente (Excel ou CSV).
 *
 * LE FICHIER RÉEL EST IRRÉGULIER (cadrage v2, section 6). Ce lecteur traite
 * trois pièges, avant même toute validation métier :
 *
 *  1. LE NUMÉRO DE TÉLÉPHONE ARRIVE EN NOMBRE. Excel stocke « 70123456 » comme
 *     un nombre, et le rendre tel quel donnerait « 7.0123456E+7 » ou perdrait
 *     un zéro de tête. Il est donc lu en TEXTE, sans notation scientifique et
 *     sans séparateur de milliers. « Ne jamais le traiter comme un entier. »
 *
 *  2. LES DATES peuvent être des numéros de série Excel, du texte, ou les deux
 *     dans la même colonne selon la ligne.
 *
 *  3. L'EN-TÊTE n'est pas toujours en première ligne : un export porte souvent
 *     un titre ou une ligne vide au-dessus.
 *
 * La lecture ne juge rien : elle extrait et normalise la FORME. Le fond — un
 * numéro à 8 chiffres, une date qui n'est pas dans le futur, un doublon — est
 * l'affaire de ValidateurLigneVolontaire.
 */
class LecteurFichierVolontaires
{
    /** Nombre de lignes explorées pour trouver l'en-tête. */
    private const LIGNES_RECHERCHE_ENTETE = 12;

    /** Colonnes à lire en texte quoi qu'il arrive, jamais en nombre. */
    private const COLONNES_TEXTE = ['telephone', 'numero_cnib', 'numero_ordre'];

    /**
     * @return array{
     *     lignes: array<int, array{numero: int, donnees: array}>,
     *     entetes: array<int, string>,
     *     manquantes: string[],
     *     ligne_entete: int|null
     * }
     */
    public function lire(string $chemin): array
    {
        if (! is_file($chemin)) {
            throw new \RuntimeException("Fichier introuvable : {$chemin}");
        }

        $lecteur = IOFactory::createReaderForFile($chemin);
        $lecteur->setReadDataOnly(true);
        $feuille = $lecteur->load($chemin)->getActiveSheet();

        $brutes = [];

        foreach ($feuille->getRowIterator() as $ligne) {
            $cellules = [];

            foreach ($ligne->getCellIterator() as $cellule) {
                $cellules[] = $cellule;
            }

            $brutes[$ligne->getRowIndex()] = $cellules;
        }

        [$ligneEntete, $correspondances, $manquantes, $entetes] = $this->trouverEntete($brutes);

        if ($ligneEntete === null) {
            return [
                'lignes' => [],
                'entetes' => [],
                'manquantes' => array_keys(array_filter(
                    CanevasVolontaires::COLONNES,
                    fn ($definition) => $definition['obligatoire']
                )),
                'ligne_entete' => null,
            ];
        }

        $lignes = [];

        foreach ($brutes as $numero => $cellules) {
            if ($numero <= $ligneEntete) {
                continue;
            }

            $donnees = [];

            foreach ($correspondances as $index => $cle) {
                $donnees[$cle] = isset($cellules[$index])
                    ? $this->valeur($cellules[$index], $cle)
                    : '';
            }

            // Ligne entièrement vide : une séparation, pas une anomalie.
            if (implode('', $donnees) === '') {
                continue;
            }

            $lignes[] = ['numero' => $numero, 'donnees' => $donnees];
        }

        return [
            'lignes' => $lignes,
            'entetes' => $entetes,
            'manquantes' => $manquantes,
            'ligne_entete' => $ligneEntete,
        ];
    }

    /**
     * Cherche la ligne d'en-tête : la première qui permet de reconnaître toutes
     * les colonnes obligatoires. À défaut, celle qui en reconnaît le plus — pour
     * pouvoir dire ce qui manque, au lieu de refuser le fichier sans motif.
     */
    private function trouverEntete(array $brutes): array
    {
        $meilleure = [null, [], array_keys(CanevasVolontaires::COLONNES), []];
        $meilleurScore = -1;
        $explorees = 0;

        foreach ($brutes as $numero => $cellules) {
            if (++$explorees > self::LIGNES_RECHERCHE_ENTETE) {
                break;
            }

            $entetes = array_map(fn (Cell $c) => (string) $this->valeurBrute($c), $cellules);
            $resultat = CanevasVolontaires::reconnaitreEntetes($entetes);
            $score = count($resultat['correspondances']);

            if ($resultat['manquantes'] === [] && $score > 0) {
                return [$numero, $resultat['correspondances'], [], $entetes];
            }

            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleure = [$numero, $resultat['correspondances'], $resultat['manquantes'], $entetes];
            }
        }

        return $meilleurScore <= 0 ? [null, [], $meilleure[2], []] : $meilleure;
    }

    /** Valeur d'une cellule, normalisée selon la colonne qu'elle alimente. */
    private function valeur(Cell $cellule, string $colonne): string
    {
        $brut = $this->valeurBrute($cellule);

        if ($brut === null || $brut === '') {
            return '';
        }

        // Les dates Excel sont des numéros de série : converties en ISO.
        if (DateExcel::isDateTime($cellule) && is_numeric($brut)) {
            return DateExcel::excelToDateTimeObject((float) $brut)->format('Y-m-d');
        }

        if (in_array($colonne, self::COLONNES_TEXTE, true)) {
            return $this->enTexte($brut);
        }

        return trim((string) $brut);
    }

    private function valeurBrute(Cell $cellule): mixed
    {
        return $cellule->getDataType() === DataType::TYPE_FORMULA
            ? $cellule->getOldCalculatedValue()
            : $cellule->getValue();
    }

    /**
     * Rend un identifiant en texte, quelle que soit la façon dont Excel l'a
     * stocké.
     *
     * Un numéro saisi « 70123456 » devient un nombre dans la feuille. Le rendre
     * par un cast direct produirait « 7.0123456E+7 » dès que le nombre est
     * grand, ou « 70 123 456 » selon le format d'affichage. sprintf('%.0f')
     * force la forme entière sans séparateur ni exposant.
     *
     * Un zéro de tête, lui, est déjà perdu au moment où Excel enregistre : la
     * reconstruction est l'affaire du normalisateur de téléphone, qui sait
     * qu'un numéro burkinabè fait 8 chiffres.
     */
    private function enTexte(mixed $valeur): string
    {
        if (is_float($valeur) || is_int($valeur)) {
            return rtrim(rtrim(sprintf('%.0f', $valeur), '0'), '.') === ''
                ? '0'
                : sprintf('%.0f', $valeur);
        }

        $texte = trim((string) $valeur);

        // Une chaîne qui porte encore une notation scientifique — cas des CSV
        // réexportés depuis Excel — est ramenée à sa forme entière.
        if (preg_match('/^\d+(?:[.,]\d+)?[eE][+-]?\d+$/', $texte)) {
            return sprintf('%.0f', (float) str_replace(',', '.', $texte));
        }

        return $texte;
    }
}
