<?php

namespace App\Enums;

/**
 * Motif de bascule en réserve. OBLIGATOIRE lors d'un remplacement
 * (cadrage, section 6) : aucun remplacement n'est enregistré sans motif.
 */
enum MotifReserve: string
{
    case Desistement = 'desistement';
    case Abandon = 'abandon';
    case Indisponibilite = 'indisponibilite';
    case Performance = 'performance';
    case NonMobilise = 'non_mobilise';

    public function libelle(): string
    {
        return match ($this) {
            self::Desistement => 'Désistement',
            self::Abandon => 'Abandon de poste',
            self::Indisponibilite => 'Indisponibilité',
            self::Performance => 'Performance insuffisante',
            self::NonMobilise => 'Réserviste non encore mobilisé',
        };
    }

    /** Les motifs recevables pour un remplacement (la table remplacements en exclut NonMobilise). */
    public static function motifsRemplacement(): array
    {
        return [self::Desistement, self::Abandon, self::Indisponibilite, self::Performance];
    }
}
