<?php

namespace App\Support;

/**
 * Normalisation des numéros de téléphone burkinabè.
 *
 * Le numéro est l'IDENTIFIANT DE CONNEXION (cadrage, section 6). Un agent de
 * terrain le saisira indifféremment « 70 12 34 56 », « 0070123456 »,
 * « +226 70123456 » ou « 0022670123456 ». Refuser sa connexion parce qu'il a
 * mis un espace serait un défaut d'ergonomie, pas une sécurité : tout est
 * ramené à la forme canonique +226XXXXXXXX, à la saisie comme à l'import.
 *
 * Le Burkina Faso utilise l'indicatif +226 et des numéros à 8 chiffres.
 */
class NormalisateurTelephone
{
    public const INDICATIF = '226';

    public const LONGUEUR_NATIONALE = 8;

    /**
     * Forme canonique, ou null si le numéro n'est pas exploitable.
     * Ne lève jamais d'exception : l'appelant décide quoi faire d'un null.
     */
    public static function normaliser(?string $numero): ?string
    {
        if ($numero === null || trim($numero) === '') {
            return null;
        }

        // On ne garde que les chiffres ; le « + » de tête est implicite dans la
        // forme canonique et reconstruit à la fin.
        $chiffres = preg_replace('/\D+/', '', $numero) ?? '';

        if ($chiffres === '') {
            return null;
        }

        // 00226XXXXXXXX : préfixe international composé.
        if (str_starts_with($chiffres, '00'.self::INDICATIF)) {
            $chiffres = substr($chiffres, 2 + strlen(self::INDICATIF));
        } elseif (str_starts_with($chiffres, self::INDICATIF)
            && strlen($chiffres) === strlen(self::INDICATIF) + self::LONGUEUR_NATIONALE) {
            // 226XXXXXXXX, avec ou sans « + » d'origine.
            $chiffres = substr($chiffres, strlen(self::INDICATIF));
        } elseif (str_starts_with($chiffres, '0')
            && strlen($chiffres) === self::LONGUEUR_NATIONALE + 1) {
            // 0XXXXXXXX : zéro de service, sans valeur dans la numérotation locale.
            $chiffres = substr($chiffres, 1);
        }

        if (strlen($chiffres) !== self::LONGUEUR_NATIONALE) {
            return null;
        }

        return '+'.self::INDICATIF.$chiffres;
    }

    /** Le numéro est-il exploitable comme identifiant de connexion ? */
    public static function estValide(?string $numero): bool
    {
        return self::normaliser($numero) !== null;
    }

    /** Forme lisible pour un document papier : +226 70 12 34 56. */
    public static function pourAffichage(?string $numero): ?string
    {
        $canonique = self::normaliser($numero);

        if ($canonique === null) {
            return null;
        }

        $national = substr($canonique, 1 + strlen(self::INDICATIF));

        return '+'.self::INDICATIF.' '.implode(' ', str_split($national, 2));
    }
}
