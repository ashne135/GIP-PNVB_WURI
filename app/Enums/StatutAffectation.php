<?php

namespace App\Enums;

enum StatutAffectation: string
{
    case Proposee = 'proposee';
    case Active = 'active';
    case Terminee = 'terminee';
    case Remplacee = 'remplacee';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Active => 'Active',
            self::Terminee => 'Terminée',
            self::Remplacee => 'Remplacée',
            self::Annulee => 'Annulée',
        };
    }

    /** Une affectation active rend le volontaire non éligible à un autre tirage. */
    public function mobiliseLAgent(): bool
    {
        return in_array($this, [self::Proposee, self::Active], true);
    }
}
