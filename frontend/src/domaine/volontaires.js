/**
 * LE VOCABULAIRE DES VOLONTAIRES : catégories, accès, remise des identifiants,
 * et le canevas du fichier des retenus.
 *
 * LE CANEVAS DÉCRIT ICI EST CELUI QUE LE SERVEUR LIT (CanevasVolontaires,
 * cadrage v2, section 6). Il n'ajoute aucune colonne : il dit, pour chacune,
 * si elle est exigée et quelles valeurs passent. S'il s'écarte du serveur, c'est
 * le serveur qui a raison — et ce fichier qui doit être corrigé.
 */

export const categories = [
    { valeur: 'superviseur', libelle: 'Superviseur de centre' },
    { valeur: 'operateur', libelle: 'Opérateur de kit' },
    { valeur: 'assistant', libelle: 'Assistant (A-OPK)' },
];

export function libelleCategorie(valeur) {
    return categories.find((c) => c.valeur === valeur)?.libelle ?? null;
}

/** L'accès à la plateforme. Seuls « actif » et « disponible » ouvrent une session. */
export const statutsCompte = {
    inactif: { libelle: 'Inactif', ton: 'neutre', precision: 'pas encore affecté' },
    actif: { libelle: 'Actif', ton: 'bon' },
    disponible: { libelle: 'Disponible', ton: 'info', precision: 'entre deux vagues' },
    ferme: { libelle: 'Fermé', ton: 'neutre' },
};

export function peutSeConnecter(statut) {
    return statut === 'actif' || statut === 'disponible';
}

/** La remise des identifiants : courriel, puis SMS, puis bordereau en formation. */
export const etatsRemise = {
    non_envoye: { libelle: 'Non envoyé', ton: 'attention' },
    envoye: { libelle: 'Envoyé', ton: 'info' },
    echec: { libelle: 'Échec d’envoi', ton: 'alerte' },
    remis_main_propre: { libelle: 'Remis en main propre', ton: 'info' },
    premiere_connexion_effectuee: { libelle: 'Première connexion faite', ton: 'bon' },
};

export const canauxRemise = {
    courriel: 'Courriel',
    sms: 'SMS',
    main_propre: 'Bordereau',
};

export const typesImport = {
    volontaires_retenus: 'Retenus',
    volontaires_reserve: 'Liste d’attente',
};

export const statutsImport = {
    analyse: { libelle: 'Analyse en cours', ton: 'neutre' },
    apercu_pret: { libelle: 'À confirmer', ton: 'attention' },
    applique: { libelle: 'Importé', ton: 'bon' },
    annule: { libelle: 'Annulé', ton: 'neutre' },
    echec: { libelle: 'Illisible', ton: 'alerte' },
};

/**
 * Les colonnes du fichier, dans l'ordre du fichier réel du client.
 * `exigence` : « obligatoire », « A-OPK » (exigée pour ce seul profil) ou
 * « facultative ».
 */
export const colonnesCanevas = [
    { intitule: 'N°', exigence: 'facultative', valeurs: 'Numéro d’ordre, non utilisé.' },
    {
        intitule: 'Email Address',
        exigence: 'facultative',
        valeurs: 'Adresse valide. Sans adresse, les identifiants partent par SMS ou sur le bordereau de formation.',
    },
    {
        intitule: 'numéro',
        exigence: 'obligatoire',
        valeurs: '8 chiffres (70 12 34 56). C’est l’identifiant de connexion : un seul compte par numéro.',
    },
    { intitule: 'Nom', exigence: 'obligatoire', valeurs: 'Mis en majuscules à l’import.' },
    { intitule: 'Prénom(s)', exigence: 'obligatoire', valeurs: 'Mis en capitale initiale à l’import.' },
    { intitule: 'Date de naissance', exigence: 'facultative', valeurs: 'JJ/MM/AAAA, AAAA-MM-JJ ou date Excel.' },
    { intitule: 'Lieu de naissance', exigence: 'facultative', valeurs: 'Texte libre.' },
    { intitule: 'Sexe', exigence: 'facultative', valeurs: 'Masculin ou Féminin (M, F acceptés).' },
    {
        intitule: 'N° CNIB / Passeport',
        exigence: 'facultative',
        valeurs: 'Unique. Premier critère de dédoublonnage, jamais affiché dans les listes.',
    },
    {
        intitule: 'Date d’établissement de la CNIB / du Passeport',
        exigence: 'facultative',
        valeurs: 'Même format que la date de naissance, jamais dans le futur.',
    },
    {
        intitule: 'Profil',
        exigence: 'facultative',
        valeurs: 'Superviseur de centre (ou SUP), Opérateur de kit (ou OPK), A-OPK (ou Assistant). '
            + 'Vide : la fiche est importée « à qualifier » et doit recevoir un profil avant toute vague.',
    },
    { intitule: 'Region', exigence: 'facultative', valeurs: 'Nom ou code d’une région du référentiel.' },
    { intitule: 'Province', exigence: 'facultative', valeurs: 'Lue, non utilisée.' },
    {
        intitule: 'commune',
        exigence: 'A-OPK',
        valeurs: 'Exigée quand plusieurs communes ont une localité du même nom.',
    },
    { intitule: 'arrondissement', exigence: 'facultative', valeurs: 'Lue, non utilisée.' },
    {
        intitule: 'secteur · quartier · village',
        exigence: 'A-OPK',
        valeurs: 'Une des trois, au nom exact du référentiel territorial : l’A-OPK reste rattaché à sa localité.',
    },
    { intitule: 'site', exigence: 'facultative', valeurs: 'Lue, non utilisée : le site vient de l’affectation.' },
];

/** Les clés internes que le serveur nomme quand une colonne manque. */
export const libellesColonnesServeur = {
    telephone: 'numéro',
    nom: 'Nom',
    prenoms: 'Prénom(s)',
};

/** La localité telle que le fichier la nomme : une seule des trois colonnes est remplie. */
export function localiteDuFichier(donnees) {
    const nom = ['village', 'secteur', 'quartier']
        .map((cle) => (donnees?.[cle] ?? '').trim())
        .find(Boolean);

    if (!nom) {
        return null;
    }

    const commune = (donnees?.commune ?? '').trim();

    return commune ? `${nom} (${commune})` : nom;
}
