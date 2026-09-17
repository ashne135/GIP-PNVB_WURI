<?php

namespace App\Enums;

/**
 * Statut porté par une ligne de feuille de présence.
 * Le motif est OBLIGATOIRE pour une absence justifiée (cadrage, section 8.3).
 */
enum StatutPresence: string
{
    case Present = 'present';
    case Absent = 'absent';
    case AbsentJustifie = 'absent_justifie';

    public function libelle(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Absent => 'Absent',
            self::AbsentJustifie => 'Absent justifié',
        };
    }

    public function exigeUnMotif(): bool
    {
        return $this === self::AbsentJustifie;
    }

    public function compteCommePresent(): bool
    {
        return $this === self::Present;
    }
}
