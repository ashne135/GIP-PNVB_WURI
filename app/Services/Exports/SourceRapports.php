<?php

namespace App\Services\Exports;

use App\Models\RapportJournalier;
use App\Services\Rapports\ExportRapports;

/**
 * Les rapports journaliers de la période.
 *
 * SEULS LES RAPPORTS VISÉS y figurent. Un brouillon ou un rapport renvoyé pour
 * correction n'a été contrôlé par personne : le faire figurer dans un fichier
 * qui circule reviendrait à donner à des chiffres non vérifiés l'apparence d'un
 * document officiel. C'est la même règle que pour les agrégats.
 *
 * Les colonnes sont celles de l'export à la demande, empruntées telles quelles :
 * deux définitions finiraient par diverger, et deux fichiers censés dire la
 * même chose ne se compareraient plus.
 */
class SourceRapports implements SourceExport
{
    public function __construct(private readonly ExportRapports $exports) {}

    public function cle(): string
    {
        return 'rapports';
    }

    public function libelle(): string
    {
        return 'Rapports journaliers visés';
    }

    public function colonnes(): array
    {
        return $this->exports->colonnes();
    }

    public function lignes(?int $regionId, string $du, string $au): iterable
    {
        return RapportJournalier::query()
            ->vises()
            ->whereBetween('date_rapport', [$du, $au])
            ->when($regionId, fn ($requete) => $requete->where('region_id', $regionId))
            ->with([
                'auteur:id,user_id,matricule', 'auteur.user:id,nom,prenoms',
                'superieur:id,user_id,matricule', 'superieur.user:id,nom,prenoms',
                'site:id,code', 'centre:id,code', 'region:id,nom',
                'activitesAopk', 'productionOpk', 'qualite', 'difficultes', 'corrections',
            ])
            ->orderBy('date_rapport')
            ->orderBy('centre_id')
            ->get()
            ->map(fn (RapportJournalier $rapport) => $this->exports->ligne($rapport))
            ->all();
    }
}
