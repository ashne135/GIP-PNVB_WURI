<?php

namespace App\Services\Exports;

use App\Models\AgregatCouvertureLocalite;

/**
 * La couverture cumulée, une ligne par localité.
 *
 * C'est une PHOTOGRAPHIE au moment de la production, pas une période : le cumul
 * et le taux sont ceux du jour où le fichier est produit. L'intitulé du fichier
 * le dit, pour qu'on ne lise pas ces chiffres comme ceux de la veille.
 *
 * UNE POPULATION INCONNUE N'EST PAS UN TAUX DE ZÉRO : la localité est écrite
 * « non mesurable ». Un zéro la ferait remonter en tête des localités en retard,
 * et enverrait des équipes là où rien ne prouve qu'il faut aller.
 */
class SourceCouverture implements SourceExport
{
    public function cle(): string
    {
        return 'couverture';
    }

    public function libelle(): string
    {
        return 'Couverture par localité (cumul)';
    }

    public function colonnes(): array
    {
        return [
            'Région', 'Commune', 'Localité', 'Population cible', 'Cumul enregistrés',
            'Taux de couverture (%)', 'Sites', 'Sites couverts', 'Dernière activité',
        ];
    }

    public function lignes(?int $regionId, string $du, string $au): iterable
    {
        return AgregatCouvertureLocalite::query()
            ->when($regionId, fn ($requete) => $requete->where('region_id', $regionId))
            ->with(['region:id,nom', 'commune:id,nom', 'localite:id,nom'])
            ->orderBy('region_id')
            ->orderBy('commune_id')
            ->get()
            ->map(fn (AgregatCouvertureLocalite $couverture) => [
                $couverture->region?->nom,
                $couverture->commune?->nom,
                $couverture->localite?->nom,
                (int) $couverture->population_cible === 0 ? 'non renseignée' : $couverture->population_cible,
                $couverture->cumul_enregistres,
                (int) $couverture->population_cible === 0 ? 'non mesurable' : $couverture->taux_couverture,
                $couverture->nb_sites,
                $couverture->nb_sites_couverts,
                $couverture->derniere_activite_le?->format('d/m/Y') ?? 'aucune',
            ])
            ->all();
    }
}
