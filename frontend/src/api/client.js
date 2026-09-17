import axios from 'axios';

/**
 * LE CLIENT DE L'API.
 *
 * Le serveur répond TOUJOURS la même enveloppe :
 *
 *     { success: bool, message: string, data: object|array|null }
 *
 * Ce module la défait une fois pour toutes. Sans cela, chaque page rejouerait
 * le même `reponse.data.data` et finirait par oublier `message` — qui est la
 * phrase en français que le serveur destine à l'agent, et la seule chose
 * lisible quand quelque chose refuse.
 */

const CLE_JETON = 'pnvb.jeton';

export const client = axios.create({
    baseURL: '/api/v1',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

// ---------------------------------------------------------------------------
// Le jeton
// ---------------------------------------------------------------------------

/**
 * Le jeton Bearer vit dans localStorage.
 *
 * COMPROMIS ASSUMÉ : un jeton en localStorage est lisible par un script injecté
 * dans la page. L'alternative — un cookie httpOnly — n'est pas ouverte ici :
 * `sanctum.guard` est vide côté serveur, précisément pour qu'un jeton révoqué
 * le soit vraiment, sans repli sur la session. On compense par la révocation
 * côté serveur à la déconnexion, qui invalide le jeton et pas seulement sa
 * copie locale.
 *
 * Chaque accès est protégé : en navigation privée ou avec le stockage bloqué,
 * localStorage lève une exception au lieu de rendre null.
 */
export const jeton = {
    lire() {
        try {
            return window.localStorage.getItem(CLE_JETON);
        } catch {
            return null;
        }
    },
    ecrire(valeur) {
        try {
            window.localStorage.setItem(CLE_JETON, valeur);
        } catch {
            /* sans mémoire, la session dure le temps de l'onglet */
        }
    },
    effacer() {
        try {
            window.localStorage.removeItem(CLE_JETON);
        } catch {
            /* rien à effacer */
        }
    },
};

client.interceptors.request.use((config) => {
    const valeur = jeton.lire();

    if (valeur) {
        config.headers.Authorization = `Bearer ${valeur}`;
    }

    return config;
});

// ---------------------------------------------------------------------------
// Les erreurs
// ---------------------------------------------------------------------------

/**
 * Une erreur d'API, normalisée.
 *
 * Elle porte le message français du serveur, le code HTTP, et le détail champ
 * par champ quand il s'agit d'une validation — c'est ce détail qui permet de
 * surligner le bon champ plutôt que d'afficher une bannière rouge générique.
 */
export class ErreurApi extends Error {
    constructor({ message, statut, erreurs, donnees }) {
        super(message);
        this.name = 'ErreurApi';
        this.statut = statut;
        this.erreurs = erreurs ?? {};
        this.donnees = donnees ?? null;
    }

    /** Le droit est refusé — ce n'est pas une panne, et on ne déconnecte pas. */
    get estRefus() {
        return this.statut === 403;
    }

    /**
     * Une erreur de VALIDATION porte un détail champ par champ.
     *
     * Le statut 422 seul ne suffit pas : l'API l'emploie aussi pour une RÈGLE
     * MÉTIER refusée — « Le mot de passe actuel est incorrect », « Ce kit est
     * déjà détenu » — sans aucun champ à surligner. Traiter ces refus comme des
     * erreurs de champ les rendait invisibles : aucun champ ne les affichait.
     */
    get estValidation() {
        return this.statut === 422 && Object.keys(this.erreurs).length > 0;
    }

    get estIntrouvable() {
        return this.statut === 404;
    }

    /** Trop de tentatives : le serveur dit en combien de temps réessayer. */
    get estLimite() {
        return this.statut === 429;
    }

    /** Une obligation à lever avant toute autre action : charte, mot de passe. */
    get actionRequise() {
        return this.donnees?.action_requise ?? null;
    }
}

/** Appelé quand le serveur dit que la session n'est plus valable. */
let surSessionPerdue = () => {};

export function brancherSessionPerdue(rappel) {
    surSessionPerdue = rappel;
}

client.interceptors.response.use(
    (reponse) => reponse,
    (erreur) => {
        const reponse = erreur.response;

        // Pas de réponse du tout : coupure réseau, serveur injoignable. C'est
        // fréquent sur le terrain, et ça ne doit pas ressembler à un bogue.
        if (!reponse) {
            return Promise.reject(
                new ErreurApi({
                    message:
                        'Le serveur est injoignable. Vérifiez votre connexion, puis réessayez.',
                    statut: 0,
                }),
            );
        }

        // 401 : le jeton a expiré ou a été révoqué. C'est le SEUL cas où l'on
        // déconnecte. Un 403 veut dire « ce compte n'a pas le droit » —
        // déconnecter alors ferait croire à une panne de session là où il n'y a
        // qu'une permission manquante.
        if (reponse.status === 401) {
            jeton.effacer();
            surSessionPerdue();
        }

        const corps = reponse.data ?? {};

        return Promise.reject(
            new ErreurApi({
                message: corps.message || 'Une erreur est survenue.',
                statut: reponse.status,
                erreurs: corps.data?.erreurs,
                donnees: corps.data,
            }),
        );
    },
);

// ---------------------------------------------------------------------------
// Les verbes
// ---------------------------------------------------------------------------

/** Rend directement `data` : les pages n'ont pas à connaître l'enveloppe. */
async function sansMessage(methode, url, donnees, config) {
    const reponse = await client.request({ method: methode, url, data: donnees, ...config });

    return reponse.data?.data ?? null;
}

/**
 * Rend l'enveloppe entière.
 *
 * Toute action qui MODIFIE quelque chose passe par ici : le serveur rédige un
 * message précis — « Rapport signé et transmis à X pour visa », « 14 kits
 * restent à récupérer » — et ce message vaut mieux que n'importe quel «\u00c9lément
 * enregistré » écrit côté client.
 */
async function avecMessage(methode, url, donnees, config) {
    const reponse = await client.request({ method: methode, url, data: donnees, ...config });

    return {
        message: reponse.data?.message ?? '',
        donnees: reponse.data?.data ?? null,
    };
}

export const api = {
    lire: (url, config) => sansMessage('get', url, undefined, config),
    creer: (url, donnees, config) => avecMessage('post', url, donnees, config),
    modifier: (url, donnees, config) => avecMessage('put', url, donnees, config),
    agir: (url, donnees, config) => avecMessage('post', url, donnees, config),
    supprimer: (url, config) => avecMessage('delete', url, undefined, config),

    /**
     * Un téléchargement : PDF ou tableur.
     *
     * Rend le binaire ET le nom que le serveur lui donne — « rapport-opk-
     * 2026-09-14-PNVB-OPK00123.pdf » vaut mieux que « telechargement ». Le nom
     * vient de Content-Disposition, que la configuration CORS expose
     * explicitement pour le cas d'un déploiement sur deux domaines.
     */
    telecharger: async (url, config) => {
        const reponse = await client.get(url, { responseType: 'blob', ...config });

        return { fichier: reponse.data, nom: nomDuFichier(reponse.headers) };
    },
};

/** Le nom porté par Content-Disposition, ou null s'il est absent. */
function nomDuFichier(entetes) {
    const disposition = entetes?.['content-disposition'] ?? entetes?.['Content-Disposition'];

    if (!disposition) {
        return null;
    }

    // filename*=UTF-8''... d'abord : c'est la forme qui porte les accents.
    const encode = /filename\*=UTF-8''([^;]+)/i.exec(disposition);

    if (encode) {
        try {
            return decodeURIComponent(encode[1]);
        } catch {
            /* nom mal encodé : on retombe sur la forme simple */
        }
    }

    const simple = /filename="?([^";]+)"?/i.exec(disposition);

    return simple ? simple[1] : null;
}

/**
 * Construit une adresse avec ses paramètres, en écartant ce qui est vide.
 * Sans ce tri, un filtre non renseigné partirait en `?statut=` et le serveur
 * y verrait une valeur, pas une absence.
 */
export function avecParametres(url, parametres = {}) {
    const utiles = Object.entries(parametres).filter(
        ([, valeur]) => valeur !== undefined && valeur !== null && valeur !== '',
    );

    if (utiles.length === 0) {
        return url;
    }

    const chaine = new URLSearchParams(utiles.map(([cle, valeur]) => [cle, String(valeur)]));

    return `${url}?${chaine.toString()}`;
}
