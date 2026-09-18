import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Tournees } from '../src/pages/vagues/Tournees';

/**
 * LES PASSAGES DU KIT SUR LES SITES.
 *
 * Ce qu'on protège :
 *
 *   - un kit SANS opérateur se voit. C'est un état réel, et le masquer derrière
 *     un tiret le rendrait invisible exactement là où il compte ;
 *   - vider la date de fin envoie null, pas une chaîne vide : « sans date de
 *     fin » et « ne rien changer » sont deux intentions différentes ;
 *   - sans le droit de corriger, l'écran reste consultable mais n'offre aucun
 *     bouton — on ne promet pas une action que le serveur refusera.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), modifier: vi.fn(), creer: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: appels,
}));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

const passage = (surcharge = {}) => ({
    id: 7,
    ordre: 2,
    statut: 'en_cours',
    date_debut: '2026-09-01T00:00:00.000000Z',
    date_fin: '2026-09-20T00:00:00.000000Z',
    centre: { id: 3, code: 'BAN-C001', nom: 'Centre de Bagassi' },
    site: { id: 11, code: 'BAN-C001-S01', nom: 'Site Assio' },
    kit: { id: 5, reference: 'KIT-0005' },
    affectation_operateur: {
        id: 44,
        volontaire: { matricule: 'PNVB-OPK000001', user: { nom: 'Kaboré', prenoms: 'Salif' } },
    },
    ...surcharge,
});

function afficher({ peutAjuster = true, ligne = passage() } = {}) {
    session.valeur = { peut: () => peutAjuster };

    appels.lire.mockImplementation((url) => {
        if (url.startsWith('/referentiel/centres')) {
            return Promise.resolve({ data: [{ id: 3, code: 'BAN-C001', nom: 'Centre de Bagassi' }] });
        }

        if (url.startsWith('/referentiel/sites')) {
            return Promise.resolve({
                data: [
                    { id: 11, code: 'BAN-C001-S01', nom: 'Site Assio' },
                    { id: 12, code: 'BAN-C001-S02', nom: 'Site Kana' },
                ],
            });
        }

        if (url.startsWith('/vagues')) {
            return Promise.resolve({
                data: [
                    { id: 5, code: 'BAN-2026-V1', libelle: 'Première vague', statut: 'active' },
                    { id: 6, code: 'BAN-2026-V0', libelle: 'Vague close', statut: 'cloturee' },
                ],
            });
        }

        if (url.startsWith('/equipes')) {
            return Promise.resolve({
                data: [{
                    id: 44,
                    volontaire: { matricule: 'PNVB-OPK000001', user: { nom: 'Kaboré', prenoms: 'Salif' } },
                }],
            });
        }

        if (url.includes('/operateurs')) {
            return Promise.resolve([
                { id: 44, volontaire: { matricule: 'PNVB-OPK000001', user: { nom: 'Kaboré', prenoms: 'Salif' } } },
                { id: 45, volontaire: { matricule: 'PNVB-OPK000002', user: { nom: 'Sawadogo', prenoms: 'Awa' } } },
            ]);
        }

        return Promise.resolve({ data: [ligne], current_page: 1, last_page: 1, total: 1 });
    });

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <MemoryRouter>
            <QueryClientProvider client={cache}>
                <Tournees />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

/** Les filtres portent aussi un « Statut » : on interroge le formulaire visé. */
const formulaireDe = (bouton) => within(screen.getByRole('button', { name: bouton }).closest('form'));

beforeEach(() => {
    appels.lire.mockReset();
    appels.modifier.mockReset();
    session.valeur = {};
});

describe('les passages des kits', () => {
    it('montre le passage, son rang et l’agent qui porte le kit', async () => {
        afficher();

        expect(await screen.findByText('Site Assio')).toBeInTheDocument();
        expect(screen.getByText(/PNVB-OPK000001/)).toBeInTheDocument();
        // Un kit s'identifie par sa RÉFÉRENCE. Ne pas l'affirmer ici avait
        // laissé passer une colonne inexistante jusqu'à l'écran.
        expect(screen.getByText('KIT-0005')).toBeInTheDocument();
        expect(screen.getByText('01/09/2026 — 20/09/2026')).toBeInTheDocument();
    });

    it('signale un kit resté sans porteur au lieu de l’effacer', async () => {
        afficher({ ligne: passage({ affectation_operateur: null }) });

        expect(await screen.findByText('Sans opérateur')).toBeInTheDocument();
    });

    it('n’offre aucune correction à qui n’a pas le droit', async () => {
        afficher({ peutAjuster: false });

        // Attendre la ligne chargée : sans cela, l'absence du bouton ne
        // prouverait rien, il n'est pas encore rendu au premier passage.
        expect(await screen.findByText('Site Assio')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Corriger' })).not.toBeInTheDocument();
    });

    it('envoie null, et non une chaîne vide, quand on retire la date de fin', async () => {
        appels.modifier.mockResolvedValue({ message: 'Passage corrigé.', donnees: {} });

        afficher();

        fireEvent.click(await screen.findByRole('button', { name: 'Corriger' }));

        const champs = formulaireDe('Enregistrer le passage');

        // Attendre que les sites du centre soient arrivés : le select est
        // « required », et le poser avant que ses options existent laisserait
        // le navigateur refuser l'envoi sans que rien ne le dise.
        expect(await champs.findByRole('option', { name: /Site Kana/ })).toBeInTheDocument();

        fireEvent.change(champs.getByLabelText(/Site couvert/), { target: { value: '12' } });
        fireEvent.change(champs.getByLabelText(/Fin du passage/), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer le passage' }));

        await waitFor(() => expect(appels.modifier).toHaveBeenCalledOnce());

        expect(appels.modifier).toHaveBeenCalledWith('/tournees/7', {
            site_id: 12,
            date_debut: '2026-09-01',
            date_fin: null,
            ordre: 2,
            statut: 'en_cours',
        });
    });

    it('détache l’opérateur sans toucher au site ni aux dates', async () => {
        appels.modifier.mockResolvedValue({ message: 'Le kit est sans porteur.', donnees: {} });

        afficher();

        fireEvent.click(await screen.findByRole('button', { name: 'Corriger' }));

        const champs = formulaireDe('Enregistrer l’opérateur');

        // L'autre opérateur du centre est proposé : la liste vient du serveur.
        expect(await champs.findByRole('option', { name: /PNVB-OPK000002/ })).toBeInTheDocument();

        fireEvent.change(champs.getByLabelText(/Opérateur qui tient le passage/), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer l’opérateur' }));

        await waitFor(() => expect(appels.modifier).toHaveBeenCalledOnce());

        expect(appels.modifier).toHaveBeenCalledWith('/tournees/7/operateur', {
            affectation_operateur_id: null,
        });
    });
});

describe('Programmer un passage', () => {
    it('envoie le site et l’opérateur, sans jamais envoyer le centre', async () => {
        appels.creer.mockResolvedValue({ message: 'Passage programmé : le kit couvre Site Kana.', donnees: {} });

        afficher({ peutAjuster: true });

        fireEvent.click(await screen.findByRole('button', { name: 'Programmer un passage' }));
        const formulaire = screen.getByRole('form', { name: 'Programmer un passage' });

        // Une vague clôturée ne se propose pas : le serveur la refuserait.
        await within(formulaire).findByRole('option', { name: /BAN-2026-V1/ });
        expect(within(formulaire).queryByRole('option', { name: /BAN-2026-V0/ })).not.toBeInTheDocument();

        fireEvent.change(within(formulaire).getByLabelText('Vague'), { target: { value: '5' } });
        fireEvent.change(within(formulaire).getByLabelText(/^Centre/), { target: { value: '3' } });

        await within(formulaire).findByRole('option', { name: /Site Kana/ });
        fireEvent.change(within(formulaire).getByLabelText('Site'), { target: { value: '11' } });

        await within(formulaire).findByRole('option', { name: /PNVB-OPK000001/ });
        fireEvent.change(within(formulaire).getByLabelText(/^Opérateur/), { target: { value: '44' } });
        fireEvent.change(within(formulaire).getByLabelText('Premier jour'), { target: { value: '2026-09-20' } });

        const bouton = within(formulaire).getByRole('button', { name: 'Programmer le passage' });
        expect(bouton).toBeEnabled();
        fireEvent.submit(formulaire);

        await waitFor(() => expect(appels.creer).toHaveBeenCalledWith('/tournees', {
            vague_id: 5,
            site_id: 11,
            affectation_operateur_id: 44,
            date_debut: '2026-09-20',
            date_fin: null,
            statut: 'planifiee',
        }));
    });

    it('ne propose pas de programmer sans le droit de corriger', async () => {
        afficher({ peutAjuster: false });

        await screen.findByText('Passages des kits');
        expect(screen.queryByRole('button', { name: 'Programmer un passage' })).not.toBeInTheDocument();
    });
});
