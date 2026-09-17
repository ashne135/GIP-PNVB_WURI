/**
 * LES COULEURS DES GRAPHIQUES — toutes validées par le script de contrôle,
 * jamais choisies à l'œil.
 *
 * Surface : les cartes du back-office sont blanches, c'est contre #ffffff que
 * chaque couleur a été vérifiée.
 *
 * POURQUOI PAS LES COULEURS DU LOGO : le vert (#028428), le jaune (#F8C314) et
 * le rouge (#CD0819) du Programme habillent l'interface, jamais les données.
 * Le rouge et le vert sont déjà pris par l'erreur et le succès, et c'est le
 * couple que confondent la plupart des daltoniens : deux séries ainsi teintes
 * seraient illisibles pour une partie des lecteurs. Les marques de données
 * prennent donc le bleu de référence, qui passe bande de luminosité,
 * saturation et contraste.
 *
 * Le back-office n'a pas de thème sombre : ces valeurs ne sont validées que
 * pour le thème clair.
 */
export const viz = {
    surface: '#ffffff',

    // Série unique — bande L, saturation >= 0,10, contraste >= 3:1 : PASS.
    serie: '#2a78d6',
    // Lavis de l'aire : la teinte de la série à 10 %, jamais un aplat saturé.
    lavis: 'rgba(42, 120, 214, 0.10)',

    // Filets et axes : jamais du texte (2,7:1 et 1,6:1 sur blanc).
    grille: '#D9E0E1',
    axe: '#BCC7C9',

    // Encre des libellés : 6,3:1 sur blanc.
    texte: '#526366',
    texteFort: '#1A2022',
};

/**
 * LES CINQ CLASSES DU TAUX DE COUVERTURE — rampe ordinale, une seule teinte,
 * du clair au foncé. Contrôle « ordinal » : luminosité monotone, écarts
 * visibles, extrémité claire à 2,11:1 sur blanc, teinte unique : PASS.
 *
 * La même teinte que les barres de couverture : c'est la même mesure.
 */
export const CLASSES_COUVERTURE = [
    { min: 0, max: 10, couleur: '#86b6ef', libelle: 'moins de 10 %' },
    { min: 10, max: 25, couleur: '#5598e7', libelle: '10 à 25 %' },
    { min: 25, max: 50, couleur: '#2a78d6', libelle: '25 à 50 %' },
    { min: 50, max: 75, couleur: '#1c5cab', libelle: '50 à 75 %' },
    { min: 75, max: Infinity, couleur: '#104281', libelle: '75 % et plus' },
];

/** La classe d'un taux, ou null pour une valeur non mesurable. */
export function classeCouverture(taux) {
    if (taux === null || taux === undefined || Number.isNaN(Number(taux))) {
        return null;
    }

    const valeur = Number(taux);

    return (
        CLASSES_COUVERTURE.find((classe) => valeur >= classe.min && valeur < classe.max)
        ?? CLASSES_COUVERTURE[CLASSES_COUVERTURE.length - 1]
    );
}
