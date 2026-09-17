import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

/**
 * Le back-office est un CLIENT de l'API, pas une application Laravel.
 * Il se construit seul et se déploie en fichiers statiques.
 *
 * EN DÉVELOPPEMENT, le serveur de Vite relaie /api vers PHP : le navigateur ne
 * voit qu'une seule origine, donc aucun CORS à configurer pour travailler.
 * EN PRODUCTION, Nginx sert dist/ et relaie /api de la même façon. Le CORS ne
 * devient nécessaire que si le back-office et l'API vivent sur deux domaines
 * différents — la configuration Laravel le permet, elle n'est pas active.
 */
export default defineConfig({
    plugins: [react()],

    server: {
        port: 5173,
        proxy: {
            '/api': {
                target: process.env.VITE_API_URL || 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },

    build: {
        outDir: 'dist',
        // Le back-office tourne sur des postes modestes et des connexions
        // lentes : on sépare les dépendances du code métier pour qu'un
        // déploiement ne force pas à retélécharger React à chaque fois.
        rollupOptions: {
            output: {
                manualChunks: {
                    socle: ['react', 'react-dom', 'react-router-dom'],
                    donnees: ['@tanstack/react-query', 'axios'],
                },
            },
        },
    },

    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: './tests/preparation.js',
    },
});
