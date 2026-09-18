/**
 * LE MENU, construit depuis les permissions.
 *
 * Chaque entrée nomme les permissions qui la rendent utile. Un chef d'antenne
 * régional et un administrateur national ne voient pas le même menu — non pas
 * parce qu'on leur cache des choses, mais parce que proposer une page qui
 * répondra « action non autorisée » est une promesse qu'on ne tient pas.
 *
 * RAPPEL : ceci ne protège rien. Le serveur revérifie droit et périmètre à
 * chaque requête. Une entrée retirée du menu reste atteignable à l'adresse, et
 * c'est la Policy qui refusera — pas ce fichier.
 *
 * Les entrées dont la page n'existe pas encore portent `aVenir: true` : elles
 * apparaissent grisées plutôt que de disparaître, pour que la structure du
 * back-office soit lisible dès la tâche 13.
 */
export const entrees = [
    {
        titre: 'Pilotage',
        liens: [
            {
                chemin: '/',
                libelle: 'Tableau de bord',
                permissions: ['tableau_bord.consulter'],
            },
            {
                chemin: '/cartographie',
                libelle: 'Cartographie',
                permissions: ['tableau_bord.consulter'],
            },
            {
                chemin: '/alertes',
                libelle: 'Alertes',
                permissions: ['alertes.consulter'],
            },
            {
                chemin: '/exports',
                libelle: 'Exports',
                permissions: ['exports.generer'],
            },
        ],
    },
    {
        titre: 'Terrain',
        liens: [
            {
                chemin: '/presences',
                libelle: 'Présences',
                permissions: ['presence.consulter_feuille', 'presence.consulter_carte', 'ecarts.consulter'],
            },
            {
                chemin: '/rapports',
                libelle: 'Rapports journaliers',
                permissions: ['rapports.consulter'],
            },
            {
                chemin: '/incidents',
                libelle: 'Incidents',
                permissions: ['incidents.consulter'],
            },
            {
                chemin: '/appreciations',
                libelle: 'Appréciations',
                permissions: ['appreciations.consulter_equipe'],
            },
        ],
    },
    {
        titre: 'Déploiement',
        liens: [
            {
                chemin: '/vagues',
                libelle: 'Vagues et affectations',
                permissions: ['vagues.consulter'],
            },
            {
                chemin: '/equipes',
                libelle: 'Équipes déployées',
                permissions: ['affectations.consulter'],
            },
            {
                chemin: '/tournees',
                libelle: 'Passages des kits',
                permissions: ['affectations.consulter'],
            },
            {
                chemin: '/volontaires',
                libelle: 'Volontaires',
                permissions: ['volontaires.consulter'],
            },
            {
                chemin: '/kits',
                libelle: 'Parc de kits',
                permissions: ['kits.consulter'],
            },
        ],
    },
    {
        titre: 'Référentiel',
        liens: [
            {
                chemin: '/centres',
                libelle: 'Centres et sites',
                permissions: ['referentiel.consulter'],
            },
            {
                chemin: '/territoire',
                libelle: 'Référentiel territorial',
                permissions: ['referentiel.consulter'],
            },
        ],
    },
    {
        titre: 'Administration',
        liens: [
            {
                chemin: '/administration/comptes',
                libelle: 'Comptes d’administration',
                permissions: ['roles.attribuer'],
            },
            {
                chemin: '/administration/incidents',
                libelle: 'Listes des incidents',
                permissions: ['incidents.nomenclatures'],
            },
            {
                chemin: '/administration/synchronisations',
                libelle: 'Synchronisations',
                permissions: ['journal.consulter'],
            },
            {
                chemin: '/parametres',
                libelle: 'Paramètres',
                permissions: ['parametres.consulter'],
            },
            {
                chemin: '/journal',
                libelle: 'Journal d’activité',
                permissions: ['journal.consulter'],
            },
        ],
    },
];

/**
 * Le menu réellement affiché : les entrées sans permission utile disparaissent,
 * et une rubrique vide disparaît avec elles.
 */
export function menuPour(peutAuMoins) {
    return entrees
        .map((rubrique) => ({
            ...rubrique,
            liens: rubrique.liens.filter(
                (lien) => !lien.permissions || peutAuMoins(...lien.permissions),
            ),
        }))
        .filter((rubrique) => rubrique.liens.length > 0);
}
