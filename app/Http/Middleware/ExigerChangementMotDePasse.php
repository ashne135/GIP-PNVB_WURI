<?php

namespace App\Http\Middleware;

use App\Http\Responses\ReponseApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le mot de passe initial est À USAGE UNIQUE, avec changement OBLIGATOIRE à la
 * première connexion (cadrage, section 6).
 *
 * Tant que le changement n'est pas fait, seules les routes qui permettent
 * précisément de le faire — et de se déconnecter — restent accessibles. Sans
 * ce verrou serveur, un mot de passe distribué par bordereau papier resterait
 * utilisable indéfiniment.
 */
class ExigerChangementMotDePasse
{
    /** Routes nommées accessibles malgré l'obligation en cours. */
    private const ROUTES_AUTORISEES = [
        'api.moi',
        'api.deconnexion',
        'api.mot-de-passe.changer',
        'api.charte.accepter',
    ];

    public function handle(Request $requete, Closure $suivant): Response
    {
        $utilisateur = $requete->user();

        if (! $utilisateur || ! $utilisateur->doit_changer_mot_de_passe) {
            return $suivant($requete);
        }

        if (in_array($requete->route()?->getName(), self::ROUTES_AUTORISEES, true)) {
            return $suivant($requete);
        }

        return ReponseApi::echec(
            'Vous devez d\'abord changer votre mot de passe pour continuer.',
            ['action_requise' => 'changer_mot_de_passe'],
            403
        );
    }
}
