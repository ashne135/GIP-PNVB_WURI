import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ErreurApi } from '../src/api/client';
import { AccepterCharte } from '../src/pages/PremiereConnexion';

/**
 * L'ACCEPTATION DE LA CHARTE.
 *
 * Le texte vient du serveur, et la version acceptée est celle DU TEXTE AFFICHÉ :
 * jamais celle mémorisée à la connexion, qui a pu changer depuis.
 */
const session = vi.hoisted(() => ({ valeur: {} }));
const appels = vi.hoisted(() => ({ lire: vi.fn(), creer: vi.fn() }));

vi.mock('../src/auth/ContexteAuth', () => ({
    useAuth: () => session.valeur,
}));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire, creer: appels.creer },
}));

const texte = {
    version: '2026.1',
    provisoire: true,
    titre: 'Charte du volontaire',
    preambule: 'Ce que la plateforme enregistre.',
    articles: [{ titre: 'Ce qui est enregistré', texte: 'La position, pendant les heures de service.' }],
    engagement: 'J’ai lu cette charte et je l’accepte.',
};

function afficher() {
    session.valeur = {
        doitAccepterCharte: true,
        // Une version mémorisée à la connexion, différente de celle affichée.
        versionCharte: '2025.9',
        rafraichir: vi.fn().mockResolvedValue({}),
        deconnecter: vi.fn(),
    };

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <MemoryRouter initialEntries={['/premiere-connexion/charte']}>
                <Routes>
                    <Route path="/premiere-connexion/charte" element={<AccepterCharte />} />
                    <Route path="/" element={<p>Tableau de bord</p>} />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    appels.lire.mockReset();
    appels.creer.mockReset();
    appels.lire.mockResolvedValue(texte);
});

describe('la charte', () => {
    it('affiche le texte servi par le serveur, avec son avertissement provisoire', async () => {
        afficher();

        expect(await screen.findByText('Ce qui est enregistré')).toBeInTheDocument();
        expect(appels.lire).toHaveBeenCalledWith('/charte');
        expect(screen.getByText('Texte provisoire — sans valeur juridique')).toBeInTheDocument();
    });

    it('accepte la version du texte affiché, pas celle mémorisée à la connexion', async () => {
        appels.creer.mockResolvedValue({ message: 'Merci, la charte est acceptée.', donnees: {} });

        afficher();

        fireEvent.click(await screen.findByLabelText('J’ai lu cette charte et je l’accepte.'));
        fireEvent.click(screen.getByRole('button', { name: 'J’accepte la charte' }));

        await waitFor(() => expect(appels.creer).toHaveBeenCalledOnce());

        expect(appels.creer).toHaveBeenCalledWith('/charte/accepter', { version_charte: '2026.1' });
        expect(await screen.findByText('Tableau de bord')).toBeInTheDocument();
    });

    it('recharge le texte et décoche la case quand la charte a changé pendant la lecture', async () => {
        appels.creer.mockRejectedValue(
            new ErreurApi({
                message: 'La charte a été mise à jour pendant votre lecture. Relisez la nouvelle version avant de l’accepter.',
                statut: 409,
            }),
        );

        afficher();

        fireEvent.click(await screen.findByLabelText('J’ai lu cette charte et je l’accepte.'));
        fireEvent.click(screen.getByRole('button', { name: 'J’accepte la charte' }));

        expect(await screen.findByText(/mise à jour pendant votre lecture/)).toBeInTheDocument();
        await waitFor(() => expect(appels.lire).toHaveBeenCalledTimes(2));
        expect(screen.getByLabelText('J’ai lu cette charte et je l’accepte.')).not.toBeChecked();
    });
});
