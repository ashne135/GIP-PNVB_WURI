<?php

namespace App\Services\Import;

use App\Models\User;
use App\Models\Volontaire;
use App\Services\Demonstration\PurgeurActiviteFictive;
use Illuminate\Support\Facades\DB;

/**
 * Purge du jeu de volontaires FICTIFS, pour le mode « remplacer » de l'import.
 *
 * Point de vigilance : purger le fictif SANS CASSER LES CLÉS ÉTRANGÈRES.
 * Les volontaires fictifs sont référencés par les affectations, les unités de
 * supervision, les kits qu'ils détiennent, les feuilles de présence qu'ils ont
 * validées, les rapports qu'ils ont rédigés. L'ordre de suppression compte, et
 * certaines dépendances doivent interdire la purge plutôt que d'être forcées.
 *
 * RÈGLE : si des données RÉELLES dépendent des volontaires fictifs, la purge est
 * REFUSÉE avec un motif explicite. On ne détruit jamais en silence une feuille
 * de présence ou un rapport qui fait foi.
 */
class PurgeurVolontairesFictifs
{
    /**
     * Vérifie qu'aucune donnée réelle ne dépend du jeu fictif.
     *
     * @return string[] Les obstacles trouvés, vide si la purge est possible.
     */
    public function obstacles(): array
    {
        $idsFictifs = Volontaire::query()->fictifs()->pluck('id');

        if ($idsFictifs->isEmpty()) {
            return [];
        }

        $obstacles = [];

        $verifications = [
            'feuilles_presence' => ['colonne' => 'superviseur_id', 'libelle' => 'feuilles de présence'],
            // Depuis la refonte v2, les rapports vivent dans rapports_journaliers :
            // rapports_site et rapports_centre n'existent plus, et les interroger
            // faisait échouer toute importation en mode « remplacer ».
            'rapports_journaliers' => ['colonne' => 'auteur_volontaire_id', 'libelle' => 'rapports journaliers'],
        ];

        foreach ($verifications as $table => $definition) {
            $nombre = DB::table($table)
                ->whereIn($definition['colonne'], $idsFictifs)
                ->where('est_fictif', false)
                ->count();

            if ($nombre > 0) {
                $obstacles[] = "{$nombre} {$definition['libelle']} réels sont rattachés à des volontaires "
                    .'de démonstration. Traitez-les avant de remplacer le jeu fictif.';
            }
        }

        return $obstacles;
    }

    /**
     * Supprime le jeu fictif dans l'ordre imposé par les clés étrangères.
     *
     * @return array<string, int> Nombre de lignes supprimées par table.
     */
    public function purger(): array
    {
        $idsFictifs = Volontaire::query()->fictifs()->pluck('id');

        if ($idsFictifs->isEmpty()) {
            return [];
        }

        $idsUsers = Volontaire::query()->fictifs()->pluck('user_id');
        $compte = [];

        DB::transaction(function () use ($idsFictifs, $idsUsers, &$compte) {
            // 0. L'activité de démonstration — rapports, feuilles, incidents — tient
            //    ses auteurs par des clés restrictives : elle part avant eux, sans
            //    quoi la suppression des volontaires échouerait.
            foreach ((new PurgeurActiviteFictive)->purger() as $cle => $nombre) {
                $compte["activite_{$cle}"] = $nombre;
            }

            // 1. Les kits sont rattachés à un agent : on les détache, on ne les
            //    supprime pas — le parc peut être réel même si l'agent est fictif.
            $compte['kits_detaches'] = DB::table('kits')
                ->whereIn('volontaire_detenteur_id', $idsFictifs)
                ->update(['volontaire_detenteur_id' => null, 'centre_courant_id' => null, 'site_courant_id' => null]);

            // 2. Les mouvements de kit référencent source et destination.
            $compte['kit_mouvements'] = DB::table('kit_mouvements')
                ->where(fn ($q) => $q->whereIn('volontaire_source_id', $idsFictifs)
                    ->orWhereIn('volontaire_destination_id', $idsFictifs))
                ->delete();

            // 3. Les tournées pointent vers l'affectation de l'opérateur.
            $compte['tournees'] = DB::table('tournees_site')
                ->whereIn('affectation_operateur_id', function ($q) use ($idsFictifs) {
                    $q->select('id')->from('affectations')->whereIn('volontaire_id', $idsFictifs);
                })
                ->delete();

            // 4. Remplacements, puis affectations, puis unités de supervision.
            $compte['remplacements'] = DB::table('remplacements')
                ->where(fn ($q) => $q->whereIn('volontaire_sortant_id', $idsFictifs)
                    ->orWhereIn('volontaire_entrant_id', $idsFictifs))
                ->delete();

            $compte['affectations'] = DB::table('affectations')
                ->whereIn('volontaire_id', $idsFictifs)
                ->delete();

            $compte['unites_supervision'] = DB::table('unites_supervision')
                ->whereIn('volontaire_superviseur_id', $idsFictifs)
                ->delete();

            // 5. Signaux, relevés et lignes de présence de ces agents.
            $compte['signaux_arrivee'] = DB::table('signaux_arrivee')
                ->whereIn('volontaire_id', $idsFictifs)->delete();
            $compte['releves_position'] = DB::table('releves_position')
                ->whereIn('volontaire_id', $idsFictifs)->delete();
            $compte['lignes_presence'] = DB::table('lignes_presence')
                ->whereIn('volontaire_id', $idsFictifs)->delete();

            // 6. Les fiches elles-mêmes, puis les comptes.
            $compte['volontaires'] = DB::table('volontaires')->whereIn('id', $idsFictifs)->delete();

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $idsUsers)
                ->delete();

            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $idsUsers)
                ->delete();

            $compte['remises_identifiants'] = DB::table('remises_identifiants')
                ->whereIn('user_id', $idsUsers)->delete();
            DB::table('consentements')->whereIn('user_id', $idsUsers)->delete();
            DB::table('alerte_lectures')->whereIn('user_id', $idsUsers)->delete();

            $compte['users'] = DB::table('users')->whereIn('id', $idsUsers)->delete();
        });

        activity('import')
            ->withProperties($compte)
            ->log('Purge du jeu de volontaires de démonstration');

        return $compte;
    }
}
