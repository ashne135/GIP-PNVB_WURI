<?php

namespace App\Enums;

/**
 * Les TROIS rapports journaliers du cadrage v2 (section 9).
 *
 * Chacun est signé par son auteur puis visé par son supérieur direct :
 *
 *     A-OPK  ──signe──►  visa OPK
 *     OPK    ──signe──►  visa SUPERVISEUR DE CENTRE
 *     SUPERVISEUR ──signe──►  visa CONTRÔLEUR TERRAIN / CHEF ARV
 */
enum TypeRapport: string
{
    case Aopk = 'aopk';
    case Opk = 'opk';
    case Superviseur = 'superviseur';

    public function libelle(): string
    {
        return match ($this) {
            self::Aopk => 'Rapport journalier A-OPK',
            self::Opk => 'Rapport journalier opérateur de kit',
            self::Superviseur => 'Rapport journalier superviseur de centre',
        };
    }

    /** La catégorie de volontaire qui produit ce rapport. */
    public function categorieAuteur(): CategorieVolontaire
    {
        return match ($this) {
            self::Aopk => CategorieVolontaire::Assistant,
            self::Opk => CategorieVolontaire::Operateur,
            self::Superviseur => CategorieVolontaire::Superviseur,
        };
    }

    /**
     * La catégorie qui VISE ce rapport — null pour le rapport du superviseur,
     * visé par le contrôleur terrain, qui n'est pas un volontaire.
     */
    public function categorieViseur(): ?CategorieVolontaire
    {
        return match ($this) {
            self::Aopk => CategorieVolontaire::Operateur,
            self::Opk => CategorieVolontaire::Superviseur,
            self::Superviseur => null,
        };
    }

    /** Le rôle applicatif habilité à viser ce rapport. */
    public function roleViseur(): RolePnvb
    {
        return match ($this) {
            self::Aopk => RolePnvb::VolontaireOperateur,
            self::Opk => RolePnvb::VolontaireSuperviseur,
            self::Superviseur => RolePnvb::ControleurTerrain,
        };
    }

    /** Le niveau inférieur, dont les chiffres pré-remplissent celui-ci. */
    public function niveauInferieur(): ?self
    {
        return match ($this) {
            self::Aopk => null,
            self::Opk => self::Aopk,
            self::Superviseur => self::Opk,
        };
    }
}
