<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

/**
 * Canevas du fichier des RETENUS et de la LISTE D'ATTENTE — colonnes réelles
 * fournies par le client (cadrage v2, section 6) :
 *
 *   N° · Email Address · numéro · Nom · Prénom(s) · Date de naissance ·
 *   Lieu de naissance · Sexe · N° CNIB / Passeport ·
 *   Date d'établissement de la CNIB / du Passeport · Profil ·
 *   Niveau d'étude · Diplôme ·
 *   Region · Province · commune · arrondissement · secteur · quartier ·
 *   village · site
 *
 * NIVEAU D'ÉTUDE et DIPLÔME ont été ajoutés le 17/09/2026 à la demande du
 * client : le niveau commande le profil, l'intitulé du diplôme documente le
 * dossier.
 *
 * La reconnaissance des en-têtes reste SOUPLE — insensible à la casse, aux
 * accents, aux espaces et à la ponctuation — parce que le fichier vient d'un
 * export Google Forms dont les intitulés bougent d'une campagne à l'autre.
 *
 * COLONNES VIDES dans le fichier fourni : Profil et tout le bloc territorial.
 * Elles restent déclarées ici : le lecteur doit savoir les reconnaître le jour
 * où elles seront remplies.
 */
class CanevasVolontaires
{
    /**
     * Colonnes du canevas : clé interne => intitulés reconnus.
     *
     * Seuls le nom, les prénoms et le numéro sont structurellement
     * obligatoires. Le COURRIEL ne l'est pas — le cadrage a tranché : « une
     * ligne sans courriel est acceptée et marquée à livrer par un autre
     * canal ». Le PROFIL ne l'est pas non plus : sans lui, la ligne part
     * « à qualifier ».
     */
    public const COLONNES = [
        'numero_ordre' => [
            'intitules' => ['n', 'no', 'numero d ordre', 'ordre', 'n°'],
            'obligatoire' => false,
        ],
        'email' => [
            'intitules' => ['email address', 'email', 'courriel', 'adresse email',
                'adresse courriel', 'mail', 'e mail'],
            'obligatoire' => false,
        ],
        'telephone' => [
            'intitules' => ['numero', 'numéro', 'telephone', 'tel', 'contact',
                'numero de telephone', 'telephone 1'],
            'obligatoire' => true,
        ],
        'nom' => [
            'intitules' => ['nom', 'nom de famille'],
            'obligatoire' => true,
        ],
        'prenoms' => [
            'intitules' => ['prenom s', 'prenoms', 'prenom', 'prenom(s)'],
            'obligatoire' => true,
        ],
        'date_naissance' => [
            'intitules' => ['date de naissance', 'date naissance', 'ne le', 'nee le'],
            'obligatoire' => false,
        ],
        'lieu_naissance' => [
            'intitules' => ['lieu de naissance', 'lieu naissance'],
            'obligatoire' => false,
        ],
        'sexe' => [
            'intitules' => ['sexe', 'genre'],
            'obligatoire' => false,
        ],
        'numero_cnib' => [
            'intitules' => ['n cnib passeport', 'no cnib passeport', 'cnib',
                'n cnib', 'numero cnib', 'cnib passeport', 'piece d identite'],
            'obligatoire' => false,
        ],
        'date_etablissement_cnib' => [
            'intitules' => ['date d etablissement de la cnib du passeport',
                'date d etablissement de la cnib', 'date etablissement cnib',
                'date d etablissement', 'date de delivrance'],
            'obligatoire' => false,
        ],
        'profil' => [
            'intitules' => ['profil', 'poste', 'fonction', 'type de volontaire'],
            'obligatoire' => false,
        ],
        /*
         * LE NIVEAU D'ÉTUDE commande le profil (décision du client, 17/09/2026) :
         * 4ème pour un A-OPK, BAC+1 pour un opérateur, Licence pour un
         * superviseur. Il porte une valeur de l'échelle, seule forme comparable.
         * Le DIPLÔME, lui, est l'intitulé exact — il s'affiche, il ne se compare
         * jamais.
         */
        'niveau_etude' => [
            'intitules' => ['niveau d etude', 'niveau d etudes', 'niveau', 'niveau scolaire',
                'niveau d instruction', 'niveau academique', 'niveau atteint'],
            'obligatoire' => false,
        ],
        'diplome' => [
            'intitules' => ['diplome', 'diplomes', 'dernier diplome', 'diplome obtenu',
                'intitule du diplome', 'qualification'],
            'obligatoire' => false,
        ],
        // ---- Bloc territorial : obligatoire pour les A-OPK seulement ----
        'region' => [
            'intitules' => ['region'],
            'obligatoire' => false,
        ],
        'province' => [
            'intitules' => ['province'],
            'obligatoire' => false,
        ],
        'commune' => [
            'intitules' => ['commune'],
            'obligatoire' => false,
        ],
        'arrondissement' => [
            'intitules' => ['arrondissement'],
            'obligatoire' => false,
        ],
        'secteur' => [
            'intitules' => ['secteur'],
            'obligatoire' => false,
        ],
        'quartier' => [
            'intitules' => ['quartier'],
            'obligatoire' => false,
        ],
        'village' => [
            'intitules' => ['village'],
            'obligatoire' => false,
        ],
        'site' => [
            'intitules' => ['site'],
            'obligatoire' => false,
        ],
    ];

    /**
     * Profils acceptés. Les trois catégories sont ÉTANCHES : aucun alias ne
     * fait passer de l'une à l'autre, et « A-OPK » ne désigne jamais un OPK.
     */
    public const PROFILS = [
        'superviseur' => ['superviseur', 'superviseur de centre', 'sup',
            'chef de centre', 'superviseur centre'],
        'operateur' => ['operateur', 'operateur de kit', 'opk', 'operateur kit',
            'operateur de kits'],
        'assistant' => ['assistant', 'a opk', 'aopk', 'aide operateur',
            'aide operateur de kit', 'assistant operateur', 'aide opk', 'ass'],
    ];

    /**
     * Le fichier réel porte « Masculin » et « Féminin » en toutes lettres. Les
     * formes courtes sont acceptées aussi : le même formulaire a pu changer
     * d'une campagne à l'autre.
     */
    public const SEXES = [
        'M' => ['m', 'masculin', 'homme', 'h', 'male'],
        'F' => ['f', 'feminin', 'femme', 'femelle'],
    ];

    /** Les trois niveaux de localité, dans l'ordre où on les cherche. */
    public const COLONNES_LOCALITE = ['village', 'secteur', 'quartier'];

    /** Normalise un intitulé ou une valeur pour la comparaison. */
    public static function normaliser(?string $texte): string
    {
        if ($texte === null) {
            return '';
        }

        // Str::ascii et non iconv('ASCII//TRANSLIT') : la translittération
        // d'iconv dépend de la libc du système. Sur Windows, « Prénom(s) »
        // devient « pr enom s » et « numéro » devient « num ero », ce qui fait
        // échouer la reconnaissance des en-têtes. Str::ascii s'appuie sur une
        // table interne, identique partout.
        $texte = Str::ascii($texte);
        $texte = preg_replace('/[^A-Za-z0-9]+/', ' ', $texte) ?? $texte;

        return trim(mb_strtolower(preg_replace('/\s+/', ' ', $texte) ?? $texte));
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

    /** Le profil reconnu, ou null si la colonne est vide ou illisible. */
    public static function reconnaitreProfil(?string $valeur): ?string
    {
        $normalise = self::normaliser($valeur);

        if ($normalise === '') {
            return null;
        }

        foreach (self::PROFILS as $profil => $alias) {
            if (in_array($normalise, array_map(fn ($a) => self::normaliser($a), $alias), true)) {
                return $profil;
            }
        }

        return null;
    }

    public static function reconnaitreSexe(?string $valeur): ?string
    {
        $normalise = self::normaliser($valeur);

        if ($normalise === '') {
            return null;
        }

        foreach (self::SEXES as $sexe => $alias) {
            if (in_array($normalise, $alias, true)) {
                return $sexe;
            }
        }

        return null;
    }

    /**
     * NOM en majuscules, Prénoms en capitale initiale.
     * « La casse du fichier est incohérente » (cadrage v2, section 6).
     */
    public static function normaliserNom(?string $nom): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $nom) ?? ''), 'UTF-8');
    }

    public static function normaliserPrenoms(?string $prenoms): string
    {
        $propre = trim(preg_replace('/\s+/', ' ', (string) $prenoms) ?? '');

        if ($propre === '') {
            return '';
        }

        $titre = mb_convert_case(mb_strtolower($propre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        // Rétablit la capitale après une apostrophe ou un trait d'union :
        // « N'guessan », « Jean-Baptiste ».
        return preg_replace_callback(
            '/[\x27\-]\p{Ll}/u',
            fn ($t) => mb_strtoupper($t[0], 'UTF-8'),
            $titre
        ) ?? $titre;
    }

    /** Intitulés du modèle téléchargeable, dans l'ordre du fichier client. */
    public static function entetesDuModele(): array
    {
        return [
            'N°', 'Email Address', 'numéro', 'Nom', 'Prénom(s)', 'Date de naissance',
            'Lieu de naissance', 'Sexe', 'N° CNIB / Passeport',
            "Date d'établissement de la CNIB / du Passeport", 'Profil',
            "Niveau d'étude", 'Diplôme',
            'Region', 'Province', 'commune', 'arrondissement', 'secteur', 'quartier',
            'village', 'site',
        ];
    }

    /** Lignes d'exemple : les trois profils, dont un A-OPK avec son territoire. */
    public static function lignesDuModele(): array
    {
        return [
            ['1', 'aminata.ouedraogo@exemple.bf', '70123456', 'OUEDRAOGO', 'Aminata',
                '15/04/1992', 'Ouagadougou', 'Féminin', 'B1234567', '12/03/2018',
                'Superviseur de centre', 'Licence', 'Licence en sociologie',
                '', '', '', '', '', '', '', ''],
            ['2', 'issa.kabore@exemple.bf', '76234567', 'KABORE', 'Issa',
                '02/11/1995', 'Bobo-Dioulasso', 'Masculin', 'B2345678', '05/07/2019',
                'Opérateur de kit', 'BAC+2', 'BTS en informatique',
                '', '', '', '', '', '', '', ''],
            ['3', '', '65345678', 'SAWADOGO', 'Fatimata',
                '21/07/1998', 'Bagassi', 'Féminin', 'B3456789', '18/01/2020',
                'A-OPK', '3ème', 'BEPC',
                'Bankui', 'Bale', 'Bagassi', '', '', '', 'Assio', ''],
        ];
    }
}
