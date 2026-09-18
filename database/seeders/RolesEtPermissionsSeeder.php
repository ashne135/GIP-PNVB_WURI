<?php

namespace Database\Seeders;

use App\Enums\RolePnvb;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Les huit acteurs du cadrage (section 5) et leurs droits.
 *
 * Rappel de la règle non négociable : le DROIT (ici) et le PÉRIMÈTRE (le scope
 * Eloquent perimetre()) sont DEUX mécanismes distincts et tous les deux
 * obligatoires. Un chef d'antenne et un administrateur national peuvent tous
 * deux « valider un rapport de centre » : c'est le périmètre, pas la permission,
 * qui les sépare.
 *
 * SÉPARATION DES POUVOIRS : seul le super administrateur porte roles.attribuer.
 * L'administrateur national ne peut donc pas s'octroyer de droits, ni à lui-même
 * ni à quiconque.
 *
 * Idempotent : rejouable sans créer de doublon.
 */
class RolesEtPermissionsSeeder extends Seeder
{
    /** Les permissions, groupées par module pour rester lisibles. */
    public const PERMISSIONS = [
        'referentiel' => [
            'referentiel.consulter' => 'Consulter le référentiel territorial',
            'referentiel.importer' => 'Importer un référentiel',
            'referentiel.modifier' => 'Créer et modifier centres et sites',
            // DÉROGATION AU CADRAGE (section 2), décidée par le client le
            // 17/09/2026 : le référentiel territorial se corrige aussi ligne à
            // ligne, et plus seulement par import. Droit national uniquement.
            'referentiel.modifier_territoire' => 'Corriger le référentiel territorial et y ajouter une localité',
        ],
        'volontaires' => [
            'volontaires.consulter' => 'Consulter le registre des volontaires',
            'volontaires.importer' => 'Importer les retenus et les réservistes',
            'volontaires.modifier' => 'Modifier une fiche de volontaire',
            'donnees.supprimer' => 'Supprimer définitivement des fiches d\'essai (volontaire, centre, site, kit)',
            'volontaires.qualifier' => 'Attribuer un profil aux fiches importées sans profil',
        ],
        'comptes' => [
            'comptes.consulter' => 'Consulter les comptes et leur état de remise',
            'comptes.renvoyer_identifiants' => 'Renvoyer les identifiants, à l\'unité ou en lot',
            'comptes.debloquer' => 'Débloquer un accès',
        ],
        'vagues' => [
            'vagues.consulter' => 'Consulter les vagues de déploiement',
            'vagues.planifier' => 'Planifier une vague',
            'vagues.tirer' => 'Déclencher l\'affectation automatique',
            'vagues.valider' => 'Valider une proposition d\'affectation',
            'vagues.cloturer' => 'Clôturer une vague',
        ],
        'affectations' => [
            'affectations.consulter' => 'Consulter les affectations',
            'affectations.ajuster' => 'Ajuster manuellement une affectation',
            // Ajuster ne vaut qu'AVANT validation. Déplacer un agent déjà
            // déployé est un autre acte : il touche un kit en circulation et
            // des passages à venir, et exige un motif.
            'affectations.deplacer' => 'Déplacer un agent déployé vers un autre centre',
        ],
        // Le passage du kit sur un site dit OÙ un opérateur travaille un jour
        // donné : corriger un passage déplace un agent sur le terrain. C'est un
        // acte distinct de l'ajustement d'une affectation, qui lui ne vaut
        // qu'avant validation — d'où un droit propre.
        'tournees' => [
            'tournees.ajuster' => 'Corriger le passage d\'un kit sur un site',
        ],
        'remplacements' => [
            'remplacements.consulter' => 'Consulter les remplacements',
            'remplacements.decider' => 'Mobiliser un réserviste en remplacement',
        ],
        'kits' => [
            'kits.consulter' => 'Consulter le parc de kits',
            'kits.gerer' => 'Gérer le parc de kits',
            'kits.declarer_mouvement' => 'Déclarer un mouvement de kit',
        ],
        'presence' => [
            'presence.signaler_arrivee' => 'Signaler son arrivée et son départ',
            'presence.consulter_carte' => 'Consulter la carte temps réel de ses agents',
            'presence.consulter_feuille' => 'Consulter les feuilles de présence',
            'presence.valider_feuille' => 'Valider une feuille de présence',
            'presence.corriger_feuille' => 'Corriger une feuille de présence validée',
            'presence.exporter' => 'Exporter la liste des présents en PDF et Excel',
            'ecarts.consulter' => 'Consulter les écarts de présence constatés',
            'ecarts.traiter' => 'Marquer un écart de présence examiné ou clos, avec commentaire',
        ],
        // Cadrage v2, section 9 : trois rapports journaliers et une chaîne de
        // visas. Le droit de VISER est distinct du droit de SAISIR — c'est ce
        // qui sépare l'auteur de son supérieur à chaque niveau.
        'rapports' => [
            'rapports.saisir' => 'Saisir son rapport journalier',
            'rapports.consulter' => 'Consulter les rapports',
            'rapports.viser' => 'Viser le rapport de ses agents',
            'rapports.corriger_prerempli' => 'Corriger une valeur pré-remplie, avec motif',
            'rapports.cloturer' => 'Clôturer un rapport visé',
            'rapports.exporter' => 'Exporter les rapports en PDF et Excel',
        ],

        // La notation quotidienne peut affecter le maintien d'une personne dans
        // le dispositif : le droit d'apprécier et celui de se défendre sont deux
        // permissions distinctes, et l'agent noté porte la seconde.
        'appreciations' => [
            'appreciations.apprecier' => 'Apprécier le travail de ses agents',
            'appreciations.consulter_les_miennes' => 'Consulter les appréciations me concernant',
            'appreciations.repondre' => 'Répondre à une appréciation me concernant',
            'appreciations.consulter_equipe' => 'Consulter les appréciations de son périmètre',
        ],
        'incidents' => [
            'incidents.declarer' => 'Signaler un incident',
            'incidents.consulter' => 'Consulter les incidents',
            'incidents.traiter' => 'Prendre en charge et traiter un incident',
            'incidents.cloturer' => 'Clôturer un incident',
            'incidents.nomenclatures' => 'Gérer les listes du canevas d\'incident (natures, impacts, mesures, destinataires)',
        ],
        'alertes' => [
            'alertes.consulter' => 'Lire les alertes',
            'alertes.publier' => 'Publier une alerte descendante',
        ],
        'pilotage' => [
            'tableau_bord.consulter' => 'Consulter le tableau de bord',
            'exports.generer' => 'Générer les exports',
        ],
        'administration' => [
            'parametres.consulter' => 'Consulter les paramètres du système',
            'parametres.modifier' => 'Modifier les paramètres du système',
            'roles.attribuer' => 'Attribuer rôles et permissions',
            'journal.consulter' => 'Consulter le journal d\'activité',
        ],
    ];

    public function run(): void
    {
        Artisan::call('permission:cache-reset');

        foreach (self::PERMISSIONS as $module => $permissions) {
            foreach (array_keys($permissions) as $nom) {
                Permission::findOrCreate($nom, 'web');
                Permission::findOrCreate($nom, 'sanctum');
            }
        }

        foreach ($this->droitsParRole() as $role => $permissions) {
            foreach (['web', 'sanctum'] as $garde) {
                Role::findOrCreate($role, $garde)
                    ->syncPermissions(Permission::whereIn('name', $permissions)->where('guard_name', $garde)->get());
            }
        }

        Artisan::call('permission:cache-reset');

        $this->command?->info(sprintf(
            '  Rôles : %d — Permissions : %d',
            count($this->droitsParRole()),
            Permission::where('guard_name', 'sanctum')->count()
        ));
    }

    /** @return array<string, string[]> */
    private function droitsParRole(): array
    {
        // Acteur 1 : A-OPK. Périmètre : lui-même.
        // Il N'ENREGISTRE PERSONNE : il tient l'accueil du site. Il saisit son
        // rapport, mais ne vise celui de personne — il est au bas de la chaîne.
        $assistant = [
            'presence.signaler_arrivee',
            'presence.consulter_feuille',
            'rapports.saisir',
            'rapports.consulter',
            'incidents.declarer',
            'incidents.consulter',
            'alertes.consulter',
            'appreciations.consulter_les_miennes',
            'appreciations.repondre',
        ];

        // Acteur 2 : opérateur de kit. Lui-même, son kit et les A-OPK rattachés.
        // Premier maillon qui VISE : il vise les rapports de ses A-OPK et les apprécie.
        $operateur = [
            ...$assistant,
            'kits.consulter',
            'kits.declarer_mouvement',
            'rapports.viser',
            'appreciations.apprecier',
        ];

        // Acteur 3 : superviseur. Ses 2 centres et leurs sites.
        $superviseur = [
            'presence.signaler_arrivee',
            'presence.consulter_carte',
            'presence.consulter_feuille',
            'presence.valider_feuille',
            'presence.exporter',
            'rapports.saisir',
            'rapports.consulter',
            'rapports.viser',
            'rapports.corriger_prerempli',
            'rapports.exporter',
            'appreciations.apprecier',
            'appreciations.consulter_equipe',
            'appreciations.consulter_les_miennes',
            'appreciations.repondre',
            'incidents.declarer',
            'incidents.consulter',
            'incidents.traiter',
            'kits.consulter',
            'kits.declarer_mouvement',
            'alertes.consulter',
            'affectations.consulter',
            'volontaires.consulter',
            'referentiel.consulter',
        ];

        // Acteur 4 : chef d'antenne régional. Sa région, filtrée côté serveur.
        $chefAntenne = [
            ...$superviseur,
            'presence.corriger_feuille',
            'ecarts.consulter',
            'ecarts.traiter',
            'rapports.cloturer',
            'referentiel.modifier',
            'vagues.consulter',
            // Au plus près du terrain : le chef d'antenne corrige les passages
            // de SA région — le périmètre, lui, reste appliqué côté serveur.
            'tournees.ajuster',
            'kits.gerer',
            'incidents.cloturer',
            'alertes.publier',
            'tableau_bord.consulter',
            'exports.generer',
            'comptes.consulter',
        ];

        // Acteur 5 : administrateur national. Hérite du chef d'antenne.
        $administrateur = [
            ...$chefAntenne,
            'referentiel.importer',
            'referentiel.modifier_territoire',
            'incidents.nomenclatures',
            'volontaires.importer',
            'volontaires.modifier',
            'volontaires.qualifier',
            'comptes.renvoyer_identifiants',
            'comptes.debloquer',
            'vagues.planifier',
            'vagues.tirer',
            'vagues.valider',
            'vagues.cloturer',
            'affectations.ajuster',
            'affectations.deplacer',
            'remplacements.consulter',
            'remplacements.decider',
            'parametres.consulter',
            // EFFACER POUR DE BON n'appartient qu'à ce niveau : c'est le geste
            // qui ne se rattrape pas. Le chef d'antenne ferme un centre et
            // retire une fiche — deux gestes réversibles, qui gardent la trace.
            'donnees.supprimer',
        ];

        // Acteur 7 : observateur. LECTURE SEULE STRICTE.
        $observateur = [
            'referentiel.consulter',
            'volontaires.consulter',
            'vagues.consulter',
            'affectations.consulter',
            'presence.consulter_feuille',
            'rapports.consulter',
            'incidents.consulter',
            'kits.consulter',
            'alertes.consulter',
            'tableau_bord.consulter',
            'exports.generer',
        ];

        /*
         * Acteur 9 : CONTRÔLEUR TERRAIN / CHEF ARV.
         *
         * DÉCISION PRISE FAUTE DE RÉPONSE CLIENT (question 1 restée ouverte).
         * Le cadrage v2 le nomme comme viseur du rapport de superviseur, sans
         * dire s'il s'agit du chef d'antenne régional ou d'un acteur distinct.
         * Je le crée comme rôle distinct, de périmètre régional, dont la SEULE
         * capacité supplémentaire par rapport à un superviseur est de viser les
         * rapports de superviseur : c'est le plus petit engagement réversible.
         * Si le client répond « c'est le chef d'antenne », il suffira de retirer
         * ce rôle et d'ajouter rapports.viser au chef d'antenne.
         */
        $controleurTerrain = [
            'referentiel.consulter',
            'volontaires.consulter',
            'affectations.consulter',
            'presence.consulter_feuille',
            'presence.consulter_carte',
            'rapports.consulter',
            'rapports.viser',
            'rapports.cloturer',
            'rapports.exporter',
            'appreciations.consulter_equipe',
            'incidents.consulter',
            'alertes.consulter',
            'tableau_bord.consulter',
        ];

        return [
            RolePnvb::VolontaireAssistant->value => $assistant,
            RolePnvb::VolontaireOperateur->value => $operateur,
            RolePnvb::VolontaireSuperviseur->value => $superviseur,
            RolePnvb::ControleurTerrain->value => $controleurTerrain,
            RolePnvb::ChefAntenneRegional->value => $chefAntenne,
            RolePnvb::AdministrateurNational->value => $administrateur,

            // Acteur 6 : super administrateur (DSI). Seul à porter roles.attribuer.
            RolePnvb::SuperAdministrateur->value => [
                ...$administrateur,
                'parametres.modifier',
                'roles.attribuer',
                'journal.consulter',
            ],

            RolePnvb::Observateur->value => $observateur,

            // Acteur 8 : SYSTÈME. Le planificateur cron, jamais un humain.
            RolePnvb::Systeme->value => [
                'alertes.publier',
                'incidents.traiter',
                'comptes.debloquer',
            ],
        ];
    }
}
