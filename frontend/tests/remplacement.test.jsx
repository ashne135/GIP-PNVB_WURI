import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Remplacement } from '../src/pages/vagues/Remplacement';

/**
 * REMPLACER L'AGENT D'UNE AFFECTATION.
 *
 * Ce qu'on protège : le motif est obligatoire — un remplacement sans motif est
 * inexploitable plus tard —, et l'état du kit est exigé dès que le sortant en
 * détient un. C'est le SERVEUR qui dit s'il en détient un : l'écran suit cette
 * réponse au lieu de la deviner.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), creer: vi.fn() }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire, creer: appels.creer },
}));

const reponse = (detientUnKit) => ({
    affectation: { id: 11, role_terrain: 'operateur' },
    titulaire: { id: 3, matricule: 'PNVB-OPK000001' },
    detient_un_kit: detientUnKit,
    candidats: {
        data: [{ id: 9, matricule: 'PNVB-OPK000042', user: { nom: 'Kaboré', prenoms: 'Salif' } }],
        current_page: 1,
        last_page: 1,
        total: 1,
    },
});

function afficher(detientUnKit) {
    appels.lire.mockResolvedValue(reponse(detientUnKit));

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <Remplacement affectation={{ id: 11 }} onFait={vi.fn()} />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    appels.lire.mockReset();
    appels.creer.mockReset();
});

describe('le remplacement d’une affectation', () => {
    it('annonce ce que le remplacement entraîne, kit compris', async () => {
        afficher(true);

        fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }));

        // Attendre la phrase du kit, et non celle qui s'affiche déjà avant la
        // réponse : c'est le serveur qui dit si le sortant détient un kit.
        expect(await screen.findByText(/kit sera transféré/)).toBeInTheDocument();
        expect(screen.getByText(/passera en réserve/)).toBeInTheDocument();
    });

    it('refuse de désigner un remplaçant tant que le motif manque', async () => {
        afficher(false);

        fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }));

        const candidat = await screen.findByRole('button', { name: /PNVB-OPK000042/ });

        expect(candidat).toBeDisabled();

        fireEvent.change(screen.getByLabelText(/Motif du remplacement/), { target: { value: 'abandon' } });

        expect(candidat).toBeEnabled();
    });

    it('exige l’état du kit quand le sortant en détient un, et l’envoie', async () => {
        appels.creer.mockResolvedValue({ message: 'Remplacé.', donnees: {} });

        afficher(true);

        fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }));

        const candidat = await screen.findByRole('button', { name: /PNVB-OPK000042/ });

        fireEvent.change(screen.getByLabelText(/Motif du remplacement/), { target: { value: 'indisponibilite' } });

        // Le motif seul ne suffit pas : un kit est en jeu.
        expect(candidat).toBeDisabled();

        fireEvent.change(screen.getByLabelText(/État constaté du kit/), { target: { value: 'usage' } });
        expect(candidat).toBeEnabled();

        fireEvent.click(candidat);

        await waitFor(() => expect(appels.creer).toHaveBeenCalledOnce());

        expect(appels.creer).toHaveBeenCalledWith('/remplacements', {
            affectation_id: 11,
            volontaire_entrant_id: 9,
            motif: 'indisponibilite',
            etat_kit_constate: 'usage',
        });
    });
});
