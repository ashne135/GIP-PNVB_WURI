<?php

namespace App\Services\Exports;

use App\Models\AgregatJourRegion;

/**
 * Les journées agrégées, une ligne par région et par jour.
 *
 * L'EFFECTIF DÉPLOYÉ NE S'ADDITIONNE PAS entre régions : ce fichier rend donc
 * une ligne par région, jamais un total national. Quiconque sommerait la
 * colonne obtiendrait plusieurs milliers d'agents là où ce sont les mêmes
 * équipes qui tournent — d'où l'intitulé de la colonne, qui le dit.
 */
class SourceTableauBord implements SourceExport
{
    public function cle(): string
    {
        return 'tableau_bord';
    }

    public function libelle(): string
    {
        return 'Tableau de bord — journées agrégées';
    }

    public function colonnes(): array
    {
        return [
            'Date', 'Région', 'Enregistrements', 'Rejetés',
            'Centres ouverts', 'Sites couverts',
            'Effectif déployé (région, à ne pas additionner)', 'Taux de présence (%)',
            'Incidents ouverts — mineur', 'Incidents ouverts — modéré',
            'Incidents ouverts — majeur', 'Incidents ouverts — critique',
        ];
    }

    public function lignes(?int $regionId, string $du, string $au): iterable
    {
        return AgregatJourRegion::query()
            ->whereBetween('date_jour', [$du, $au])
            ->when($regionId, fn ($requete) => $requete->where('region_id', $regionId))
            ->with('region:id,code,nom')
            ->orderBy('date_jour')
            ->orderBy('region_id')
            ->get()
            ->map(function (AgregatJourRegion $jour) {
                $incidents = $jour->nb_incidents_ouverts_par_gravite ?? [];

                return [
                    $jour->date_jour?->format('d/m/Y'),
                    $jour->region?->nom,
                    $jour->nb_enregistres,
                    $jour->nb_rejetes,
                    $jour->nb_centres_ouverts,
                    $jour->nb_sites_couverts,
                    $jour->effectif_deploye,
                    $jour->taux_presence,
                    $incidents['niveau_1'] ?? 0,
                    $incidents['niveau_2'] ?? 0,
                    $incidents['niveau_3'] ?? 0,
                    $incidents['niveau_4'] ?? 0,
                ];
            })
            ->all();
    }
}
