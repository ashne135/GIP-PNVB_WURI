<?php

namespace App\Enums;

/**
 * Position du volontaire dans le dispositif.
 *
 * La réserve de 15 % est un vivier mobilisable en cas de désistement, d'abandon,
 * d'indisponibilité ou de remplacement. Un agent passé en réserve peut être
 * remobilisé plus tard : l'historique conserve tous ses passages.
 */
enum StatutVolontaire: string
{
    case Operationnel = 'operationnel';
    case Reserve = 'reserve';
    case Retire = 'retire';

    public function libelle(): string
    {
        return match ($this) {
            self::Operationnel => 'Opérationnel',
            self::Reserve => 'En réserve',
            self::Retire => 'Retiré du dispositif',
        };
    }

    /** Peut-il être tiré au sort pour une vague ? */
    public function estMobilisable(): bool
    {
        return $this === self::Operationnel;
    }
}
