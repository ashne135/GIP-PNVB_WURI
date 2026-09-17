<?php

namespace App\Services\Demonstration;

use App\Services\Agregats\CalculateurAgregats;
use App\Services\Agregats\CalculateurCouverture;
use Illuminate\Support\Facades\DB;

/**
 * RETIRE L'ACTIVITÉ FICTIVE — rapports, feuilles de présence, incidents — et
 * remet les agrégats d'accord avec ce qui reste.
 *
 * POURQUOI ELLE EST INDISPENSABLE : un rapport tient son auteur, une feuille
 * son superviseur, un incident son déclarant, tous par des clés RESTRICTIVES.
 * Tant que cette activité existe, la purge des volontaires fictifs échouerait
 * sur une contrainte de clé étrangère. Elle part donc avant eux.
 *
 * Les enfants suivent en cascade : production, visas et suivi des agents avec
 * le rapport ; lignes et écarts avec la feuille ; actions et natures avec
 * l'incident. Seules les alertes, rattachées à l'incident sans clé étrangère,
 * sont supprimées explicitement.
 */
class PurgeurActiviteFictive
{
    public function __construct(
        private readonly CalculateurAgregats $agregats = new CalculateurAgregats,
        private readonly CalculateurCouverture $couverture = new CalculateurCouverture,
    ) {}

    /**
     * @param  int|null  $idVague  Limite la purge à une vague ; nul : toute l'activité fictive.
     * @return array{rapports: int, feuilles: int, incidents: int, alertes: int, journees_recalculees: int}
     */
    public function purger(?int $idVague = null): array
    {
        $rapports = fn () => DB::table('rapports_journaliers')
            ->where('est_fictif', true)
            ->when($idVague, fn ($q) => $q->where('vague_id', $idVague));

        $feuilles = fn () => DB::table('feuilles_presence')
            ->where('est_fictif', true)
            ->when($idVague, fn ($q) => $q->where('vague_id', $idVague));

        // Un incident ne porte pas sa vague : on le rattache par les centres ouverts pour elle.
        $idsIncidents = DB::table('incidents')
            ->where('est_fictif', true)
            ->when($idVague, fn ($q) => $q->whereIn(
                'centre_id',
                DB::table('vague_centres')->where('vague_id', $idVague)->select('centre_id')
            ))
            ->pluck('id');

        // Les journées touchées, relevées AVANT suppression : ce sont elles dont
        // les agrégats deviennent faux.
        $dates = $rapports()->distinct()->pluck('date_rapport')
            ->merge($feuilles()->distinct()->pluck('date_presence'))
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->unique()
            ->values();

        $compte = DB::transaction(fn () => [
            'alertes' => DB::table('alertes')->whereIn('incident_id', $idsIncidents)->delete(),
            'incidents' => DB::table('incidents')->whereIn('id', $idsIncidents)->delete(),
            'rapports' => $rapports()->delete(),
            'feuilles' => $feuilles()->delete(),
        ]);

        // Les agrégats d'une journée touchée sont effacés puis recalculés sur ce
        // qui reste : une ligne dont toute l'activité était fictive ne doit pas
        // survivre. Le recalcul est rejouable (pnvb:recalculer-agregats).
        foreach ($dates as $date) {
            DB::table('agregats_jour_site')->whereDate('date_jour', $date)->delete();
            DB::table('agregats_jour_centre')->whereDate('date_jour', $date)->delete();
            DB::table('agregats_jour_region')->whereDate('date_jour', $date)->delete();

            $this->agregats->recalculerJournee($date);
        }

        if ($dates->isNotEmpty()) {
            // Cumuls et mois recalculés de bout en bout, sans les lignes devenues sans objet.
            DB::table('agregats_couverture_localite')->delete();
            DB::table('agregats_mois_region')->delete();
            $this->couverture->recalculer();
        }

        return [...$compte, 'journees_recalculees' => $dates->count()];
    }
}
