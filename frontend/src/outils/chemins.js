/**
 * LES FICHIERS PUBLICS (logo, images), quand le back-office n'est pas servi à
 * la racine d'un domaine.
 *
 * Écrire `src="/logo-pnvb.jpg"` désigne la RACINE DU DOMAINE. Servi sous
 * https://exemple.net/pnvbwuri, le navigateur va alors chercher l'image à
 * https://exemple.net/logo-pnvb.jpg — c'est-à-dire chez une tout autre
 * application, et l'image ne s'affiche pas. La panne est silencieuse : aucune
 * erreur à l'écran, juste un logo manquant.
 *
 * `import.meta.env.BASE_URL` porte le chemin figé à la compilation, et se
 * termine toujours par une barre oblique. On le préfixe ici, une fois pour
 * toutes, plutôt que de le répéter à chaque balise.
 */
export function fichierPublic(nom) {
    const base = import.meta.env.BASE_URL ?? '/';

    return `${base.endsWith('/') ? base : `${base}/`}${String(nom).replace(/^\//, '')}`;
}
