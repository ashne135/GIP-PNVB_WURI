import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { Disposition } from '../src/composants/Disposition';

/**
 * LA COQUE DU BACK-OFFICE.
 *
 * Trois choses à ne pas casser en refondant le design : le logo identifie la
 * plateforme, le menu ne propose que ce que le compte a le droit d'ouvrir, et
 * le périmètre reste affiché en permanence — « National » ou « une région »
 * change le sens de tous les chiffres à l'écran.
 */
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/auth/ContexteAuth', () => ({ useAuth: () => session.valeur }));

function afficher(auth = {}) {
    session.valeur = {
        utilisateur: { nom: 'Ouédraogo', prenoms: 'Awa', telephone: '+22670000002', peut_saisir: true },
        volontaire: null,
        perimetre: { libelle: 'National', regions: [] },
        estNational: true,
        estLectureSeule: false,
        peutAuMoins: () => true,
        deconnecter: vi.fn(),
        ...auth,
    };

    return render(
        <MemoryRouter initialEntries={['/']}>
            <Routes>
                <Route element={<Disposition />}>
                    <Route path="/" element={<p>Contenu de la page</p>} />
                </Route>
            </Routes>
        </MemoryRouter>,
    );
}

beforeEach(() => {
    session.valeur = {};
});

describe('la coque du back-office', () => {
    it('porte le logo du Programme et le contenu de la page', () => {
        afficher();

        expect(screen.getByAltText(/Programme national de volontariat/i)).toHaveAttribute(
            'src',
            '/logo-pnvb.jpg',
        );
        expect(screen.getByText('Contenu de la page')).toBeInTheDocument();
    });

    it('affiche en permanence le périmètre et le compte connecté', () => {
        afficher();

        expect(screen.getByText('National — 12 régions')).toBeInTheDocument();
        expect(screen.getByText('Awa Ouédraogo')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Quitter' })).toBeInTheDocument();
    });

    it('signale un compte en lecture seule, pour qu’il ne cherche pas un bouton inexistant', () => {
        afficher({ estLectureSeule: true });

        expect(screen.getByText('Lecture seule')).toBeInTheDocument();
    });

    it('ne propose que les écrans que le compte a le droit d’ouvrir', () => {
        afficher({ peutAuMoins: (...permissions) => permissions.includes('alertes.consulter') });

        expect(screen.getByRole('link', { name: /Alertes/ })).toBeInTheDocument();
        // Sans la permission, l'entrée disparaît : promettre une page qui
        // répondra « non autorisé » est une promesse qu'on ne tient pas.
        expect(screen.queryByRole('link', { name: /Parc de kits/ })).not.toBeInTheDocument();
    });
});
