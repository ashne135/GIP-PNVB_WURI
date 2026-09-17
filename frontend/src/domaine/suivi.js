/**
 * LES LIBELLÉS DU SUIVI : écarts de présence, appréciations, synchronisations.
 *
 * Les codes viennent du serveur ; seuls les mots à afficher vivent ici. Un
 * code inconnu s'affiche tel quel plutôt que de disparaître.
 */

export const typesEcart = {
    present_sans_releve: 'Déclaré présent, aucun relevé dans la zone du site',
    releve_zone_declare_absent: 'Déclaré absent, relevé dans la zone du site',
};

export const statutsEcart = {
    ouvert: { libelle: 'Ouvert', ton: 'attention' },
    examine: { libelle: 'Examiné', ton: 'info' },
    clos: { libelle: 'Clos', ton: 'neutre' },
};

export const presencesAgent = {
    present: { libelle: 'Présent', ton: 'bon' },
    absent: { libelle: 'Absent', ton: 'alerte' },
    absent_justifie: { libelle: 'Absent justifié', ton: 'attention' },
};

export const productionsAgent = {
    satisfaisant: { libelle: 'Satisfaisant', ton: 'bon' },
    passable: { libelle: 'Passable', ton: 'attention' },
    peu_satisfaisant: { libelle: 'Peu satisfaisant', ton: 'alerte' },
};

export const anomaliesAgent = {
    retard: 'Retard',
    absenteisme: 'Absentéisme',
    propos_discourtois: 'Propos discourtois',
    autre: 'Autre',
};

export const categoriesAgent = {
    aopk: 'A-OPK',
    opk: 'Opérateur de kit',
};

/** Les types d'éléments qu'un téléphone synchronise. */
export const typesSynchronisation = {
    signal_arrivee: 'Signal d’arrivée',
    releve_position: 'Relevé de position',
    feuille_presence: 'Feuille de présence',
    rapport_journalier: 'Rapport journalier',
    visa_rapport: 'Visa de rapport',
    incident: 'Incident',
    mouvement_kit: 'Mouvement de kit',
    lecture_alerte: 'Lecture d’alerte',
    reponse_appreciation: 'Réponse à une appréciation',
};

export function libelleDe(table, code) {
    const valeur = table[code];

    if (valeur === undefined) {
        return code ?? '—';
    }

    return typeof valeur === 'string' ? valeur : valeur.libelle;
}
