/**
 * Les échelles des graphiques : une seule fonction place les marques, les
 * graduations et les libellés, pour qu'aucun libellé ne nomme une valeur que
 * le tracé n'atteint pas.
 */

/** Échelle linéaire : domaine vers plage. */
export function echelleLineaire([d0, d1], [r0, r1]) {
    const etendue = d1 - d0 || 1;

    return (valeur) => r0 + ((valeur - d0) / etendue) * (r1 - r0);
}

/**
 * Des graduations RONDES : 0 / 50 / 100 / 150, jamais 0 / 34 / 68 / 103.
 * Le plafond est la première graduation qui couvre le maximum.
 */
export function graduations(maximum, nombreVise = 4) {
    if (!Number.isFinite(maximum) || maximum <= 0) {
        return { max: 1, pas: 1, valeurs: [0, 1] };
    }

    const brut = maximum / nombreVise;
    const puissance = 10 ** Math.floor(Math.log10(brut));
    const fraction = brut / puissance;
    const facteur = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 2.5 ? 2.5 : fraction <= 5 ? 5 : 10;
    const pas = facteur * puissance;
    const plafond = Math.ceil(maximum / pas) * pas;

    const valeurs = [];

    for (let valeur = 0; valeur <= plafond + pas / 2; valeur += pas) {
        valeurs.push(Math.round(valeur * 1e6) / 1e6);
    }

    return { max: plafond, pas, valeurs };
}

/** L'index de la position la plus proche : le réticule s'aimante au jour. */
export function indexLePlusProche(x, positions) {
    if (positions.length === 0) {
        return null;
    }

    let meilleur = 0;

    for (let i = 1; i < positions.length; i += 1) {
        if (Math.abs(positions[i] - x) < Math.abs(positions[meilleur] - x)) {
            meilleur = i;
        }
    }

    return meilleur;
}
