<?php

namespace App\Enums;

/**
 * Les trois catégories de volontaires.
 *
 * RÈGLE FERME (cadrage, section 3) : les trois catégories sont ÉTANCHES.
 * Un assistant ne peut pas être promu opérateur. Le changement de catégorie
 * n'existe pas dans la plateforme : aucune méthode de cet enum n'en propose.
 */
enum CategorieVolontaire: string
{
    case Superviseur = 'superviseur';
    case Operateur = 'operateur';
    case Assistant = 'assistant';

    public function libelle(): string
    {
        return match ($this) {
            self::Superviseur => 'Volontaire superviseur',
            self::Operateur => 'Volontaire opérateur de kit',
            self::Assistant => 'Volontaire assistant / aide-opérateur de kit (A-OPK)',
        };
    }

    public function prefixeMatricule(): string
    {
        return match ($this) {
            self::Superviseur => 'SUP',
            self::Operateur => 'OPK',
            self::Assistant => 'ASS',
        };
    }

    /**
     * L'affectation est-elle tournante ?
     *
     * L'assistant est recruté localement et rattaché en permanence à sa localité :
     * il n'est JAMAIS redéployé. L'opérateur et le superviseur tournent de région
     * en région (cadrage, section 4).
     */
    public function estTournante(): bool
    {
        return $this !== self::Assistant;
    }

    /** Le rôle applicatif correspondant. */
    public function role(): RolePnvb
    {
        return match ($this) {
            self::Superviseur => RolePnvb::VolontaireSuperviseur,
            self::Operateur => RolePnvb::VolontaireOperateur,
            self::Assistant => RolePnvb::VolontaireAssistant,
        };
    }

    /** Le rapport journalier que produit cette catégorie (cadrage v2, section 9). */
    public function typeRapport(): TypeRapport
    {
        return match ($this) {
            self::Superviseur => TypeRapport::Superviseur,
            self::Operateur => TypeRapport::Opk,
            self::Assistant => TypeRapport::Aopk,
        };
    }

    /**
     * L'A-OPK N'ENREGISTRE PERSONNE (cadrage v2, section 5, acteur 1).
     * Il tient l'accueil du site : justificatifs, plaintes, affluence.
     * Seul l'opérateur de kit produit un chiffre d'enregistrement.
     */
    public function enregistreLaPopulation(): bool
    {
        return $this === self::Operateur;
    }
}
