import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/ContexteAuth';
import { Bouton, Champ, Saisie } from '../composants/Champs';

/**
 * LA CONNEXION.
 *
 * L'identifiant est le NUMÉRO DE TÉLÉPHONE, pas une adresse électronique :
 * beaucoup de volontaires n'en ont pas, et c'est le numéro qui a servi à leur
 * remettre leurs identifiants.
 */
export function Connexion() {
    const auth = useAuth();
    const naviguer = useNavigate();
    const emplacement = useLocation();

    const [telephone, setTelephone] = useState('');
    const [motDePasse, setMotDePasse] = useState('');
    const [erreur, setErreur] = useState(null);
    const [enCours, setEnCours] = useState(false);

    async function soumettre(evenement) {
        evenement.preventDefault();
        setErreur(null);
        setEnCours(true);

        try {
            const session = await auth.connecter(telephone.trim(), motDePasse);
            const actions = session.actions_requises ?? {};

            // L'ordre suit celui des middlewares du serveur : mot de passe,
            // puis charte. Envoyer l'agent ailleurs le ferait rebondir.
            if (actions.changer_mot_de_passe) {
                naviguer('/premiere-connexion/mot-de-passe', { replace: true });
            } else if (actions.accepter_charte) {
                naviguer('/premiere-connexion/charte', { replace: true });
            } else {
                naviguer(emplacement.state?.depuis ?? '/', { replace: true });
            }
        } catch (echec) {
            setErreur(echec);
        } finally {
            setEnCours(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-ardoise-100 px-4 py-10 font-sans">
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <p className="text-lg font-semibold text-pnvb-800">GIP-PNVB · Projet WURI</p>
                    <p className="mt-1 text-sm text-ardoise-600">
                        Plateforme de gestion des volontaires
                    </p>
                </div>

                <form
                    onSubmit={soumettre}
                    className="space-y-4 rounded-lg border border-ardoise-200 bg-white p-6 shadow-sm"
                >
                    <h1 className="text-base font-semibold text-ardoise-900">Connexion</h1>

                    {erreur && (
                        <div
                            className="rounded border border-brique-300 bg-brique-50 px-3 py-2 text-sm text-brique-900"
                            role="alert"
                        >
                            {erreur.message}
                        </div>
                    )}

                    <Champ
                        nom="telephone"
                        libelle="Numéro de téléphone"
                        erreurs={erreur?.erreurs}
                        aide="Le numéro auquel vos identifiants ont été remis."
                    >
                        <Saisie
                            type="tel"
                            name="telephone"
                            autoComplete="username"
                            inputMode="tel"
                            placeholder="+226 70 00 00 00"
                            value={telephone}
                            onChange={(e) => setTelephone(e.target.value)}
                            required
                            autoFocus
                        />
                    </Champ>

                    <Champ nom="mot_de_passe" libelle="Mot de passe" erreurs={erreur?.erreurs}>
                        <Saisie
                            type="password"
                            name="mot_de_passe"
                            autoComplete="current-password"
                            value={motDePasse}
                            onChange={(e) => setMotDePasse(e.target.value)}
                            required
                        />
                    </Champ>

                    <Bouton type="submit" disabled={enCours} className="w-full">
                        {enCours ? 'Connexion…' : 'Se connecter'}
                    </Bouton>

                    <p className="text-xs text-ardoise-500">
                        Mot de passe oublié ou identifiants non reçus : adressez-vous à votre chef
                        d’antenne régional.
                    </p>
                </form>
            </div>
        </div>
    );
}
