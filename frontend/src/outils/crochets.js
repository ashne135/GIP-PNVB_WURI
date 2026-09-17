import { useCallback, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, avecParametres } from '../api/client';

/**
 * UNE LISTE PAGINÉE ET FILTRÉE.
 *
 * Le serveur pagine à la façon de Laravel : `{ data, current_page, last_page,
 * total, from, to }`. Ce crochet garde les filtres, remet la page à 1 dès
 * qu'un filtre change — sans quoi on se retrouve « page 7 sur 2 », écran vide,
 * sans comprendre pourquoi — et rend la liste avec sa pagination.
 */
export function useListe(cle, url, filtresInitiaux = {}) {
    const [filtres, setFiltres] = useState(filtresInitiaux);
    const [page, setPage] = useState(1);

    const parametres = useMemo(() => ({ ...filtres, page }), [filtres, page]);

    const requete = useQuery({
        queryKey: [cle, parametres],
        queryFn: () => api.lire(avecParametres(url, parametres)),
        placeholderData: (precedent) => precedent,
    });

    const changerFiltre = useCallback((nom, valeur) => {
        setFiltres((actuels) => ({ ...actuels, [nom]: valeur }));
        setPage(1);
    }, []);

    const reinitialiser = useCallback(() => {
        setFiltres(filtresInitiaux);
        setPage(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const donnees = requete.data;
    const paginee = donnees && Array.isArray(donnees.data);

    return {
        ...requete,
        lignes: paginee ? donnees.data : (donnees ?? []),
        pagination: paginee ? donnees : null,
        filtres,
        changerFiltre,
        reinitialiser,
        page,
        setPage,
    };
}

/**
 * UNE ACTION QUI MODIFIE QUELQUE CHOSE.
 *
 * Elle garde LE MESSAGE DU SERVEUR — « Rapport signé et transmis à X pour
 * visa », « 14 kits restent à récupérer » — plutôt que d'afficher un
 * « Enregistré » générique. Ces phrases sont écrites côté serveur pour être
 * lues par l'agent ; les jeter serait dommage.
 */
export function useAction(aRafraichir = []) {
    const cache = useQueryClient();
    const [enCours, setEnCours] = useState(false);
    const [message, setMessage] = useState(null);
    const [erreur, setErreur] = useState(null);

    const lancer = useCallback(
        async (appel) => {
            setEnCours(true);
            setErreur(null);
            setMessage(null);

            try {
                const resultat = await appel();
                setMessage(resultat?.message ?? null);

                await Promise.all(
                    aRafraichir.map((cleRequete) =>
                        cache.invalidateQueries({ queryKey: [cleRequete] }),
                    ),
                );

                return resultat;
            } catch (echec) {
                setErreur(echec);

                return null;
            } finally {
                setEnCours(false);
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [cache],
    );

    return {
        lancer,
        enCours,
        message,
        erreur,
        oublierMessage: () => setMessage(null),
        oublierErreur: () => setErreur(null),
    };
}

/**
 * UN TÉLÉCHARGEMENT (PDF, tableur).
 *
 * Le serveur rend un binaire et nomme le fichier dans `Content-Disposition` :
 * « rapport-opk-2026-09-14-PNVB-OPK00123.pdf » vaut mieux que « telechargement ».
 */
export function useTelechargement() {
    const [enCours, setEnCours] = useState(false);
    const [erreur, setErreur] = useState(null);

    const telecharger = useCallback(async (url, nomParDefaut) => {
        setEnCours(true);
        setErreur(null);

        try {
            const { fichier, nom } = await api.telecharger(url);
            const lien = document.createElement('a');
            const adresse = URL.createObjectURL(fichier);

            lien.href = adresse;
            // Le nom du serveur prime : il est plus precis que le notre.
            lien.download = nom ?? nomParDefaut;
            document.body.appendChild(lien);
            lien.click();
            lien.remove();
            URL.revokeObjectURL(adresse);
        } catch (echec) {
            setErreur(echec);
        } finally {
            setEnCours(false);
        }
    }, []);

    return { telecharger, enCours, erreur };
}
