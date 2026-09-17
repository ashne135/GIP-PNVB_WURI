<?php

namespace App\Services\Referentiel;

use Illuminate\Support\Str;

/**
 * Codification du territoire et du dispositif.
 *
 * Aucune règle n'existait côté client : celle-ci est dérivée du territoire
 * (cadrage, section 7). Les codes sont générés UNE SEULE FOIS et ne changent
 * jamais : ils apparaîtront sur des documents papier.
 *
 *   Centre : <CODE_RÉGION>-<CODE_COMMUNE>-C<numéro sur 3 chiffres>
 *   Site   : <CODE_CENTRE>-S<numéro sur 2 chiffres>
 *   Exemple : KAD-OUAG-C012 et KAD-OUAG-C012-S01
 */
class CodificateurTerritorial
{
    /**
     * Les 12 codes régionaux, fixés par le cadrage. Ils ne figurent pas dans le
     * fichier source : c'est ici qu'ils sont adossés aux noms de régions.
     */
    public const CODES_REGIONS = [
        'KADIOGO' => 'KAD',
        'GUIRIKO' => 'GUI',
        'NANDO' => 'NAN',
        'DJORO' => 'DJO',
        'NAKAMBE' => 'NAK',
        'OUBRI' => 'OUB',
        'YAADGA' => 'YAA',
        'KOULSE' => 'KOU',
        'NAZINON' => 'NAZ',
        'BANKUI' => 'BAN',
        'TANNOUNYAN' => 'TAN',
        'GOULMOU' => 'GOU',
    ];

    /**
     * Nombre de sites par région, chiffres du plan projet (total 12 294).
     * Servent de total de contrôle à la répartition entre localités, et de
     * repli si le fichier source ne les porte pas en valeur lisible.
     */
    public const SITES_PAR_REGION = [
        'KADIOGO' => 2247,
        'GUIRIKO' => 1502,
        'NANDO' => 1355,
        'DJORO' => 1270,
        'NAKAMBE' => 1033,
        'OUBRI' => 1013,
        'YAADGA' => 974,
        'KOULSE' => 765,
        'NAZINON' => 765,
        'BANKUI' => 661,
        'TANNOUNYAN' => 583,
        'GOULMOU' => 126,
    ];

    /** Noms d'affichage, accentués, tels qu'ils doivent apparaître à l'écran. */
    public const NOMS_REGIONS = [
        'KADIOGO' => 'Kadiogo',
        'GUIRIKO' => 'Guiriko',
        'NANDO' => 'Nando',
        'DJORO' => 'Djôrô',
        'NAKAMBE' => 'Nakambé',
        'OUBRI' => 'Oubri',
        'YAADGA' => 'Yaadga',
        'KOULSE' => 'Koulsé',
        'NAZINON' => 'Nazinon',
        'BANKUI' => 'Bankui',
        'TANNOUNYAN' => 'Tannounyan',
        'GOULMOU' => 'Goulmou',
    ];

    /** Codes de commune déjà attribués, par région : ['BAN' => ['BAGA', 'BANA']]. */
    private array $codesUtilises = [];

    /**
     * Normalise un libellé : majuscules, sans accent, sans ponctuation.
     * C'est la forme sur laquelle on compare les noms venus du fichier source.
     */
    public static function normaliser(string $texte): string
    {
        $texte = trim($texte);
        // Str::ascii et non iconv : la translittération d'iconv dépend de la
        // libc et diffère entre Windows et Linux — un même fichier produirait
        // des codes de commune différents selon la machine, alors que ces
        // codes sont figés à vie et impriment sur des documents papier.
        $texte = Str::ascii($texte);
        $texte = preg_replace('/[^A-Za-z0-9 ]/', '', $texte) ?? $texte;

        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $texte) ?? $texte));
    }

    public function codeRegion(string $nomRegion): ?string
    {
        return self::CODES_REGIONS[self::normaliser($nomRegion)] ?? null;
    }

    public function nomAffichageRegion(string $nomRegion): string
    {
        $cle = self::normaliser($nomRegion);

        return self::NOMS_REGIONS[$cle] ?? ucfirst(mb_strtolower($nomRegion));
    }

    public function nombreSitesRegion(string $nomRegion): ?int
    {
        return self::SITES_PAR_REGION[self::normaliser($nomRegion)] ?? null;
    }

    /**
     * Code de commune : nom sans accent, majuscules, tronqué à 4 caractères,
     * collisions résolues à l'intérieur de la région.
     *
     * En cas de collision, le dernier caractère est remplacé par un chiffre :
     * OUAG, OUA2, OUA3… Puis, si nécessaire, sur trois caractères : OU10, OU11…
     */
    public function codeCommune(string $codeRegion, string $nomCommune): string
    {
        $base = preg_replace('/[^A-Z0-9]/', '', self::normaliser($nomCommune)) ?: 'XXXX';
        $candidat = mb_substr($base, 0, 4);
        $candidat = str_pad($candidat, 4, 'X');

        $this->codesUtilises[$codeRegion] ??= [];

        if (! in_array($candidat, $this->codesUtilises[$codeRegion], true)) {
            $this->codesUtilises[$codeRegion][] = $candidat;

            return $candidat;
        }

        for ($suffixe = 2; $suffixe <= 9; $suffixe++) {
            $variante = mb_substr($base, 0, 3).$suffixe;

            if (! in_array($variante, $this->codesUtilises[$codeRegion], true)) {
                $this->codesUtilises[$codeRegion][] = $variante;

                return $variante;
            }
        }

        for ($suffixe = 10; $suffixe <= 99; $suffixe++) {
            $variante = mb_substr($base, 0, 2).$suffixe;

            if (! in_array($variante, $this->codesUtilises[$codeRegion], true)) {
                $this->codesUtilises[$codeRegion][] = $variante;

                return $variante;
            }
        }

        throw new \RuntimeException(
            "Impossible de coder la commune « {$nomCommune} » : plus de 100 collisions dans la région {$codeRegion}."
        );
    }

    /** Enregistre un code déjà présent en base, pour que la résolution reste cohérente. */
    public function declarerCodeExistant(string $codeRegion, string $codeCommune): void
    {
        $this->codesUtilises[$codeRegion] ??= [];

        if (! in_array($codeCommune, $this->codesUtilises[$codeRegion], true)) {
            $this->codesUtilises[$codeRegion][] = $codeCommune;
        }
    }

    public function codeCentre(string $codeRegion, string $codeCommune, int $numero): string
    {
        return sprintf('%s-%s-C%03d', $codeRegion, $codeCommune, $numero);
    }

    public function codeSite(string $codeCentre, int $numero): string
    {
        return sprintf('%s-S%02d', $codeCentre, $numero);
    }

    /**
     * Chefs-lieux dont les arrondissements tiennent lieu d'unité communale dans
     * le fichier source : Kadiogo compte 12 arrondissements, Houet 7, et ni
     * l'une ni l'autre de ces provinces ne porte de ligne COMMUNE pour eux.
     *
     * Ce rattachement est la seule donnée extérieure au fichier introduite ici,
     * en dehors des codes régionaux. À confirmer par le client.
     */
    public const CHEFS_LIEUX_ARRONDISSEMENTS = [
        'KADIOGO' => 'Ouagadougou',
        'HOUET' => 'Bobo-Dioulasso',
    ];

    /**
     * « ARRONDISSEMENT 1 » dans la province du Kadiogo donne
     * « Ouagadougou — Arrondissement 1 », dont le code sera OUAG, OUA2, OUA3…
     * conformément à l'exemple du cadrage : KAD-OUAG-C012.
     */
    public function nomArrondissement(string $cleProvince, string $libelle): string
    {
        $chefLieu = self::CHEFS_LIEUX_ARRONDISSEMENTS[self::normaliser($cleProvince)] ?? null;
        $numero = preg_match('/(\d+)/', $libelle, $trouve) ? (int) $trouve[1] : 0;

        if ($chefLieu === null) {
            return 'Arrondissement '.$numero;
        }

        return "{$chefLieu} — Arrondissement {$numero}";
    }
}
