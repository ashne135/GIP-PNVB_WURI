<?php

use App\Http\Middleware\ExigerAcceptationCharte;
use App\Http\Middleware\ExigerChangementMotDePasse;
use App\Http\Middleware\LimiterJetonRattrapage;
use App\Http\Middleware\RefuserEcritureObservateur;
use App\Http\Responses\ReponseApi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'observateur.lecture_seule' => RefuserEcritureObservateur::class,
            'mot_de_passe.change' => ExigerChangementMotDePasse::class,
            'charte.acceptee' => ExigerAcceptationCharte::class,
            'jeton.rattrapage' => LimiterJetonRattrapage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
        |----------------------------------------------------------------------
        | Format de réponse constant, y compris en erreur (cadrage, section 14)
        |----------------------------------------------------------------------
        | { "success": bool, "message": string, "data": object|array }
        |
        | Le message est TOUJOURS en français simple, compréhensible par un agent
        | de terrain. Jamais « Error 422 Unprocessable Entity ».
        */
        $exceptions->render(function (ValidationException $e, Request $requete) {
            if (! $requete->expectsJson()) {
                return null;
            }

            return ReponseApi::validation(
                'Certaines informations sont incorrectes ou manquantes.',
                $e->errors()
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $requete) {
            if (! $requete->expectsJson()) {
                return null;
            }

            return ReponseApi::echec('Vous devez être connecté pour faire cette action.', null, 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $requete) {
            if (! $requete->expectsJson()) {
                return null;
            }

            return ReponseApi::echec("Vous n'avez pas les droits pour faire cette action.", null, 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $requete) {
            if (! $requete->expectsJson()) {
                return null;
            }

            // Message identique à un 404 ordinaire : ne pas révéler qu'un objet
            // existe mais qu'il est hors du périmètre de l'utilisateur.
            return ReponseApi::echec("Cet élément est introuvable.", null, 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $requete) {
            if (! $requete->expectsJson()) {
                return null;
            }

            return ReponseApi::echec("Cet élément est introuvable.", null, 404);
        });

        $exceptions->render(function (Throwable $e, Request $requete) {
            if (! $requete->expectsJson()) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                // Laravel convertit AuthenticationException et
                // AuthorizationException en HttpException avant d'arriver ici :
                // les codes 401 et 403 sont donc traités dans ce match, sans
                // quoi un refus de droit afficherait un message générique au
                // lieu d'expliquer à l'agent ce qui lui est refusé.
                return ReponseApi::echec(
                    match ($e->getStatusCode()) {
                        401 => 'Vous devez être connecté pour faire cette action.',
                        403 => "Vous n'avez pas les droits pour faire cette action.",
                        404 => 'Cet élément est introuvable.',
                        405 => "Cette action n'est pas possible sur cette adresse.",
                        429 => 'Trop de requêtes. Patientez un instant avant de réessayer.',
                        default => "Une erreur est survenue. Réessayez, et prévenez votre superviseur si cela continue.",
                    },
                    null,
                    $e->getStatusCode()
                );
            }

            // En production, aucun détail technique ne sort : l'agent n'a pas à
            // lire une trace PHP, et elle ne doit pas fuiter. Le détail reste
            // dans les journaux.
            if (config('app.debug')) {
                return null;
            }

            return ReponseApi::echec(
                "Une erreur est survenue. Réessayez, et prévenez votre superviseur si cela continue.",
                null,
                500
            );
        });
    })->create();
