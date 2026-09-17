<?php

namespace App\Services\Comptes;

use App\Models\Parametre;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * FERMER UN ACCÈS SANS FAIRE PERDRE LA DERNIÈRE JOURNÉE.
 *
 * Deux exigences opposées, et ce service les tient ensemble :
 *
 *  - un accès fermé doit l'être VRAIMENT. Refuser la connexion ne suffit pas :
 *    le téléphone garde le jeton qu'il a déjà reçu, et parlerait encore à l'API ;
 *
 *  - l'agent ne doit JAMAIS attendre le réseau (cadrage, section 11). Celui qui a
 *    travaillé sans réseau le dernier jour a encore sa journée dans son téléphone.
 *
 * À la fermeture, les jetons ne sont donc pas supprimés : ils sont RESTREINTS à
 * l'envoi de la file, et expirent au terme du délai de rattrapage (paramètre).
 * Le moteur de synchronisation refuse alors toute action horodatée après la
 * fermeture. À la réouverture, un jeton encore valide retrouve ses droits.
 */
class ServiceAccesRattrapage
{
    /** La seule capacité d'un jeton restreint. */
    public const CAPACITE = 'sync:rattrapage';

    /**
     * Les routes qu'un jeton restreint atteint encore : envoyer la file, savoir
     * ce que le serveur accepte, relire son profil, se déconnecter.
     */
    public const ROUTES_AUTORISEES = [
        'api.sync',
        'api.sync.types',
        'api.sync.fichiers',
        'api.sync.lots',
        'api.moi',
        'api.deconnexion',
    ];

    /**
     * Un jeton est restreint dès qu'il ne porte pas tous les droits. Un jeton
     * transitoire — session web, tests — n'est jamais restreint.
     */
    public static function estRestreint(mixed $jeton): bool
    {
        return $jeton instanceof PersonalAccessToken && $jeton->cant('*');
    }

    public function restreindre(User $utilisateur): void
    {
        $delai = Parametre::entier('comptes.delai_rattrapage_jours', 7);

        $utilisateur->forceFill(['acces_ferme_le' => now()])->save();

        $utilisateur->tokens()->update([
            'abilities' => json_encode([self::CAPACITE]),
            'expires_at' => now()->addDays($delai),
        ]);
    }

    public function retablir(User $utilisateur): void
    {
        $utilisateur->forceFill(['acces_ferme_le' => null])->save();

        // Un jeton de rattrapage expiré ne se ranime pas : l'agent se reconnecte.
        $utilisateur->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $utilisateur->tokens()
            ->where('abilities', json_encode([self::CAPACITE]))
            ->update(['abilities' => json_encode(['*']), 'expires_at' => null]);
    }
}
