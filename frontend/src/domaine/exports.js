/** Les contenus produits chaque nuit (cadrage, tâche 18). */
export const typesExport = {
    rapports: 'Rapports journaliers visés',
    presences: 'Listes des présents',
    incidents: 'Incidents déclarés',
    tableau_bord: 'Tableau de bord — journées agrégées',
    couverture: 'Couverture par localité',
    kits: 'Parc de kits',
};

/**
 * Trois états, et un seul est un échec.
 *
 * « Vide » dit qu'il n'y avait rien à exporter ce jour-là : ce n'est pas une
 * panne, et l'afficher comme telle ferait chercher un problème inexistant.
 */
export const statutsExport = {
    pret: { libelle: 'Prêt', ton: 'bon' },
    vide: { libelle: 'Aucune donnée', ton: 'neutre' },
    echec: { libelle: 'Échec', ton: 'alerte' },
};

export function typeExport(valeur) {
    return typesExport[valeur] ?? valeur ?? '—';
}

export function statutExport(valeur) {
    return statutsExport[valeur] ?? { libelle: valeur ?? '—', ton: 'neutre' };
}

/** Une taille de fichier lisible, en kilo-octets. */
export function tailleLisible(octets) {
    if (!octets) {
        return '—';
    }

    return octets < 1024 ? `${octets} o` : `${Math.round(octets / 1024)} Ko`;
}
