import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { TableauBord } from '../src/pages/tableauBord/TableauBord';

/**
 * LE TABLEAU DE BORD.
 *
 * Ce qu'on protège :
 *   - l'effectif national est un PIC, et l'écran le dit : un cumul des douze
 *     régions annoncerait des milliers d'agents qui n'existent pas ;
 *   - les files d'attente ne montrent QUE ce qui n'est pas vide, et seulement
 *     les écrans que l'utilisateur a le droit d'ouvrir ;
 *   - la courbe change de mesure — par jour, en cumul — sans jamais afficher
 *     deux échelles ;
 *   - la date de référence des chiffres est écrite : ils viennent des agrégats
 *     de la nuit, pas de l'instant présent.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn() }));
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/api/client', async (original) => ({ ...(await original()), api: appels }));
vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

// La carte tire Leaflet, inutile ici : on la remplace par un repère simple.
vi.mock('../src/graphiques/CarteCouverture', () => ({
    CarteCouverture: () => <div>carte</div>,
}));

function monter() {
    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <MemoryRouter><TableauBord /></MemoryRouter>
        </QueryClientProvider>,
    );
}

const pilotage = (surcharge = {}) => ({
    vague: {
        id: 3,
        code: 'BAN-2026-V1',
        libelle: 'Première vague',
        region: 'Bankui',
        date_debut: '2026-09-14',
        date_fin: '2026-09-30',
        jours_restants: 13,
        agents: { superviseur: 4, operateur: 12, assistant: 24, total: 40 },
        centres: { total: 6, ouverts: 5 },
    },
    volontaires: {
        a_qualifier: 7, sans_niveau: 0, identifiants_non_remis: 3,
        premiere_connexion_faite: 12, acces_ouverts: 40,
    },
    terrain: {
        rapports_a_viser: 0, incidents_ouverts: 2, incidents_critiques: 1,
        incidents_en_retard: 1, ecarts_ouverts: 0, alertes_non_lues: 0,
    },
    materiel: { total: 30, disponibles: 18, hors_service: 2, non_restitues: 4 },
    ...surcharge,
});

const evolution = {
    du: '2026-09-04', au: '2026-09-17',
    jours: [
        { date: '2026-09-15', enregistrements: 120, rejetes: 2, cumul: 120, sites_couverts: 4, pic_effectif_regional: 30 },
        { date: '2026-09-16', enregistrements: 180, rejetes: 1, cumul: 300, sites_couverts: 5, pic_effectif_regional: 32 },
    ],
};

const synthese = {
    date: '2026-09-16',
    portee: 'nationale',
    enregistrements: { du_jour: 180, rejetes_du_jour: 1, cumul: 3000 },
    deploiement: {
        centres_ouverts: 5, sites_couverts: 5, taux_presence: 92.5,
        effectif_simultane: { valeur: 32, nature: 'pic_regional', region: 'Bankui' },
    },
    incidents_ouverts: { niveau_1: 1, niveau_2: 0, niveau_3: 0, niveau_4: 1, total: 2 },
    couverture: { population_cible: 100000, enregistres: 3000, taux: 3 },
    regions: [],
};

function lireTableauBord(donneesPilotage = pilotage()) {
    return (url) => {
        if (url.startsWith('/tableau-bord/pilotage')) return Promise.resolve(donneesPilotage);
        if (url.startsWith('/tableau-bord/evolution')) return Promise.resolve(evolution);
        if (url.startsWith('/tableau-bord/centres')) return Promise.resolve([]);
        if (url.startsWith('/tableau-bord/couverture')) return Promise.resolve([]);
        if (url.startsWith('/tableau-bord/sites-carte')) return Promise.resolve({ total_sites: 0, localises: 0, sites: [] });
        if (url.startsWith('/tableau-bord/retards')) return Promise.resolve([]);

        return Promise.resolve(synthese);
    };
}

beforeEach(() => {
    appels.lire.mockReset();
    session.valeur = { peut: () => true, estNational: true };
});

describe('Tableau de bord', () => {
    it('montre la vague en cours, ses effectifs et son parc', async () => {
        appels.lire.mockImplementation(lireTableauBord());

        monter();

        expect(await screen.findByText('Vague en cours')).toBeInTheDocument();
        expect(screen.getByText('BAN-2026-V1')).toBeInTheDocument();
        expect(screen.getByText(/13/)).toBeInTheDocument();
        expect(screen.getByText('Agents déployés')).toBeInTheDocument();
        expect(screen.getByText('A-OPK')).toBeInTheDocument();
        expect(screen.getByText(/5 centres ouverts sur 6 prévus/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Voir la vague' })).toHaveAttribute('href', '/vagues/3');
        expect(screen.getByText('Parc de kits')).toBeInTheDocument();
    });

    it('n’affiche que les files non vides, et chacune ouvre son écran', async () => {
        appels.lire.mockImplementation(lireTableauBord());

        monter();

        const aQualifier = await screen.findByRole('link', { name: /fiches sans profil/ });
        expect(aQualifier).toHaveAttribute('href', '/volontaires/a-qualifier');
        expect(screen.getByRole('link', { name: /kits non restitués/ })).toHaveAttribute('href', '/kits/non-restitues');
        expect(screen.getByRole('link', { name: /incidents sans prise en charge/ })).toHaveAttribute('href', '/incidents/en-retard');

        // Files à zéro : absentes.
        expect(screen.queryByRole('link', { name: /rapports attendent/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /écarts de présence/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /sans niveau d’étude/ })).not.toBeInTheDocument();
    });

    it('n’ouvre pas un écran que le compte n’a pas le droit de voir', async () => {
        session.valeur = { peut: (droit) => droit !== 'volontaires.qualifier', estNational: true };
        appels.lire.mockImplementation(lireTableauBord());

        monter();

        await screen.findByText('Vague en cours');
        expect(screen.queryByRole('link', { name: /fiches sans profil/ })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: /identifiants non remis/ })).toBeInTheDocument();
    });

    it('dit quand rien n’attend, plutôt que d’aligner des zéros', async () => {
        appels.lire.mockImplementation(lireTableauBord(pilotage({
            volontaires: { a_qualifier: 0, sans_niveau: 0, identifiants_non_remis: 0 },
            terrain: { rapports_a_viser: 0, incidents_ouverts: 0, incidents_critiques: 0, incidents_en_retard: 0, ecarts_ouverts: 0, alertes_non_lues: 0 },
            materiel: { total: 30, disponibles: 30, hors_service: 0, non_restitues: 0 },
        })));

        monter();

        expect(await screen.findByText(/Rien n’attend de vous/)).toBeInTheDocument();
    });

    it('propose de planifier quand aucune vague n’est active', async () => {
        appels.lire.mockImplementation(lireTableauBord(pilotage({ vague: null })));

        monter();

        expect(await screen.findByText('Aucune vague active')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Planifier une vague' })).toHaveAttribute('href', '/vagues/planifier');
    });

    it('nomme la nature de l’effectif et la date des chiffres', async () => {
        appels.lire.mockImplementation(lireTableauBord());

        monter();

        // Le pic régional, jamais une somme des régions.
        expect(await screen.findByText(/Pic régional — Bankui, jamais un cumul/)).toBeInTheDocument();
        expect(screen.getByText(/dernier jour avec des rapports visés/)).toBeInTheDocument();
    });

    it('bascule la courbe entre le jour et le cumul, sur un seul axe', async () => {
        appels.lire.mockImplementation(lireTableauBord());

        monter();

        expect(await screen.findByText('Enregistrements par jour')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cumulé' }));

        expect(await screen.findByText('Enregistrements cumulés')).toBeInTheDocument();
        expect(screen.getByText(/Cumul depuis le début de la période/)).toBeInTheDocument();

        // La vue en tableau donne les mêmes valeurs, sans survol.
        fireEvent.click(screen.getByRole('button', { name: 'Afficher le tableau' }));
        const tableau = await screen.findByRole('table');
        expect(within(tableau).getByText('300')).toBeInTheDocument();
    });

    it('change de période et redemande les chiffres', async () => {
        appels.lire.mockImplementation(lireTableauBord());

        monter();

        await screen.findByText('Vague en cours');
        fireEvent.change(screen.getByLabelText('Période'), { target: { value: '30' } });

        await waitFor(() => {
            const appelsEvolution = appels.lire.mock.calls.filter(([url]) => url.startsWith('/tableau-bord/evolution'));
            expect(appelsEvolution.length).toBeGreaterThan(1);
        });
    });
});
