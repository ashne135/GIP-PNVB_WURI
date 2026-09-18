import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ErreurApi,
    api,
    avecParametres,
    brancherSessionPerdue,
    client,
    jeton,
} from '../src/api/client';

/**
 * LE CLIENT DE L'API.
 *
 * Ces règles ne doivent pas casser en silence : c'est ici que se décide si un
 * agent voit la phrase que le serveur lui destine, et si un refus de droit le
 * déconnecte à tort.
 */

/** Un faux transport : on décide ce que le serveur répond. */
function repondre(statut, corps) {
    client.defaults.adapter = async (config) => {
        const reponse = { data: corps, status: statut, statusText: '', headers: {}, config };

        if (statut >= 200 && statut < 300) {
            return reponse;
        }

        const erreur = new Error('requête refusée');
        erreur.response = reponse;
        erreur.config = config;

        throw erreur;
    };
}

/** Le serveur ne répond pas du tout : coupure réseau. */
function neRienRepondre() {
    client.defaults.adapter = async () => {
        throw new Error('Network Error');
    };
}

afterEach(() => {
    delete client.defaults.adapter;
    brancherSessionPerdue(() => {});
});

describe("l'enveloppe du serveur", () => {
    it('rend data, pour que les pages ignorent l’enveloppe', async () => {
        repondre(200, { success: true, message: 'Profil récupéré.', data: { utilisateur: { id: 7 } } });

        expect(await api.lire('/moi')).toEqual({ utilisateur: { id: 7 } });
    });

    it('rend AUSSI le message sur une action, parce qu’il est précis', async () => {
        repondre(201, {
            success: true,
            message: 'Rapport signé et transmis à Ouédraogo Salif pour visa.',
            data: { id: 12 },
        });

        const resultat = await api.agir('/rapports/12/soumettre');

        expect(resultat.message).toContain('transmis à Ouédraogo Salif');
        expect(resultat.donnees).toEqual({ id: 12 });
    });

    it('supporte une réponse sans data', async () => {
        repondre(200, { success: true, message: 'Vous êtes déconnecté.' });

        expect(await api.lire('/deconnexion')).toBeNull();
    });
});

describe('les refus', () => {
    it('remonte le message français du serveur, pas un message inventé', async () => {
        repondre(422, {
            success: false,
            message: 'Indiquez ce qui doit être corrigé : un rejet sans motif est inexploitable.',
            data: { erreurs: { motif: ['Ce champ est obligatoire.'] } },
        });

        await expect(api.agir('/rapports/1/rejeter')).rejects.toSatisfy((erreur) => {
            expect(erreur).toBeInstanceOf(ErreurApi);
            expect(erreur.message).toContain('rejet sans motif');
            expect(erreur.estValidation).toBe(true);
            // Le détail champ par champ permet de surligner le bon champ.
            expect(erreur.erreurs.motif[0]).toBe('Ce champ est obligatoire.');

            return true;
        });
    });

    it('DÉCONNECTE sur 401 : le jeton est expiré ou révoqué', async () => {
        jeton.ecrire('jeton-perime');
        const perdue = vi.fn();
        brancherSessionPerdue(perdue);

        repondre(401, { success: false, message: 'Non authentifié.' });

        await expect(api.lire('/moi')).rejects.toBeInstanceOf(ErreurApi);

        expect(perdue).toHaveBeenCalledOnce();
        expect(jeton.lire()).toBeNull();
    });

    it('NE DÉCONNECTE PAS sur 403 : c’est une permission, pas une panne de session', async () => {
        jeton.ecrire('jeton-valable');
        const perdue = vi.fn();
        brancherSessionPerdue(perdue);

        repondre(403, {
            success: false,
            message: "Vous n'êtes pas le supérieur désigné de ce rapport.",
        });

        await expect(api.agir('/rapports/3/viser')).rejects.toSatisfy((erreur) => {
            expect(erreur.estRefus).toBe(true);

            return true;
        });

        expect(perdue).not.toHaveBeenCalled();
        // Le jeton reste : l'agent est bien connecté, il n'a simplement pas ce droit.
        expect(jeton.lire()).toBe('jeton-valable');
    });

    it('expose l’action requise quand le serveur en impose une', async () => {
        repondre(403, {
            success: false,
            message: "Vous devez d'abord lire et accepter la charte du volontaire.",
            data: { action_requise: 'accepter_charte' },
        });

        await expect(api.lire('/rapports')).rejects.toSatisfy((erreur) => {
            expect(erreur.actionRequise).toBe('accepter_charte');

            return true;
        });
    });

    it('dit franchement quand le serveur est injoignable', async () => {
        neRienRepondre();

        await expect(api.lire('/moi')).rejects.toSatisfy((erreur) => {
            expect(erreur.statut).toBe(0);
            expect(erreur.message).toContain('injoignable');

            return true;
        });
    });
});

describe('le jeton', () => {
    it('accompagne chaque requête quand il existe', async () => {
        jeton.ecrire('abc123');

        let vu = null;
        client.defaults.adapter = async (config) => {
            vu = config.headers.Authorization;

            return { data: { success: true, data: null }, status: 200, headers: {}, config };
        };

        await api.lire('/moi');

        expect(vu).toBe('Bearer abc123');
    });

    it('ne met aucun en-tête quand il n’y en a pas', async () => {
        let vu = 'non-defini';
        client.defaults.adapter = async (config) => {
            vu = config.headers.Authorization;

            return { data: { success: true, data: null }, status: 200, headers: {}, config };
        };

        await api.lire('/referentiel/regions');

        expect(vu).toBeUndefined();
    });

    it('survit à un stockage bloqué, comme en navigation privée', () => {
        const vraiSetItem = window.localStorage.setItem;
        window.localStorage.setItem = () => {
            throw new Error('stockage refusé');
        };

        // Ne doit pas lever : l'application continue, sans mémoire.
        expect(() => jeton.ecrire('x')).not.toThrow();

        window.localStorage.setItem = vraiSetItem;
    });
});

describe('les paramètres d’adresse', () => {
    it('écarte les filtres vides, qu’un serveur prendrait pour des valeurs', () => {
        expect(
            avecParametres('/incidents', {
                statut: 'nouveau',
                gravite: '',
                centre_id: null,
                page: undefined,
                ouverts: 1,
            }),
        ).toBe('/incidents?statut=nouveau&ouverts=1');
    });

    it('rend l’adresse nue quand rien n’est renseigné', () => {
        expect(avecParametres('/kits', { etat: '', centre_id: null })).toBe('/kits');
    });
});

describe('ce qui compte comme erreur de validation', () => {
    it('un 422 SANS détail de champ est une règle métier, et doit rester visible', async () => {
        repondre(422, { success: false, message: 'Le mot de passe actuel est incorrect.', data: null });

        await expect(api.creer('/mot-de-passe/changer', {})).rejects.toSatisfy((erreur) => {
            // Aucun champ ne peut porter ce message : l'écran doit l'afficher.
            expect(erreur.estValidation).toBe(false);
            expect(erreur.message).toBe('Le mot de passe actuel est incorrect.');

            return true;
        });
    });

    it('un 422 AVEC détail de champ est une validation', async () => {
        repondre(422, {
            success: false,
            message: 'Certaines informations sont incorrectes ou manquantes.',
            data: { erreurs: { nouveau_mot_de_passe: ['Choisissez un nouveau mot de passe.'] } },
        });

        await expect(api.creer('/mot-de-passe/changer', {})).rejects.toSatisfy((erreur) => {
            expect(erreur.estValidation).toBe(true);

            return true;
        });
    });
});

/**
 * LES FICHIERS PUBLICS SUIVENT LE CHEMIN DE DÉPLOIEMENT.
 *
 * Le logo de la barre latérale pointait sur « /logo-pnvb.jpg » : servi sous
 * /pnvbwuri, le navigateur allait le chercher à la racine du domaine — chez
 * une autre application — et l'image ne s'affichait pas, sans la moindre
 * erreur à l'écran.
 */
describe('les fichiers publics', () => {
    it('préfixent le chemin de base, et ne partent jamais de la racine', async () => {
        const { fichierPublic } = await import('../src/outils/chemins');

        expect(fichierPublic('logo-pnvb.jpg')).toBe(`${import.meta.env.BASE_URL}logo-pnvb.jpg`);
        // Une barre oblique de tête ne doit pas casser la composition.
        expect(fichierPublic('/logo-pnvb.jpg')).toBe(fichierPublic('logo-pnvb.jpg'));
        expect(fichierPublic('logo-pnvb.jpg').startsWith('//')).toBe(false);
    });
});

/**
 * L'ITINÉRAIRE VERS UN SITE (demande du client, 18/09/2026).
 *
 * Un site d'enregistrement est souvent dans un village sans adresse : seules
 * ses coordonnées y mènent. Sans elles, on ne fabrique aucun lien — un trajet
 * vers un point approximatif enverrait un chef d'antenne à des kilomètres.
 */
describe('l’itinéraire vers un site', () => {
    it('ouvre Google Maps sur la destination, et rien d’autre', async () => {
        const { lienItineraire, lienPosition } = await import('../src/outils/itineraire');

        expect(lienItineraire(11.9456, -3.0021)).toBe(
            'https://www.google.com/maps/dir/?api=1&destination=11.9456,-3.0021&travelmode=driving',
        );
        expect(lienPosition('11.9456', '-3.0021')).toBe(
            'https://www.google.com/maps/search/?api=1&query=11.9456,-3.0021',
        );
    });

    it('ne fabrique aucun lien sans coordonnées', async () => {
        const { lienItineraire } = await import('../src/outils/itineraire');

        expect(lienItineraire(null, null)).toBeNull();
        expect(lienItineraire(undefined, -3.0021)).toBeNull();
        expect(lienItineraire('', '')).toBeNull();
        // 0, 0 : le point « nul » de l'Atlantique, jamais un site du Burkina.
        expect(lienItineraire(0, 0)).toBeNull();
        expect(lienItineraire('abc', 'def')).toBeNull();
    });
});
