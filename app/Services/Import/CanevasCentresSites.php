<?php

namespace App\Services\Import;

/**
 * Canevas du fichier des CENTRES et SITES d'enregistrement.
 *
 * ATTENTION — CANEVAS PROPOSÉ, À VALIDER PAR LE CLIENT.
 * La Partie D du cadrage annonce « votre liste réelle, ou la règle de
 * regroupement » comme à venir : aucun canevas n'a été fourni. Celui-ci est
 * DÉDUIT du modèle — une ligne par SITE, le centre étant créé ou retrouvé au
 * passage, ce qui évite d'imposer deux fichiers au client.
 *
 * Les CODES ne sont pas demandés : ils sont générés par le serveur selon la
 * règle du cadrage (section 7). Une colonne « Code centre » reste toutefois
 * reconnue : si le client dispose déjà d'une codification, elle est reprise
 * telle quelle plutôt qu'écrasée.
 */
class CanevasCentresSites
{
    public const COLONNES = [
        'region' => ['intitules' => ['region'], 'obligatoire' => true],
        'province' => ['intitules' => ['province'], 'obligatoire' => false],
        'commune' => ['intitules' => ['commune'], 'obligatoire' => true],
        'arrondissement' => ['intitules' => ['arrondissement'], 'obligatoire' => false],
        'localite' => [
            'intitules' => ['localite', 'village', 'secteur', 'quartier', 'localite du site'],
            'obligatoire' => true,
        ],
        'code_centre' => [
            'intitules' => ['code centre', 'code du centre'],
            'obligatoire' => false,
        ],
        'nom_centre' => [
            'intitules' => ['centre', 'nom du centre', 'nom centre', 'centre d enregistrement'],
            'obligatoire' => true,
        ],
        'nombre_kits' => [
            'intitules' => ['nombre de kits', 'nombre kits', 'kits', 'nb kits'],
            'obligatoire' => false,
        ],
        'nom_site' => [
            'intitules' => ['site', 'nom du site', 'nom site', 'site d enregistrement'],
            'obligatoire' => true,
        ],
        'ordre_tournee' => [
            'intitules' => ['ordre', 'ordre de tournee', 'rang', 'ordre tournee'],
            'obligatoire' => false,
        ],
        'latitude' => ['intitules' => ['latitude', 'lat'], 'obligatoire' => false],
        'longitude' => ['intitules' => ['longitude', 'lon', 'lng'], 'obligatoire' => false],
    ];

    public static function normaliser(?string $texte): string
    {
        return CanevasVolontaires::normaliser($texte);
    }

    /**
     * @param  string[]  $entetes
     * @return array{correspondances: array<int, string>, manquantes: string[]}
     */
    public static function reconnaitreEntetes(array $entetes): array
    {
        $correspondances = [];

        foreach ($entetes as $index => $entete) {
            $normalise = self::normaliser($entete);

            if ($normalise === '') {
                continue;
            }

            foreach (self::COLONNES as $cle => $definition) {
                $reconnus = array_map(fn ($i) => self::normaliser($i), $definition['intitules']);

                if (in_array($normalise, $reconnus, true) && ! in_array($cle, $correspondances, true)) {
                    $correspondances[$index] = $cle;
                    break;
                }
            }
        }

        $trouvees = array_values($correspondances);
        $manquantes = [];

        foreach (self::COLONNES as $cle => $definition) {
            if ($definition['obligatoire'] && ! in_array($cle, $trouvees, true)) {
                $manquantes[] = $cle;
            }
        }

        return ['correspondances' => $correspondances, 'manquantes' => $manquantes];
    }

    public static function entetesDuModele(): array
    {
        return [
            'Region', 'Province', 'Commune', 'Arrondissement', 'Localité',
            'Code centre', 'Nom du centre', 'Nombre de kits',
            'Nom du site', 'Ordre de tournée', 'Latitude', 'Longitude',
        ];
    }

    public static function lignesDuModele(): array
    {
        return [
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '1',
                'Site Assio 1', '1', '11.9456', '-3.0021'],
            ['Bankui', 'Bale', 'Bagassi', '', 'Bagassi', '', 'Centre de Bagassi', '1',
                'Site Bagassi 1', '2', '', ''],
            ['Kadiogo', 'Kadiogo', 'Ouagadougou — Arrondissement 1', '', 'Secteur 01', '',
                'Centre Ouaga Arr. 1', '2', 'Site Secteur 01', '1', '', ''],
        ];
    }
}
