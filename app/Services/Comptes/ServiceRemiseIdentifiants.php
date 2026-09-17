<?php

namespace App\Services\Comptes;

use App\Enums\EtatRemise;
use App\Mail\IdentifiantsVolontaireMail;
use App\Models\Parametre;
use App\Models\RemiseIdentifiants;
use App\Models\User;
use App\Support\NormalisateurTelephone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Cascade de remise des identifiants (cadrage, section 6) :
 *
 *   1. COURRIEL — canal par défaut.
 *   2. SMS — si le courriel est absent, invalide, ou si l'envoi échoue.
 *   3. REMISE EN MAIN PROPRE pendant la formation — bordereau PDF par session.
 *      Ce maillon n'est pas automatique : c'est un acte humain, déclenché depuis
 *      l'écran de suivi (voir GenerateurBordereauFormation).
 *
 * LE MOT DE PASSE TEMPORAIRE N'EST JAMAIS STOCKÉ EN CLAIR : il est généré,
 * envoyé, puis oublié. Seule la trace de l'envoi subsiste — canal, destinataire,
 * date, succès ou motif d'échec.
 *
 * Chaque compte porte un ÉTAT DE REMISE, qui alimente l'écran de suivi et le
 * bouton de renvoi de l'administrateur national.
 */
class ServiceRemiseIdentifiants
{
    public function __construct(
        private readonly PasserelleSms $sms = new PasserelleSms,
    ) {
    }

    /**
     * Point d'entrée : tente le premier canal possible, et enchaîne sur le
     * suivant en cas d'échec.
     */
    public function amorcerCascade(User $utilisateur, ?User $auteur = null): RemiseIdentifiants
    {
        if ($utilisateur->email) {
            return $this->tenterCourriel($utilisateur, $auteur);
        }

        if (Parametre::booleen('comptes.canal_secours_sms', true)) {
            return $this->tenterSms($utilisateur, $auteur);
        }

        return $this->marquerEnAttenteMainPropre($utilisateur, $auteur);
    }

    public function tenterCourriel(User $utilisateur, ?User $auteur = null): RemiseIdentifiants
    {
        if (! $utilisateur->email) {
            return $this->tenterSms($utilisateur, $auteur);
        }

        $motDePasse = $this->reinitialiserMotDePasse($utilisateur);

        $remise = RemiseIdentifiants::query()->create([
            'user_id' => $utilisateur->id,
            'canal' => 'courriel',
            'rang' => 1,
            'statut' => 'en_attente',
            'destinataire' => $utilisateur->email,
        ]);

        try {
            Mail::to($utilisateur->email)->send(
                new IdentifiantsVolontaireMail($utilisateur, $motDePasse)
            );

            $remise->update(['statut' => 'envoye', 'envoye_le' => now()]);
            $utilisateur->update(['etat_remise' => EtatRemise::Envoye->value]);

            $this->journaliser($utilisateur, 'courriel', 'envoye', null, $auteur);

            return $remise;
        } catch (\Throwable $exception) {
            $remise->update([
                'statut' => 'echec',
                'erreur' => Str::limit($exception->getMessage(), 500, ''),
            ]);
            $utilisateur->update(['etat_remise' => EtatRemise::Echec->value]);

            $this->journaliser($utilisateur, 'courriel', 'echec', $exception->getMessage(), $auteur);

            // Le courriel a échoué : on bascule sur le SMS, sans intervention.
            // L'auteur suit la cascade : c'est le même acte, jusqu'au bout.
            return $this->tenterSms($utilisateur, $auteur);
        }
    }

    public function tenterSms(User $utilisateur, ?User $auteur = null): RemiseIdentifiants
    {
        if (! Parametre::booleen('comptes.canal_secours_sms', true)) {
            return $this->marquerEnAttenteMainPropre($utilisateur, $auteur);
        }

        $motDePasse = $this->reinitialiserMotDePasse($utilisateur);

        $remise = RemiseIdentifiants::query()->create([
            'user_id' => $utilisateur->id,
            'canal' => 'sms',
            'rang' => 2,
            'statut' => 'en_attente',
            'destinataire' => $utilisateur->telephone,
        ]);

        $envoye = $this->sms->envoyer($utilisateur->telephone, $this->messageSms($utilisateur, $motDePasse));

        if ($envoye) {
            $remise->update(['statut' => 'envoye', 'envoye_le' => now()]);
            $utilisateur->update(['etat_remise' => EtatRemise::Envoye->value]);

            $this->journaliser($utilisateur, 'sms', 'envoye', null, $auteur);

            return $remise;
        }

        $remise->update(['statut' => 'echec', 'erreur' => "L'envoi du SMS a échoué."]);
        $utilisateur->update(['etat_remise' => EtatRemise::Echec->value]);

        $this->journaliser($utilisateur, 'sms', 'echec', null, $auteur);

        // Dernier recours : la remise en main propre en formation.
        return $this->marquerEnAttenteMainPropre($utilisateur, $auteur);
    }

    /**
     * Ne génère PAS de mot de passe : c'est le bordereau de session qui les
     * génère en lot, au moment où il est imprimé. Ici, on ne fait que signaler
     * que ce compte attend une remise physique.
     */
    public function marquerEnAttenteMainPropre(User $utilisateur, ?User $auteur = null): RemiseIdentifiants
    {
        $remise = RemiseIdentifiants::query()->create([
            'user_id' => $utilisateur->id,
            'canal' => 'main_propre',
            'rang' => 3,
            'statut' => 'en_attente',
            'destinataire' => $utilisateur->telephone,
        ]);

        $this->journaliser($utilisateur, 'main_propre', 'en_attente', null, $auteur);

        return $remise;
    }

    /**
     * Un SMS est court et lu sur un téléphone basique : pas de mise en forme,
     * pas de lien, juste ce qu'il faut pour se connecter.
     */
    private function messageSms(User $utilisateur, string $motDePasse): string
    {
        $identifiant = NormalisateurTelephone::pourAffichage($utilisateur->telephone);

        return "GIP-PNVB. Bonjour {$utilisateur->prenoms}. "
            ."Votre compte est ouvert. Identifiant : {$identifiant}. "
            ."Mot de passe provisoire : {$motDePasse}. "
            .'A changer a la premiere connexion. Ne le communiquez a personne.';
    }

    /**
     * Génère un mot de passe temporaire, le pose sur le compte (haché par le
     * cast du modèle) et le renvoie en clair à l'appelant, une seule fois.
     */
    private function reinitialiserMotDePasse(User $utilisateur): string
    {
        $motDePasse = Str::password(
            length: Parametre::entier('comptes.longueur_mot_de_passe_initial', 10),
            symbols: false
        );

        $utilisateur->forceFill([
            'password' => $motDePasse,
            'doit_changer_mot_de_passe' => true,
        ])->save();

        return $motDePasse;
    }

    private function journaliser(
        User $utilisateur,
        string $canal,
        string $resultat,
        ?string $erreur = null,
        ?User $auteur = null
    ): void {
        activity('compte')
            // Un renvoi RÉGÉNÈRE le mot de passe : l'ancien cesse de marcher.
            // Qui l'a déclenché fait partie de ce qu'on devra pouvoir établir
            // si un agent dit n'avoir jamais reçu ses identifiants.
            //
            // Null reste juste quand la cascade part d'elle-même, à l'ouverture
            // d'un accès : personne n'a « décidé » cet envoi.
            ->causedBy($auteur)
            ->performedOn($utilisateur)
            ->withProperties(array_filter([
                'canal' => $canal,
                'resultat' => $resultat,
                'erreur' => $erreur,
            ]))
            ->log("Remise des identifiants par {$canal} : {$resultat}");

        Log::channel('pnvb_comptes')->info('remise_identifiants', [
            'user_id' => $utilisateur->id,
            'canal' => $canal,
            'resultat' => $resultat,
        ]);
    }
}
