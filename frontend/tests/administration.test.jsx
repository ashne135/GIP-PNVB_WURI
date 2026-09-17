import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ComptesAdministration } from '../src/pages/administration/ComptesAdministration';
import { NomenclaturesIncident } from '../src/pages/administration/NomenclaturesIncident';
import { Synchronisations } from '../src/pages/administration/Synchronisations';

/**
 * L'ADMINISTRATION : comptes, listes d'incidents, synchronisations.
 *
 * Ce qu'on protège :
 *   - la région n'est demandée qu'aux rôles régionaux, et le téléphone n'est
 *     envoyé qu'à la création ;
 *   - le mot de passe provisoire s'affiche une fois, puis disparaît ;
 *   - on ne se ferme pas soi-même, et une fermeture exige un motif ;
 *   - le code d'une entrée d'incident n'est jamais envoyé ;
 *   - un refus de synchronisation se lit sans jamais livrer ses données.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), creer: vi.fn(), modifier: vi.fn(), agir: vi.fn() }));
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

beforeEach(() => {
    Object.values(appels).forEach((appel) => appel.mockReset());
    session.valeur = { peut: () => true, utilisateur: { id: 1 } };
});

// ---------------------------------------------------------------------------
// Comptes d'administration
// ---------------------------------------------------------------------------

const roles = [
    { valeur: 'chef_antenne_regional', libelle: 'Chef d’antenne régional', regional: true },
    { valeur: 'observateur', libelle: 'Observateur', regional: false },
    { valeur: 'super_administrateur', libelle: 'Super administrateur (DSI)', regional: false },
];

const moi = {
    id: 1, nom: 'DSI', prenoms: 'Awa', telephone: '+22670000001', email: null,
    statut_compte: 'actif', role: 'super_administrateur', role_libelle: 'Super administrateur (DSI)', region: null,
};
const chef = {
    id: 2, nom: 'KABORE', prenoms: 'Issa', telephone: '+22670000003', email: 'chef@pnvb.bf',
    statut_compte: 'actif', role: 'chef_antenne_regional', role_libelle: 'Chef d’antenne régional',
    region: { id: 7, nom: 'Bankui' }, derniere_connexion_le: null, doit_changer_mot_de_passe: true,
};

function lireComptes(url) {
    if (url.startsWith('/administration/comptes')) {
        return Promise.resolve({ comptes: paginee([moi, chef]), roles });
    }

    if (url.startsWith('/referentiel/regions')) {
        return Promise.resolve([{ id: 7, nom: 'Bankui' }]);
    }

    return Promise.resolve([]);
}

describe('Comptes d’administration', () => {
    it('crée un chef d’antenne avec sa région et montre le mot de passe une seule fois', async () => {
        appels.lire.mockImplementation(lireComptes);
        appels.creer.mockResolvedValue({
            message: 'Compte créé.',
            donnees: { compte: { telephone: '+22670445566' }, mot_de_passe_provisoire: 'Xq7Kp2Lm9R' },
        });

        monter(<ComptesAdministration />);

        fireEvent.click(await screen.findByRole('button', { name: 'Nouveau compte' }));
        const formulaire = screen.getByRole('form', { name: 'Compte d’administration' });

        fireEvent.change(within(formulaire).getByLabelText('Nom'), { target: { value: 'Ouédraogo' } });
        fireEvent.change(within(formulaire).getByLabelText('Prénoms'), { target: { value: 'Awa' } });
        fireEvent.change(within(formulaire).getByLabelText(/Téléphone/), { target: { value: '70 44 55 66' } });

        // Rôle national : aucune région demandée.
        fireEvent.change(within(formulaire).getByLabelText('Rôle'), { target: { value: 'observateur' } });
        expect(within(formulaire).queryByLabelText('Région')).not.toBeInTheDocument();

        fireEvent.change(within(formulaire).getByLabelText('Rôle'), { target: { value: 'chef_antenne_regional' } });
        await within(formulaire).findByRole('option', { name: 'Bankui' });
        fireEvent.change(within(formulaire).getByLabelText('Région'), { target: { value: '7' } });
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Créer le compte' }));

        await waitFor(() => expect(appels.creer).toHaveBeenCalledWith('/administration/comptes', {
            nom: 'Ouédraogo', prenoms: 'Awa', email: null, role: 'chef_antenne_regional', region_id: 7, telephone: '70 44 55 66',
        }));

        expect(await screen.findByText('Xq7Kp2Lm9R')).toBeInTheDocument();
        expect(screen.getByText(/il ne sera plus affiché/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'J’ai noté le mot de passe' }));
        expect(screen.queryByText('Xq7Kp2Lm9R')).not.toBeInTheDocument();
    });

    it('ne propose ni de se fermer soi-même, ni de fermer sans motif', async () => {
        appels.lire.mockImplementation(lireComptes);
        appels.agir.mockResolvedValue({ message: 'Compte de Issa KABORE fermé.', donnees: { compte: chef } });

        monter(<ComptesAdministration />);

        const ligneMoi = (await screen.findByText('(vous)')).closest('tr');
        expect(within(ligneMoi).queryByRole('button', { name: 'Fermer' })).not.toBeInTheDocument();

        const ligneChef = screen.getByText('+22670000003').closest('tr');
        fireEvent.click(within(ligneChef).getByRole('button', { name: 'Fermer' }));

        const formulaire = screen.getByRole('form', { name: 'Fermer le compte de Issa KABORE' });
        const bouton = within(formulaire).getByRole('button', { name: 'Fermer le compte' });
        expect(bouton).toBeDisabled();

        fireEvent.change(within(formulaire).getByLabelText(/Motif/), { target: { value: 'Départ du projet' } });
        fireEvent.click(bouton);

        await screen.findByText('Compte de Issa KABORE fermé.');
        expect(appels.agir).toHaveBeenCalledWith('/administration/comptes/2/fermer', { motif: 'Départ du projet' });
    });

    it('n’envoie pas le téléphone en modification, et fige son propre rôle', async () => {
        appels.lire.mockImplementation(lireComptes);
        appels.modifier.mockResolvedValue({ message: 'Compte modifié.', donnees: { compte: moi } });

        monter(<ComptesAdministration />);

        const ligneMoi = (await screen.findByText('(vous)')).closest('tr');
        fireEvent.click(within(ligneMoi).getByRole('button', { name: 'Modifier' }));

        const formulaire = screen.getByRole('form', { name: 'Compte d’administration' });
        expect(within(formulaire).getByLabelText(/Téléphone/)).toBeDisabled();
        expect(within(formulaire).getByLabelText(/^Rôle/)).toBeDisabled();

        fireEvent.click(within(formulaire).getByRole('button', { name: 'Enregistrer' }));

        await waitFor(() => expect(appels.modifier).toHaveBeenCalledWith('/administration/comptes/1', {
            nom: 'DSI', prenoms: 'Awa', email: null, role: 'super_administrateur', region_id: null,
        }));
    });
});

// ---------------------------------------------------------------------------
// Listes des incidents
// ---------------------------------------------------------------------------

const listes = [
    {
        cle: 'natures',
        libelle: 'Nature de l’incident',
        entrees: [
            { id: 3, code: 'transport', libelle: 'Transport', libelle_moore: null, libelle_dioula: null, ordre: 5, actif: true, incidents_count: 4 },
        ],
    },
    { cle: 'impacts', libelle: 'Impact constaté', entrees: [] },
];

describe('Listes des incidents', () => {
    it('désactive une entrée sans toucher à son code', async () => {
        appels.lire.mockResolvedValue(listes);
        appels.modifier.mockResolvedValue({ message: '« Transport » désactivé.', donnees: {} });

        monter(<NomenclaturesIncident />);

        const ligne = (await screen.findByText('transport')).closest('tr');
        expect(within(ligne).getAllByText('à traduire')).toHaveLength(2);
        fireEvent.click(within(ligne).getByRole('button', { name: 'Désactiver' }));

        await screen.findByText('« Transport » désactivé.');
        expect(appels.modifier).toHaveBeenCalledWith('/incidents-nomenclatures/natures/3', { libelle: 'Transport', actif: false });
    });

    it('ajoute une entrée dans la liste choisie, sans code', async () => {
        appels.lire.mockResolvedValue(listes);
        appels.creer.mockResolvedValue({ message: '« Coupure d’électricité » ajouté.', donnees: {} });

        monter(<NomenclaturesIncident />);

        fireEvent.click(await screen.findByRole('button', { name: 'Impact constaté (0)' }));
        const formulaire = screen.getByRole('form', { name: 'Ajouter une entrée' });
        fireEvent.change(within(formulaire).getByLabelText('Libellé en français'), { target: { value: 'Coupure d’électricité' } });
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Ajouter' }));

        await waitFor(() => expect(appels.creer).toHaveBeenCalledWith('/incidents-nomenclatures/impacts', {
            libelle: 'Coupure d’électricité', libelle_moore: null, libelle_dioula: null, ordre: null,
        }));
    });
});

// ---------------------------------------------------------------------------
// Synchronisations
// ---------------------------------------------------------------------------

describe('Synchronisations', () => {
    it('nomme chaque refus, et dit quand le téléphone réessaiera', async () => {
        appels.lire.mockResolvedValue(paginee([{
            id: 1, recu_le: '2026-09-17T10:00:00Z', nb_elements: 3, nb_acceptes: 1, nb_rejetes: 2, duree_ms: 340,
            user: { nom: 'OUEDRAOGO', prenoms: 'Ali', telephone: '+22670077010', matricule: 'PNVB-OPK000010' },
            rejets: [
                { rang: 1, type: 'releve_position', code: 'regle_metier', code_libelle: 'Règle métier non satisfaite', motif: 'Hors des heures de service.', reessayer: false },
                { rang: 2, type: 'rapport_journalier', code: 'erreur_serveur', code_libelle: 'Incident technique du serveur', motif: null, reessayer: true },
            ],
        }]));

        monter(<Synchronisations />);

        expect(await screen.findByText('Relevé de position')).toBeInTheDocument();
        expect(screen.getByText(/Hors des heures de service/)).toBeInTheDocument();
        expect(screen.getByText('(le téléphone réessaiera)')).toBeInTheDocument();
        expect(screen.getByText('PNVB-OPK000010 · +22670077010')).toBeInTheDocument();
    });

    it('transmet le filtre des envois refusés', async () => {
        appels.lire.mockResolvedValue(paginee([]));

        monter(<Synchronisations />);

        fireEvent.click(await screen.findByLabelText('Seulement les envois avec un refus'));

        await waitFor(() => expect(appels.lire).toHaveBeenLastCalledWith('/sync/supervision?avec_rejets=1&page=1'));
    });
});
