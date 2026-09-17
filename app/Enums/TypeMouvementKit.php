<?php

namespace App\Enums;

/**
 * Types de mouvements de kit (cadrage, section 13).
 *
 * Le kit suit la personne, pas le site : un redéploiement d'une région à une
 * autre n'est pas une restitution, l'opérateur emporte son kit.
 */
enum TypeMouvementKit: string
{
    case Remise = 'remise';
    case ChangementSite = 'changement_site';
    case Transfert = 'transfert';
    case Restitution = 'restitution';
    case Panne = 'panne';
    case PerteVol = 'perte_vol';

    public function libelle(): string
    {
        return match ($this) {
            self::Remise => 'Remise initiale',
            self::ChangementSite => 'Changement de site lors d\'un redéploiement',
            self::Transfert => 'Transfert lors d\'un remplacement',
            self::Restitution => 'Restitution en fin de mission',
            self::Panne => 'Déclaration de panne',
            self::PerteVol => 'Déclaration de perte ou de vol',
        };
    }

    /** Ce mouvement solde-t-il la détention du kit à la clôture d'une vague ? */
    public function soldeLaDetention(): bool
    {
        return in_array($this, [self::Restitution, self::Transfert, self::PerteVol], true);
    }

    /** Ce mouvement exige-t-il une photo de l'état constaté ? */
    public function exigeUnePhoto(): bool
    {
        return in_array($this, [self::Remise, self::Transfert, self::Restitution], true);
    }
}
