<?php

namespace App\Services\Rapports;

use App\Models\RapportJournalier;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Exports des rapports journaliers (cadrage v2, section 9).
 *
 * Le PDF reprend LE CANEVAS du niveau concerné — le document que les agents
 * remplissaient sur papier — et y ajoute ce que le papier ne portait pas : la
 * chaîne de visas, les corrections motivées, et les réponses des agents aux
 * appréciations qui les concernent.
 *
 * Un rapport encore au brouillon reste exportable, mais le document le dit en
 * toutes lettres : un rapport non signé ne doit jamais circuler comme s'il
 * l'était.
 */
class ExportRapports
{
    public function pdf(RapportJournalier $rapport, User $auteur): \Barryvdh\DomPDF\PDF
    {
        $this->journaliser($rapport, $auteur, 'pdf');

        return Pdf::loadView('documents.rapport-journalier', [
            'rapport' => $rapport,
            'genere_le' => now(),
            'genere_par' => $auteur->nomComplet(),
        ])->setPaper('a4');
    }

    /**
     * Synthèse tableur d'une SÉLECTION de rapports : une ligne par rapport,
     * avec les chiffres clés de son niveau. Le CSV s'ouvre dans Excel comme
     * dans un tableur libre, et se relit sur un poste modeste.
     *
     * @param  Collection<int, RapportJournalier>  $rapports
     */
    public function csv(Collection $rapports, User $auteur, string $intitule): callable
    {
        foreach ($rapports as $rapport) {
            $this->journaliser($rapport, $auteur, 'csv');
        }

        return function () use ($rapports, $auteur, $intitule) {
            $sortie = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel massacre les accents.
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, ['GIP-PNVB — Projet WURI — Rapports journaliers'], ';');
            fputcsv($sortie, [$intitule], ';');
            fputcsv($sortie, ['Généré le', now()->format('d/m/Y à H:i'), 'par', $auteur->nomComplet()], ';');
            fputcsv($sortie, [], ';');

            fputcsv($sortie, $this->colonnes(), ';');

            foreach ($rapports as $rapport) {
                fputcsv($sortie, $this->ligne($rapport), ';');
            }

            fclose($sortie);
        };
    }

    /**
     * Les colonnes de la synthèse tableur.
     *
     * Publiques, et partagées avec l'export planifié de nuit : deux définitions
     * des mêmes colonnes finiraient par diverger, et deux fichiers censés dire
     * la même chose ne se compareraient plus.
     *
     * @return array<int, string>
     */
    public function colonnes(): array
    {
        return [
            'Date', 'Niveau', 'Matricule', 'Nom et prénoms', 'Région', 'Centre', 'Site',
            'Statut', 'Signé le', 'Visé le', 'Supérieur',
            'Objectif', 'Enregistrements', 'Écart', 'Taux de réalisation (%)',
            'Dossiers contrôlés', 'Dossiers conformes', 'Taux de conformité (%)',
            'Justificatifs reçus', 'Justificatifs transmis', 'Plaintes enregistrées',
            'Corrigé', 'Difficultés signalées',
        ];
    }

    /** @return array<int, string|int|float|null> */
    public function ligne(RapportJournalier $rapport): array
    {
        $production = $rapport->productionOpk;
        $qualite = $rapport->qualite;
        $activites = $rapport->activitesAopk;

        return [
            $rapport->date_rapport?->format('d/m/Y'),
            $rapport->type?->libelle(),
            $rapport->auteur?->matricule,
            $rapport->auteur?->user?->nomComplet(),
            $rapport->region?->nom,
            $rapport->centre?->code,
            $rapport->site?->code,
            $rapport->statut?->libelle(),
            $rapport->soumis_le?->format('d/m/Y H:i') ?? '',
            $rapport->vise_le?->format('d/m/Y H:i') ?? '',
            $rapport->superieur?->user?->nomComplet() ?? '',

            // Colonnes propres au niveau : vides quand elles ne s'appliquent pas,
            // plutôt qu'à zéro — un zéro se confondrait avec un vrai résultat nul.
            $production?->objectif_enregistrements ?? '',
            $production?->enregistrements_realises ?? '',
            $production?->ecart_enregistrements ?? '',
            $production?->taux_realisation ?? '',

            $qualite?->dossiers_controles ?? '',
            $qualite?->dossiers_conformes ?? '',
            $qualite?->taux_conformite ?? '',

            $activites?->justificatifs_recus_realise ?? '',
            $activites?->justificatifs_transmis_realise ?? '',
            $activites?->plaintes_enregistrees_realise ?? '',

            $rapport->corrections->isNotEmpty() ? 'oui' : 'non',
            $rapport->difficultes->count(),
        ];
    }

    /**
     * Un rapport exporté est un document qui quitte la plateforme : on garde
     * trace de qui l'a produit, quand, et sous quel format.
     */
    private function journaliser(RapportJournalier $rapport, User $auteur, string $format): void
    {
        activity('rapport')
            ->causedBy($auteur)
            ->performedOn($rapport)
            ->withProperties([
                'format' => $format,
                'statut' => $rapport->statut?->value,
                'date_rapport' => $rapport->date_rapport?->toDateString(),
            ])
            ->log("Export {$format} d'un rapport journalier");
    }
}
