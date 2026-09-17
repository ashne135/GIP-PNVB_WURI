/** Les statuts d'un centre et d'un site, tels que le serveur les accepte. */
export const statutsCentre = {
    planifie: { libelle: 'Planifié', ton: 'neutre' },
    ouvert: { libelle: 'Ouvert', ton: 'bon' },
    ferme: { libelle: 'Fermé', ton: 'attention' },
};

export const statutsSite = {
    planifie: { libelle: 'Planifié', ton: 'neutre' },
    ouvert: { libelle: 'Ouvert', ton: 'bon' },
    couvert: { libelle: 'Couvert', ton: 'info' },
    ferme: { libelle: 'Fermé', ton: 'attention' },
};

export function statut(table, valeur) {
    return table[valeur] ?? { libelle: valeur ?? '—', ton: 'neutre' };
}

/** Les rôles, avec le libellé qu'un agent reconnaît. */
export const libellesRoles = {
    volontaire_assistant: 'Assistant (A-OPK)',
    volontaire_operateur: 'Opérateur de kit',
    volontaire_superviseur: 'Superviseur de centre',
    controleur_terrain: 'Contrôleur terrain',
    chef_antenne_regional: 'Chef d’antenne régional',
    administrateur_national: 'Administrateur national',
    super_administrateur: 'Super administrateur',
    observateur: 'Observateur',
    systeme: 'Système',
};

/**
 * Les rôles qu'une matrice de notification peut contenir — les mêmes que le
 * serveur accepte. Les autres y seraient prévenus dans tout le pays.
 */
export const rolesNotifiables = [
    'volontaire_superviseur',
    'controleur_terrain',
    'chef_antenne_regional',
    'administrateur_national',
    'super_administrateur',
];

/** Les groupes de paramètres, dans l'ordre où on les lit. */
export const groupesParametres = [
    ['dispositif', 'Dispositif'],
    ['affectation', 'Affectation et tirage'],
    ['presence', 'Présence'],
    ['incidents', 'Incidents et escalade'],
    ['kits', 'Parc de kits'],
    ['comptes', 'Comptes et charte'],
    ['carte', 'Carte'],
    ['retention', 'Conservation des données'],
    ['sync', 'Synchronisation hors ligne'],
];
