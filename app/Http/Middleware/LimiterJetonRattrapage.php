<?php

namespace App\Http\Middleware;

use App\Http\Responses\ReponseApi;
use App\Services\Comptes\ServiceAccesRattrapage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LE JETON D'UN ACCÈS FERMÉ NE SERT PLUS QU'À ENVOYER LA FILE.
 *
 * Après la fermeture d'un accès, le téléphone garde son jeton pendant le délai
 * de rattrapage, pour remonter le travail fait sans réseau. Tout le reste lui
 * est refusé : consulter, saisir en ligne, changer son mot de passe. Le message
 * dit à l'agent ce qui lui reste possible, et jusqu'à quand.
 */
class LimiterJetonRattrapage
{
    public function handle(Request $requete, Closure $suivant): Response
    {
        $jeton = $requete->user()?->currentAccessToken();

        if (! ServiceAccesRattrapage::estRestreint($jeton)) {
            return $suivant($requete);
        }

        if (in_array($requete->route()?->getName(), ServiceAccesRattrapage::ROUTES_AUTORISEES, true)) {
            return $suivant($requete);
        }

        $jusquAu = $jeton->expires_at;

        return ReponseApi::echec(
            'Votre accès est fermé. Votre téléphone peut encore envoyer le travail fait pendant votre mission'
                .($jusquAu ? ' jusqu\'au '.$jusquAu->format('d/m/Y') : '')
                .', mais plus rien d\'autre.',
            [
                'action_requise' => 'acces_ferme',
                'rattrapage_jusqu_au' => $jusquAu?->toIso8601String(),
            ],
            403
        );
    }
}
