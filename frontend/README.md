# Back-office GIP-PNVB

Client web de l'API de la plateforme de gestion des volontaires (projet WURI).

Ce dossier est **indépendant du backend Laravel** : le back-office consomme
`/api/v1` par jeton Bearer, exactement comme l'application mobile Flutter. Il se
construit seul et se déploie en fichiers statiques.

## Démarrer

```bash
npm install
npm run dev
```

Le serveur de développement écoute sur `http://localhost:5173` et **relaie
`/api` vers `http://127.0.0.1:8000`** — le navigateur ne voit qu'une seule
origine, il n'y a donc aucun CORS à configurer pour travailler. Pour viser une
autre API, copiez `.env.example` en `.env` et changez `VITE_API_URL`.

Côté Laravel, dans un autre terminal :

```bash
php artisan serve
```

## Vérifier

```bash
npm run test     # Vitest : client d'API, garde de route, navigation
npm run build    # construit dist/
```

## Déployer

`npm run build` produit `dist/`. Nginx le sert en statique et relaie `/api`
vers PHP-FPM. Tant que les deux sont derrière le même domaine, aucun CORS
n'est nécessaire ; sinon, `config/cors.php` côté Laravel prévoit la liste des
origines autorisées.

## Organisation

| Dossier | Rôle |
| --- | --- |
| `src/api` | le client de l'API : enveloppe, jeton, erreurs normalisées |
| `src/auth` | session, permissions, périmètre, garde de route |
| `src/composants` | briques réutilisables — tableau, champs, états |
| `src/navigation` | le menu, construit depuis les permissions |
| `src/pages` | les écrans |
| `src/contenu` | les textes longs, séparés du code |

Le code, les noms de fichiers et les commentaires sont **en français**, comme
le backend.

## Ce que le front ne fait pas

Il ne sécurise rien. Les permissions servies par `/api/v1/moi` construisent une
interface honnête — ne pas proposer ce qui sera refusé — mais **le serveur
revérifie le droit et le périmètre à chaque requête**. Masquer un bouton ici ne
protège aucune donnée.
