<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Services\Agregats\ServiceTableauBord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le tableau de bord (cadrage, section 15).
 *
 *   GET /tableau-bord              synthèse du jour, cadrée sur mon périmètre
 *   GET /tableau-bord/evolution    la courbe, jour par jour
 *   GET /tableau-bord/couverture   taux par région, pour le fond de carte
 *   GET /tableau-bord/retards      les localités où il faut retourner
 *   GET /tableau-bord/centres      classement des centres du jour
 *
 * Toutes ces routes lisent les AGRÉGATS, jamais les tables de détail : c'est
 * ce qui permet au tableau de bord de rester utilisable après des mois de
 * collecte sur 12 294 sites.
 */
class TableauBordController extends Controller
{
    public function __construct(private readonly ServiceTableauBord $service) {}

    public function synthese(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $date = $requete->string('date')->toString() ?: now()->subDay()->toDateString();

        return ReponseApi::succes(
            "Synthèse du {$date}.",
            $this->service->synthese($requete->user(), $date)
        );
    }

    /**
     * CE QUI APPELLE UNE ACTION : les files d'attente, lues en direct.
     *
     * Les autres appels du tableau de bord lisent les agrégats de la nuit ;
     * celui-ci compte l'existant, parce qu'une file affichée avec un jour de
     * retard fait agir trop tard.
     */
    public function pilotage(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        return ReponseApi::succes(
            'Pilotage récupéré.',
            $this->service->pilotage($requete->user())
        );
    }

    public function evolution(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $valide = $requete->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date', 'after_or_equal:du'],
        ], ['au.after_or_equal' => 'La date de fin doit suivre la date de début.']);

        $au = $valide['au'] ?? now()->toDateString();
        $du = $valide['du'] ?? now()->parse($au)->subDays(29)->toDateString();

        return ReponseApi::succes(
            'Évolution récupérée.',
            $this->service->evolution($requete->user(), $du, $au)
        );
    }

    public function couverture(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $regions = $this->service->couvertureParRegion($requete->user());

        $sansContour = collect($regions)->whereNull('contour_geojson')->count();

        return ReponseApi::succes(
            $sansContour > 0
                ? "Couverture récupérée. {$sansContour} régions n'ont pas encore de contour "
                    .'cartographique : la carte s\'affichera sans aplats.'
                : 'Couverture récupérée.',
            $regions
        );
    }

    /** Les localités les moins couvertes : celles où il faut retourner. */
    public function retards(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        return ReponseApi::succes(
            'Localités les moins couvertes.',
            $this->service->localitesEnRetard(
                $requete->user(),
                min($requete->integer('limite', 50), 500)
            )
        );
    }

    public function centres(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $date = $requete->string('date')->toString() ?: now()->subDay()->toDateString();

        return ReponseApi::succes(
            "Centres du {$date}.",
            $this->service->centresDuJour($requete->user(), $date)
        );
    }

    /** Les sites localisés, pour les marqueurs de la carte. */
    public function sitesCarte(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $donnees = $this->service->sitesCarte($requete->user());

        return ReponseApi::succes(
            $donnees['localises'] === 0
                ? "Aucun site n'a encore de coordonnées : la carte ne peut pas les placer."
                : "{$donnees['localises']} sites localisés sur {$donnees['total_sites']}.",
            $donnees
        );
    }

    private function autoriser(Request $requete): void
    {
        abort_unless($requete->user()->can('tableau_bord.consulter'), 403);
    }
}
