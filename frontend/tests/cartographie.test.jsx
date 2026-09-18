import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Cartographie } from '../src/pages/Cartographie';

/**
 * LA PAGE DE CARTOGRAPHIE.
 *
 * Leaflet n'est pas monté — il lui faut un vrai navigateur pour mesurer son
 * conteneur. Ce qui est testé ici, c'est ce que la page DEMANDE au serveur et
 * ce qu'elle ANNONCE :
 *
 *   - choisir une région ajoute region_id à la requête, et ne filtre pas
 *     seulement l'affichage : sur 12 294 sites, la différence est réelle ;
 *   - la répartition par région reste entière quand une région est filtrée,
 *     sinon on ne pourrait plus en choisir une autre ;
 *   - les sites SANS COORDONNÉES sont annoncés. Les taire ferait lire une
 *     région à moitié saisie comme une région à moitié vide.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn() }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: appels,
}));

// Leaflet touche au DOM d'une manière que jsdom ne suit pas : la carte
// elle-même est remplacée, le reste de la page est bien réel.
vi.mock('../src/graphiques/CarteCouverture', () => ({
    CarteCouverture: ({ sitesCarte }) => <div data-testid="carte">{(sitesCarte?.sites ?? []).length} marqueurs</div>,
}));

const parRegion = [
    { region_id: 1, code: 'BAN', nom: 'Bankui', sites: 2, localises: 1, couverts: 1 },
    { region_id: 2, code: 'NAN', nom: 'Nando', sites: 1, localises: 1, couverts: 0 },
];

function monter() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter><Cartographie /></MemoryRouter>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    vi.clearAllMocks();

    appels.lire.mockImplementation((url) => {
        if (url.startsWith('/tableau-bord/couverture')) {
            return Promise.resolve([
                { region_id: 1, code: 'BAN', nom: 'Bankui', taux_couverture: 12.5, contour_geojson: null },
                { region_id: 2, code: 'NAN', nom: 'Nando', taux_couverture: 4, contour_geojson: null },
            ]);
        }

        if (url.includes('region_id=2')) {
            return Promise.resolve({
                region_id: 2,
                total_sites: 1,
                localises: 1,
                sites: [{ id: 3, code: 'NAN-C001-S01', nom: 'Site de Nando', region_id: 2, lat: 12.4, lng: -1.5, couvert: false }],
                par_region: parRegion,
            });
        }

        return Promise.resolve({
            region_id: null,
            total_sites: 3,
            localises: 2,
            sites: [
                { id: 1, code: 'BAN-C001-S01', nom: 'Site placé', region_id: 1, lat: 11.9, lng: -3.3, couvert: true },
                { id: 3, code: 'NAN-C001-S01', nom: 'Site de Nando', region_id: 2, lat: 12.4, lng: -1.5, couvert: false },
            ],
            par_region: parRegion,
        });
    });
});

describe('la cartographie', () => {
    it('annonce les sites qu’elle ne peut pas placer', async () => {
        monter();

        await screen.findByText('Sites du périmètre');

        // 3 sites existent, 2 seulement sont plaçables : les DEUX nombres sont
        // à l'écran, et le second est nommé pour ce qu'il est.
        // Le libellé paraît deux fois, et c'est voulu : en indicateur du
        // périmètre, et en colonne du tableau par région.
        expect(screen.getAllByText('Placés sur la carte')).toHaveLength(2);
        expect(screen.getByText('Les autres n’ont pas encore de coordonnées')).toBeInTheDocument();
        expect(screen.getByTestId('carte')).toHaveTextContent('2 marqueurs');
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('demande la région au serveur, et ne se contente pas de filtrer l’écran', async () => {
        monter();

        await screen.findByText('Sites du périmètre');

        fireEvent.change(screen.getByLabelText('Région'), { target: { value: '2' } });

        await waitFor(() =>
            expect(appels.lire).toHaveBeenCalledWith(expect.stringContaining('region_id=2')),
        );

        // Un seul marqueur reste, et le titre nomme la région choisie.
        await screen.findByText('1 marqueurs');
        expect(screen.getByText('Les sites de la région Nando.')).toBeInTheDocument();
    });

    it('garde la répartition entière quand une région est filtrée', async () => {
        monter();

        await screen.findByText('Sites du périmètre');
        fireEvent.change(screen.getByLabelText('Région'), { target: { value: '2' } });

        await screen.findByText('1 marqueurs');

        // Les deux régions restent listées : c'est par là qu'on en change.
        expect(screen.getByRole('button', { name: 'Bankui' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Nando' })).toBeInTheDocument();
    });

    it('bascule de région d’un clic dans le tableau', async () => {
        monter();

        await screen.findByText('Sites du périmètre');
        fireEvent.click(await screen.findByRole('button', { name: 'Nando' }));

        await waitFor(() =>
            expect(appels.lire).toHaveBeenCalledWith(expect.stringContaining('region_id=2')),
        );
    });
});
