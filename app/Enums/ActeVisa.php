<?php

namespace App\Enums;

/**
 * Les actes tracés sur un rapport (cadrage v2, section 9).
 * « Chaque transition enregistre l'auteur, la date, l'heure et la position. »
 */
enum ActeVisa: string
{
    case Signature = 'signature';
    case Visa = 'visa';
    case Rejet = 'rejet';
    case Cloture = 'cloture';
    case Reouverture = 'reouverture';

    public function libelle(): string
    {
        return match ($this) {
            self::Signature => 'Signature de l\'auteur',
            self::Visa => 'Visa du supérieur',
            self::Rejet => 'Rejet pour correction',
            self::Cloture => 'Clôture',
            self::Reouverture => 'Réouverture',
        };
    }

    /** Un rejet sans motif n'a aucune valeur pour celui qui doit corriger. */
    public function exigeUnMotif(): bool
    {
        return in_array($this, [self::Rejet, self::Reouverture], true);
    }
}
