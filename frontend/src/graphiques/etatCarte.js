/**
 * CE QUE LA CARTE PEUT MONTRER — calculé à part, sans Leaflet, pour être testé.
 *
 * Une carte vide sans explication fait croire à une panne. Celle-ci dit ce qui
 * lui manque, en chiffres : combien de régions ont un contour, combien de sites
 * ont des coordonnées. Aucune position n'est inventée pour la remplir.
 */
const nombres = new Intl.NumberFormat('fr-FR');

export function etatCarte({ regions = [], sitesCarte = null }) {
    const totalRegions = regions.length;
    const contours = regions.filter((region) => Boolean(region.contour_geojson)).length;
    const localises = sitesCarte?.localises ?? 0;
    const totalSites = sitesCarte?.total_sites ?? 0;

    const resume = `${contours} contour${contours > 1 ? 's' : ''} de région sur ${totalRegions}`
        + ` · ${nombres.format(localises)} site${localises > 1 ? 's' : ''} localisé${localises > 1 ? 's' : ''}`
        + ` sur ${nombres.format(totalSites)}`;

    const vide = contours === 0 && localises === 0;

    return {
        contours,
        totalRegions,
        localises,
        totalSites,
        vide,
        partielle: !vide && (contours < totalRegions || localises < totalSites),
        resume,
        message: vide
            ? 'Aucun contour de région ni aucune coordonnée de site n’est encore chargé. '
                + 'La carte se complétera dès réception des fichiers géographiques ; '
                + 'la couverture reste lisible dans le graphique en barres.'
            : null,
    };
}
