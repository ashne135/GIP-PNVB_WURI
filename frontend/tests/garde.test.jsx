import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { Garde } from '../src/auth/Garde';

/**
 * LE GARDE DE ROUTE suit l'ordre du serveur : connexion, mot de passe, charte.
 *
 * Se tromper d'ordre enverrait l'agent sur un écran que le serveur refuse
 * encore, et le ferait rebondir sans qu'il comprenne pourquoi.
 */

// Le garde lit la session : on la fabrique de toutes pièces.
const session = vi.hoisted(() => ({ valeur: {} }));

vi.mock('../src/auth/ContexteAuth', () => ({
    useAuth: () => session.valeur,
}));

function poser(auth, chemin = '/rapports') {
    session.valeur = {
        chargement: false,
        connecte: true,
        doitChangerMotDePasse: false,
        doitAccepterCharte: false,
        peutAuMoins: () => true,
        ...auth,
    };

    return render(
        <MemoryRouter initialEntries={[chemin]}>
            <Routes>
                <Route
                    path="/rapports"
                    element={
                        <Garde permission="rapports.consulter">
                            <p>Les rapports</p>
                        </Garde>
                    }
                />
                <Route path="/connexion" element={<p>Écran de connexion</p>} />
                <Route path="/premiere-connexion/mot-de-passe" element={<p>Nouveau mot de passe</p>} />
                <Route path="/premiere-connexion/charte" element={<p>La charte</p>} />
                <Route path="/acces-refuse" element={<p>Accès refusé</p>} />
            </Routes>
        </MemoryRouter>,
    );
}

describe('le garde de route', () => {
    it('attend la vérification avant de décider quoi que ce soit', () => {
        poser({ chargement: true, connecte: false });

        expect(screen.getByRole('status')).toHaveTextContent('Vérification de votre session');
    });

    it('renvoie à la connexion qui n’est pas connecté', () => {
        poser({ connecte: false });

        expect(screen.getByText('Écran de connexion')).toBeInTheDocument();
    });

    it('impose le mot de passe AVANT la charte', () => {
        poser({ doitChangerMotDePasse: true, doitAccepterCharte: true });

        expect(screen.getByText('Nouveau mot de passe')).toBeInTheDocument();
    });

    it('impose la charte une fois le mot de passe changé', () => {
        poser({ doitAccepterCharte: true });

        expect(screen.getByText('La charte')).toBeInTheDocument();
    });

    it('refuse la page à qui n’a pas la permission', () => {
        poser({ peutAuMoins: () => false });

        expect(screen.getByText('Accès refusé')).toBeInTheDocument();
    });

    it('laisse passer quand tout est levé', () => {
        poser({});

        expect(screen.getByText('Les rapports')).toBeInTheDocument();
    });
});
