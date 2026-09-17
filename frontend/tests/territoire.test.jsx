import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Territoire } from '../src/pages/referentiel/Territoire';

/**
 * LE RÉFÉRENTIEL TERRITORIAL.
 *
 * Ce qu'on protège :
 *   - on ne peut pas enregistrer une population sans avoir vu l'effet sur les
 *     quotas, et un aperçu dépassé ne compte plus ;
 *   - l'aperçu part en simulation, l'enregistrement sans ;
 *   - une commune se corrige directement : elle ne touche aucun quota ;
 *   - sans le droit de corriger, l'écran se parcourt sans aucun bouton.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), modifier: vi.fn(), creer: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({ ...(await original()), api: appels }));
vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

function monter() {
    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <MemoryRouter><Territoire /></MemoryRouter>
        </QueryClientProvider>,
    );
}

const paginee = (data) => ({ data, current_page: 1, last_page: 1, total: data.length, from: 1, to: data.length });

const assio = {
    id: 11, nom: 'Assio', type_localite: 'village', commune: { id: 2, nom: 'Bagassi' },
    population_hommes: 500, population_femmes: 500, population_totale: 1000,
    quota_sites: 1, sites_count: 0, latitude: null, longitude: null,
};

function lireTerritoire(url) {
    if (url.startsWith('/referentiel/territoire/regions')) {
        return Promise.resolve([{
            id: 1, code: 'BAN', nom: 'Bankui', population_totale: 5000, nombre_sites_alloues: 5, somme_quotas: '5',
            provinces_count: 1, communes_count: 1, localites_count: 3, centres_count: 1, sites_count: 3,
        }]);
    }

    if (url.startsWith('/referentiel/territoire/localites')) {
        return Promise.resolve(paginee([assio]));
    }

    if (url.startsWith('/referentiel/territoire/communes')) {
        return Promise.resolve(paginee([{
            id: 2, code: 'BAGA', nom: 'Bagassi', type: 'rurale', province: { nom: 'Bale' },
            population_hommes: 2600, population_femmes: 2600, population_totale: 5200, population_localites: 5000,
            est_zone_defis_securitaires: false, localites_count: 3, centres_count: 1,
        }]));
    }

    if (url.startsWith('/referentiel/territoire/provinces')) {
        return Promise.resolve([{ id: 4, code: 'BALE', nom: 'Bale', population_totale: 5000, communes_count: 1 }]);
    }

    if (url.startsWith('/referentiel/communes')) {
        return Promise.resolve([{ id: 2, nom: 'Bagassi' }]);
    }

    return Promise.resolve([]);
}

beforeEach(() => {
    Object.values(appels).forEach((appel) => appel.mockReset());
    session.valeur = { peut: () => true };
    appels.lire.mockImplementation(lireTerritoire);
});

describe('Référentiel territorial', () => {
    it('exige un aperçu à jour avant d’enregistrer une population', async () => {
        appels.modifier.mockImplementation((url, corps) => Promise.resolve(corps.simulation
            ? {
                message: 'Aperçu : rien n’est encore enregistré. 2 localités verraient leur quota de sites changer.',
                donnees: {
                    simulation: true,
                    changements: [
                        { localite_id: 10, localite: 'Kahin', commune: 'Bagassi', quota_avant: 3, quota_apres: 2, sites_existants: 3 },
                        { localite_id: 11, localite: 'Assio', commune: 'Bagassi', quota_avant: 1, quota_apres: 2, sites_existants: 0 },
                    ],
                    alertes: [{ localite_id: 10 }],
                },
            }
            : { message: 'Localité Assio enregistrée.', donnees: { simulation: false, changements: [], alertes: [] } }));

        monter();

        const ligne = (await screen.findByText('Assio')).closest('tr');
        fireEvent.click(within(ligne).getByRole('button', { name: 'Modifier' }));

        const formulaire = screen.getByRole('form', { name: 'Modifier la localité Assio' });
        const enregistrer = within(formulaire).getByRole('button', { name: 'Enregistrer' });
        expect(enregistrer).toBeDisabled();

        fireEvent.change(within(formulaire).getByLabelText('Hommes'), { target: { value: '1500' } });
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Voir l’effet sur les quotas' }));

        expect(await within(formulaire).findByText(/2 localités verraient/)).toBeInTheDocument();
        expect(within(formulaire).getByText('3 — au-dessus du quota')).toBeInTheDocument();
        expect(appels.modifier).toHaveBeenLastCalledWith('/referentiel/territoire/localites/11', {
            nom: 'Assio', type_localite: 'village', population_hommes: 1500, population_femmes: 500,
            latitude: null, longitude: null, simulation: true,
        });
        expect(enregistrer).toBeEnabled();

        // Une valeur change : l'aperçu ne vaut plus.
        fireEvent.change(within(formulaire).getByLabelText('Femmes'), { target: { value: '600' } });
        expect(enregistrer).toBeDisabled();
        expect(within(formulaire).getByText(/relancez-le avant d’enregistrer/)).toBeInTheDocument();

        fireEvent.click(within(formulaire).getByRole('button', { name: 'Voir l’effet sur les quotas' }));
        await waitFor(() => expect(enregistrer).toBeEnabled());
        fireEvent.click(enregistrer);

        await screen.findByText('Localité Assio enregistrée.');
        expect(appels.modifier).toHaveBeenLastCalledWith('/referentiel/territoire/localites/11', {
            nom: 'Assio', type_localite: 'village', population_hommes: 1500, population_femmes: 600,
            latitude: null, longitude: null,
        });
    });

    it('corrige une commune directement, sans aperçu', async () => {
        appels.modifier.mockResolvedValue({ message: 'Commune Bagassi enregistrée.', donnees: {} });

        monter();

        fireEvent.click(await screen.findByRole('button', { name: 'Communes' }));
        const ligne = (await screen.findByText('BAGA')).closest('tr');
        expect(within(ligne).getByText('-200')).toBeInTheDocument();
        fireEvent.click(within(ligne).getByRole('button', { name: 'Modifier' }));

        const formulaire = screen.getByRole('form', { name: 'Modifier la commune Bagassi' });
        expect(within(formulaire).queryByRole('button', { name: 'Voir l’effet sur les quotas' })).not.toBeInTheDocument();

        fireEvent.click(within(formulaire).getByLabelText('Zone à défis sécuritaires'));
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Enregistrer' }));

        await screen.findByText('Commune Bagassi enregistrée.');
        expect(appels.modifier).toHaveBeenCalledWith('/referentiel/territoire/communes/2', {
            nom: 'Bagassi', type: 'rurale', population_hommes: 2600, population_femmes: 2600, est_zone_defis_securitaires: true,
        });
    });

    it('ajoute une localité dans une commune de la région', async () => {
        appels.creer.mockResolvedValue({ message: 'Aperçu.', donnees: { simulation: true, changements: [], alertes: [] } });

        monter();

        fireEvent.click(await screen.findByRole('button', { name: 'Ajouter une localité' }));
        const formulaire = screen.getByRole('form', { name: 'Ajouter une localité' });

        await within(formulaire).findByRole('option', { name: 'Bagassi' });
        fireEvent.change(within(formulaire).getByLabelText('Commune'), { target: { value: '2' } });
        fireEvent.change(within(formulaire).getByLabelText('Nom'), { target: { value: 'Dora' } });
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Voir l’effet sur les quotas' }));

        await waitFor(() => expect(appels.creer).toHaveBeenCalledWith('/referentiel/territoire/localites', {
            commune_id: 2, nom: 'Dora', type_localite: 'village', population_hommes: 0, population_femmes: 0,
            latitude: null, longitude: null, simulation: true,
        }));
    });

    it('se parcourt sans aucun bouton de correction sans le droit', async () => {
        session.valeur = { peut: (droit) => droit !== 'referentiel.modifier_territoire' };

        monter();

        await screen.findByText('Assio');
        expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Ajouter une localité' })).not.toBeInTheDocument();
    });
});
