<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutCompte;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangementMotDePasseRequest;
use App\Http\Requests\Auth\ConnexionRequest;
use App\Http\Resources\UtilisateurConnecteResource;
use App\Http\Responses\ReponseApi;
use App\Models\Consentement;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Authentification par numéro de téléphone, jetons Bearer (Sanctum).
 *
 * Le cadrage (section 14) impose la limitation des tentatives de connexion :
 * elle est appliquée ici PAR NUMÉRO ET PAR ADRESSE IP, pas seulement par IP —
 * plusieurs agents partagent souvent la même connexion sur un site.
 */
class AuthController extends Controller
{
    private const TENTATIVES_MAX = 5;

    private const BLOCAGE_SECONDES = 60;

    public function connexion(ConnexionRequest $requete): JsonResponse
    {
        $cle = $this->cleLimitation($requete);

        if (RateLimiter::tooManyAttempts($cle, self::TENTATIVES_MAX)) {
            $secondes = RateLimiter::availableIn($cle);

            return ReponseApi::echec(
                "Trop de tentatives de connexion. Réessayez dans {$secondes} secondes.",
                ['secondes_restantes' => $secondes],
                429
            );
        }

        $utilisateur = User::query()
            ->with('volontaire')
            ->where('telephone', $requete->validated('telephone'))
            ->first();

        if (! $utilisateur || ! Hash::check($requete->validated('mot_de_passe'), $utilisateur->password)) {
            RateLimiter::hit($cle, self::BLOCAGE_SECONDES);

            // Message volontairement identique dans les deux cas : ne pas
            // révéler si le numéro existe en base.
            return ReponseApi::echec('Numéro de téléphone ou mot de passe incorrect.', null, 401);
        }

        if ($refus = $this->refuserSelonStatut($utilisateur)) {
            RateLimiter::hit($cle, self::BLOCAGE_SECONDES);

            return $refus;
        }

        RateLimiter::clear($cle);

        // Un nouveau jeton par appareil : l'agent peut être connecté sur son
        // téléphone et le superviseur sur le web, sans se déconnecter l'un l'autre.
        $nomAppareil = $requete->validated('nom_appareil') ?: 'appareil-inconnu';
        $jeton = $utilisateur->createToken($nomAppareil)->plainTextToken;

        $premiereConnexion = $utilisateur->premiere_connexion_le === null;

        $utilisateur->forceFill([
            'derniere_connexion_le' => now(),
            'premiere_connexion_le' => $utilisateur->premiere_connexion_le ?? now(),
        ])->save();

        if ($premiereConnexion) {
            $utilisateur->update(['etat_remise' => 'premiere_connexion_effectuee']);

            activity('compte')
                ->performedOn($utilisateur)
                ->log('Première connexion effectuée');
        }

        return ReponseApi::succes('Connexion réussie.', [
            'jeton' => $jeton,
            ...(new UtilisateurConnecteResource($utilisateur))->toArray($requete),
        ]);
    }

    /**
     * Le STATUT DE COMPTE commande l'accès (cadrage, section 6). Les messages
     * expliquent la situation à l'agent au lieu de le laisser devant un refus
     * sec : entre deux vagues, un assistant n'a rien fait de mal.
     */
    private function refuserSelonStatut(User $utilisateur): ?JsonResponse
    {
        if ($utilisateur->statut_compte->autoriseConnexion()) {
            return null;
        }

        $message = match ($utilisateur->statut_compte) {
            StatutCompte::Inactif => "Votre compte n'est pas encore activé. "
                .'Il le sera dès votre affectation à une mission.',
            StatutCompte::Ferme => 'Votre accès est fermé car aucune mission n\'est en cours '
                .'sur votre localité. Il se rouvrira à la prochaine vague.',
            default => 'Votre accès n\'est pas ouvert actuellement.',
        };

        return ReponseApi::echec($message, ['statut_compte' => $utilisateur->statut_compte->value], 403);
    }

    public function deconnexion(Request $requete): JsonResponse
    {
        $requete->user()->currentAccessToken()->delete();

        return ReponseApi::succes('Vous êtes déconnecté.');
    }

    /** Profil complet : rôles, permissions et périmètre, comme à la connexion. */
    public function moi(Request $requete): JsonResponse
    {
        $utilisateur = $requete->user()->load('volontaire');

        return ReponseApi::succes(
            'Profil récupéré.',
            (new UtilisateurConnecteResource($utilisateur))->toArray($requete)
        );
    }

    public function changerMotDePasse(ChangementMotDePasseRequest $requete): JsonResponse
    {
        $utilisateur = $requete->user();

        if (! Hash::check($requete->validated('mot_de_passe_actuel'), $utilisateur->password)) {
            return ReponseApi::echec('Le mot de passe actuel est incorrect.', null, 422);
        }

        $utilisateur->forceFill([
            'password' => $requete->validated('nouveau_mot_de_passe'),
            'doit_changer_mot_de_passe' => false,
            'mot_de_passe_change_le' => now(),
        ])->save();

        // Tous les autres jetons sont révoqués : si le mot de passe initial a
        // circulé (courriel, SMS, bordereau papier), les sessions ouvertes
        // ailleurs avec ce mot de passe doivent tomber.
        $jetonCourant = $requete->user()->currentAccessToken();
        $utilisateur->tokens()->where('id', '!=', $jetonCourant->id)->delete();

        activity('compte')
            ->performedOn($utilisateur)
            ->log('Mot de passe changé');

        return ReponseApi::succes('Votre mot de passe a été changé.');
    }

    /**
     * Acceptation de la charte du volontaire.
     *
     * Le cadrage (section 8.4) l'exige avant toute collecte de position :
     * « Discret ne veut pas dire caché. Le dispositif figure dans la charte du
     * volontaire et est accepté à la première connexion, avec trace du
     * consentement. »
     */
    public function accepterCharte(Request $requete): JsonResponse
    {
        $utilisateur = $requete->user();
        $version = (string) Parametre::valeur('comptes.charte_version_courante', '2026.1');

        // Le client envoie la version du texte QU'IL A AFFICHÉ. Si la charte a
        // changé pendant la lecture, on refuse : enregistrer l'acceptation de la
        // version courante reviendrait à faire accepter un texte non lu.
        $affichee = $requete->validate(['version_charte' => ['nullable', 'string', 'max:40']])['version_charte'] ?? null;

        if (filled($affichee) && $affichee !== $version) {
            return ReponseApi::echec(
                'La charte a été mise à jour pendant votre lecture. Relisez la nouvelle version avant de l’accepter.',
                ['version_courante' => $version],
                409
            );
        }

        Consentement::query()->updateOrCreate(
            ['user_id' => $utilisateur->id, 'version_charte' => $version],
            [
                'accepte_le' => now(),
                'adresse_ip' => $requete->ip(),
                'agent_utilisateur' => Str::limit((string) $requete->userAgent(), 250, ''),
            ]
        );

        activity('compte')
            ->performedOn($utilisateur)
            ->withProperties(['version_charte' => $version])
            ->log('Charte du volontaire acceptée');

        return ReponseApi::succes('Merci, la charte est acceptée.', ['version_charte' => $version]);
    }

    /**
     * Limitation par numéro ET par adresse IP : sur un site, plusieurs agents
     * partagent la même connexion, une limitation par IP seule les bloquerait
     * tous à cause d'un seul qui se trompe de mot de passe.
     */
    private function cleLimitation(ConnexionRequest $requete): string
    {
        return 'connexion:'.$requete->input('telephone').'|'.$requete->ip();
    }
}
