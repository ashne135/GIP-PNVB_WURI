<?php

namespace App\Enums;

/**
 * Cycle de vie de l'accès (cadrage, section 6).
 *
 *  INACTIF    : compte créé à l'import, aucun accès ouvert.
 *  ACTIF      : affecté à une vague active.
 *  DISPONIBLE : entre deux vagues, réservé aux catégories TOURNANTES. L'agent se
 *               connecte, voit son profil, son historique et les alertes, mais
 *               aucun site actif et aucune saisie possible.
 *  FERME      : accès coupé. C'est la règle pour l'assistant hors vague, et pour
 *               le réserviste non mobilisé.
 *
 * Aucun changement d'accès n'est fait à la main : tout passe par le service de
 * cycle de vie et le job planifié.
 */
enum StatutCompte: string
{
    case Inactif = 'inactif';
    case Actif = 'actif';
    case Disponible = 'disponible';
    case Ferme = 'ferme';

    public function libelle(): string
    {
        return match ($this) {
            self::Inactif => 'Inactif',
            self::Actif => 'Actif',
            self::Disponible => 'Disponible entre deux vagues',
            self::Ferme => 'Fermé',
        };
    }

    /** L'utilisateur peut-il ouvrir une session ? */
    public function autoriseConnexion(): bool
    {
        return in_array($this, [self::Actif, self::Disponible], true);
    }

    /** L'utilisateur peut-il écrire sur le terrain ? */
    public function autoriseSaisie(): bool
    {
        return $this === self::Actif;
    }
}
