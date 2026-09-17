<?php

namespace App\Services\Referentiel;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Lecture du classeur hiérarchique du référentiel territorial.
 *
 * Feuille unique, avec des lignes de rupture :
 *
 *   REGION: BANKUI
 *   PROVINCE: BALE
 *   "COMMUNE: BAGASSI - Rural"
 *   Assio            748    801
 *
 * Colonnes : A nom, B hommes, C femmes, D ensemble, E nombre de sites.
 *
 * PARTICULARITÉS DU FICHIER FOURNI, toutes vérifiées ligne à ligne :
 *
 *  1. Les colonnes D et E sont des FORMULES dont Excel n'a pas conservé le
 *     résultat : D n'est lisible que sur 360 lignes, E sur 556 lignes de
 *     localité sur 7 433. Le total est donc recalculé (hommes + femmes) et le
 *     quota de sites aussi (voir RepartiteurSites).
 *
 *  2. 37 chefs-lieux sont écrits sur TROIS lignes : une ligne de total sans
 *     mention de type, puis « X - Rural » et « X - Urbain ». La ligne de total
 *     reprend la population de ses deux composantes : la retenir créerait un
 *     double comptage. Elle est donc ignorée, et signalée.
 *
 *  3. Deux provinces n'ont pas de lignes COMMUNE mais des lignes
 *     « ARRONDISSEMENT n » : Kadiogo (12) et Houet (7). Ces arrondissements
 *     tiennent lieu d'unité communale et sont traités comme telles.
 *
 *  4. Coquille dans le fichier : « PROVINVE: BOUGOURIBA » au lieu de PROVINCE.
 *     Tolérée, et signalée.
 *
 *  5. La population portée par une ligne COMMUNE diffère parfois de la somme de
 *     ses localités. L'écart est conservé, jamais lissé.
 *
 * La lecture se fait en DEUX PASSES : extraction des lignes brutes, puis
 * construction de la hiérarchie. La deuxième passe a besoin de regarder la ligne
 * suivante pour reconnaître une ligne de total — ce qu'un simple itérateur ne
 * permet pas.
 */
class LecteurReferentielTerritorial
{
    private const LIGNES_IGNOREES = [
        'LOCALITES', 'COLONNE1', 'COLONNE2', 'COLONNE3', 'COLONNE4', 'COLONNE5',
        'BURKINA FASO', 'TOTAL GENERAL', 'HOMMES', 'FEMMES', 'ENSEMBLE',
    ];

    public function __construct(private readonly CodificateurTerritorial $codificateur)
    {
    }

    /**
     * @return array{regions: array, anomalies: array, compteurs: array}
     */
    public function lire(string $chemin): array
    {
        if (! is_file($chemin)) {
            throw new \RuntimeException("Fichier de référentiel introuvable : {$chemin}");
        }

        return $this->construireHierarchie($this->extraireLignes($chemin));
    }

    // ------------------------------------------------------------------
    // Passe 1 : extraction brute
    // ------------------------------------------------------------------

    /** @return array<int, array{numero: int, libelle: string, hommes: int, femmes: int, sites: int}> */
    private function extraireLignes(string $chemin): array
    {
        $lecteur = IOFactory::createReaderForFile($chemin);
        $lecteur->setReadDataOnly(true);
        $lecteur->setReadFilter(new class implements IReadFilter
        {
            public function readCell($colonne, $ligne, $feuille = ''): bool
            {
                return in_array($colonne, ['A', 'B', 'C', 'D', 'E'], true);
            }
        });

        $feuille = $lecteur->load($chemin)->getActiveSheet();
        $lignes = [];

        foreach ($feuille->getRowIterator() as $ligne) {
            $numero = $ligne->getRowIndex();
            $libelle = trim((string) $this->valeur($feuille->getCell('A'.$numero)));

            if ($libelle === '') {
                continue;
            }

            $lignes[] = [
                'numero' => $numero,
                'libelle' => $this->nettoyerLibelle($libelle),
                'hommes' => $this->entier($feuille->getCell('B'.$numero)),
                'femmes' => $this->entier($feuille->getCell('C'.$numero)),
                'sites' => $this->entier($feuille->getCell('E'.$numero)),
            ];
        }

        return $lignes;
    }

    // ------------------------------------------------------------------
    // Passe 2 : construction de la hiérarchie
    // ------------------------------------------------------------------

    private function construireHierarchie(array $lignes): array
    {
        $regions = [];
        $anomalies = [];
        $compteurs = [
            'regions' => 0, 'provinces' => 0, 'communes' => 0, 'arrondissements' => 0,
            'localites' => 0, 'lignes_total_ignorees' => 0,
        ];

        $cleRegion = null;
        $cleProvince = null;
        $cleCommune = null;
        $nomProvinceCourante = null;

        foreach ($lignes as $index => $ligne) {
            $libelle = $ligne['libelle'];
            $numero = $ligne['numero'];
            $normalise = CodificateurTerritorial::normaliser($libelle);

            if (in_array($normalise, self::LIGNES_IGNOREES, true)) {
                continue;
            }

            // ---------- RÉGION ----------
            if (str_starts_with($normalise, 'REGION')) {
                $nomRegion = $this->apresDeuxPoints($libelle);
                $code = $this->codificateur->codeRegion($nomRegion);

                if ($code === null) {
                    $anomalies[] = "Ligne {$numero} : région « {$nomRegion} » inconnue du cadrage, ignorée.";
                    $cleRegion = $cleProvince = $cleCommune = null;

                    continue;
                }

                $cleRegion = $code;
                $cleProvince = $cleCommune = null;

                $regions[$code] ??= [
                    'code' => $code,
                    'nom' => $this->codificateur->nomAffichageRegion($nomRegion),
                    'sites_declares' => $ligne['sites'] ?: $this->codificateur->nombreSitesRegion($nomRegion),
                    'provinces' => [],
                ];
                $compteurs['regions']++;

                continue;
            }

            // ---------- PROVINCE (tolère la coquille « PROVINVE ») ----------
            if (preg_match('/^PROVIN[CV]E/', $normalise)) {
                if (str_starts_with($normalise, 'PROVINVE')) {
                    $anomalies[] = "Ligne {$numero} : « PROVINVE » au lieu de « PROVINCE » dans le fichier source, corrigé à la lecture.";
                }

                if ($cleRegion === null) {
                    $anomalies[] = "Ligne {$numero} : province rencontrée hors d'une région.";

                    continue;
                }

                $nomProvinceCourante = $this->apresDeuxPoints($libelle);
                $cleProvince = CodificateurTerritorial::normaliser($nomProvinceCourante);
                $cleCommune = null;

                $regions[$cleRegion]['provinces'][$cleProvince] ??= [
                    'nom' => $this->enCasseDeTitre($nomProvinceCourante),
                    'code' => mb_substr(preg_replace('/[^A-Z0-9]/', '', $cleProvince) ?: 'XXX', 0, 8),
                    'communes' => [],
                ];
                $compteurs['provinces']++;

                continue;
            }

            // ---------- ARRONDISSEMENT : tient lieu de commune ----------
            if (str_starts_with($normalise, 'ARRONDISSEMENT')) {
                if ($cleRegion === null || $cleProvince === null) {
                    $anomalies[] = "Ligne {$numero} : arrondissement rencontré hors d'une province.";

                    continue;
                }

                $nomCommune = $this->codificateur->nomArrondissement($cleProvince, $libelle);
                $cleCommune = CodificateurTerritorial::normaliser($nomCommune);

                $regions[$cleRegion]['provinces'][$cleProvince]['communes'][$cleCommune] ??= [
                    'nom' => $nomCommune,
                    'type' => 'urbaine',
                    'population_hommes_declares' => $ligne['hommes'],
                    'population_femmes_declarees' => $ligne['femmes'],
                    'population_declaree' => $ligne['hommes'] + $ligne['femmes'],
                    'localites' => [],
                ];
                $compteurs['arrondissements']++;
                $compteurs['communes']++;

                continue;
            }

            // ---------- COMMUNE ----------
            if (str_starts_with($normalise, 'COMMUNE')) {
                if ($cleRegion === null || $cleProvince === null) {
                    $anomalies[] = "Ligne {$numero} : commune rencontrée hors d'une province.";

                    continue;
                }

                $contenu = $this->apresDeuxPoints($libelle);
                [$nomCommune, $type] = $this->separerNomEtType($contenu);

                // Ligne de TOTAL : un chef-lieu sans mention de type, suivi de ses
                // deux composantes « - Rural » et « - Urbain ». La retenir
                // reviendrait à compter sa population deux fois.
                if ($type === null && $this->estLigneDeTotal($lignes, $index, $nomCommune)) {
                    $compteurs['lignes_total_ignorees']++;

                    continue;
                }

                if ($type === null) {
                    $type = 'rurale';
                    $anomalies[] = "Ligne {$numero} : commune « {$nomCommune} » sans mention "
                        ."Urbain ou Rural, classée rurale par défaut.";
                }

                $cleCommune = CodificateurTerritorial::normaliser($nomCommune).'|'.$type;

                // Deux lignes COMMUNE de même nom et même type dans la même
                // province : ce n'est pas un cas prévu par la structure du
                // fichier (une seule ligne « - Rural » et une seule « - Urbain »
                // par chef-lieu). Repéré sur Kongoussi (Koulsé), où la partie
                // urbaine — des localités « Secteur 1 » à « Secteur 7 » — est
                // étiquetée « - Rural » par erreur dans le fichier source. Les
                // deux jeux de localités sont conservés et cumulés : aucune
                // population n'est perdue, seule la répartition Rural/Urbain de
                // cette commune est à vérifier auprès du client.
                $communeDejaVue = isset(
                    $regions[$cleRegion]['provinces'][$cleProvince]['communes'][$cleCommune]
                );

                if ($communeDejaVue) {
                    $anomalies[] = "Ligne {$numero} : deuxième ligne « {$libelle} » pour la commune "
                        ."« {$nomCommune} », déjà rencontrée avec le même type ({$type}) — probable erreur "
                        ."d'étiquetage Rural/Urbain dans le fichier source. Les localités des deux lignes "
                        ."sont conservées et cumulées sous cette même commune.";
                } else {
                    $compteurs['communes']++;
                }

                $regions[$cleRegion]['provinces'][$cleProvince]['communes'][$cleCommune] ??= [
                    'nom' => $this->nomCommuneAffiche($nomCommune, $type),
                    'type' => $type,
                    'population_hommes_declares' => $ligne['hommes'],
                    'population_femmes_declarees' => $ligne['femmes'],
                    'population_declaree' => $ligne['hommes'] + $ligne['femmes'],
                    'localites' => [],
                ];

                continue;
            }

            // ---------- LOCALITÉ ----------
            if ($cleRegion === null || $cleProvince === null || $cleCommune === null) {
                $anomalies[] = "Ligne {$numero} : localité « {$libelle} » rencontrée hors d'une commune.";

                continue;
            }

            if ($ligne['hommes'] === 0 && $ligne['femmes'] === 0) {
                $anomalies[] = "Ligne {$numero} : localité « {$libelle} » sans population, ignorée.";

                continue;
            }

            $cleLocalite = CodificateurTerritorial::normaliser($libelle);
            $commune = &$regions[$cleRegion]['provinces'][$cleProvince]['communes'][$cleCommune];

            if (isset($commune['localites'][$cleLocalite])) {
                $anomalies[] = "Ligne {$numero} : localité « {$libelle} » en double dans "
                    ."{$commune['nom']}, populations cumulées.";
                $commune['localites'][$cleLocalite]['population_hommes'] += $ligne['hommes'];
                $commune['localites'][$cleLocalite]['population_femmes'] += $ligne['femmes'];
                unset($commune);

                continue;
            }

            $commune['localites'][$cleLocalite] = [
                'nom' => $this->enCasseDeTitre($libelle),
                'type' => $this->deduireTypeLocalite($libelle, $commune['type']),
                'population_hommes' => $ligne['hommes'],
                'population_femmes' => $ligne['femmes'],
            ];
            $compteurs['localites']++;
            unset($commune);
        }

        return ['regions' => $regions, 'anomalies' => $anomalies, 'compteurs' => $compteurs];
    }

    /**
     * Une ligne COMMUNE sans mention de type est une ligne de total si l'une des
     * deux lignes suivantes est une COMMUNE portant le même nom de base.
     */
    private function estLigneDeTotal(array $lignes, int $index, string $nomCommune): bool
    {
        $base = CodificateurTerritorial::normaliser($nomCommune);

        foreach ([1, 2] as $decalage) {
            $suivante = $lignes[$index + $decalage] ?? null;

            if ($suivante === null) {
                continue;
            }

            $normalise = CodificateurTerritorial::normaliser($suivante['libelle']);

            if (! str_starts_with($normalise, 'COMMUNE')) {
                continue;
            }

            [$nomSuivant] = $this->separerNomEtType($this->apresDeuxPoints($suivante['libelle']));

            if (CodificateurTerritorial::normaliser($nomSuivant) === $base) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Utilitaires de lecture
    // ------------------------------------------------------------------

    /**
     * Valeur d'une cellule. Quand c'est une formule, on prend le résultat mis en
     * cache par Excel plutôt que de recalculer : recalculer des milliers de
     * formules serait long, et la plupart n'ont pas de résultat exploitable.
     */
    private function valeur(Cell $cellule): mixed
    {
        if ($cellule->getDataType() === DataType::TYPE_FORMULA) {
            return $cellule->getOldCalculatedValue();
        }

        return $cellule->getValue();
    }

    private function entier(Cell $cellule): int
    {
        $valeur = $this->valeur($cellule);

        if ($valeur === null || $valeur === '') {
            return 0;
        }

        return (int) round((float) str_replace([' ', ','], ['', '.'], (string) $valeur));
    }

    /** Retire guillemets, astérisques de renvoi et espaces surnuméraires. */
    private function nettoyerLibelle(string $libelle): string
    {
        $libelle = trim($libelle, " \t\n\r\0\x0B\"'");
        $libelle = str_replace('**', '', $libelle);

        return trim(preg_replace('/\s+/', ' ', $libelle) ?? $libelle);
    }

    private function apresDeuxPoints(string $libelle): string
    {
        $position = mb_strpos($libelle, ':');

        return $position === false ? trim($libelle) : trim(mb_substr($libelle, $position + 1));
    }

    /**
     * « BAGASSI - Rural » donne ['Bagassi', 'rurale'].
     * Le tiret est facultatif : le fichier contient « NIANKORODOUGOU Rural ».
     * Sans mention, le type vaut null — l'appelant décide quoi en faire.
     *
     * @return array{0: string, 1: string|null}
     */
    private function separerNomEtType(string $libelle): array
    {
        if (preg_match('/^(.*?)\s*-?\s*(Urbain\w*|Rural\w*)$/iu', trim($libelle), $trouve)) {
            return [
                trim($trouve[1]),
                stripos($trouve[2], 'urb') === 0 ? 'urbaine' : 'rurale',
            ];
        }

        return [trim($libelle), null];
    }

    /**
     * Un chef-lieu éclaté en deux communes doit rester distinguable à l'écran :
     * « Boromo (urbaine) » et « Boromo (rurale) ».
     */
    private function nomCommuneAffiche(string $nom, string $type): string
    {
        return $this->enCasseDeTitre($nom).' ('.$type.')';
    }

    /**
     * Le fichier ne porte pas le type de localité : il est déduit du type de la
     * commune et du libellé. À remplacer par la donnée réelle si le client la
     * fournit.
     */
    private function deduireTypeLocalite(string $nom, string $typeCommune): string
    {
        if (preg_match('/^\s*secteur\b/iu', $nom)) {
            return 'secteur';
        }

        return $typeCommune === 'urbaine' ? 'quartier' : 'village';
    }

    private function enCasseDeTitre(string $texte): string
    {
        $texte = mb_convert_case(mb_strtolower(trim($texte)), MB_CASE_TITLE, 'UTF-8');

        return preg_replace_callback(
            '/\b(D|L|N)\x27(\w)/u',
            fn ($t) => mb_strtolower($t[1])."'".mb_strtoupper($t[2]),
            $texte
        ) ?? $texte;
    }
}
