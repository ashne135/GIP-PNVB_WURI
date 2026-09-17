import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Journal } from '../src/pages/Journal';

/**
 * LE JOURNAL D'ACTIVITÉ.
 *
 * Ce qu'on protège :
 *
 *   - un acte SANS AUTEUR s'affiche « acteur système », jamais vide : c'est
 *     l'information exacte quand le planificateur nocturne agit seul, et une
 *     ligne vide laisserait croire à un oubli d'enregistrement ;
 *   - les noms techniques ne fuient pas à l'écran : on lit « Centre », pas
 *     « App\Models\Centre » ;
 *   - le détail de l'acte est rendu tel qu'il a été enregistré, y compris le
 *     « avant / après » imbriqué.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn() }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire },
}));

const actes = [
    {
        id: 3,
        log_name: 'compte',
        description: 'Changement de statut de compte : actif → disponible',
        subject_type: 'App\\Models\\User',
        subject_id: 9,
        causer: null,
        properties: { avant: { statut: 'actif' }, apres: 'disponible', evenement: 'recalcul_planifie' },
        created_at: '2026-09-16T04:30:00.000000Z',
    },
    {
        id: 2,
        log_name: 'referentiel',
        description: 'Centre créé',
        subject_type: 'App\\Models\\Centre',
        subject_id: 3,
        causer: { id: 1, nom: 'Ouédraogo', prenoms: 'Awa', telephone: '+22670000002' },
        properties: { code: 'BAN-BAGA-C001' },
        created_at: '2026-09-15T10:00:00.000000Z',
    },
];

function afficher() {
    appels.lire.mockImplementation((url) => {
        if (url.startsWith('/journal/journaux')) {
            return Promise.resolve([
                { log_name: 'compte', total: 543 },
                { log_name: 'referentiel', total: 5 },
            ]);
        }

        return Promise.resolve({ data: actes, current_page: 1, last_page: 1, total: 2 });
    });

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <MemoryRouter>
            <QueryClientProvider client={cache}>
                <Journal />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

beforeEach(() => {
    appels.lire.mockReset();
});

describe('le journal d’activité', () => {
    it('nomme l’auteur d’un acte signé', async () => {
        afficher();

        expect(await screen.findByText('Centre créé')).toBeInTheDocument();
        expect(screen.getByText(/Awa Ouédraogo/)).toBeInTheDocument();
    });

    it('affiche « acteur système » plutôt qu’une ligne vide', async () => {
        afficher();

        expect(await screen.findByText(/Acteur système/)).toBeInTheDocument();
    });

    it('traduit les noms techniques des journaux et des objets', async () => {
        afficher();

        // « App\Models\Centre » n'est pas une information pour qui lit.
        expect(await screen.findByRole('link', { name: 'Centre' })).toHaveAttribute('href', '/centres/3');
        expect(screen.getByText('Comptes et accès')).toBeInTheDocument();
        expect(screen.queryByText(/App\\Models/)).not.toBeInTheDocument();
    });

    it('rend le détail de l’acte, y compris le « avant » imbriqué', async () => {
        afficher();

        expect(await screen.findByText('BAN-BAGA-C001')).toBeInTheDocument();
        expect(screen.getByText('statut : actif')).toBeInTheDocument();
        expect(screen.getByText('recalcul_planifie')).toBeInTheDocument();
    });
});
