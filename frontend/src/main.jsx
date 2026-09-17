import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FournisseurAuth } from './auth/ContexteAuth';
import { App } from './App';
import './index.css';

/**
 * Le cache des requêtes.
 *
 * `retry: false` est délibéré : le client d'API distingue déjà un refus de
 * droit d'une panne réseau, et réessayer trois fois un 403 ne fait que
 * retarder le message que l'agent doit lire. Les pages qui gagnent vraiment à
 * réessayer le demandent explicitement.
 */
const cache = new QueryClient({
    defaultOptions: {
        queries: {
            retry: false,
            refetchOnWindowFocus: false,
            // Les agrégats sont recalculés une fois par nuit : les relire à
            // chaque montage de composant n'apprendrait rien de nouveau.
            staleTime: 60_000,
        },
    },
});

/*
 * LE ROUTEUR SUIT LE CHEMIN DE DÉPLOIEMENT.
 *
 * Servi sous https://exemple.net/pnvbwuri, le back-office doit produire des
 * adresses préfixées. Sans `basename`, le routeur se croit à la racine du
 * domaine : la barre d'adresse affiche /connexion au lieu de
 * /pnvbwuri/connexion, et recharger la page tombe sur ce qui occupe la racine
 * — une autre application, ou rien.
 *
 * Le symptôme est trompeur, car la navigation interne CONTINUE DE FONCTIONNER
 * tant qu'on ne recharge pas : l'écran paraît sain alors que ses adresses sont
 * fausses.
 *
 * BASE_URL est la valeur figée par Vite à la compilation, celle-là même dont
 * le client d'API déduit son adresse. React Router la veut sans barre oblique
 * finale — et « / » doit devenir la chaîne vide.
 */
const basename = import.meta.env.BASE_URL.replace(/\/$/, '');

createRoot(document.getElementById('racine')).render(
    <StrictMode>
        <QueryClientProvider client={cache}>
            <BrowserRouter basename={basename}>
                <FournisseurAuth>
                    <App />
                </FournisseurAuth>
            </BrowserRouter>
        </QueryClientProvider>
    </StrictMode>,
);
