<?php

namespace App\Enums;

/**
 * Section F du canevas d'incident : un seul choix.
 *
 * Le niveau de gravité pilote la matrice de notification et le délai d'escalade,
 * tous deux PARAMÉTRABLES en base (table parametres). Aucun délai n'est écrit en
 * dur ici : cleDelaiEscalade() ne fait que nommer le paramètre à lire.
 */
enum GraviteIncident: int
{
    case Mineur = 1;
    case Modere = 2;
    case Majeur = 3;
    case Critique = 4;

    public function libelle(): string
    {
        return match ($this) {
            self::Mineur => 'Niveau 1 — mineur',
            self::Modere => 'Niveau 2 — modéré',
            self::Majeur => 'Niveau 3 — majeur',
            self::Critique => 'Niveau 4 — critique',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Mineur => 'Traité localement',
            self::Modere => 'Intervention du superviseur ou de la coordination',
            self::Majeur => 'Impact significatif sur la sécurité ou la continuité',
            self::Critique => 'Danger grave ou immédiat, intervention urgente',
        };
    }

    /** Couleur d'affichage, du plus calme au plus alarmant. */
    public function couleur(): string
    {
        return match ($this) {
            self::Mineur => '#2F7D4F',
            self::Modere => '#B7791F',
            self::Majeur => '#C05621',
            self::Critique => '#9B2C2C',
        };
    }

    /** Clé du paramètre portant le délai d'escalade de ce niveau. */
    public function cleDelaiEscalade(): string
    {
        return "incidents.delai_escalade_minutes.niveau_{$this->value}";
    }

    /** Clé du paramètre portant la liste des rôles à notifier. */
    public function cleMatriceNotification(): string
    {
        return "incidents.notification.niveau_{$this->value}";
    }
}
