<?php

namespace App\Enums;

/**
 * Les huit acteurs du cadrage (section 5).
 *
 * SYSTÈME n'est pas un rôle humain : c'est le planificateur cron. Il existe ici
 * pour que ses écritures soient journalisées sous une identité explicite.
 *
 * SÉPARATION DES POUVOIRS : l'administrateur national ne peut pas s'attribuer de
 * droits à lui-même ni à quiconque. Seul le super administrateur (DSI) le peut.
 */
enum RolePnvb: string
{
    case VolontaireAssistant = 'volontaire_assistant';
    case VolontaireOperateur = 'volontaire_operateur';
    case VolontaireSuperviseur = 'volontaire_superviseur';
    case ControleurTerrain = 'controleur_terrain';
    case ChefAntenneRegional = 'chef_antenne_regional';
    case AdministrateurNational = 'administrateur_national';
    case SuperAdministrateur = 'super_administrateur';
    case Observateur = 'observateur';
    case Systeme = 'systeme';

    public function libelle(): string
    {
        return match ($this) {
            self::VolontaireAssistant => 'Volontaire assistant (aide-opérateur)',
            self::VolontaireOperateur => 'Volontaire opérateur de kit',
            self::VolontaireSuperviseur => 'Volontaire superviseur',
            self::ControleurTerrain => 'Contrôleur terrain / Chef ARV',
            self::ChefAntenneRegional => 'Chef d\'antenne régional',
            self::AdministrateurNational => 'Administrateur national',
            self::SuperAdministrateur => 'Super administrateur (DSI)',
            self::Observateur => 'Observateur',
            self::Systeme => 'Système (planificateur)',
        };
    }

    public function niveauPerimetre(): NiveauPerimetre
    {
        return match ($this) {
            self::VolontaireAssistant, self::VolontaireOperateur => NiveauPerimetre::LuiMeme,
            self::VolontaireSuperviseur => NiveauPerimetre::SesCentres,
            self::ControleurTerrain, self::ChefAntenneRegional => NiveauPerimetre::SaRegion,
            self::AdministrateurNational, self::SuperAdministrateur,
            self::Observateur, self::Systeme => NiveauPerimetre::National,
        };
    }

    /**
     * LECTURE SEULE STRICTE : le serveur refuse toute requête d'écriture venant
     * de ce rôle (cadrage, section 5, acteur 7).
     */
    public function estLectureSeule(): bool
    {
        return $this === self::Observateur;
    }

    public function estVolontaire(): bool
    {
        return in_array($this, [
            self::VolontaireAssistant,
            self::VolontaireOperateur,
            self::VolontaireSuperviseur,
        ], true);
    }
}
