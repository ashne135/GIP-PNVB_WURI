<?php

namespace App\Http\Middleware;

use App\Http\Responses\ReponseApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La charte du volontaire est acceptée à la première connexion, avec trace du
 * consentement (cadrage, section 8.4).
 *
 * Ce middleware ne s'applique qu'aux VOLONTAIRES : c'est leur position qui est
 * relevée par le mécanisme de rapprochement, ce sont eux que la charte engage.
 * Un chef d'antenne ou un observateur n'a pas de relevé de position.
 */
class ExigerAcceptationCharte
{
    private const ROUTES_AUTORISEES = [
        'api.moi',
        'api.deconnexion',
        'api.mot-de-passe.changer',
        'api.charte.accepter',
    ];

    public function handle(Request $requete, Closure $suivant): Response
    {
        $utilisateur = $requete->user();

        if (! $utilisateur?->volontaire) {
            return $suivant($requete);
        }

        if (in_array($requete->route()?->getName(), self::ROUTES_AUTORISEES, true)) {
            return $suivant($requete);
        }

        if ($utilisateur->aAccepteLaCharte()) {
            return $suivant($requete);
        }

        return ReponseApi::echec(
            'Vous devez d\'abord lire et accepter la charte du volontaire.',
            ['action_requise' => 'accepter_charte'],
            403
        );
    }
}
