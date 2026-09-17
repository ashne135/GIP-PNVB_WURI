/**
 * LE JOURNAL D'ACTIVITÉ : qui a fait quoi, et quand.
 *
 * Les noms techniques des journaux et des objets concernés ne veulent rien dire
 * pour qui les lit — « App\Models\VagueDeploiement » n'est pas une information.
 * Ces tables leur donnent le mot que l'on emploie sur le terrain, et le lien
 * vers la fiche quand elle existe.
 */
export const libellesJournaux = {
    compte: 'Comptes et accès',
    referentiel: 'Centres et sites',
    feuille_presence: 'Feuilles de présence',
    rapprochement: 'Écarts de présence',
    rapport: 'Rapports journaliers',
    incident: 'Incidents',
    kit: 'Parc de kits',
    kit_mouvement: 'Mouvements de kit',
    vague: 'Vagues et tirages',
    affectation: 'Affectations',
    tournee_site: 'Passages des kits',
    remplacement: 'Remplacements',
    alerte: 'Alertes',
    appreciation: 'Appréciations',
    import: 'Imports',
    export: 'Exports',
    parametre: 'Paramètres',
    piece_jointe: 'Photos',
    securite: 'Sécurité',
};

export function libelleJournal(log) {
    return libellesJournaux[log] ?? log ?? '—';
}

/**
 * Les objets sur lesquels un acte peut porter. Le lien n'est proposé que
 * lorsqu'une fiche existe réellement : promettre une page absente est pire que
 * de n'en proposer aucune.
 */
export const sujets = {
    'App\\Models\\User': { libelle: 'Compte' },
    'App\\Models\\Volontaire': { libelle: 'Volontaire' },
    'App\\Models\\Centre': { libelle: 'Centre', lien: (id) => `/centres/${id}` },
    'App\\Models\\Site': { libelle: 'Site', lien: (id) => `/sites/${id}` },
    'App\\Models\\Kit': { libelle: 'Kit', lien: (id) => `/kits/${id}` },
    'App\\Models\\KitMouvement': { libelle: 'Mouvement de kit' },
    'App\\Models\\VagueDeploiement': { libelle: 'Vague', lien: (id) => `/vagues/${id}` },
    'App\\Models\\Affectation': { libelle: 'Affectation' },
    'App\\Models\\TourneeSite': { libelle: 'Passage de kit', lien: () => '/tournees' },
    'App\\Models\\Remplacement': { libelle: 'Remplacement' },
    'App\\Models\\FeuillePresence': { libelle: 'Feuille de présence', lien: (id) => `/presences/feuilles/${id}` },
    'App\\Models\\RapportJournalier': { libelle: 'Rapport', lien: (id) => `/rapports/${id}` },
    'App\\Models\\Incident': { libelle: 'Incident', lien: (id) => `/incidents/${id}` },
    'App\\Models\\Alerte': { libelle: 'Alerte', lien: () => '/alertes' },
    'App\\Models\\Parametre': { libelle: 'Paramètre', lien: () => '/parametres' },
};

export function sujetDe(type) {
    return sujets[type] ?? { libelle: (type ?? '').split('\\').pop() || null };
}
