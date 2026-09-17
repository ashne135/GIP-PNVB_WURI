import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Alertes } from '../src/pages/Alertes';

/**
 * PUBLIER UNE ALERTE DESCENDANTE.
 *
 * Ce qu'on protège ici : un chef d'antenne régional ne se voit PAS proposer la
 * portée nationale. Le serveur la lui refuse de toute façon — c'est lui qui
 * décide — mais la lui proposer reviendrait à lui faire écrire une consigne
 * pour les douze régions avant de la lui refuser.
 *
 * Et : la cible part avec l'alerte. Une alerte régionale sans région ne
 * toucherait personne tout en paraissant publiée.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), creer: vi.fn(), agir: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire, creer: appels.creer, agir: appels.agir },
}));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

function afficher({ peutPublier = true, estNational = true } = {}) {
    session.valeur = { peut: () => peutPublier, estNational };

    appels.lire.mockImplementation((url) => {
        if (url.startsWith('/referentiel/regions')) {
            return Promise.resolve([{ id: 5, nom: 'Nord' }, { id: 6, nom: 'Sud-Ouest' }]);
        }

        if (url.startsWith('/referentiel/centres')) {
            return Promise.resolve({ data: [{ id: 3, code: 'NRD-C001', nom: 'Centre Nord' }] });
        }

        return Promise.resolve({ data: [], current_page: 1, last_page: 1, total: 0 });
    });

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <MemoryRouter>
            <QueryClientProvider client={cache}>
                <Alertes />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

/**
 * Les filtres de la page portent eux aussi un « Niveau » : on interroge le
 * formulaire, pas la page entière, sans quoi la requête est ambiguë.
 */
function formulaire() {
    return within(screen.getByRole('button', { name: 'Publier l’alerte' }).closest('form'));
}

beforeEach(() => {
    appels.lire.mockReset();
    appels.creer.mockReset();
    appels.agir.mockReset();
    session.valeur = {};
});

describe('la publication d’une alerte', () => {
    it('n’offre le bouton qu’à qui porte la permission', async () => {
        afficher({ peutPublier: false });

        // Attendre la liste chargée : sans cela, l'absence du bouton ne
        // prouverait rien — il n'est pas encore rendu au premier passage.
        expect(await screen.findByText('Aucune alerte en cours')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Publier une alerte' })).not.toBeInTheDocument();
    });

    it('ne propose jamais la portée nationale à un chef d’antenne régional', async () => {
        afficher({ estNational: false });

        fireEvent.click(await screen.findByRole('button', { name: 'Publier une alerte' }));

        expect(screen.queryByRole('option', { name: 'Toutes les régions' })).not.toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /rôle/ })).not.toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Une région' })).toBeInTheDocument();
    });

    it('envoie la consigne avec sa cible, et rend le message du serveur', async () => {
        appels.creer.mockResolvedValue({
            message: 'Alerte ALR-2026-000001 publiée pour la région Nord.',
            donnees: {},
        });

        afficher();

        fireEvent.click(await screen.findByRole('button', { name: 'Publier une alerte' }));

        const champs = formulaire();

        fireEvent.change(champs.getByLabelText(/Titre/), { target: { value: 'Réunion régionale' } });
        fireEvent.change(champs.getByLabelText('Message'), { target: { value: 'Réunion lundi à 9 h.' } });
        fireEvent.change(champs.getByLabelText('Niveau'), { target: { value: 'important' } });
        fireEvent.change(champs.getByLabelText('Destinataires'), { target: { value: 'regionale' } });

        fireEvent.change(champs.getByLabelText('Région'), { target: { value: '5' } });

        fireEvent.click(screen.getByRole('button', { name: 'Publier l’alerte' }));

        await waitFor(() => expect(appels.creer).toHaveBeenCalledOnce());

        expect(appels.creer).toHaveBeenCalledWith('/alertes', {
            titre: 'Réunion régionale',
            message: 'Réunion lundi à 9 h.',
            niveau: 'important',
            portee: 'regionale',
            region_id: 5,
        });

        // Le message du serveur NOMME les destinataires : c'est lui qu'on montre.
        expect(await screen.findByText(/publiée pour la région Nord/)).toBeInTheDocument();
    });

    it('n’ouvre la liste des centres qu’une fois la région choisie', async () => {
        afficher();

        fireEvent.click(await screen.findByRole('button', { name: 'Publier une alerte' }));
        const champs = formulaire();

        fireEvent.change(champs.getByLabelText('Destinataires'), { target: { value: 'centre' } });

        expect(champs.getByLabelText('Centre')).toBeDisabled();

        fireEvent.change(champs.getByLabelText('Région'), { target: { value: '5' } });

        expect(await champs.findByRole('option', { name: /NRD-C001/ })).toBeInTheDocument();
        expect(champs.getByLabelText('Centre')).toBeEnabled();
    });
});
