/**
 * LE FORMATAGE, en un seul endroit.
 *
 * Sur une plateforme où les mêmes chiffres partent en PDF opposable, en export
 * tableur et à l'écran, laisser chaque page formater à sa façon finirait par
 * produire trois versions du même nombre.
 */

const nombres = new Intl.NumberFormat('fr-FR');

export function nombre(valeur) {
    if (valeur === null || valeur === undefined || valeur === '') {
        return '—';
    }

    return nombres.format(valeur);
}

export function pourcentage(valeur, decimales = 1) {
    if (valeur === null || valeur === undefined || valeur === '') {
        return '—';
    }

    return `${Number(valeur).toFixed(decimales).replace('.', ',')} %`;
}

export function date(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

export function dateLongue(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export function dateHeure(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function heure(valeur) {
    if (!valeur) {
        return '—';
    }

    // L'API rend tantôt « 08:30 », tantôt une date complète.
    return valeur.length <= 8 ? valeur.slice(0, 5) : dateHeure(valeur);
}

/** La veille : la journée que le tableau de bord montre par défaut. */
export function veille() {
    return new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);
}

export function aujourdhui() {
    return new Date().toISOString().slice(0, 10);
}

/** « il y a 3 jours », pour situer un retard sans faire calculer le lecteur. */
export function depuis(iso) {
    if (!iso) {
        return '—';
    }

    const jours = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);

    if (jours <= 0) {
        return "aujourd'hui";
    }

    if (jours === 1) {
        return 'hier';
    }

    return `il y a ${jours} jours`;
}

/** Un libellé de statut : on affiche le mot, jamais le code technique. */
export function humaniser(valeur) {
    if (!valeur) {
        return '—';
    }

    const mot = String(valeur).replace(/_/g, ' ');

    return mot.charAt(0).toUpperCase() + mot.slice(1);
}

/**
 * LE NOM D'UNE PERSONNE.
 *
 * `nom_complet` n'existe que sur l'utilisateur connecté (/moi) : ailleurs,
 * l'API charge les relations en `id, nom, prenoms`, et User::nomComplet() est
 * une méthode, pas un attribut sérialisé. Afficher `user.nom_complet` sur une
 * relation chargée donnerait un champ vide, sans erreur.
 *
 * L'ordre suit exactement User::nomComplet() côté serveur — prénoms puis nom —
 * pour qu'un même agent porte le même nom à l'écran et sur ses PDF.
 */
export function nomDe(personne) {
    if (!personne) {
        return '—';
    }

    if (personne.nom_complet) {
        return personne.nom_complet;
    }

    const nom = [personne.prenoms, personne.nom].filter(Boolean).join(' ').trim();

    return nom || '—';
}
