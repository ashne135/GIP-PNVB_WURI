import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, brancherSessionPerdue, jeton } from '../api/client';

/**
 * LA SESSION : qui est connecté, ce qu'il a le droit de faire, et ce qu'il doit
 * faire avant toute autre chose.
 *
 * POINT DE VIGILANCE DU CADRAGE, répété ici parce qu'il se perd vite :
 * MASQUER UN BOUTON DANS REACT NE SÉCURISE RIEN. Les permissions servies ici
 * construisent une interface honnête — ne pas proposer ce qui sera refusé —
 * mais le serveur revérifie tout à chaque requête, Policy ET périmètre. Aucune
 * décision de sécurité ne se prend dans ce fichier.
 */

const Contexte = createContext(null);

export function FournisseurAuth({ children }) {
    const [session, setSession] = useState(null);
    const [chargement, setChargement] = useState(true);

    const oublierSession = useCallback(() => {
        jeton.effacer();
        setSession(null);
    }, []);

    // Le client appelle ceci sur un 401 : jeton expiré, ou révoqué ailleurs.
    useEffect(() => {
        brancherSessionPerdue(oublierSession);
    }, [oublierSession]);

    // Au démarrage, un jeton en mémoire ne prouve rien : seul /moi le dit.
    useEffect(() => {
        let annule = false;

        async function recuperer() {
            if (!jeton.lire()) {
                setChargement(false);

                return;
            }

            try {
                const profil = await api.lire('/moi');

                if (!annule) {
                    setSession(profil);
                }
            } catch {
                if (!annule) {
                    oublierSession();
                }
            } finally {
                if (!annule) {
                    setChargement(false);
                }
            }
        }

        recuperer();

        return () => {
            annule = true;
        };
    }, [oublierSession]);

    const connecter = useCallback(async (telephone, motDePasse) => {
        const { donnees } = await api.creer('/connexion', {
            telephone,
            mot_de_passe: motDePasse,
            nom_appareil: 'back-office',
        });

        jeton.ecrire(donnees.jeton);
        setSession(donnees);

        return donnees;
    }, []);

    const deconnecter = useCallback(async () => {
        try {
            // Révoquer côté serveur, pas seulement oublier côté client : un
            // jeton simplement effacé du navigateur resterait valable.
            await api.agir('/deconnexion');
        } catch {
            /* le jeton était peut-être déjà invalide */
        } finally {
            oublierSession();
        }
    }, [oublierSession]);

    /** Recharge le profil après une action qui change les droits ou les obligations. */
    const rafraichir = useCallback(async () => {
        const profil = await api.lire('/moi');
        setSession(profil);

        return profil;
    }, []);

    const valeur = useMemo(() => {
        const permissions = new Set(session?.permissions ?? []);
        const roles = new Set(session?.roles ?? []);
        const actions = session?.actions_requises ?? {};

        return {
            session,
            chargement,
            connecte: session !== null,
            utilisateur: session?.utilisateur ?? null,
            volontaire: session?.volontaire ?? null,
            perimetre: session?.perimetre ?? null,

            /** Affichage seulement : le serveur revérifie toujours. */
            peut: (permission) => permissions.has(permission),
            peutAuMoins: (...liste) => liste.some((p) => permissions.has(p)),
            aLeRole: (role) => roles.has(role),

            estNational: session?.perimetre?.niveau === 'national',
            estLectureSeule: roles.has('observateur'),

            // Ce que l'agent doit lever avant d'accéder au reste. Le serveur le
            // refuse de toute façon ; l'annoncer ici évite de le laisser buter
            // sur des refus qu'il ne comprendrait pas.
            doitChangerMotDePasse: Boolean(actions.changer_mot_de_passe),
            doitAccepterCharte: Boolean(actions.accepter_charte),
            versionCharte: actions.version_charte ?? null,

            connecter,
            deconnecter,
            rafraichir,
        };
    }, [session, chargement, connecter, deconnecter, rafraichir]);

    return <Contexte.Provider value={valeur}>{children}</Contexte.Provider>;
}

export function useAuth() {
    const contexte = useContext(Contexte);

    if (!contexte) {
        throw new Error('useAuth doit être utilisé à l’intérieur d’un FournisseurAuth.');
    }

    return contexte;
}
