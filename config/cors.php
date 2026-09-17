<?php

/*
|--------------------------------------------------------------------------
| Partage des ressources entre origines (CORS)
|--------------------------------------------------------------------------
|
| DANS LE CAS NORMAL, RIEN DE TOUT CECI NE SERT.
|
| En développement, le serveur de Vite relaie /api vers PHP : le navigateur ne
| voit qu'une seule origine. En production, Nginx sert les fichiers statiques du
| back-office ET relaie /api vers PHP-FPM : là encore, une seule origine.
|
| Cette configuration n'existe que pour le jour où le back-office serait déployé
| sur un domaine distinct de l'API — par exemple un sous-domaine séparé, ou un
| hébergement statique externe. Elle se règle alors par la variable
| d'environnement CORS_ORIGINES, en listant les origines une à une.
|
| LES ORIGINES NE SONT JAMAIS OUVERTES À TOUT LE MONDE PAR DÉFAUT. Un « * » sur
| une API qui sert des données de mission — y compris des numéros CNIB à
| l'administration nationale — laisserait n'importe quel site appeler cette API
| depuis le navigateur d'un agent connecté.
|
| `supports_credentials` reste FAUX : l'authentification passe par un en-tête
| Bearer, jamais par un cookie. Le mettre à vrai n'apporterait rien et
| interdirait toute souplesse sur les origines.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Vide par défaut : aucune origine tierce n'est admise tant que le
    // déploiement ne l'exige pas explicitement.
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ORIGINES', '')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    // Le client lit ces en-têtes : le nom du fichier pour un export PDF ou
    // tableur, sans quoi le navigateur enregistrerait « download ».
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 3600,

    // Jetons Bearer, pas de cookies : aucune raison d'autoriser les
    // informations d'identification entre origines.
    'supports_credentials' => false,

];
