/** Les états d'un kit et les types de mouvement (cadrage, section 13). */
export const etatsKit = {
    fonctionnel: { libelle: 'Fonctionnel', ton: 'bon' },
    panne: { libelle: 'En panne', ton: 'attention' },
    perdu: { libelle: 'Perdu', ton: 'alerte' },
    vole: { libelle: 'Volé', ton: 'alerte' },
    reforme: { libelle: 'Réformé', ton: 'neutre' },
};

export function etatKit(valeur) {
    return etatsKit[valeur] ?? { libelle: valeur ?? '—', ton: 'neutre' };
}

/**
 * Les six mouvements, et ce que chacun exige.
 *
 * `destinataire` : le mouvement désigne un nouvel agent (remise, transfert).
 * `constat` : l'état du kit doit être constaté, parce que du matériel change
 * de mains — le serveur le refuse sinon.
 */
export const mouvements = {
    remise: { libelle: 'Remise initiale', destinataire: true, constat: true, detenu: false },
    transfert: { libelle: 'Transfert à un autre agent', destinataire: true, constat: true, detenu: true },
    changement_site: { libelle: 'Changement de site', site: true, detenu: true },
    restitution: { libelle: 'Restitution au parc', constat: true, detenu: true },
    panne: { libelle: 'Déclaration de panne', detenu: true },
    perte_vol: { libelle: 'Déclaration de perte ou de vol', circonstance: true, detenu: true },
};
