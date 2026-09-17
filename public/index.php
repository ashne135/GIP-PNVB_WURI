<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Servi sous un sous-chemin ? On le retire AVANT que Laravel ne lise l'URL.
|--------------------------------------------------------------------------
|
| Certains hébergements associent une adresse publique (/pnvbwuri) à un
| répertoire du disque sans en informer PHP : SCRIPT_NAME vaut alors le chemin
| DISQUE (/pnvb/public/index.php), Symfony ne trouve aucun préfixe commun avec
| REQUEST_URI, et conserve le sous-chemin dans path(). Plus AUCUNE route ne
| correspond — et la route de repli sert la page du back-office jusque sur les
| adresses d'API, en 200, là où le téléphone attend du JSON.
|
| Constaté en déploiement réel sur alwaysdata.
|
| VIDE PAR DÉFAUT : le développement local, les tests et tout hébergement
| classique sont strictement inchangés. Se règle par APP_PREFIXE_URL.
*/
$prefixe = getenv('APP_PREFIXE_URL') ?: '';

// getenv() ne voit PAS le .env : ce code s'exécute avant que Laravel ne
// charge sa configuration. On lit donc le fichier directement, en repli ; une
// variable posée côté serveur garde la priorité.
if ($prefixe === '' && is_file($fichierEnv = __DIR__.'/../.env')) {
    foreach (file($fichierEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
        $ligne = trim($ligne);

        if (str_starts_with($ligne, 'APP_PREFIXE_URL=')) {
            $prefixe = trim(substr($ligne, strlen('APP_PREFIXE_URL=')), " \"'");
            break;
        }
    }
}

if ($prefixe !== '' && isset($_SERVER['REQUEST_URI'])) {
    $prefixe = '/'.trim($prefixe, '/');
    $uri = $_SERVER['REQUEST_URI'];

    if ($uri === $prefixe || str_starts_with($uri, $prefixe.'/') || str_starts_with($uri, $prefixe.'?')) {
        $_SERVER['REQUEST_URI'] = substr($uri, strlen($prefixe)) ?: '/';
    }
}

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
