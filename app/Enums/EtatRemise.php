<?php

namespace App\Enums;

/**
 * État de remise des identifiants (cadrage, section 6).
 *
 * Une partie des volontaires, notamment les assistants recrutés en milieu rural,
 * n'a pas d'adresse de courriel exploitable. La remise suit une cascade :
 * courriel, puis SMS, puis bordereau signé en formation.
 */
enum EtatRemise: string
{
    case NonEnvoye = 'non_envoye';
    case Envoye = 'envoye';
    case Echec = 'echec';
    case RemisMainPropre = 'remis_main_propre';
    case PremiereConnexionEffectuee = 'premiere_connexion_effectuee';

    public function libelle(): string
    {
        return match ($this) {
            self::NonEnvoye => 'Non envoyé',
            self::Envoye => 'Envoyé',
            self::Echec => 'Échec d\'envoi',
            self::RemisMainPropre => 'Remis en main propre',
            self::PremiereConnexionEffectuee => 'Première connexion effectuée',
        };
    }

    /** Faut-il relancer la cascade pour ce compte ? */
    public function appelleUnRenvoi(): bool
    {
        return in_array($this, [self::NonEnvoye, self::Echec], true);
    }
}
