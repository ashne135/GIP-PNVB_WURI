import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ErreurApi } from '../src/api/client';
import { ChangerMotDePasse } from '../src/pages/PremiereConnexion';

/**
 * LE CHANGEMENT DE MOT DE PASSE DE PREMIÈRE CONNEXION.
 *
 * Ces tests existent parce que l'écran a été livré cassé : il envoyait des noms
 * de champs que le serveur n'attend pas, et le refus qui en résultait ne
 * s'affichait nulle part. L'agent cliquait, rien ne se passait.
 */

const session = vi.hoisted(() => ({ valeur: {} }));
const appels = vi.hoisted(() => ({ creer: vi.fn() }));

vi.mock('../src/auth/ContexteAuth', () => ({
    useAuth: () => session.valeur,
}));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { creer: appels.creer },
}));

function afficher() {
    session.valeur = {
        doitChangerMotDePasse: true,
        doitAccepterCharte: false,
        rafraichir: vi.fn().mockResolvedValue({ actions_requises: {} }),
    };

    return render(
        <MemoryRouter initialEntries={['/premiere-connexion/mot-de-passe']}>
            <Routes>
                <Route path="/premiere-connexion/mot-de-passe" element={<ChangerMotDePasse />} />
                <Route path="/" element={<p>Tableau de bord</p>} />
            </Routes>
        </MemoryRouter>,
    );
}

function remplirEtEnvoyer() {
    fireEvent.change(screen.getByLabelText(/^Mot de passe actuel/), { target: { value: 'ChangerMoi#2026' } });
    fireEvent.change(screen.getByLabelText(/^Nouveau mot de passe/), { target: { value: 'Ouaga2026' } });
    fireEvent.change(screen.getByLabelText(/^Confirmez/), { target: { value: 'Ouaga2026' } });
    fireEvent.click(screen.getByRole('button', { name: /Enregistrer et continuer/ }));
}

beforeEach(() => {
    appels.creer.mockReset();
});

describe('le changement de mot de passe', () => {
    it('envoie les noms de champs que le serveur attend', async () => {
        appels.creer.mockResolvedValue({ message: 'Votre mot de passe a été changé.', donnees: null });

        afficher();
        remplirEtEnvoyer();

        await waitFor(() => expect(appels.creer).toHaveBeenCalledOnce());

        expect(appels.creer).toHaveBeenCalledWith('/mot-de-passe/changer', {
            mot_de_passe_actuel: 'ChangerMoi#2026',
            nouveau_mot_de_passe: 'Ouaga2026',
            nouveau_mot_de_passe_confirmation: 'Ouaga2026',
        });

        // Une fois le mot de passe changé, l'agent quitte l'écran.
        expect(await screen.findByText('Tableau de bord')).toBeInTheDocument();
    });

    it('affiche un refus métier qui ne porte sur aucun champ', async () => {
        appels.creer.mockRejectedValue(
            new ErreurApi({ message: 'Le mot de passe actuel est incorrect.', statut: 422, donnees: null }),
        );

        afficher();
        remplirEtEnvoyer();

        expect(await screen.findByRole('alert')).toHaveTextContent('Le mot de passe actuel est incorrect.');
    });

    it('affiche une erreur de champ sous le bon champ', async () => {
        appels.creer.mockRejectedValue(
            new ErreurApi({
                message: 'Certaines informations sont incorrectes ou manquantes.',
                statut: 422,
                erreurs: { nouveau_mot_de_passe: ['Les deux mots de passe saisis ne sont pas identiques.'] },
            }),
        );

        afficher();
        remplirEtEnvoyer();

        expect(
            await screen.findByText('Les deux mots de passe saisis ne sont pas identiques.'),
        ).toBeInTheDocument();
    });

    it('annonce la règle réelle du serveur, pas une règle inventée', () => {
        afficher();

        expect(screen.getByText('Au moins 8 caractères, dont au moins une lettre et un chiffre.')).toBeInTheDocument();
    });
});
