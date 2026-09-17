import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Exports } from '../src/pages/Exports';

/**
 * L'ÉCRAN DES EXPORTS DÉPOSÉS.
 *
 * Deux choses à ne pas casser : un export sans donnée ne propose pas de
 * téléchargement — et le dit, au lieu d'offrir un bouton qui rendrait une
 * erreur —, et le téléchargement passe par l'API avec le jeton, jamais par une
 * adresse de fichier.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), agir: vi.fn(), telecharger: vi.fn() }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire, agir: appels.agir, telecharger: appels.telecharger },
}));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => ({ peut: () => true }) }));

const page = {
    data: [
        {
            id: 1,
            type: 'rapports',
            portee: 'national',
            region: null,
            date_debut: '2026-09-15',
            nb_lignes: 12,
            taille_octets: 2048,
            statut: 'pret',
            genere_le: '2026-09-16T05:00:00.000000Z',
            nom_fichier: 'rapports-national-2026-09-15.csv',
        },
        {
            id: 2,
            type: 'kits',
            portee: 'region',
            region: { code: 'NORD', nom: 'Nord' },
            date_debut: '2026-09-15',
            nb_lignes: 0,
            taille_octets: 0,
            statut: 'vide',
            genere_le: '2026-09-16T05:00:00.000000Z',
            nom_fichier: 'kits-NORD-2026-09-15.csv',
        },
    ],
    current_page: 1,
    last_page: 1,
    total: 2,
};

function afficher() {
    appels.lire.mockResolvedValue(page);

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <Exports />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    appels.lire.mockReset();
    appels.agir.mockReset();
    appels.telecharger.mockReset();
    URL.createObjectURL = vi.fn(() => 'blob:export');
    URL.revokeObjectURL = vi.fn();
});

describe('les exports déposés', () => {
    it('nomme le contenu et le périmètre de chaque fichier', async () => {
        afficher();

        // « Prêt » n'existe que dans le tableau. Attendre un libellé de contenu
        // ne prouverait rien : il figure déjà dans le filtre avant tout
        // chargement, et le test passerait sur une liste vide.
        expect(await screen.findByText('Prêt')).toBeInTheDocument();

        expect(screen.getAllByText('Rapports journaliers visés').length).toBeGreaterThan(1);
        expect(screen.getByText('National')).toBeInTheDocument();
        expect(screen.getByText('Nord')).toBeInTheDocument();
    });

    it('ne propose aucun téléchargement pour une journée sans donnée', async () => {
        afficher();

        expect(await screen.findByText('Aucune donnée')).toBeInTheDocument();
        expect(screen.getByText('Rien à télécharger')).toBeInTheDocument();
        // Un seul bouton : celui de la ligne prête.
        expect(screen.getAllByRole('button', { name: 'Télécharger' })).toHaveLength(1);
    });

    it('demande le fichier à l’API, avec le jeton', async () => {
        appels.telecharger.mockResolvedValue({
            fichier: new Blob(['csv'], { type: 'text/csv' }),
            nom: 'rapports-national-2026-09-15.csv',
        });

        afficher();

        fireEvent.click(await screen.findByRole('button', { name: 'Télécharger' }));

        await waitFor(() => expect(appels.telecharger).toHaveBeenCalledOnce());

        expect(appels.telecharger).toHaveBeenCalledWith('/exports/1/telecharger');
    });
});
