import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ErreurApi } from '../src/api/client';
import { ImportVolontaires } from '../src/pages/volontaires/ImportVolontaires';
import { Qualification } from '../src/pages/volontaires/Qualification';
import { RemiseIdentifiants } from '../src/pages/volontaires/RemiseIdentifiants';
import { Registre } from '../src/pages/volontaires/Registre';

/**
 * LE CHEMIN DU FICHIER DES RETENUS JUSQU'AU TÉLÉPHONE.
 *
 * Ce qu'on protège :
 *
 *   - l'import part avec sa LISTE et son MODE : un fichier de liste d'attente
 *     importé comme des retenus mettrait des réservistes sur le terrain ;
 *   - une colonne manquante est nommée comme dans le fichier (« numéro »), pas
 *     par sa clé interne (« telephone ») ;
 *   - un obstacle à la purge bloque la confirmation AVANT le clic ;
 *   - la qualification d'un lot d'A-OPK envoie la localité choisie, et une
 *     fiche refusée reste cochée pour qu'on y revienne ;
 *   - un serveur qui n'envoie rien pour de vrai le dit, et le bordereau se
 *     télécharge par son NOM — l'adresse absolue ignorerait le sous-chemin.
 */
const appels = vi.hoisted(() => ({
    lire: vi.fn(),
    creer: vi.fn(),
    modifier: vi.fn(),
    agir: vi.fn(),
    supprimer: vi.fn(),
    telecharger: vi.fn(),
}));
const session = vi.hoisted(() => ({ valeur: { peut: () => true } }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: appels,
}));

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
    session.valeur = { peut: () => true };
    URL.createObjectURL = vi.fn(() => 'blob:bordereau');
    URL.revokeObjectURL = vi.fn();
});

// ---------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------

const importAnalyse = (surcharge = {}) => ({
    id: 9,
    fichier_nom: 'retenus-bankui.xlsx',
    type_referentiel: 'volontaires_reserve',
    statut: 'apercu_pret',
    lignes_total: 3,
    lignes_valides: 2,
    lignes_erreur: 1,
    resume: { sans_courriel: 1, a_qualifier: 1, motifs_frequents: { 'Le nom est vide.': 1 }, obstacles_purge: [] },
    ...surcharge,
});

function lireImport(url) {
    if (url.startsWith('/imports/volontaires/9/apercu')) {
        return Promise.resolve({
            import: importAnalyse(),
            lignes: paginee([
                { id: 1, numero_ligne: 3, valide: false, motif_erreur: 'Le nom est vide.', donnees: { prenoms: 'Awa', telephone: '+22670000003' } },
                {
                    id: 2, numero_ligne: 2, valide: true, motif_erreur: null,
                    donnees: { nom: 'SAWADOGO', prenoms: 'Fatimata', telephone: '+22665345678', categorie: 'assistant', village: 'Assio', commune: 'Bagassi' },
                },
            ]),
        });
    }

    return Promise.resolve(paginee([]));
}

/** Soumet le formulaire : jsdom ignore le fichier posé par le test dans sa validation native. */
function analyser() {
    fireEvent.submit(screen.getByRole('form', { name: 'Importer un fichier de volontaires' }));
}

function choisirFichier() {
    const champ = document.getElementById('fichier-volontaires');
    const fichier = new File(['x'], 'retenus-bankui.xlsx');
    fireEvent.change(champ, { target: { files: [fichier] } });

    return fichier;
}

describe('Importer les retenus', () => {
    it('envoie le fichier avec sa liste et son mode, puis montre l’aperçu', async () => {
        appels.lire.mockImplementation(lireImport);
        appels.creer.mockResolvedValue({ message: 'Fichier lu : 2 lignes valides.', donnees: { import: importAnalyse() } });

        monter(<ImportVolontaires />);

        const fichier = choisirFichier();
        fireEvent.click(screen.getByLabelText(/Liste d’attente/));
        analyser();

        await screen.findByText('Fichier lu : 2 lignes valides.');

        const [url, formulaire] = appels.creer.mock.calls[0];
        expect(url).toBe('/imports/volontaires');
        expect(formulaire.get('fichier')).toBe(fichier);
        expect(formulaire.get('type')).toBe('volontaires_reserve');
        expect(formulaire.get('mode')).toBe('completer');

        // La localité est reconstituée depuis village + commune.
        expect(await screen.findByText('Assio (Bagassi)')).toBeInTheDocument();
        expect(screen.getAllByText('Le nom est vide.').length).toBeGreaterThan(0);
    });

    it('confirme, puis rappelle que les comptes restent inactifs jusqu’à l’affectation', async () => {
        appels.lire.mockImplementation(lireImport);
        appels.creer.mockResolvedValue({ message: 'Fichier lu.', donnees: { import: importAnalyse() } });
        appels.agir.mockResolvedValue({
            message: '2 comptes créés, en statut inactif.',
            donnees: { import: importAnalyse({ statut: 'applique', resume: { comptes_crees: 2, a_qualifier: 1 } }) },
        });

        monter(<ImportVolontaires />);

        choisirFichier();
        analyser();
        fireEvent.click(await screen.findByRole('button', { name: 'Confirmer : créer 2 comptes' }));

        await screen.findByText('2 comptes créés, en statut inactif.');
        expect(appels.agir).toHaveBeenCalledWith('/imports/volontaires/9/confirmer');
        expect(screen.getByText(/L’accès s’ouvre à l’affectation/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Attribuer les 1 profils manquants/ })).toHaveAttribute('href', '/volontaires/a-qualifier');
        expect(screen.queryByRole('button', { name: /Confirmer/ })).not.toBeInTheDocument();
    });

    it('nomme les colonnes manquantes comme dans le fichier', async () => {
        appels.lire.mockImplementation(lireImport);
        appels.creer.mockRejectedValue(new ErreurApi({
            message: 'Colonnes obligatoires introuvables : telephone, prenoms.',
            statut: 422,
            donnees: { import: { id: 10, statut: 'echec', resume: { colonnes_manquantes: ['telephone', 'prenoms'] } } },
        }));

        monter(<ImportVolontaires />);

        choisirFichier();
        analyser();

        expect(await screen.findByText('numéro, Prénom(s)')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Confirmer/ })).not.toBeInTheDocument();
    });

    it('bloque la confirmation quand la purge du jeu fictif est impossible', async () => {
        const bloque = importAnalyse({
            resume: { purge_prevue: 40, obstacles_purge: ['3 feuilles de présence réels sont rattachés à des volontaires de démonstration.'] },
        });
        appels.lire.mockImplementation(lireImport);
        appels.creer.mockResolvedValue({ message: 'Fichier lu.', donnees: { import: bloque } });

        monter(<ImportVolontaires />);

        choisirFichier();
        fireEvent.click(screen.getByLabelText(/Remplacer le jeu de démonstration/));
        analyser();

        expect(await screen.findByText(/3 feuilles de présence réels/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Confirmer : créer 2 comptes' })).toBeDisabled();
        expect(appels.creer.mock.calls[0][1].get('mode')).toBe('remplacer');
    });
});

// ---------------------------------------------------------------------------
// Qualification
// ---------------------------------------------------------------------------

const ficheAvecLocalite = {
    id: 21, matricule: 'PNVB-AQU000001', localite_id: 5,
    user: { nom: 'SAWADOGO', prenoms: 'Fatimata', telephone: '+22665345678' },
    localite: { id: 5, nom: 'Assio', commune: { id: 2, nom: 'Bagassi' } },
};
const ficheSansLocalite = {
    id: 22, matricule: 'PNVB-AQU000002', localite_id: null,
    user: { nom: 'ZONGO', prenoms: 'Ali', telephone: '+22670000022' },
    localite: null,
};

function lireQualification(url) {
    if (url.startsWith('/volontaires/a-qualifier')) {
        return Promise.resolve({
            fiches: paginee([ficheAvecLocalite, ficheSansLocalite]),
            profils_possibles: [
                { valeur: 'superviseur', libelle: 'Superviseur de centre' },
                { valeur: 'operateur', libelle: 'Opérateur de kit' },
                { valeur: 'assistant', libelle: 'Assistant (A-OPK)' },
            ],
        });
    }

    if (url.startsWith('/referentiel/regions')) {
        return Promise.resolve([{ id: 1, nom: 'Bankui' }]);
    }

    if (url.startsWith('/referentiel/communes')) {
        return Promise.resolve([{ id: 2, nom: 'Bagassi' }]);
    }

    if (url.startsWith('/referentiel/localites')) {
        return Promise.resolve([{ id: 7, nom: 'Kahin' }]);
    }

    return Promise.resolve(null);
}

describe('Attribuer les profils', () => {
    it('garde la localité du fichier par défaut, et prévient pour les fiches qui n’en ont pas', async () => {
        appels.lire.mockImplementation(lireQualification);
        appels.agir.mockResolvedValue({
            message: '1 fiches qualifiées.',
            donnees: {
                qualifiees: [{ volontaire_id: 21 }],
                refusees: [{ volontaire_id: 22, nom_complet: 'Ali ZONGO', motif: 'Un A-OPK est rattaché en permanence à sa localité.' }],
            },
        });

        monter(<Qualification />);

        fireEvent.click(await screen.findByLabelText('Cocher toute la page'));
        const formulaire = await screen.findByRole('form', { name: 'Attribuer un profil' });
        fireEvent.change(within(formulaire).getByLabelText('Profil à attribuer'), { target: { value: 'assistant' } });

        expect(within(formulaire).getByText(/1 fiches choisies n’en ont aucune/)).toBeInTheDocument();

        fireEvent.click(within(formulaire).getByRole('button', { name: 'Attribuer ce profil' }));

        await screen.findByText('1 fiches qualifiées.');
        expect(appels.agir).toHaveBeenCalledWith('/volontaires/a-qualifier', {
            qualifications: [
                { volontaire_id: 21, categorie: 'assistant' },
                { volontaire_id: 22, categorie: 'assistant' },
            ],
        });

        // La fiche refusée est nommée, et reste seule cochée.
        expect(screen.getByText(/Ali ZONGO : Un A-OPK est rattaché/)).toBeInTheDocument();
        expect(screen.getByLabelText('Choisir Ali ZONGO')).toBeChecked();
        expect(screen.getByLabelText('Choisir Fatimata SAWADOGO')).not.toBeChecked();
    });

    it('applique la localité choisie à tout le lot', async () => {
        appels.lire.mockImplementation(lireQualification);
        appels.agir.mockResolvedValue({ message: '1 fiches qualifiées.', donnees: { qualifiees: [], refusees: [] } });

        monter(<Qualification />);

        fireEvent.click(await screen.findByLabelText('Choisir Ali ZONGO'));
        const formulaire = await screen.findByRole('form', { name: 'Attribuer un profil' });
        fireEvent.change(within(formulaire).getByLabelText('Profil à attribuer'), { target: { value: 'assistant' } });

        await within(formulaire).findByRole('option', { name: 'Bankui' });
        fireEvent.change(within(formulaire).getByLabelText('Région de la localité'), { target: { value: '1' } });
        await within(formulaire).findByRole('option', { name: 'Bagassi' });
        fireEvent.change(within(formulaire).getByLabelText('Commune'), { target: { value: '2' } });
        await within(formulaire).findByRole('option', { name: 'Kahin' });
        fireEvent.change(within(formulaire).getByLabelText(/^Localité/), { target: { value: '7' } });

        fireEvent.click(within(formulaire).getByRole('button', { name: 'Attribuer ce profil' }));

        await waitFor(() => expect(appels.agir).toHaveBeenCalledWith('/volontaires/a-qualifier', {
            qualifications: [{ volontaire_id: 22, categorie: 'assistant', localite_id: 7 }],
        }));
    });
});

// ---------------------------------------------------------------------------
// Remise des identifiants
// ---------------------------------------------------------------------------

const compte = (surcharge = {}) => ({
    id: 31,
    nom: 'KABORE',
    prenoms: 'Issa',
    telephone: '+22676234567',
    email: null,
    statut_compte: 'actif',
    etat_remise: 'envoye',
    volontaire: { matricule: 'PNVB-OPK000001', categorie: 'operateur' },
    remises_identifiants: [{ id: 1, canal: 'sms', statut: 'envoye', envoye_le: '2026-09-17T08:00:00Z' }],
    ...surcharge,
});

function lireRemises(canaux) {
    return () => Promise.resolve({
        comptes: paginee([
            compte(),
            compte({
                id: 32, nom: 'ZONGO', prenoms: 'Ali', statut_compte: 'inactif', etat_remise: 'non_envoye',
                volontaire: { matricule: 'PNVB-OPK000002', categorie: 'operateur' }, remises_identifiants: [],
            }),
        ]),
        repartition: { non_envoye: { nombre: 1 }, envoye: { nombre: 1 } },
        canaux,
    });
}

describe('Remise des identifiants', () => {
    it('dit quand le serveur n’envoie rien pour de vrai', async () => {
        appels.lire.mockImplementation(lireRemises({ courriel_simule: true, sms_simule: true }));

        monter(<RemiseIdentifiants />);

        expect(await screen.findByText('Ce serveur n’envoie ni courriel ni SMS.')).toBeInTheDocument();
        expect(screen.getByText(/SMS · envoye/)).toBeInTheDocument();
    });

    it('se tait quand les deux canaux sont réels', async () => {
        appels.lire.mockImplementation(lireRemises({ courriel_simule: false, sms_simule: false }));

        monter(<RemiseIdentifiants />);

        await screen.findByText('PNVB-OPK000001');
        expect(screen.queryByText(/Ce serveur n’envoie/)).not.toBeInTheDocument();
    });

    it('génère le bordereau puis le télécharge par son nom', async () => {
        appels.lire.mockImplementation(lireRemises({ courriel_simule: true, sms_simule: true }));
        appels.agir.mockResolvedValue({
            message: 'Bordereau généré pour 2 volontaires.',
            donnees: { fichier: 'bordereau-test-mobile-20260917-101500.pdf', url_telechargement: 'https://ailleurs/api/v1/x.pdf' },
        });
        appels.telecharger.mockResolvedValue({ fichier: new Blob(['%PDF']), nom: null });

        monter(<RemiseIdentifiants />);

        fireEvent.click(await screen.findByLabelText('Cocher toute la page'));

        // Un compte encore inactif ne pourra pas se connecter : l'écran le dit.
        expect(screen.getByText(/1 de ces comptes n’ont pas d’accès ouvert/)).toBeInTheDocument();

        const formulaire = screen.getByRole('form', { name: 'Générer un bordereau' });
        fireEvent.change(within(formulaire).getByLabelText('Session de formation'), { target: { value: 'Test mobile' } });
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Générer le bordereau PDF' }));

        await screen.findByText('Bordereau généré pour 2 volontaires.');
        expect(appels.agir).toHaveBeenCalledWith('/comptes/remises/bordereau', { user_ids: [31, 32], session: 'Test mobile' });
        await waitFor(() => expect(appels.telecharger).toHaveBeenCalledWith('/comptes/remises/bordereau/bordereau-test-mobile-20260917-101500.pdf'));
    });

    it('n’offre aucune case à cocher sans le droit de renvoyer', async () => {
        session.valeur = { peut: (droit) => droit !== 'comptes.renvoyer_identifiants' };
        appels.lire.mockImplementation(lireRemises({}));

        monter(<RemiseIdentifiants />);

        await screen.findByText('PNVB-OPK000001');
        expect(screen.queryByLabelText('Cocher toute la page')).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox', { name: /Choisir/ })).not.toBeInTheDocument();
    });
});

// ---------------------------------------------------------------------------
// Registre : fiche, niveau d'étude, retrait
// ---------------------------------------------------------------------------

const ficheRegistre = (surcharge = {}) => ({
    id: 41,
    matricule: 'PNVB-OPK000001',
    categorie: 'operateur',
    statut: 'operationnel',
    niveau_etude: 'bac_plus_2',
    diplome: 'BTS en informatique',
    localite: null,
    user: { id: 91, nom: 'KABORE', prenoms: 'Issa', telephone: '+22676234567', email: null, statut_compte: 'actif' },
    ...surcharge,
});

describe('Registre des volontaires', () => {
    it('corrige une fiche sans jamais proposer la catégorie ni le matricule', async () => {
        appels.lire.mockImplementation((url) => (url.startsWith('/volontaires/41')
            ? Promise.resolve({ volontaire: ficheRegistre(), numero_cnib: 'B1234567' })
            : Promise.resolve(paginee([ficheRegistre()]))));
        appels.modifier.mockResolvedValue({ message: 'Fiche enregistrée.', donnees: {} });

        monter(<Registre />);

        expect(await screen.findByText('BAC+2')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));

        const formulaire = screen.getByRole('form', { name: 'Fiche du volontaire' });
        expect(within(formulaire).queryByLabelText(/Catégorie/)).not.toBeInTheDocument();
        expect(within(formulaire).queryByLabelText(/Matricule/)).not.toBeInTheDocument();
        expect(await within(formulaire).findByText(/B1234567/)).toBeInTheDocument();

        fireEvent.change(within(formulaire).getByLabelText(/^Niveau d’étude/), { target: { value: 'licence' } });
        fireEvent.change(within(formulaire).getByLabelText(/^Diplôme/), { target: { value: 'Licence en gestion' } });
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Enregistrer' }));

        await screen.findByText('Fiche enregistrée.');
        expect(appels.modifier).toHaveBeenCalledWith('/volontaires/41', expect.objectContaining({
            niveau_etude: 'licence', diplome: 'Licence en gestion', nom: 'KABORE', telephone: '+22676234567',
        }));
    });

    it('signale un profil tenu par dérogation', async () => {
        appels.lire.mockResolvedValue(paginee([ficheRegistre({
            categorie: 'superviseur', niveau_etude: 'bac', derogation_niveau_motif: 'Dix ans d’expérience.',
        })]));

        monter(<Registre />);

        expect(await screen.findByText('BAC — dérogation')).toBeInTheDocument();
    });

    it('retire un lot de fiches avec un motif', async () => {
        appels.lire.mockResolvedValue(paginee([ficheRegistre(), ficheRegistre({ id: 42, matricule: 'PNVB-OPK000002' })]));
        appels.agir.mockResolvedValue({
            message: '1 fiches retirées. 1 n’ont pas pu l’être : consultez le détail.',
            donnees: { retires: [{ volontaire_id: 41 }], refusees: [{ matricule: 'PNVB-OPK000002', motif: 'engagée dans une vague' }] },
        });

        monter(<Registre />);

        fireEvent.click(await screen.findByLabelText('Cocher toute la page'));
        fireEvent.click(screen.getByRole('button', { name: 'Retirer du dispositif' }));

        const formulaire = screen.getByRole('form', { name: 'Retirer 2 fiches du dispositif' });
        const bouton = within(formulaire).getByRole('button', { name: 'Retirer les fiches choisies' });
        expect(bouton).toBeDisabled();

        fireEvent.change(within(formulaire).getByLabelText(/Motif/), { target: { value: 'Fin de collaboration' } });
        fireEvent.click(bouton);

        await screen.findByText(/1 fiches retirées/);
        expect(appels.agir).toHaveBeenCalledWith('/volontaires/retrait-en-lot', {
            volontaire_ids: [41, 42], motif: 'Fin de collaboration',
        });
        // Le détail des refus est repris dans le message.
        expect(screen.getByText(/PNVB-OPK000002 : engagée dans une vague/)).toBeInTheDocument();
    });

    it('n’offre ni modification ni retrait sans le droit', async () => {
        session.valeur = { peut: (droit) => droit !== 'volontaires.modifier' };
        appels.lire.mockResolvedValue(paginee([ficheRegistre()]));

        monter(<Registre />);

        await screen.findByText('PNVB-OPK000001');
        expect(screen.queryByRole('button', { name: 'Modifier' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Cocher toute la page')).not.toBeInTheDocument();
    });
});

describe('Dérogation au niveau d’étude', () => {
    it('réclame un motif quand le niveau ne permet pas le profil, et l’envoie', async () => {
        appels.lire.mockImplementation(lireQualification);
        appels.agir.mockResolvedValue({ message: '1 fiches qualifiées.', donnees: { qualifiees: [], refusees: [] } });

        monter(<Qualification />);

        fireEvent.click(await screen.findByLabelText('Choisir Ali ZONGO'));
        const formulaire = await screen.findByRole('form', { name: 'Attribuer un profil' });
        fireEvent.change(within(formulaire).getByLabelText('Profil à attribuer'), { target: { value: 'superviseur' } });

        expect(within(formulaire).getByText(/n’atteignent pas le niveau exigé/)).toBeInTheDocument();
        expect(within(formulaire).getByText(/Licence \(BAC\+3\)/)).toBeInTheDocument();

        fireEvent.change(within(formulaire).getByLabelText(/^Motif de la dérogation/), {
            target: { value: 'Dix ans d’expérience en recensement.' },
        });
        fireEvent.click(within(formulaire).getByRole('button', { name: 'Attribuer ce profil' }));

        await waitFor(() => expect(appels.agir).toHaveBeenCalledWith('/volontaires/a-qualifier', {
            qualifications: [{
                volontaire_id: 22,
                categorie: 'superviseur',
                motif_derogation: 'Dix ans d’expérience en recensement.',
            }],
        }));
    });
});

describe('État des accès', () => {
    it('télécharge le PDF avec les filtres de l’écran', async () => {
        appels.lire.mockImplementation(lireRemises({ courriel_simule: true, sms_simule: true }));
        appels.telecharger.mockResolvedValue({ fichier: new Blob(['%PDF']), nom: 'etat-acces.pdf' });

        monter(<RemiseIdentifiants />);

        fireEvent.change(await screen.findByLabelText('Recherche'), { target: { value: 'KABORE' } });
        fireEvent.click(screen.getByRole('button', { name: 'État des accès (PDF)' }));

        await waitFor(() => expect(appels.telecharger).toHaveBeenCalledWith('/comptes/remises/etat-acces?recherche=KABORE'));
    });
});
