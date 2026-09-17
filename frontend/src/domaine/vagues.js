/**
 * LES VAGUES DE DÉPLOIEMENT (cadrage, section 7).
 *
 * Le parcours est en quatre temps, et l'écran doit les rendre visibles :
 * planifier, tirer, relire, valider. RIEN N'EST NOTIFIÉ tant que la proposition
 * n'est pas validée — c'est ce qui autorise à rejouer un tirage sans
 * conséquence pour personne.
 */
export const statutsVague = {
    brouillon: { libelle: 'Brouillon', ton: 'neutre', etape: 'Planifiée, en attente de tirage' },
    proposee: { libelle: 'Proposition', ton: 'attention', etape: 'Proposition à relire, rien n’est notifié' },
    validee: { libelle: 'Validée', ton: 'info', etape: 'Accès ouverts' },
    active: { libelle: 'Active', ton: 'bon', etape: 'Mission en cours' },
    cloturee: { libelle: 'Clôturée', ton: 'neutre', etape: 'Mission terminée' },
    annulee: { libelle: 'Annulée', ton: 'alerte', etape: 'Vague abandonnée' },
};

export function statutVague(valeur) {
    return statutsVague[valeur] ?? { libelle: valeur ?? '—', ton: 'neutre', etape: '' };
}

/** Les trois rôles de terrain d'une affectation. */
export const rolesTerrain = {
    superviseur: 'Superviseur de centre',
    operateur: 'Opérateur de kit',
    assistant: 'Assistant (A-OPK)',
};

export function roleTerrain(valeur) {
    return rolesTerrain[valeur] ?? valeur ?? '—';
}

/**
 * La catégorie de volontaire qu'un rôle de terrain exige.
 *
 * Un ajustement ne doit jamais proposer un agent d'une autre catégorie : le
 * serveur le refuse, et proposer un choix voué au refus fait perdre du temps.
 */
export const categoriePourRole = {
    superviseur: 'superviseur',
    operateur: 'operateur',
    assistant: 'assistant',
};
