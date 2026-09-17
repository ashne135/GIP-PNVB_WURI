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

createRoot(document.getElementById('racine')).render(
    <StrictMode>
        <QueryClientProvider client={cache}>
            <BrowserRouter>
                <FournisseurAuth>
                    <App />
                </FournisseurAuth>
            </BrowserRouter>
        </QueryClientProvider>
    </StrictMode>,
);
