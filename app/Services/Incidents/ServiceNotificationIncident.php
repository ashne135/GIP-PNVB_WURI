<?php

namespace App\Services\Incidents;

use App\Enums\GraviteIncident;
use App\Models\Alerte;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Parametre;
use App\Models\User;
use App\Services\Comptes\PasserelleSms;
use App\Services\Support\NumeroteurAlerte;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PRÉVENIR LES BONNES PERSONNES, ET GARDER LA TRACE DE QUI A ÉTÉ PRÉVENU.
 *
 * Deux canaux, et une règle qui les sépare :
 *
 *   - l'ALERTE en base est le canal normal : elle apparaît dans l'application
 *     de chaque destinataire, et se lit quand il l'ouvre ;
 *   - le SMS est réservé aux niveaux où attendre l'ouverture de l'application
 *     n'est pas acceptable. Envoyer un SMS pour chaque incident mineur coûterait
 *     cher et apprendrait surtout aux responsables à ne plus les lire.
 *
 * Chaque envoi écrit une ligne d'action de type « notification », avec la liste
 * nominative des destinataires : sans cela, personne ne peut dire plus tard qui
 * savait quoi, et à quelle heure — c'est précisément ce qu'on reproche aux
 * dispositifs d'alerte qui échouent.
 */
class ServiceNotificationIncident
{
    public function __construct(
        private readonly MatriceNotification $matrice,
        private readonly NumeroteurAlerte $numeroteurAlerte,
        private readonly PasserelleSms $sms,
    ) {}

    /**
     * @param  string  $motif  « declaration » ou « escalade »
     * @return array{destinataires: int, alerte: string|null, sms: int}
     */
    public function notifier(Incident $incident, string $motif): array
    {
        $destinataires = $this->matrice->destinatairesPour($incident, (int) $incident->niveau_escalade);

        if ($destinataires->isEmpty()) {
            // Aucun destinataire n'est un fait à tracer, pas un non-événement :
            // c'est le signe qu'un rôle n'est pourvu nulle part sur ce
            // territoire, et quelqu'un doit pouvoir s'en apercevoir.
            Log::warning('incident_sans_destinataire', [
                'incident' => $incident->numero,
                'gravite' => $incident->gravite->value,
                'region_id' => $incident->region_id,
                'centre_id' => $incident->centre_id,
            ]);

            $this->tracer($incident, $motif, collect(), 0, null);

            return ['destinataires' => 0, 'alerte' => null, 'sms' => 0];
        }

        $alerte = DB::transaction(fn () => $this->publierAlerte($incident, $motif));

        $envoyes = $this->envoyerLesSms($incident, $destinataires);

        $this->tracer($incident, $motif, $destinataires, $envoyes, $alerte->code);

        return [
            'destinataires' => $destinataires->count(),
            'alerte' => $alerte->code,
            'sms' => $envoyes,
        ];
    }

    /**
     * L'alerte porte LE TERRITOIRE DE L'INCIDENT, au plus juste : le centre
     * quand il en a un, sinon la région, sinon le national faute de mieux.
     *
     * Elle n'est PAS élargie pour atteindre les échelons supérieurs : c'est la
     * lecture qui remonte la hiérarchie (Alerte::scopePour), pas l'émission qui
     * descend. Publier « nationale » un incident de Boussé le ferait apparaître
     * aux douze régions — et une alerte que onze régions doivent ignorer est
     * une alerte que la douzième finira par ignorer aussi.
     */
    private function publierAlerte(Incident $incident, string $motif): Alerte
    {
        [$portee, $region, $centre] = match (true) {
            $incident->centre_id !== null => ['centre', null, $incident->centre_id],
            $incident->region_id !== null => ['regionale', $incident->region_id, null],
            default => ['nationale', null, null],
        };

        return Alerte::query()->create([
            'code' => $this->numeroteurAlerte->suivant(),
            'type' => $motif === 'escalade' ? 'escalade_incident' : 'systeme',
            'titre' => $this->titre($incident, $motif),
            'message' => $this->message($incident, $motif),
            'niveau' => $this->niveauAlerte($incident->gravite),
            // Émetteur nul : c'est l'acteur SYSTÈME qui alerte, pas une personne.
            'emetteur_user_id' => null,
            'portee' => $portee,
            'region_id' => $region,
            'centre_id' => $centre,
            'incident_id' => $incident->id,
            'publiee_le' => now(),
        ]);
    }

    /**
     * Le SMS ne part qu'à partir du niveau paramétré — par défaut, majeur.
     * Il dit l'essentiel et le numéro de fiche : il sert à faire ouvrir
     * l'application, pas à remplacer la fiche.
     *
     * @param  Collection<int, User>  $destinataires
     */
    private function envoyerLesSms(Incident $incident, Collection $destinataires): int
    {
        $seuil = Parametre::entier('incidents.gravite_minimale_sms', 3);

        if ($incident->gravite->value < $seuil) {
            return 0;
        }

        $texte = sprintf(
            'PNVB %s — %s. %s. Ouvrez l\'application pour la fiche complète.',
            $incident->numero,
            $incident->gravite->libelle(),
            Str::limit(strip_tags($incident->recit), 90)
        );

        $envoyes = 0;

        foreach ($destinataires as $destinataire) {
            if (blank($destinataire->telephone)) {
                continue;
            }

            if ($this->sms->envoyer($destinataire->telephone, $texte)) {
                $envoyes++;
            }
        }

        return $envoyes;
    }

    /** @param  Collection<int, User>  $destinataires */
    private function tracer(
        Incident $incident,
        string $motif,
        Collection $destinataires,
        int $sms,
        ?string $codeAlerte
    ): void {
        IncidentAction::query()->create([
            'incident_id' => $incident->id,
            // Nul : c'est le SYSTÈME qui notifie, pas un utilisateur.
            'user_id' => null,
            'type_action' => $motif === 'escalade' ? 'escalade' : 'notification',
            'commentaire' => $destinataires->isEmpty()
                ? 'Aucun destinataire trouvé pour ce niveau sur ce territoire.'
                : "Notification de {$destinataires->count()} responsables"
                    .($sms > 0 ? ", dont {$sms} par SMS." : '.'),
            'destinataires' => [
                'motif' => $motif,
                'niveau_escalade' => $incident->niveau_escalade,
                'alerte' => $codeAlerte,
                'sms_envoyes' => $sms,
                // Nominatif : qui savait quoi, et à quelle heure.
                'personnes' => $destinataires->map(fn (User $u) => [
                    'user_id' => $u->id,
                    'nom' => $u->nomComplet(),
                    'roles' => $u->getRoleNames()->all(),
                ])->all(),
            ],
            'effectue_le' => now(),
        ]);
    }

    private function titre(Incident $incident, string $motif): string
    {
        $prefixe = $motif === 'escalade'
            ? "Incident non pris en charge — {$incident->numero}"
            : "Incident {$incident->gravite->libelle()} — {$incident->numero}";

        return Str::limit($prefixe, 155);
    }

    private function message(Incident $incident, string $motif): string
    {
        $lieu = $incident->site?->nom
            ?? $incident->centre?->nom
            ?? $incident->region?->nom
            ?? 'lieu non précisé';

        if ($motif === 'escalade') {
            return "Déclaré le {$incident->declare_le->format('d/m/Y à H:i')} à {$lieu}, "
                ."cet incident n'est toujours pas pris en charge. "
                .'Il est remonté au niveau supérieur. '
                .Str::limit(strip_tags($incident->recit), 300);
        }

        return "Déclaré le {$incident->declare_le->format('d/m/Y à H:i')} à {$lieu} "
            ."par {$incident->declarant?->nomComplet()}. "
            .($incident->danger_immediat ? 'DANGER IMMÉDIAT SIGNALÉ. ' : '')
            .Str::limit(strip_tags($incident->recit), 300);
    }

    private function niveauAlerte(GraviteIncident $gravite): string
    {
        return match ($gravite) {
            GraviteIncident::Mineur => 'info',
            GraviteIncident::Modere => 'important',
            GraviteIncident::Majeur, GraviteIncident::Critique => 'critique',
        };
    }
}
