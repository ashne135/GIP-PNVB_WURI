<?php

namespace App\Mail;

use App\Models\User;
use App\Support\NormalisateurTelephone;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Courriel de remise des identifiants — premier maillon de la cascade.
 *
 * Le mot de passe est passé en paramètre et JAMAIS relu depuis la base : il
 * n'existe en clair que le temps de cet envoi (cadrage, section 6 : mot de
 * passe généré aléatoirement, à usage unique).
 */
class IdentifiantsVolontaireMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $utilisateur,
        public readonly string $motDePasseTemporaire,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vos identifiants de connexion — Plateforme des volontaires GIP-PNVB',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'courriels.identifiants',
            with: [
                'nomComplet' => $this->utilisateur->nomComplet(),
                'identifiant' => NormalisateurTelephone::pourAffichage($this->utilisateur->telephone),
                'motDePasse' => $this->motDePasseTemporaire,
            ],
        );
    }
}
