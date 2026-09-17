import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Navigate, useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { useAuth } from '../auth/ContexteAuth';
import { Bouton, Champ, Saisie } from '../composants/Champs';
import { Chargement } from '../composants/Chargement';
import { Echec } from '../composants/Etats';

/**
 * LES DEUX OBLIGATIONS DE LA PREMIÈRE CONNEXION.
 *
 * Le serveur les impose par middleware : tant qu'elles ne sont pas levées, tout
 * le reste de l'API répond 403. Ces écrans ne les appliquent pas — ils les
 * rendent franchissables.
 */

function Cadre({ titre, etape, children }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-ardoise-100 px-4 py-10 font-sans">
            <div className="w-full max-w-2xl">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ardoise-500">
                    Première connexion · étape {etape} sur 2
                </p>
                <div className="rounded-lg border border-ardoise-200 bg-white p-6 shadow-sm">
                    <h1 className="text-lg font-semibold text-ardoise-900">{titre}</h1>
                    <div className="mt-4">{children}</div>
                </div>
            </div>
        </div>
    );
}

export function ChangerMotDePasse() {
    const auth = useAuth();
    const naviguer = useNavigate();

    const [actuel, setActuel] = useState('');
    const [nouveau, setNouveau] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [erreur, setErreur] = useState(null);
    const [enCours, setEnCours] = useState(false);

    if (!auth.doitChangerMotDePasse) {
        return <Navigate to={auth.doitAccepterCharte ? '/premiere-connexion/charte' : '/'} replace />;
    }

    async function soumettre(evenement) {
        evenement.preventDefault();
        setErreur(null);
        setEnCours(true);

        try {
            await api.creer('/mot-de-passe/changer', {
                // Les noms de ChangementMotDePasseRequest, côté serveur : la
                // règle « confirmed » cherche nouveau_mot_de_passe_confirmation.
                mot_de_passe_actuel: actuel,
                nouveau_mot_de_passe: nouveau,
                nouveau_mot_de_passe_confirmation: confirmation,
            });

            // Le profil porte les obligations restantes : le relire décide de
            // la suite, plutôt que de la supposer ici.
            const profil = await auth.rafraichir();

            naviguer(
                profil.actions_requises?.accepter_charte ? '/premiere-connexion/charte' : '/',
                { replace: true },
            );
        } catch (echec) {
            setErreur(echec);
        } finally {
            setEnCours(false);
        }
    }

    return (
        <Cadre titre="Choisissez votre mot de passe" etape={1}>
            <p className="text-sm text-ardoise-600">
                Le mot de passe qui vous a été remis est provisoire. Choisissez-en un que vous êtes
                seul à connaître : il ouvre l’accès à des données de mission.
            </p>

            <form onSubmit={soumettre} className="mt-5 space-y-4">
                {/* Le message général s'affiche dès qu'aucun champ ne peut le
                    porter : règle métier refusée, ou champ inattendu. */}
                {erreur
                    && (!erreur.estValidation
                        || Object.keys(erreur.erreurs).some(
                            (cle) => !['mot_de_passe_actuel', 'nouveau_mot_de_passe'].includes(cle),
                        )) && (
                    <div
                        className="rounded border border-brique-300 bg-brique-50 px-3 py-2 text-sm text-brique-900"
                        role="alert"
                    >
                        {erreur.message}
                    </div>
                )}

                <Champ nom="mot_de_passe_actuel" libelle="Mot de passe actuel" erreurs={erreur?.erreurs}>
                    <Saisie
                        type="password"
                        autoComplete="current-password"
                        value={actuel}
                        onChange={(e) => setActuel(e.target.value)}
                        required
                        autoFocus
                    />
                </Champ>

                <Champ
                    nom="nouveau_mot_de_passe"
                    libelle="Nouveau mot de passe"
                    erreurs={erreur?.erreurs}
                    aide="Au moins 8 caractères, dont au moins une lettre et un chiffre."
                >
                    <Saisie
                        type="password"
                        autoComplete="new-password"
                        value={nouveau}
                        onChange={(e) => setNouveau(e.target.value)}
                        required
                    />
                </Champ>

                <Champ nom="nouveau_mot_de_passe_confirmation" libelle="Confirmez le nouveau mot de passe">
                    <Saisie
                        type="password"
                        autoComplete="new-password"
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                        required
                    />
                </Champ>

                <Bouton type="submit" disabled={enCours}>
                    {enCours ? 'Enregistrement…' : 'Enregistrer et continuer'}
                </Bouton>
            </form>
        </Cadre>
    );
}

/**
 * L'ACCEPTATION DE LA CHARTE.
 *
 * Le texte vient du serveur (GET /charte) : c'est le même pour le web et le
 * mobile, et il n'en existe qu'un par version. La version renvoyée à
 * l'acceptation est celle DU TEXTE AFFICHÉ — si la charte change pendant la
 * lecture, le serveur refuse, et l'écran recharge le nouveau texte.
 */
export function AccepterCharte() {
    const auth = useAuth();
    const naviguer = useNavigate();

    const [lue, setLue] = useState(false);
    const [erreur, setErreur] = useState(null);
    const [enCours, setEnCours] = useState(false);

    const charte = useQuery({
        queryKey: ['charte'],
        queryFn: () => api.lire('/charte'),
        enabled: Boolean(auth.doitAccepterCharte),
    });

    if (!auth.doitAccepterCharte) {
        return <Navigate to="/" replace />;
    }

    async function accepter() {
        setErreur(null);
        setEnCours(true);

        try {
            await api.creer('/charte/accepter', { version_charte: charte.data.version });
            await auth.rafraichir();
            naviguer('/', { replace: true });
        } catch (echec) {
            setErreur(echec);

            // Le texte a changé pendant la lecture : on recharge le nouveau, et
            // la case est décochée — un consentement donné à l'ancien texte ne
            // se reporte pas sur le suivant.
            if (echec.statut === 409) {
                setLue(false);
                charte.refetch();
            }
        } finally {
            setEnCours(false);
        }
    }

    if (charte.isPending) {
        return (
            <Cadre titre="Charte du volontaire" etape={2}>
                <Chargement message="Chargement de la charte…" />
            </Cadre>
        );
    }

    if (charte.error) {
        return (
            <Cadre titre="Charte du volontaire" etape={2}>
                <Echec erreur={charte.error} onReessayer={charte.refetch} />
            </Cadre>
        );
    }

    const texte = charte.data;

    return (
        <Cadre titre={texte.titre} etape={2}>
            {texte.provisoire && (
                <div
                    className="mb-5 rounded border-2 border-brique-600 bg-brique-50 px-4 py-3 text-sm text-brique-900"
                    role="alert"
                >
                    <p className="font-bold uppercase tracking-wide">
                        Texte provisoire — sans valeur juridique
                    </p>
                    <p className="mt-1">
                        Ce texte décrit fidèlement ce que la plateforme fait, mais il n’a pas été
                        rédigé par un juriste. Il doit être remplacé par la charte officielle du
                        GIP-PNVB avant toute mise en service sur le terrain.
                    </p>
                </div>
            )}

            <p className="text-sm text-ardoise-600">{texte.preambule}</p>

            <div className="mt-5 max-h-96 space-y-4 overflow-y-auto rounded border border-ardoise-200 bg-ardoise-50 p-4">
                {texte.articles.map((article) => (
                    <section key={article.titre}>
                        <h2 className="text-sm font-semibold text-ardoise-900">{article.titre}</h2>
                        <p className="mt-1 text-sm leading-relaxed text-ardoise-700">
                            {article.texte}
                        </p>
                    </section>
                ))}
            </div>

            {erreur && (
                <div
                    className="mt-4 rounded border border-brique-300 bg-brique-50 px-3 py-2 text-sm text-brique-900"
                    role="alert"
                >
                    {erreur.message}
                </div>
            )}

            <label className="mt-5 flex items-start gap-3 text-sm text-ardoise-800">
                <input
                    type="checkbox"
                    checked={lue}
                    onChange={(e) => setLue(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-ardoise-400 text-pnvb-700 focus:ring-pnvb-300"
                />
                <span>{texte.engagement}</span>
            </label>

            <div className="mt-5 flex flex-wrap items-center gap-3">
                {/* Le bouton reste inactif tant que la case n'est pas cochée :
                    un consentement qu'on peut donner sans le vouloir n'en est
                    pas un, et celui-ci est enregistré avec sa date. */}
                <Bouton type="button" onClick={accepter} disabled={!lue || enCours || charte.isFetching}>
                    {enCours ? 'Enregistrement…' : 'J’accepte la charte'}
                </Bouton>
                <button
                    type="button"
                    onClick={() => auth.deconnecter()}
                    className="text-sm text-ardoise-600 underline hover:text-ardoise-800"
                >
                    Refuser et quitter
                </button>
            </div>

            <p className="mt-3 text-xs text-ardoise-500">
                Version {texte.version}. Votre acceptation est enregistrée avec sa date et la
                version du texte.
            </p>
        </Cadre>
    );
}
