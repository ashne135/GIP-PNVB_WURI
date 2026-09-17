/** Les trois niveaux de rapport journalier (cadrage, section 9). */
export const typesRapport = {
    aopk: { libelle: 'Accueil (A-OPK)', court: 'A-OPK' },
    opk: { libelle: 'Production (opérateur)', court: 'OPK' },
    superviseur: { libelle: 'Centre (superviseur)', court: 'Superviseur' },
};

/** Le cycle de vie d'un rapport, de sa saisie à sa clôture. */
export const statutsRapport = {
    brouillon: { libelle: 'Brouillon', ton: 'neutre' },
    soumis: { libelle: 'Signé, en attente de visa', ton: 'attention' },
    vise: { libelle: 'Visé', ton: 'bon' },
    rejete: { libelle: 'Renvoyé pour correction', ton: 'alerte' },
    clos: { libelle: 'Clos', ton: 'neutre' },
};

export function statutRapport(valeur) {
    return statutsRapport[valeur] ?? { libelle: valeur ?? '—', ton: 'neutre' };
}

export function typeRapport(valeur) {
    return typesRapport[valeur] ?? { libelle: valeur ?? '—', court: valeur ?? '—' };
}
