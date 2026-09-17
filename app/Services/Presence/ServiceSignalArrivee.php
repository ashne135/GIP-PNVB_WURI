<?php

namespace App\Services\Presence;

use App\Models\Affectation;
use App\Models\SignalArrivee;
use App\Models\Site;
use App\Models\Volontaire;
use Illuminate\Support\Facades\DB;

/**
 * LE SIGNAL D'ARRIVÉE (cadrage, section 8.1).
 *
 * « L'OPK et l'assistant disposent sur leur téléphone d'un seul gros bouton
 * JE SUIS ARRIVÉ. Ils ne pointent pas : ils SIGNALENT leur présence. »
 *
 * CE SIGNAL N'A AUCUNE VALEUR ADMINISTRATIVE. Il alimente la carte du
 * superviseur, rien d'autre. Seule la feuille de présence validée fait foi, et
 * c'est le superviseur qui la valide — jamais l'agent lui-même.
 *
 * L'idempotence est portée par uuid_client : un téléphone hors ligne peut
 * renvoyer le même signal plusieurs fois sans jamais créer de doublon.
 */
class ServiceSignalArrivee
{
    /**
     * @param  array{
     *     uuid_client: string, type: string, latitude: float, longitude: float,
     *     horodatage_telephone: string, precision_gps?: int|null, site_id?: int|null
     * }  $donnees
     */
    public function enregistrer(Volontaire $volontaire, array $donnees): SignalArrivee
    {
        // Un uuid déjà reçu ne crée jamais de doublon : on rend le signal
        // existant, sans le modifier (cadrage, section 11.5).
        $existant = SignalArrivee::query()->where('uuid_client', $donnees['uuid_client'])->first();

        if ($existant) {
            return $existant;
        }

        // L'affectation qui couvrait le JOUR DU GESTE, pas celle du jour où le
        // signal arrive : remonté après la clôture de la vague, il reste
        // recevable pendant le délai de rattrapage.
        $affectation = Affectation::query()
            ->where('volontaire_id', $volontaire->id)
            ->couvrant(\Illuminate\Support\Carbon::parse($donnees['horodatage_telephone'])->toDateString())
            ->activeDabord()
            ->first();

        if (! $affectation) {
            throw new \DomainException(
                "Vous n'avez pas de mission en cours : votre arrivée ne peut pas être signalée."
            );
        }

        // Le mobile n'envoie pas toujours le site : hors ligne, il ne sait pas
        // forcément où la tournée l'a placé. Le serveur le déduit alors.
        $site = ($donnees['site_id'] ?? null)
            ? Site::query()->find($donnees['site_id'])
            : $this->siteDuJour($volontaire, $affectation, $donnees['horodatage_telephone']);

        if (! $site) {
            throw new \DomainException(
                "Aucun site ne vous est assigné aujourd'hui. Prévenez votre superviseur."
            );
        }

        // LA DISTANCE EST CALCULÉE CÔTÉ SERVEUR, jamais reçue du téléphone :
        // c'est elle qui colore la carte du superviseur.
        $distance = $site->distanceMetresDepuis($donnees['latitude'], $donnees['longitude']);
        $dansZone = $distance !== null && $distance <= (int) $site->rayon_zone_metres;

        $this->refuserSiHorsZone($site, $distance, $dansZone);

        return DB::transaction(fn () => SignalArrivee::query()->create([
            'uuid_client' => $donnees['uuid_client'],
            'volontaire_id' => $volontaire->id,
            'affectation_id' => $affectation->id,
            'site_id' => $site->id,
            'vague_id' => $affectation->vague_id,
            'type' => $donnees['type'],
            // L'horodatage retenu est celui du TÉLÉPHONE au moment de l'action
            // (cadrage, section 11.7) ; recu_le garde la date d'arrivée serveur
            // pour le diagnostic.
            'horodatage_telephone' => $donnees['horodatage_telephone'],
            'recu_le' => now(),
            'latitude' => $donnees['latitude'],
            'longitude' => $donnees['longitude'],
            'precision_gps' => $donnees['precision_gps'] ?? null,
            'distance_site_metres' => $distance,
            'dans_zone' => $dansZone,
        ]));
    }

    /**
     * LE PÉRIMÈTRE DU SITE, quand le dispositif est réglé pour l'imposer.
     *
     * Par défaut le paramètre est à FAUX, et c'est le comportement du cadrage :
     * l'agent SIGNALE, le serveur constate l'écart, et seul le superviseur
     * valide. Activé, le serveur refuse le signal au-delà du rayon du site.
     *
     * LE CONTRÔLE EST ICI, PAS SUR LE TÉLÉPHONE : un bouton grisé se contourne,
     * et la synchronisation hors ligne emprunte le même chemin que la requête
     * en ligne — les deux passent par ce service, donc par ce refus.
     *
     * UN SITE SANS COORDONNÉES NE PERMET AUCUNE MESURE : la distance est alors
     * nulle, et le signal reste accepté. Refuser sur une mesure inexistante
     * mettrait le module de présence hors service partout où les positions ne
     * sont pas encore chargées.
     */
    private function refuserSiHorsZone(Site $site, ?int $distance, bool $dansZone): void
    {
        if ($distance === null || $dansZone) {
            return;
        }

        if (! \App\Models\Parametre::booleen('presence.bloquer_signal_hors_zone', false)) {
            return;
        }

        throw new \DomainException(
            "Vous êtes à {$distance} m du site {$site->code}, au-delà des "
            ."{$site->rayon_zone_metres} m de sa zone. Rapprochez-vous du site, puis réessayez. "
            .'Si vous êtes bien sur place, prévenez votre superviseur : il peut vous marquer présent '
            .'sur la feuille de présence.'
        );
    }

    /**
     * LA CARTE TEMPS RÉEL du superviseur (cadrage, section 8.2).
     *
     *   VERT   = arrivée signalée dans la zone du site
     *   ORANGE = arrivée signalée hors zone, avec la distance
     *   GRIS   = aucune arrivée signalée
     *
     * C'est cette carte qui rend le pointage collectif à distance fiable : le
     * superviseur sait qui est en place sans se déplacer sur chaque site.
     */
    public function carteDuJour(array $idsCentres, string $date): array
    {
        $sites = Site::query()
            ->whereIn('centre_id', $idsCentres)
            ->with('centre:id,code,nom')
            ->get();

        $signaux = SignalArrivee::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->whereDate('horodatage_telephone', $date)
            ->where('type', 'arrivee')
            ->orderByDesc('horodatage_telephone')
            ->with(['volontaire:id,user_id,matricule,categorie', 'volontaire.user:id,nom,prenoms'])
            ->get()
            // Un agent peut appuyer deux fois : on garde le dernier signal.
            ->unique('volontaire_id');

        $attendus = (new ServiceFeuillePresence)->agentsAttendus($sites, $date);
        $signauxParAgent = $signaux->keyBy('volontaire_id');

        $marqueurs = [];

        foreach ($attendus as $agent) {
            $signal = $signauxParAgent->get($agent['volontaire_id']);

            $marqueurs[] = [
                'volontaire_id' => $agent['volontaire_id'],
                'matricule' => $agent['matricule'],
                'nom_complet' => $agent['nom_complet'],
                'categorie' => $agent['categorie'],
                'site_id' => $agent['site_id'],
                'site_code' => $agent['site_code'],
                // GRIS tant qu'aucune arrivée n'est signalée : l'absence de
                // signal n'est pas une absence, c'est une absence d'information.
                'couleur' => $signal === null ? 'gris' : ($signal->dans_zone ? 'vert' : 'orange'),
                'heure_arrivee' => $signal?->horodatage_telephone?->format('H:i'),
                'distance_metres' => $signal?->distance_site_metres,
                'latitude' => $signal?->latitude,
                'longitude' => $signal?->longitude,
            ];
        }

        return [
            'date' => $date,
            'sites' => $sites->map(fn (Site $s) => [
                'id' => $s->id, 'code' => $s->code, 'nom' => $s->nom,
                'centre' => $s->centre?->code,
                'latitude' => $s->latitude, 'longitude' => $s->longitude,
                'rayon_zone_metres' => $s->rayon_zone_metres,
            ]),
            'agents' => $marqueurs,
            'resume' => [
                'vert' => collect($marqueurs)->where('couleur', 'vert')->count(),
                'orange' => collect($marqueurs)->where('couleur', 'orange')->count(),
                'gris' => collect($marqueurs)->where('couleur', 'gris')->count(),
            ],
        ];
    }

    /** Le site où l'agent travaille ce jour-là. */
    private function siteDuJour(Volontaire $volontaire, Affectation $affectation, string $horodatage): ?Site
    {
        $date = substr($horodatage, 0, 10);

        if ($volontaire->categorie === \App\Enums\CategorieVolontaire::Assistant) {
            return Site::query()
                ->where('localite_id', $volontaire->localite_id)
                ->when($affectation->centre_id, fn ($q) => $q->where('centre_id', $affectation->centre_id))
                ->first();
        }

        return \App\Models\TourneeSite::query()
            ->where('affectation_operateur_id', $affectation->id)
            ->couvrant($date)
            ->with('site')
            ->first()?->site;
    }
}
