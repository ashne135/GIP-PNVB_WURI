<?php

namespace App\Services\Exports;

use App\Models\Kit;

/**
 * L'état du parc de kits.
 *
 * C'est une PHOTOGRAPHIE : l'état et le détenteur sont ceux du moment de la
 * production. Seul le nombre de mouvements est rapporté à la période — c'est
 * lui qui dit si un kit a bougé cette nuit-là.
 *
 * LE KIT SUIT LA PERSONNE : un kit sans centre courant — restitué, perdu, volé —
 * n'appartient à aucune région et ne figure donc que dans le fichier national.
 * L'écrire dans une région serait affirmer une localisation qu'il n'a plus.
 */
class SourceKits implements SourceExport
{
    public function cle(): string
    {
        return 'kits';
    }

    public function libelle(): string
    {
        return 'Parc de kits';
    }

    public function colonnes(): array
    {
        return [
            'Référence', 'État', 'Détenteur (matricule)', 'Détenteur (nom et prénoms)',
            'Centre courant', 'Site courant', 'Zone à défis sécuritaires',
            'Mouvements sur la période',
        ];
    }

    public function lignes(?int $regionId, string $du, string $au): iterable
    {
        return Kit::query()
            ->when($regionId, fn ($requete) => $requete->whereHas(
                'centreCourant',
                fn ($centre) => $centre->where('region_id', $regionId)
            ))
            ->with([
                'detenteur:id,user_id,matricule', 'detenteur.user:id,nom,prenoms',
                'centreCourant:id,code,region_id', 'siteCourant:id,code',
            ])
            ->withCount(['mouvements as mouvements_periode' => fn ($mouvement) => $mouvement
                ->whereBetween('effectue_le', [$du.' 00:00:00', $au.' 23:59:59'])])
            ->orderBy('reference')
            ->get()
            ->map(fn (Kit $kit) => [
                $kit->reference,
                $kit->etat,
                $kit->detenteur?->matricule ?? 'au parc',
                $kit->detenteur?->user?->nomComplet() ?? '',
                $kit->centreCourant?->code ?? '',
                $kit->siteCourant?->code ?? '',
                $kit->est_permanent_zone_defis ? 'oui' : 'non',
                $kit->mouvements_periode,
            ])
            ->all();
    }
}
