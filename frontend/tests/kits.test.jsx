import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ListeKits } from '../src/pages/kits/ListeKits';
import { FicheKit } from '../src/pages/kits/FicheKit';

/**
 * L'ENTRÉE D'UN KIT AU PARC, ET LA CORRECTION DE SA FICHE.
 *
 * Ce qu'on protège :
 *
 *   - un kit entre au parc SANS DÉTENTEUR : aucun champ ne permet de l'attribuer
 *     ici, sans quoi on contournerait le mouvement qui laisse la trace ;
 *   - la COMPOSITION se saisit une ligne par élément, et les lignes vides ne
 *     deviennent pas des éléments fantômes de l'inventaire ;
 *   - la RÉFÉRENCE ne se corrige jamais : elle relie la fiche à son historique ;
 *   - sans le droit « kits.gerer », l'écran reste consultable mais n'offre
 *     aucun formulaire — on ne promet pas ce que le serveur refusera.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), creer: vi.fn(), modifier: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: appels,
}));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));
vi.mock('react-router-dom', async (original) => ({
    ...(await original()),
    useParams: () => ({ id: '5' }),
}));

const kit = (surcharge = {}) => ({
    id: 5,
    reference: 'KIT-BAN-BAGA-C001',
    etat: 'fonctionnel',
    composition: ['tablette de saisie', 'imprimante portable'],
    est_permanent_zone_defis: false,
    volontaire_detenteur_id: null,
    detenteur: null,
    centre_courant: null,
    site_courant: null,
    mouvements: [],
    ...surcharge,
});

function monter(element, { droits = ['kits.gerer', 'kits.declarer_mouvement'] } = {}) {
    session.valeur = { peut: (droit) => droits.includes(droit) };

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter>{element}</MemoryRouter>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    vi.clearAllMocks();

    appels.lire.mockImplementation((url) => {
        if (url.startsWith('/kits/synthese')) {
            return Promise.resolve({ total: 0, attribues: 0, disponibles: 0, non_restitues: 0 });
        }

        if (url.startsWith('/referentiel/centres')) {
            return Promise.resolve({ data: [{ id: 3, code: 'BAN-BAGA-C001', nom: 'Centre de Bagassi' }] });
        }

        if (url === '/kits/5') {
            return Promise.resolve(kit());
        }

        return Promise.resolve({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } });
    });
});

describe('ajouter un kit au parc', () => {
    it('envoie la référence et la composition, une ligne par élément', async () => {
        appels.creer.mockResolvedValue({ message: 'Kit KIT-0001 ajouté au parc.', donnees: { id: 9 } });

        monter(<ListeKits />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un kit au parc' }));

        const formulaire = await screen.findByRole('form', { name: 'Ajouter un kit au parc' });
        fireEvent.change(within(formulaire).getByLabelText(/^Référence/), { target: { value: 'KIT-0001' } });

        // Une ligne vide au milieu ne doit pas devenir un élément de l'inventaire.
        fireEvent.change(within(formulaire).getByLabelText(/^Composition/), {
            target: { value: 'tablette de saisie\n\n  imprimante portable  \n' },
        });

        fireEvent.submit(formulaire);

        await waitFor(() => expect(appels.creer).toHaveBeenCalled());

        const [url, corps] = appels.creer.mock.calls[0];
        expect(url).toBe('/kits');
        expect(corps.reference).toBe('KIT-0001');
        expect(corps.composition).toEqual(['tablette de saisie', 'imprimante portable']);
        expect(corps.est_permanent_zone_defis).toBe(false);
    });

    it('n’offre aucun champ pour attribuer le kit à un agent', async () => {
        monter(<ListeKits />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un kit au parc' }));

        const formulaire = await screen.findByRole('form', { name: 'Ajouter un kit au parc' });

        // Le détenteur ne se saisit jamais : il vient d'un mouvement tracé.
        // Les expressions sont ancrées au DÉBUT du libellé : le texte d'aide
        // fait partie du libellé accessible, et « il suivra son détenteur »
        // n'est pas un champ « Détenteur ».
        expect(within(formulaire).queryByLabelText(/^Détenteur/)).toBeNull();
        expect(within(formulaire).queryByLabelText(/^État/)).toBeNull();
    });

    it('ne propose rien à qui ne gère pas le parc', async () => {
        monter(<ListeKits />, { droits: ['kits.consulter'] });

        await screen.findByText('Parc de kits');
        expect(screen.queryByRole('button', { name: 'Ajouter un kit au parc' })).toBeNull();
    });
});

describe('corriger la fiche d’un kit', () => {
    it('envoie la composition et l’état, jamais la référence', async () => {
        appels.modifier.mockResolvedValue({ message: 'Kit mis à jour.', donnees: {} });

        monter(<FicheKit />);

        fireEvent.click(await screen.findByRole('button', { name: 'Corriger la fiche' }));

        const formulaire = await screen.findByRole('form', { name: 'Corriger la fiche du kit' });

        // La référence relie la fiche à son historique : elle ne se corrige pas.
        expect(within(formulaire).queryByLabelText(/[Rr]éférence/)).toBeNull();

        fireEvent.change(within(formulaire).getByLabelText(/^État matériel/), { target: { value: 'panne' } });
        fireEvent.submit(formulaire);

        await waitFor(() => expect(appels.modifier).toHaveBeenCalled());

        const [url, corps] = appels.modifier.mock.calls[0];
        expect(url).toBe('/kits/5');
        expect(corps.etat).toBe('panne');
        expect(corps.composition).toEqual(['tablette de saisie', 'imprimante portable']);
        expect(corps).not.toHaveProperty('reference');
    });

    it('ne propose pas la correction à qui ne gère pas le parc', async () => {
        monter(<FicheKit />, { droits: ['kits.consulter', 'kits.declarer_mouvement'] });

        await screen.findByText('Situation');
        expect(screen.queryByRole('button', { name: 'Corriger la fiche' })).toBeNull();
    });
});
