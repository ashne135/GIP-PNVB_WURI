import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Equipes } from '../src/pages/Equipes';

/**
 * LES ÉQUIPES DÉPLOYÉES.
 *
 * Ce qu'on protège :
 *
 *   - le SUPERVISEUR n'a pas de centre unique : l'écran montre ses DEUX
 *     centres, au lieu d'en choisir un au hasard ou de laisser la case vide ;
 *   - un opérateur sans passage ce jour-là est NOMMÉ comme tel. Un tiret seul
 *     laisserait croire à une donnée manquante, alors que l'information est
 *     précisément qu'aucun kit ne passe ;
 *   - la journée choisie part bien au serveur : le site dépend du jour, un
 *     sélecteur qui n'enverrait rien afficherait toujours la même chose.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), modifier: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire, modifier: appels.modifier },
}));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

const operateur = {
    id: 1,
    role_terrain: 'operateur',
    volontaire: {
        id: 10,
        matricule: 'PNVB-OPK000001',
        user: { nom: 'Kaboré', prenoms: 'Salif', telephone: '+22670011010' },
    },
    centre: { id: 3, code: 'BAN-BAGA-C001', nom: 'Centre de Bagassi', commune: { id: 2, nom: 'Bagassi' } },
    unite_supervision: null,
    site_du_jour: { id: 7, code: 'BAN-BAGA-C001-S01', nom: 'Site Assio' },
};

const superviseur = {
    id: 2,
    role_terrain: 'superviseur',
    volontaire: {
        id: 12,
        matricule: 'PNVB-SUP000001',
        user: { nom: 'Ouédraogo', prenoms: 'Awa', telephone: '+22670011012' },
    },
    centre: null,
    unite_supervision: {
        id: 5,
        centre_principal: { id: 3, code: 'BAN-BAGA-C001', nom: 'Centre de Bagassi', commune: { id: 2, nom: 'Bagassi' } },
        centre_secondaire: { id: 4, code: 'BAN-BAGA-C002', nom: 'Centre de Boromo' },
    },
    site_du_jour: null,
};

const sansPassage = {
    id: 3,
    role_terrain: 'operateur',
    volontaire: {
        id: 14,
        matricule: 'PNVB-OPK000002',
        user: { nom: 'Sawadogo', prenoms: 'Ali', telephone: '+22670011020' },
    },
    centre: { id: 3, code: 'BAN-BAGA-C001', nom: 'Centre de Bagassi', commune: { id: 2, nom: 'Bagassi' } },
    unite_supervision: null,
    site_du_jour: null,
};

function afficher(lignes = [operateur, superviseur, sansPassage], { peutDeplacer = false } = {}) {
    session.valeur = { peut: () => peutDeplacer };

    appels.lire.mockImplementation((url) => {
        if (url.startsWith('/referentiel/regions')) {
            return Promise.resolve([{ id: 1, nom: 'Bankui' }]);
        }

        if (url.startsWith('/referentiel/centres')) {
            // DEUX centres, et ce n'est pas décoratif : le sélecteur de centre
            // d'accueil exclut celui où l'agent travaille déjà. Avec un seul,
            // la liste serait vide et l'envoi bloqué sans rien prouver.
            return Promise.resolve({
                data: [
                    { id: 3, code: 'BAN-BAGA-C001', nom: 'Centre de Bagassi' },
                    { id: 4, code: 'BAN-BAGA-C002', nom: 'Centre de Boromo' },
                ],
            });
        }

        return Promise.resolve({ data: lignes, current_page: 1, last_page: 1, total: lignes.length });
    });

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <Equipes />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    appels.lire.mockReset();
    appels.modifier.mockReset();
    session.valeur = {};
});

describe('les équipes déployées', () => {
    it('montre l’agent, son téléphone et son rôle', async () => {
        afficher();

        const agent = await screen.findByText('Salif Kaboré');

        // « Opérateur de kit » figure aussi dans les options du filtre Rôle, et
        // sur l'autre opérateur du tableau : on interroge la LIGNE de cet
        // agent, pas la page entière — c'est d'ailleurs ce qu'on veut dire.
        const ligne = within(agent.closest('tr'));

        expect(ligne.getByText('+22670011010')).toBeInTheDocument();
        expect(ligne.getByText('Opérateur de kit')).toBeInTheDocument();
        expect(ligne.getByText('Site Assio')).toBeInTheDocument();
    });

    it('rend les DEUX centres du superviseur, sans lui inventer de site', async () => {
        afficher();

        expect(await screen.findByText('BAN-BAGA-C001 + BAN-BAGA-C002')).toBeInTheDocument();
        expect(screen.getByText('Couvre ses deux centres')).toBeInTheDocument();
    });

    it('nomme l’absence de passage au lieu d’afficher un tiret', async () => {
        afficher();

        expect(await screen.findByText('Ali Sawadogo')).toBeInTheDocument();
        expect(screen.getByText('Aucun passage ce jour')).toBeInTheDocument();
    });

    it('envoie la journée choisie au serveur', async () => {
        afficher();

        await screen.findByText('Salif Kaboré');

        fireEvent.change(screen.getByLabelText('Journée'), { target: { value: '2026-09-01' } });

        await waitFor(() => {
            expect(appels.lire).toHaveBeenCalledWith(expect.stringContaining('date=2026-09-01'));
        });
    });

    it('n’offre le déplacement que sur un opérateur', async () => {
        afficher(undefined, { peutDeplacer: true });

        await screen.findByText('Salif Kaboré');

        // Deux opérateurs dans la fixture, un superviseur : deux boutons.
        expect(screen.getAllByRole('button', { name: 'Déplacer' })).toHaveLength(2);

        // L'A-OPK ne se redéploie jamais et le superviseur dépend de son
        // unité : le serveur les refuse, l'écran ne les propose donc pas.
        const ligneSuperviseur = within(screen.getByText('Awa Ouédraogo').closest('tr'));

        expect(ligneSuperviseur.queryByRole('button', { name: 'Déplacer' })).not.toBeInTheDocument();
    });

    it('envoie le centre d’accueil et le motif du déplacement', async () => {
        appels.modifier.mockResolvedValue({ message: 'Déplacé.', donnees: {} });

        afficher(undefined, { peutDeplacer: true });

        const agent = await screen.findByText('Salif Kaboré');

        fireEvent.click(within(agent.closest('tr')).getByRole('button', { name: 'Déplacer' }));

        const formulaire = within(
            screen.getByRole('button', { name: 'Déplacer l’agent' }).closest('form'),
        );

        // Attendre les centres : le sélecteur est « required », et le poser
        // avant que ses options existent ferait refuser l'envoi en silence.
        expect(await formulaire.findByRole('option', { name: /BAN-BAGA-C002/ })).toBeInTheDocument();

        fireEvent.change(formulaire.getByLabelText(/Centre d’accueil/), { target: { value: '4' } });
        fireEvent.change(formulaire.getByLabelText(/Motif du déplacement/), {
            target: { value: 'Renfort demandé par le terrain' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Déplacer l’agent' }));

        await waitFor(() => expect(appels.modifier).toHaveBeenCalledOnce());

        expect(appels.modifier).toHaveBeenCalledWith('/equipes/affectations/1/centre', {
            centre_destination_id: 4,
            motif: 'Renfort demandé par le terrain',
        });
    });
});
