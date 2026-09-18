/**
 * L'ITINÉRAIRE VERS UN SITE (demande du client, 18/09/2026).
 *
 * Un site d'enregistrement se trouve souvent dans un village sans adresse :
 * pour une visite de suivi, le seul repère utilisable est le couple de
 * coordonnées. On ouvre donc Google Maps sur la DESTINATION, et c'est lui qui
 * calcule le trajet depuis la position du visiteur.
 *
 * CE QU'ON N'ENVOIE PAS : la position de l'agent, ni son historique. On ne
 * transmet qu'un point d'arrivée — celui d'un site public du dispositif.
 *
 * Sans coordonnées, on ne fabrique pas de lien : une adresse approximative
 * enverrait un chef d'antenne à des kilomètres du bon village.
 */
export function lienItineraire(latitude, longitude) {
    if (!estUneCoordonnee(latitude) || !estUneCoordonnee(longitude)) {
        return null;
    }

    // Forme documentée et stable des « Maps URLs » : elle ouvre l'application
    // Google Maps quand elle est installée, le navigateur sinon.
    return `https://www.google.com/maps/dir/?api=1&destination=${Number(latitude)},${Number(longitude)}&travelmode=driving`;
}

/** Le point seul, sans itinéraire : pour situer un site sans lancer un trajet. */
export function lienPosition(latitude, longitude) {
    if (!estUneCoordonnee(latitude) || !estUneCoordonnee(longitude)) {
        return null;
    }

    return `https://www.google.com/maps/search/?api=1&query=${Number(latitude)},${Number(longitude)}`;
}

function estUneCoordonnee(valeur) {
    if (valeur === null || valeur === undefined || valeur === '') {
        return false;
    }

    const nombre = Number(valeur);

    return Number.isFinite(nombre) && nombre !== 0;
}
