<?php

namespace App\Enums;

/**
 * Cycle de vie d'une vague de déploiement.
 *
 * PROPOSEE : le tirage a produit une proposition. Rien n'est écrit ni notifié
 * tant qu'elle n'est pas validée (cadrage, section 7).
 * CLOTUREE : événement métier — rapports finalisés, kits restitués ou emportés,
 * accès recalculés, équipe libérée.
 */
enum StatutVague: string
{
    case Brouillon = 'brouillon';
    case Proposee = 'proposee';
    case Validee = 'validee';
    case Active = 'active';
    case Cloturee = 'cloturee';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Proposee => 'Proposition à valider',
            self::Validee => 'Validée, en attente d\'ouverture',
            self::Active => 'Active',
            self::Cloturee => 'Clôturée',
            self::Annulee => 'Annulée',
        };
    }

    /** Les affectations de cette vague ouvrent-elles les accès ? */
    public function ouvreLesAcces(): bool
    {
        return $this === self::Active;
    }
}
