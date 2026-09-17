<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // En production, le serveur web sert directement public/index.html s'il
    // existe : cette vue n'apparaît que sur une installation sans back-office.
    return is_file(public_path('index.html'))
        ? response()->file(public_path('index.html'))
        : view('welcome');
});

/*
|--------------------------------------------------------------------------
| Le back-office est une APPLICATION À PAGE UNIQUE
|--------------------------------------------------------------------------
| Son routage vit dans le navigateur. Sans cette règle, ouvrir directement
| /equipes ou /journal — ou simplement recharger la page — renverrait une 404
| du serveur, alors que l'écran existe bel et bien.
|
| L'API EST EXCLUE, et ce n'est pas un détail : sans cette exclusion, un appel
| à une route d'API inexistante recevrait la page HTML avec un statut 200. Le
| téléphone, qui attend du JSON, échouerait de façon incompréhensible.
*/
Route::fallback(function (Request $requete) {
    if ($requete->is('api/*')) {
        abort(404);
    }

    $index = public_path('index.html');

    abort_unless(is_file($index), 404);

    return response()->file($index);
});
