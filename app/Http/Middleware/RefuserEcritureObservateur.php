<?php

namespace App\Http\Middleware;

use App\Enums\RolePnvb;
use App\Http\Responses\ReponseApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OBSERVATEUR — LECTURE SEULE STRICTE (cadrage, section 5, acteur 7) :
 * « Le serveur refuse toute requête d'écriture venant de ce rôle. »
 *
 * Barrière posée AVANT les Policies, et non à leur place : même si une Policy
 * oubliait un cas, aucune méthode d'écriture ne passe pour ce rôle. C'est une
 * défense en profondeur, pas un doublon — le cadrage exige que ce soit le
 * serveur, et non l'interface, qui refuse.
 */
class RefuserEcritureObservateur
{
    private const METHODES_ECRITURE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $requete, Closure $suivant): Response
    {
        $utilisateur = $requete->user();

        if (! $utilisateur || ! in_array($requete->method(), self::METHODES_ECRITURE, true)) {
            return $suivant($requete);
        }

        if (! $utilisateur->hasRole(RolePnvb::Observateur->value)) {
            return $suivant($requete);
        }

        activity('securite')
            ->performedOn($utilisateur)
            ->withProperties([
                'methode' => $requete->method(),
                'chemin' => $requete->path(),
                'ip' => $requete->ip(),
            ])
            ->log('Tentative d\'écriture refusée : rôle observateur en lecture seule');

        return ReponseApi::echec(
            'Votre profil est en consultation seule : vous ne pouvez pas modifier de données.',
            null,
            403
        );
    }
}
