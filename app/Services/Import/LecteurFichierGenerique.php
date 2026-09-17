<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;

/**
 * Lecture d'un fichier Excel ou CSV pilotée par un canevas.
 *
 * Même mécanique que le lecteur des volontaires — en-tête cherché dans les
 * premières lignes, reconnaissance souple des intitulés — mais le canevas est
 * passé en paramètre. Les référentiels à importer se multiplient (centres et
 * sites aujourd'hui, kits demain) et n'ont pas à dupliquer cette plomberie.
 */
class LecteurFichierGenerique
{
    private const LIGNES_RECHERCHE_ENTETE = 12;

    /**
     * @param  class-string  $canevas  Classe exposant reconnaitreEntetes() et COLONNES.
     * @return array{lignes: array, entetes: array, manquantes: array, ligne_entete: int|null}
     */
    public function lire(string $chemin, string $canevas): array
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

        [$ligneEntete, $correspondances, $manquantes, $entetes] = $this->trouverEntete($brutes, $canevas);

        if ($ligneEntete === null) {
            return [
                'lignes' => [],
                'entetes' => [],
                'manquantes' => array_keys(array_filter(
                    $canevas::COLONNES,
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
                $donnees[$cle] = isset($cellules[$index]) ? $this->valeur($cellules[$index]) : '';
            }

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

    private function trouverEntete(array $brutes, string $canevas): array
    {
        $meilleure = [null, [], array_keys($canevas::COLONNES), []];
        $meilleurScore = -1;
        $explorees = 0;

        foreach ($brutes as $numero => $cellules) {
            if (++$explorees > self::LIGNES_RECHERCHE_ENTETE) {
                break;
            }

            $entetes = array_map(fn (Cell $c) => (string) $this->valeur($c), $cellules);
            $resultat = $canevas::reconnaitreEntetes($entetes);
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

    private function valeur(Cell $cellule): string
    {
        $brut = $cellule->getDataType() === DataType::TYPE_FORMULA
            ? $cellule->getOldCalculatedValue()
            : $cellule->getValue();

        if ($brut === null) {
            return '';
        }

        if (DateExcel::isDateTime($cellule) && is_numeric($brut)) {
            return DateExcel::excelToDateTimeObject((float) $brut)->format('Y-m-d');
        }

        return trim((string) $brut);
    }
}
