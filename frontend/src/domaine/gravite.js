/**
 * Les quatre niveaux de gravité d'un incident (canevas client, section F).
 *
 * Le ton de la pastille suit la gravité, mais LE MOT RESTE : un niveau ne se
 * lit jamais à la seule couleur — ni pour un daltonien, ni sur un écran délavé
 * par le soleil.
 */
export const gravites = {
    1: { libelle: 'Mineur', description: 'Traité localement', ton: 'bon' },
    2: { libelle: 'Modéré', description: 'Intervention du superviseur', ton: 'attention' },
    3: { libelle: 'Majeur', description: 'Impact sur la sécurité ou la continuité', ton: 'alerte' },
    4: { libelle: 'Critique', description: 'Danger grave, intervention urgente', ton: 'alerte' },
};

export function gravite(niveau) {
    return gravites[niveau] ?? { libelle: '—', description: '', ton: 'neutre' };
}

/** Les statuts de traitement, section J. */
export const statutsIncident = {
    nouveau: { libelle: 'Nouveau', ton: 'alerte' },
    pris_en_charge: { libelle: 'Pris en charge', ton: 'attention' },
    en_cours: { libelle: 'En cours', ton: 'info' },
    resolu: { libelle: 'Résolu', ton: 'bon' },
    cloture: { libelle: 'Clos', ton: 'neutre' },
};

export function statutIncident(valeur) {
    return statutsIncident[valeur] ?? { libelle: valeur ?? '—', ton: 'neutre' };
}
