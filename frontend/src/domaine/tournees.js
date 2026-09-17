/**
 * Le passage d'un kit sur un site.
 *
 * 12 294 sites pour 966 kits : un kit ne peut pas tenir douze sites à la fois,
 * il les couvre EN SÉQUENCE. Le passage dit sur quel site le kit se trouve un
 * jour donné — et donc où l'opérateur qui le porte travaille ce jour-là.
 */
export const statutsTournee = {
    planifiee: { libelle: 'Planifiée', ton: 'neutre' },
    en_cours: { libelle: 'En cours', ton: 'bon' },
    terminee: { libelle: 'Terminée', ton: 'info' },
    reportee: { libelle: 'Reportée', ton: 'attention' },
};
