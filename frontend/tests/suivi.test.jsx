import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Ecarts } from '../src/pages/presences/Ecarts';
import { Appreciations } from '../src/pages/Appreciations';

/**
 * LE SUIVI : écarts de présence et appréciations.
 *
 * Ce qu'on protège :
 *   - un écart ne se traite pas sans commentaire, et sans le droit il ne
 *     s'offre même pas ;
 *   - la réponse d'un agent se marque comme lue, une seule fois.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), agir: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({ ...(await original()), api: appels }));
vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

function monter(element) {
    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <MemoryRouter>{element}</MemoryRouter>
        </QueryClientProvider>,
    );
}

const paginee = (data) => ({ data, current_page: 1, last_page: 1, total: data.length, from: 1, to: data.length });

const ecart = {
    id: 5,
    date_constat: '2026-09-16',
    type_ecart: 'present_sans_releve',
    nb_releves_zone: 0,
    statut: 'ouvert',
    commentaire: null,
    region: { nom: 'Bankui' },
    volontaire: { matricule: 'PNVB-OPK000010', user: { nom: 'KABORE', prenoms: 'Issa' } },
    feuille: { site: { code: 'BAN-C001-S01', nom: 'Site Assio' } },
};

beforeEach(() => {
    Object.values(appels).forEach((appel) => appel.mockReset());
    session.valeur = { peut: () => true };
});

describe('Écarts de présence', () => {
    it('demande un commentaire, puis traite l’écart', async () => {
        appels.lire.mockResolvedValue(paginee([ecart]));
        appels.agir.mockResolvedValue({ message: 'Écart marqué comme examiné.', donnees: {} });

        monter(<Ecarts />);

        // La liste s'ouvre sur les écarts à traiter.
        await waitFor(() => expect(appels.lire).toHaveBeenCalledWith('/presence/ecarts?statut=ouvert&page=1'));

        expect(await screen.findByText('Déclaré présent, aucun relevé dans la zone du site')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Traiter' }));

        const formulaire = screen.getByRole('form', { name: 'Traiter l’écart' });
        const enregistrer = within(formulaire).getByRole('button', { name: 'Enregistrer' });
        expect(enregistrer).toBeDisabled();

        fireEvent.change(within(formulaire).getByLabelText(/Commentaire/), { target: { value: 'Téléphone en panne.' } });
        fireEvent.click(enregistrer);

        await screen.findByText('Écart marqué comme examiné.');
        expect(appels.agir).toHaveBeenCalledWith('/presence/ecarts/5/traiter', { statut: 'examine', commentaire: 'Téléphone en panne.' });
        expect(screen.queryByRole('form', { name: 'Traiter l’écart' })).not.toBeInTheDocument();
    });

    it('n’offre aucun traitement sans le droit, ni sur un écart clos', async () => {
        session.valeur = { peut: (droit) => droit !== 'ecarts.traiter' };
        appels.lire.mockResolvedValue(paginee([ecart]));

        const { unmount } = monter(<Ecarts />);
        await screen.findByText('Site Assio (BAN-C001-S01)');
        expect(screen.queryByRole('button', { name: 'Traiter' })).not.toBeInTheDocument();
        unmount();

        session.valeur = { peut: () => true };
        appels.lire.mockResolvedValue(paginee([{ ...ecart, statut: 'clos', commentaire: '[16/09/2026 10:00 · Chef] Vérifié.' }]));

        monter(<Ecarts />);
        await screen.findByText('[16/09/2026 10:00 · Chef] Vérifié.');
        expect(screen.queryByRole('button', { name: 'Traiter' })).not.toBeInTheDocument();
    });
});

describe('Appréciations', () => {
    it('marque la réponse d’un agent comme lue', async () => {
        appels.lire.mockResolvedValue(paginee([{
            id: 9,
            categorie_agent: 'opk',
            presence: 'present',
            production: 'peu_satisfaisant',
            anomalies: ['retard'],
            observation: 'Arrivé à 9 h.',
            rapport: { id: 44, date_rapport: '2026-09-15' },
            volontaire: { matricule: 'PNVB-OPK000010', user: { nom: 'KABORE', prenoms: 'Issa' } },
            reponses: [
                { id: 3, reponse: 'Le taxi-moto est tombé en panne.', repondu_le: '2026-09-15T18:00:00Z', lu_par_superieur_le: null },
                { id: 4, reponse: 'Merci.', repondu_le: '2026-09-16T08:00:00Z', lu_par_superieur_le: '2026-09-16T09:00:00Z' },
            ],
        }]));
        appels.agir.mockResolvedValue({ message: 'Réponse marquée comme lue.', donnees: {} });

        monter(<Appreciations />);

        expect(await screen.findByText('Peu satisfaisant')).toBeInTheDocument();
        expect(screen.getByText('Retard')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Rapport du/ })).toHaveAttribute('href', '/rapports/44');
        expect(screen.getAllByRole('button', { name: 'Marquer comme lue' })).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Marquer comme lue' }));

        await screen.findByText('Réponse marquée comme lue.');
        expect(appels.agir).toHaveBeenCalledWith('/appreciations/reponses/3/lue');
    });
});
