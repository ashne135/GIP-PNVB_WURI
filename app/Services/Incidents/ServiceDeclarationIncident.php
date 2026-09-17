<?php

namespace App\Services\Incidents;

use App\Enums\GraviteIncident;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Déclaration d'un incident — sections A à I du canevas client.
 *
 * TROIS PRINCIPES portés ici :
 *
 *  1. L'AGENT NE SAISIT QUE CE QU'IL A VU. Le canevas marque « automatique »
 *     le numéro, le téléphone du déclarant, l'horodatage, la chaîne
 *     territoriale et les coordonnées du site : tout cela est déduit, jamais
 *     demandé. Sur un terrain difficile, chaque champ en moins est une
 *     déclaration de plus qui arrive.
 *
 *  2. L'ÉCHÉANCE D'ESCALADE EST POSÉE À LA DÉCLARATION, à partir du délai
 *     paramétré pour le niveau de gravité. Elle ne dépend pas d'un moteur qui
 *     se souviendrait de la règle : si le planificateur prend du retard,
 *     l'échéance reste juste.
 *
 *  3. LE TRAITEMENT N'EST PAS LA DÉCLARATION. La section J — prise en charge,
 *     mesures correctives, clôture — est « réservée aux responsables
 *     habilités » : ce service ne l'écrit jamais, même si le client l'envoyait.
 */
class ServiceDeclarationIncident
{
    public function __construct(
        private readonly NumeroteurIncident $numeroteur,
        private readonly ServiceNotificationIncident $notification,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function declarer(User $declarant, array $donnees): Incident
    {
        // Idempotence hors ligne : un uuid déjà reçu rend la fiche existante,
        // sans la modifier ni renotifier qui que ce soit.
        $existant = Incident::query()->where('uuid_client', $donnees['uuid_client'])->first();

        if ($existant) {
            return $existant;
        }

        $gravite = GraviteIncident::from((int) $donnees['gravite']);
        $site = ($donnees['site_id'] ?? null) ? Site::query()->with('localite')->find($donnees['site_id']) : null;

        $incident = DB::transaction(function () use ($declarant, $donnees, $gravite, $site) {
            $declareLe = now();

            $incident = Incident::query()->create([
                'uuid_client' => $donnees['uuid_client'],
                'numero' => $this->numeroteur->suivant(),
                'declarant_user_id' => $declarant->id,
                // Repris du compte : le déclarant ne retape pas son numéro.
                'declarant_telephone' => $declarant->telephone,
                'declare_le' => $declareLe,
                'canal' => $donnees['canal'] ?? 'mobile',
                ...$this->chaineTerritoriale($site, $donnees),

                'deja_signale' => $donnees['deja_signale'] ?? false,
                'type_lieu' => $donnees['type_lieu'] ?? 'site',
                'lieu_precision' => $donnees['lieu_precision'] ?? null,
                'encore_sur_les_lieux' => $donnees['encore_sur_les_lieux'] ?? null,

                'survenu_le' => $donnees['survenu_le'] ?? null,
                'recit' => $donnees['recit'],
                'personnes_concernees' => $donnees['personnes_concernees'] ?? null,
                'toujours_en_cours' => $donnees['toujours_en_cours'] ?? 'inconnu',
                'danger_immediat' => $donnees['danger_immediat'] ?? false,
                'nb_personnes_affectees' => $donnees['nb_personnes_affectees'] ?? null,

                'gravite' => $gravite->value,
                'preuves' => $donnees['preuves'] ?? null,
                'mesures_precisions' => $donnees['mesures_precisions'] ?? null,

                'latitude_declarant' => $donnees['latitude_declarant'] ?? null,
                'longitude_declarant' => $donnees['longitude_declarant'] ?? null,
                'horodatage_telephone' => $donnees['horodatage_telephone'] ?? null,

                // L'échéance est posée ici, à partir du paramètre du niveau.
                'echeance_escalade' => $declareLe->copy()->addMinutes($this->delaiEscalade($gravite)),
                // Ecrit explicitement : le defaut est en base, mais le modele
                // tout juste cree ne le connait pas et vaudrait null en memoire.
                'niveau_escalade' => 0,
            ]);

            $this->attacherNomenclatures($incident, $donnees);

            IncidentAction::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $declarant->id,
                'type_action' => 'creation',
                'nouveau_statut' => 'nouveau',
                'commentaire' => "Incident déclaré depuis {$incident->canal}.",
                'effectue_le' => $declareLe,
            ]);

            return $incident;
        });

        // La notification part APRÈS la transaction : elle écrit des alertes et
        // peut appeler une passerelle SMS. Un incident enregistré ne doit pas
        // être annulé parce qu'un SMS n'est pas parti.
        $this->notification->notifier($incident, 'declaration');

        return $incident->fresh(['natures', 'impacts', 'mesures', 'personnesInformees', 'actions']);
    }

    /**
     * La chaîne territoriale complète, déduite du site quand il est connu.
     * Le canevas la marque « automatique » : l'agent ne la saisit pas.
     */
    private function chaineTerritoriale(?Site $site, array $donnees): array
    {
        if (! $site) {
            // Hors site — un incident de trajet, par exemple. Le déclarant peut
            // néanmoins situer la commune, et le reste s'en déduit.
            return [
                'centre_id' => $donnees['centre_id'] ?? null,
                'commune_id' => $donnees['commune_id'] ?? null,
                'region_id' => $donnees['region_id'] ?? null,
            ];
        }

        $localite = $site->localite;

        return [
            'site_id' => $site->id,
            'centre_id' => $site->centre_id,
            'localite_id' => $site->localite_id,
            'commune_id' => $localite?->commune_id,
            'province_id' => $localite?->commune?->province_id,
            'region_id' => $site->region_id,
            'latitude_site' => $site->latitude,
            'longitude_site' => $site->longitude,
        ];
    }

    /** Les quatre listes à cases multiples du canevas (C, E, H, I). */
    private function attacherNomenclatures(Incident $incident, array $donnees): void
    {
        foreach ([
            'natures' => 'natures',
            'impacts' => 'impacts',
            'mesures' => 'mesures',
            'personnes_informees' => 'personnesInformees',
        ] as $cle => $relation) {
            if (! empty($donnees[$cle])) {
                $incident->{$relation}()->sync($donnees[$cle]);
            }
        }
    }

    /**
     * Le délai n'est JAMAIS écrit en dur : l'enum ne fait que nommer le
     * paramètre, et le paramètre porte la valeur.
     */
    public function delaiEscalade(GraviteIncident $gravite): int
    {
        return Parametre::entier($gravite->cleDelaiEscalade(), match ($gravite) {
            GraviteIncident::Mineur => 480,
            GraviteIncident::Modere => 240,
            GraviteIncident::Majeur => 120,
            GraviteIncident::Critique => 30,
        });
    }
}
