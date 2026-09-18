import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ListeKits } from '../src/pages/kits/ListeKits';

/**
 * COCHER, PUIS EFFACER POUR DE BON.
 *
 * Ce qu'on protège :
 *
 *   - RIEN NE PART AU PREMIER CLIC. Le premier annonce ce qui va disparaître,
 *     en le nommant ; le second exécute. Une suppression ne se défait pas ;
 *   - la case d'en-tête coche la PAGE, et la recoche décoche tout ;
 *   - CHAQUE REFUS EST NOMMÉ avec son motif. « Certaines ont échoué » ferait
 *     réessayer au hasard ;
 *   - sans le droit, aucune case n'apparaît : on ne montre pas une prise qui
 *     n'existe pas.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), creer: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: appels,
}));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

const kits = [
    { id: 1, reference: 'KIT-0001', etat: 'fonctionnel', detenteur: null, centre_courant: null, site_courant: null },
    { id: 2, reference: 'KIT-0002', etat: 'fonctionnel', detenteur: null, centre_courant: null, site_courant: null },
];

function monter({ droits = ['kits.consulter', 'kits.gerer', 'donnees.supprimer'] } = {}) {
    session.valeur = { peut: (droit) => droits.includes(droit) };

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter><ListeKits /></MemoryRouter>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    vi.clearAllMocks();

    appels.lire.mockImplementation((url) => {
        if (url.startsWith('/kits/synthese')) {
            return Promise.resolve({ total: 2, attribues: 0, disponibles: 2, non_restitues: 0 });
        }

        if (url.startsWith('/referentiel/centres')) {
            return Promise.resolve({ data: [] });
        }

        return Promise.resolve({ data: kits, meta: { total: 2, current_page: 1, last_page: 1 } });
    });
});

describe('supprimer des lignes cochées', () => {
    it('ne supprime rien au premier clic, et nomme ce qui partira', async () => {
        monter();

        fireEvent.click(await screen.findByLabelText('Choisir KIT-0001'));
        fireEvent.click(screen.getByRole('button', { name: 'Supprimer définitivement' }));

        // Le serveur n'a rien reçu : l'écran demande confirmation d'abord.
        expect(appels.creer).not.toHaveBeenCalled();
        const bandeau = screen.getByRole('region', { name: 'Suppression définitive' });
        expect(within(bandeau).getByText('Supprimer définitivement 1 kit ?')).toBeInTheDocument();
        expect(within(bandeau).getByText('KIT-0001')).toBeInTheDocument();
    });

    it('envoie la famille et les identifiants au second clic', async () => {
        appels.creer.mockResolvedValue({
            message: '1 kit supprimé définitivement.',
            donnees: { supprimes: [{ id: 1, libelle: 'KIT-0001' }], refusees: [] },
        });

        monter();

        fireEvent.click(await screen.findByLabelText('Choisir KIT-0001'));
        fireEvent.click(screen.getByRole('button', { name: 'Supprimer définitivement' }));
        fireEvent.click(screen.getByRole('button', { name: 'Oui, supprimer définitivement' }));

        await waitFor(() => expect(appels.creer).toHaveBeenCalledWith('/suppressions', { famille: 'kit', ids: [1] }));

        expect(await screen.findByText(/1 supprimé définitivement/)).toBeInTheDocument();
    });

    it('affiche le motif de chaque ligne conservée', async () => {
        appels.creer.mockResolvedValue({
            message: '1 kit supprimé définitivement. 1 n’a pas pu l’être.',
            donnees: {
                supprimes: [{ id: 1, libelle: 'KIT-0001' }],
                refusees: [{ id: 2, libelle: 'KIT-0002', motif: 'Suppression refusée : 3 mouvements en dépendent.' }],
            },
        });

        monter();

        fireEvent.click(await screen.findByLabelText('Cocher toute la page'));
        fireEvent.click(screen.getByRole('button', { name: 'Supprimer définitivement' }));
        fireEvent.click(screen.getByRole('button', { name: 'Oui, supprimer définitivement' }));

        // Le motif exact est lisible, pas un « certaines ont échoué ».
        expect(await screen.findByText(/3 mouvements en dépendent/)).toBeInTheDocument();

        const bandeau = screen.getByRole('region', { name: 'Suppression définitive' });
        expect(within(bandeau).getByText('KIT-0002')).toBeInTheDocument();
    });

    it('coche puis décoche toute la page avec la même case', async () => {
        monter();

        const entete = await screen.findByLabelText('Cocher toute la page');

        fireEvent.click(entete);
        const bandeau = screen.getByRole('region', { name: 'Suppression définitive' });
        expect(within(bandeau).getByText('2')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Supprimer définitivement' })).toBeInTheDocument();

        fireEvent.click(entete);
        expect(screen.queryByRole('button', { name: 'Supprimer définitivement' })).toBeNull();
    });

    it('ne montre aucune case à qui n’a pas le droit d’effacer', async () => {
        monter({ droits: ['kits.consulter', 'kits.gerer'] });

        await screen.findByText('Parc de kits');
        expect(screen.queryByLabelText('Cocher toute la page')).toBeNull();
        expect(screen.queryByLabelText('Choisir KIT-0001')).toBeNull();
    });
});
