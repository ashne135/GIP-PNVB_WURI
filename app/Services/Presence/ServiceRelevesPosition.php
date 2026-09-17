<?php

namespace App\Services\Presence;

use App\Models\Affectation;
use App\Models\Parametre;
use App\Models\RelevePosition;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\Volontaire;

/**
 * AUTO-POINTAGE DE RAPPROCHEMENT (cadrage, section 8.4).
 *
 * Le téléphone relève sa position à intervalles réguliers, UNIQUEMENT pour
 * contrôler la cohérence avec la feuille de présence. Ces relevés ne sont
 * JAMAIS présentés comme un pointage à l'agent, et aucune interface n'affiche
 * la trace de ses déplacements.
 *
 * L'ENCADREMENT EST NON NÉGOCIABLE — sans lui, le mécanisme devient de la
 * surveillance de travailleurs. Ce service REFUSE le relevé dans chacun de ces
 * cas, plutôt que de l'enregistrer et de filtrer plus tard :
 *
 *   1. CONSENTEMENT : sans charte acceptée pour la version courante, rien n'est
 *      collecté. « Discret ne veut pas dire caché. »
 *   2. JOURS D'AFFECTATION ACTIVE seulement — rien hors mission.
 *   3. HEURES DE SERVICE seulement — rien le soir, rien le week-end.
 *   4. FRÉQUENCE BASSE et paramétrable — un relevé toutes les 30 minutes par
 *      défaut ; les envois plus rapprochés sont ignorés.
 *   5. RÉTENTION LIMITÉE — la date de purge est calculée à l'écriture, et le
 *      job quotidien s'en sert.
 */
class ServiceRelevesPosition
{
    /**
     * @return array{enregistre: bool, motif: string|null}
     */
    public function enregistrer(Volontaire $volontaire, array $donnees): array
    {
        $horodatage = \Illuminate\Support\Carbon::parse($donnees['horodatage']);

        // 1. Consentement à la charte, pour la version courante.
        if (! $volontaire->user->aAccepteLaCharte()) {
            return ['enregistre' => false, 'motif' => 'charte_non_acceptee'];
        }

        // 2. Jour d'affectation : celle qui couvrait le jour du relevé, même
        //    terminée depuis, pendant le délai de rattrapage. Un relevé fait en
        //    mission et remonté après la clôture n'est pas « hors mission » ;
        //    un relevé d'un jour sans mission l'est, même si une vague est en cours.
        $affectation = Affectation::query()
            ->where('volontaire_id', $volontaire->id)
            ->couvrant($horodatage->toDateString())
            ->activeDabord()
            ->first();

        if (! $affectation) {
            return ['enregistre' => false, 'motif' => 'hors_mission'];
        }

        // 3. Heures de service, et jours ouvrés seulement.
        if (! $this->dansLesHeuresDeService($horodatage)) {
            return ['enregistre' => false, 'motif' => 'hors_heures_de_service'];
        }

        // 4. Fréquence : un relevé trop rapproché du précédent est ignoré.
        $frequence = Parametre::entier('presence.frequence_releve_minutes', 30);

        $dernier = RelevePosition::query()
            ->where('volontaire_id', $volontaire->id)
            ->orderByDesc('horodatage')
            ->value('horodatage');

        if ($dernier && $horodatage->diffInMinutes($dernier) < $frequence) {
            return ['enregistre' => false, 'motif' => 'frequence_trop_elevee'];
        }

        $site = $this->siteAttendu($volontaire, $affectation, $horodatage->toDateString());
        $distance = $site?->distanceMetresDepuis($donnees['latitude'], $donnees['longitude']);

        RelevePosition::query()->create([
            'volontaire_id' => $volontaire->id,
            'affectation_id' => $affectation->id,
            'site_id_attendu' => $site?->id,
            'horodatage' => $horodatage,
            'latitude' => $donnees['latitude'],
            'longitude' => $donnees['longitude'],
            'distance_metres' => $distance,
            'dans_zone' => $distance !== null && $distance <= (int) ($site?->rayon_zone_metres ?? 0),
            // 5. La date de purge est posée à l'écriture : la rétention ne
            //    dépend pas d'un job qui se souviendrait de la règle.
            'purge_prevue_le' => $horodatage->copy()
                ->addDays(Parametre::entier('retention.releves_jours', 90))
                ->toDateString(),
        ]);

        return ['enregistre' => true, 'motif' => null];
    }

    /**
     * Heures de service et jours ouvrés.
     * « Aucun relevé le soir, le week-end, ou hors mission. »
     */
    public function dansLesHeuresDeService(\Illuminate\Support\Carbon $moment): bool
    {
        if ($moment->isWeekend()) {
            return false;
        }

        $debut = (string) Parametre::valeur('presence.heure_debut_service', '07:00');
        $fin = (string) Parametre::valeur('presence.heure_fin_service', '17:30');

        $heure = $moment->format('H:i');

        return $heure >= $debut && $heure <= $fin;
    }

    private function siteAttendu(Volontaire $volontaire, Affectation $affectation, string $date): ?Site
    {
        if ($volontaire->categorie === \App\Enums\CategorieVolontaire::Assistant) {
            return Site::query()
                ->where('localite_id', $volontaire->localite_id)
                ->when($affectation->centre_id, fn ($q) => $q->where('centre_id', $affectation->centre_id))
                ->first();
        }

        return TourneeSite::query()
            ->where('affectation_operateur_id', $affectation->id)
            ->couvrant($date)
            ->with('site')
            ->first()?->site;
    }
}
