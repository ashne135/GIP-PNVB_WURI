<?php

namespace App\Services\Referentiel;

use App\Models\Centre;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Purge du jeu de CENTRES et de SITES fictifs, pour le mode « remplacer » de
 * l'import (cadrage, section 2).
 *
 * POINT DE VIGILANCE DE LA TÂCHE : purger le fictif SANS CASSER LES CLÉS
 * ÉTRANGÈRES. Vingt-trois colonnes de la base pointent vers centres ou sites :
 * affectations, tournées, feuilles de présence, rapports, incidents, kits,
 * alertes, agrégats, unités de supervision.
 *
 * DEUX PRINCIPES, et le premier prime :
 *
 *  1. ON REFUSE PLUTÔT QUE DE FORCER. Si une donnée RÉELLE dépend d'un centre
 *     ou d'un site fictif — un rapport signé, une feuille de présence validée,
 *     un incident déclaré — la purge est refusée avec un motif explicite. Une
 *     feuille de présence fait foi : elle ne disparaît pas parce qu'on recharge
 *     un référentiel.
 *
 *  2. Ce qui est purgé l'est DANS L'ORDRE des dépendances, et les objets qui
 *     survivent au référentiel — les kits, qui appartiennent à l'agent — sont
 *     DÉTACHÉS, pas supprimés.
 */
class PurgeurCentresEtSites
{
    /**
     * Tables dont une ligne RÉELLE interdit la purge, avec le libellé qui sera
     * montré à l'administrateur.
     */
    private const DEPENDANCES_BLOQUANTES = [
        ['table' => 'feuilles_presence', 'colonne' => 'centre_id', 'libelle' => 'feuilles de présence'],
        ['table' => 'rapports_journaliers', 'colonne' => 'centre_id', 'libelle' => 'rapports journaliers'],
        ['table' => 'incidents', 'colonne' => 'centre_id', 'libelle' => 'incidents'],
        ['table' => 'kit_mouvements', 'colonne' => 'centre_id', 'libelle' => 'mouvements de kit'],
    ];

    /**
     * Ce qui empêche la purge, en clair.
     *
     * @return string[] Vide si la purge est possible.
     */
    public function obstacles(): array
    {
        $idsCentres = Centre::query()->fictifs()->pluck('id');

        if ($idsCentres->isEmpty()) {
            return [];
        }

        $obstacles = [];

        foreach (self::DEPENDANCES_BLOQUANTES as $dependance) {
            $nombre = DB::table($dependance['table'])
                ->whereIn($dependance['colonne'], $idsCentres)
                ->where('est_fictif', false)
                ->count();

            if ($nombre > 0) {
                $obstacles[] = "{$nombre} {$dependance['libelle']} réels sont rattachés à des centres "
                    .'de démonstration. Ces données font foi : traitez-les avant de remplacer le référentiel.';
            }
        }

        // Une vague RÉELLE ouverte sur des centres fictifs : remplacer le
        // référentiel sous ses pieds la rendrait incohérente.
        $vaguesReelles = DB::table('vague_centres')
            ->join('vagues_deploiement', 'vagues_deploiement.id', '=', 'vague_centres.vague_id')
            ->whereIn('vague_centres.centre_id', $idsCentres)
            ->where('vagues_deploiement.est_fictif', false)
            ->count();

        if ($vaguesReelles > 0) {
            $obstacles[] = "{$vaguesReelles} centres de démonstration sont ouverts dans une vague réelle. "
                .'Clôturez-la avant de remplacer le référentiel.';
        }

        return $obstacles;
    }

    /**
     * Ce que la purge va supprimer, AVANT de la lancer : l'administrateur doit
     * voir l'ampleur de ce qu'il déclenche dans l'aperçu.
     *
     * @return array<string, int>
     */
    public function apercu(): array
    {
        $idsCentres = Centre::query()->fictifs()->pluck('id');

        if ($idsCentres->isEmpty()) {
            return [];
        }

        $idsSites = Site::query()->whereIn('centre_id', $idsCentres)->pluck('id');

        return array_filter([
            'centres' => $idsCentres->count(),
            'sites' => $idsSites->count(),
            'tournees' => DB::table('tournees_site')->whereIn('centre_id', $idsCentres)->count(),
            'affectations' => DB::table('affectations')->whereIn('centre_id', $idsCentres)->count(),
            'unites_supervision' => DB::table('unites_supervision')
                ->whereIn('centre_principal_id', $idsCentres)->count(),
            'vague_centres' => DB::table('vague_centres')->whereIn('centre_id', $idsCentres)->count(),
            'signaux_arrivee' => DB::table('signaux_arrivee')->whereIn('site_id', $idsSites)->count(),
            'agregats' => DB::table('agregats_jour_site')->whereIn('site_id', $idsSites)->count()
                + DB::table('agregats_jour_centre')->whereIn('centre_id', $idsCentres)->count(),
            'kits_detaches' => DB::table('kits')->whereIn('centre_courant_id', $idsCentres)->count(),
        ]);
    }

    /**
     * Supprime le jeu fictif dans l'ordre imposé par les clés étrangères.
     *
     * @return array<string, int> Lignes traitées par table.
     */
    public function purger(): array
    {
        if (($obstacles = $this->obstacles()) !== []) {
            throw new \DomainException(implode(' ', $obstacles));
        }

        $idsCentres = Centre::query()->fictifs()->pluck('id');

        if ($idsCentres->isEmpty()) {
            return [];
        }

        $idsSites = Site::query()->whereIn('centre_id', $idsCentres)->pluck('id');
        $compte = [];

        DB::transaction(function () use ($idsCentres, $idsSites, &$compte) {
            // 1. LE KIT APPARTIENT À L'AGENT, pas au site : on le détache de sa
            //    position courante, on ne le supprime jamais avec le référentiel.
            $compte['kits_detaches'] = DB::table('kits')
                ->where(fn ($q) => $q->whereIn('centre_courant_id', $idsCentres)
                    ->orWhereIn('site_courant_id', $idsSites))
                ->update(['centre_courant_id' => null, 'site_courant_id' => null]);

            // 2. Agrégats : recalculables, ils se suppriment sans remords.
            $compte['agregats'] = DB::table('agregats_jour_site')->whereIn('site_id', $idsSites)->delete()
                + DB::table('agregats_jour_centre')->whereIn('centre_id', $idsCentres)->delete();

            DB::table('agregats_couverture_localite')
                ->whereIn('localite_id', DB::table('sites')->whereIn('id', $idsSites)->pluck('localite_id'))
                ->delete();

            // 3. Traces de terrain fictives, du plus dépendant au moins dépendant.
            $compte['releves_position'] = DB::table('releves_position')
                ->whereIn('site_id_attendu', $idsSites)->delete();
            $compte['signaux_arrivee'] = DB::table('signaux_arrivee')
                ->whereIn('site_id', $idsSites)->delete();

            DB::table('lignes_presence')->whereIn(
                'feuille_presence_id',
                DB::table('feuilles_presence')->whereIn('site_id', $idsSites)->pluck('id')
            )->delete();
            $compte['feuilles_presence'] = DB::table('feuilles_presence')
                ->whereIn('site_id', $idsSites)->delete();

            $compte['rapports'] = DB::table('rapports_journaliers')
                ->where(fn ($q) => $q->whereIn('site_id', $idsSites)->orWhereIn('centre_id', $idsCentres))
                ->delete();

            // 4. Les alertes et incidents fictifs perdent leur rattachement
            //    plutôt que d'être supprimés : ils portent un historique.
            DB::table('alertes')->whereIn('centre_id', $idsCentres)->update(['centre_id' => null]);
            DB::table('incidents')->whereIn('site_id', $idsSites)->update(['site_id' => null]);
            DB::table('incidents')->whereIn('centre_id', $idsCentres)->update(['centre_id' => null]);
            DB::table('kit_mouvements')->whereIn('site_id', $idsSites)->update(['site_id' => null]);
            DB::table('kit_mouvements')->whereIn('centre_id', $idsCentres)->update(['centre_id' => null]);

            // 5. Déploiement : tournées, affectations, unités, centres ouverts.
            $compte['tournees'] = DB::table('tournees_site')->whereIn('centre_id', $idsCentres)->delete();
            $compte['affectations'] = DB::table('affectations')->whereIn('centre_id', $idsCentres)->delete();
            $compte['unites_supervision'] = DB::table('unites_supervision')
                ->where(fn ($q) => $q->whereIn('centre_principal_id', $idsCentres)
                    ->orWhereIn('centre_secondaire_id', $idsCentres))
                ->delete();
            $compte['vague_centres'] = DB::table('vague_centres')->whereIn('centre_id', $idsCentres)->delete();

            // 6. Enfin le référentiel lui-même : sites, puis centres.
            $compte['sites'] = DB::table('sites')->whereIn('id', $idsSites)->delete();
            $compte['centres'] = DB::table('centres')->whereIn('id', $idsCentres)->delete();
        });

        activity('import')
            ->withProperties($compte)
            ->log('Purge du jeu de centres et sites de démonstration');

        return $compte;
    }
}
