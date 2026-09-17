import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { FicheVague } from '../src/pages/vagues/FicheVague';

/**
 * L'ÉCRAN DES AFFECTATIONS.
 *
 * Ce qu'on protège ici, c'est l'ordre du cadrage : rien n'est notifié tant que
 * la proposition n'est pas validée, les contraintes non satisfaites se lisent
 * AVANT le bouton de validation, et la validation — qui ouvre des centaines
 * d'accès — ne part pas sur un simple clic.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), agir: vi.fn(), modifier: vi.fn() }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire, agir: appels.agir, modifier: appels.modifier },
}));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => ({ peut: () => true }) }));

const vague = {
    id: 7,
    code: 'BAN-2026-V1',
    libelle: 'Vague Bankui',
    statut: 'proposee',
    region: { id: 1, nom: 'Bankui' },
    date_debut_prevue: '2026-10-01',
    date_fin_prevue: '2026-11-15',
    objectif_enregistrements_par_kit_jour: 100,
    graine_tirage: 424242,
    centres: [{ id: 1, code: 'BAN-BAGA-C001' }],
    cree_par: { nom: 'Ouédraogo', prenoms: 'Awa' },
};

const proposition = {
    vague: { id: 7, code: 'BAN-2026-V1', statut: 'proposee', graine_tirage: 424242 },
    affectations: {
        data: [
            {
                id: 11,
                rang_tirage: 1,
                role_terrain: 'operateur',
                volontaire: { id: 3, matricule: 'PNVB-OPK000001', user: { nom: 'Sawadogo', prenoms: 'Issa' } },
                centre: { code: 'BAN-BAGA-C001' },
                kit: { reference: 'KIT-0001' },
            },
        ],
        current_page: 1,
        last_page: 1,
        total: 1,
    },
    unites_supervision: [],
    contraintes_non_satisfaites: 2,
};

function afficher() {
    appels.lire.mockImplementation((url) =>
        Promise.resolve(url.includes('/proposition') ? proposition : vague),
    );

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <MemoryRouter initialEntries={['/vagues/7']}>
                <Routes>
                    <Route path="/vagues/:id" element={<FicheVague />} />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    appels.lire.mockReset();
    appels.agir.mockReset();
    appels.modifier.mockReset();
});

describe('la fiche d’une vague', () => {
    it('annonce que rien n’est notifié tant que la proposition n’est pas validée', async () => {
        afficher();

        expect(await screen.findByText('Proposition')).toBeInTheDocument();
        expect(
            screen.getByText(/Proposition à relire, rien n’est notifié/),
        ).toBeInTheDocument();
    });

    it('montre les contraintes non satisfaites avant le bouton de validation', async () => {
        afficher();

        expect(await screen.findByText(/2 contraintes non satisfaites/)).toBeInTheDocument();
    });

    it('refuse de valider tant que la relecture n’est pas confirmée', async () => {
        afficher();

        const valider = await screen.findByRole('button', { name: 'Valider et ouvrir les accès' });

        expect(valider).toBeDisabled();

        fireEvent.click(screen.getByRole('checkbox', { name: /J’ai relu la proposition/ }));

        expect(valider).toBeEnabled();

        appels.agir.mockResolvedValue({ message: 'Vague validée.', donnees: {} });
        fireEvent.click(valider);

        await waitFor(() => expect(appels.agir).toHaveBeenCalledWith('/vagues/7/valider', {}));
    });

    it('propose l’ajustement d’une affectation, qui désigne le nouvel agent', async () => {
        afficher();

        fireEvent.click(await screen.findByRole('button', { name: 'Ajuster' }));

        expect(screen.getByLabelText(/Remplacer par/)).toBeInTheDocument();
    });
});
