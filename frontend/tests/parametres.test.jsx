import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Parametres } from '../src/pages/Parametres';

/**
 * L'ÉCRAN DES PARAMÈTRES.
 *
 * Deux choses à ne pas casser : le national ne voit aucun bouton de
 * modification — seul le super administrateur modifie —, et chaque valeur
 * repart vers le serveur avec le bon type : un nombre reste un nombre, une
 * matrice de notification reste une liste de rôles.
 */
const appels = vi.hoisted(() => ({ lire: vi.fn(), modifier: vi.fn() }));

vi.mock('../src/api/client', async (original) => ({
    ...(await original()),
    api: { lire: appels.lire, modifier: appels.modifier },
}));

const parametres = [
    {
        cle: 'affectation.centres_par_superviseur', libelle: 'Centres par superviseur', description: null,
        groupe: 'affectation', type_valeur: 'entier', valeur: 2, modifiable_par: 'super_administrateur', modifie_le: null,
    },
    {
        cle: 'incidents.notification.niveau_1', libelle: 'Matrice de notification — niveau 1', description: null,
        groupe: 'incidents', type_valeur: 'json', valeur: ['volontaire_superviseur'], modifiable_par: 'super_administrateur', modifie_le: null,
    },
];

function afficher(peutModifier) {
    appels.lire.mockResolvedValue({ peut_modifier: peutModifier, parametres });

    const cache = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={cache}>
            <Parametres />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    appels.lire.mockReset();
    appels.modifier.mockReset();
});

describe('les paramètres', () => {
    it('présente les valeurs en consultation seule au national, sans aucun bouton de modification', async () => {
        afficher(false);

        expect(await screen.findByText('Centres par superviseur')).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Consultation seule.');
        expect(screen.queryByRole('button', { name: /Modifier/ })).not.toBeInTheDocument();
        // Les rôles s'affichent sous leur libellé, pas sous leur code technique.
        expect(screen.getByText('Superviseur de centre')).toBeInTheDocument();
    });

    it('renvoie un nombre, pas une chaîne, quand le super administrateur modifie un seuil', async () => {
        appels.modifier.mockResolvedValue({ message: '« Centres par superviseur » modifié.', donnees: {} });

        afficher(true);

        fireEvent.click(await screen.findByRole('button', { name: 'Modifier Centres par superviseur' }));
        fireEvent.change(screen.getByLabelText('Centres par superviseur'), { target: { value: '3' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

        await waitFor(() => expect(appels.modifier).toHaveBeenCalledOnce());

        expect(appels.modifier).toHaveBeenCalledWith('/parametres/affectation.centres_par_superviseur', { valeur: 3 });
    });

    it('ne propose que les rôles notifiables, et renvoie la matrice en liste', async () => {
        appels.modifier.mockResolvedValue({ message: 'Modifié.', donnees: {} });

        afficher(true);

        fireEvent.click(await screen.findByRole('button', { name: 'Modifier Matrice de notification — niveau 1' }));

        // Un opérateur serait prévenu dans tout le pays : il n'est pas proposé.
        expect(screen.queryByLabelText('Opérateur de kit')).not.toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Chef d’antenne régional'));
        fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

        await waitFor(() => expect(appels.modifier).toHaveBeenCalledOnce());

        expect(appels.modifier).toHaveBeenCalledWith('/parametres/incidents.notification.niveau_1', {
            valeur: ['volontaire_superviseur', 'chef_antenne_regional'],
        });
    });
});
