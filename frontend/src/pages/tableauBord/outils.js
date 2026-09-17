/**
 * Le dernier jour qui a de l'activité.
 *
 * La synthèse ne peut pas se contenter de « hier » : un dimanche, ou le
 * lendemain d'un jour sans rapport visé, elle afficherait des zéros qui
 * ressemblent à une panne. On prend le dernier jour de la période où quelque
 * chose a été enregistré.
 *
 * Le jour est ramené à AAAA-MM-JJ : il repart vers l'API comme filtre, et une
 * date-heure ISO (« 2026-09-11T00:00:00.000000Z ») n'y retrouverait aucune
 * journée. Le serveur envoie déjà cette forme ; la découpe ici protège contre
 * un retour en arrière.
 */
export function dernierJourActif(jours) {
    const actifs = (jours ?? []).filter((jour) => Number(jour.enregistrements) > 0);

    return actifs.length > 0 ? String(actifs[actifs.length - 1].date).slice(0, 10) : null;
}

/** La date de début d'une période de N jours se terminant hier. */
export function debutPeriode(nombreDeJours, maintenant = Date.now()) {
    return new Date(maintenant - nombreDeJours * 86_400_000).toISOString().slice(0, 10);
}

/**
 * Une date courte pour les axes : 03/09.
 *
 * Lue en AAAA-MM-JJ et formatée en UTC : sans cela, un navigateur réglé sur un
 * fuseau à l'ouest de Greenwich afficherait la veille.
 */
export function dateCourte(iso) {
    const [annee, mois, jour] = String(iso).slice(0, 10).split('-').map(Number);

    return new Date(Date.UTC(annee, mois - 1, jour)).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        timeZone: 'UTC',
    });
}
