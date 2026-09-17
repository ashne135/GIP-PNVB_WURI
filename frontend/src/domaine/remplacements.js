/**
 * LES REMPLACEMENTS (cadrage, section 6).
 *
 * Remplacer n'est PAS supprimer : l'affectation du sortant est close avec son
 * motif, il bascule en réserve, son kit est transféré, et les deux accès sont
 * recalculés — le tout dans une seule transaction. C'est ce qui garde
 * l'historique lisible six mois plus tard.
 */
export const motifsRemplacement = {
    desistement: 'Désistement',
    abandon: 'Abandon de poste',
    indisponibilite: 'Indisponibilité',
    performance: 'Performance insuffisante',
};

export function motifRemplacement(valeur) {
    return motifsRemplacement[valeur] ?? valeur ?? '—';
}

/**
 * L'état du kit constaté au moment du transfert.
 *
 * Le serveur l'EXIGE dès que l'agent sortant détient un kit : sans lui, on ne
 * saura jamais qui a cassé quoi.
 */
export const etatsKitConstate = {
    bon: 'Bon',
    usage: 'Usagé',
    endommage: 'Endommagé',
    incomplet: 'Incomplet',
};
