<?php

namespace App\Services\Presence;

use App\Models\FeuillePresence;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * EXPORTS OBLIGATOIRES de la liste des présents (cadrage, section 8.3).
 *
 * « Le PDF porte l'en-tête du projet, la date, le site, le centre, le nom du
 * superviseur validant et l'heure de génération : c'est une PIÈCE
 * JUSTIFICATIVE, elle doit être traçable et OPPOSABLE. »
 *
 * D'où trois exigences portées ici :
 *   - le document nomme son signataire et l'heure exacte de sa génération ;
 *   - il indique si la feuille était validée ou encore au brouillon, et le cas
 *     échéant qu'elle a été corrigée après validation ;
 *   - toute génération est journalisée, parce qu'un document opposable qui
 *     circule doit pouvoir être rattaché à qui l'a produit.
 */
class ExportPresences
{
    /** @param  Collection<int, FeuillePresence>  $feuilles */
    public function pdf(Collection $feuilles, User $auteur, string $intitule): \Barryvdh\DomPDF\PDF
    {
        $this->journaliser($feuilles, $auteur, 'pdf', $intitule);

        return Pdf::loadView('documents.liste-presents', [
            'feuilles' => $feuilles,
            'intitule' => $intitule,
            'genere_le' => now(),
            'genere_par' => $auteur->nomComplet(),
            'totaux' => $this->totaux($feuilles),
        ])->setPaper('a4');
    }

    /**
     * Export tableur au format CSV : il s'ouvre dans Excel comme dans un
     * tableur libre, et se relit sur un poste modeste — ce qui compte plus,
     * sur le terrain, qu'un XLSX mis en forme.
     *
     * @param  Collection<int, FeuillePresence>  $feuilles
     */
    public function csv(Collection $feuilles, User $auteur, string $intitule): callable
    {
        $this->journaliser($feuilles, $auteur, 'csv', $intitule);

        return function () use ($feuilles, $auteur, $intitule) {
            $sortie = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel massacre les accents.
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, ['GIP-PNVB — Projet WURI — Liste des présents'], ';');
            fputcsv($sortie, [$intitule], ';');
            fputcsv($sortie, ['Généré le', now()->format('d/m/Y à H:i'), 'par', $auteur->nomComplet()], ';');
            fputcsv($sortie, [], ';');

            fputcsv($sortie, $this->colonnes(), ';');

            foreach ($feuilles as $feuille) {
                foreach ($this->lignesDe($feuille) as $ligne) {
                    fputcsv($sortie, $ligne, ';');
                }
            }

            fclose($sortie);
        };
    }

    /**
     * Les colonnes de la liste des présents.
     *
     * Publiques, et partagées avec l'export planifié de nuit : deux définitions
     * des mêmes colonnes finiraient par diverger.
     *
     * @return array<int, string>
     */
    public function colonnes(): array
    {
        return [
            'Date', 'Centre', 'Site', 'Matricule', 'Nom et prénoms', 'Catégorie',
            'Présence', 'Motif d\'absence', 'Arrivée signalée', 'Distance (m)',
            'Statut de la feuille', 'Superviseur validant',
        ];
    }

    /**
     * Une ligne par agent marqué sur la feuille.
     *
     * @return array<int, array<int, string|int|null>>
     */
    public function lignesDe(FeuillePresence $feuille): array
    {
        return $feuille->lignes->map(fn ($ligne) => [
            $feuille->date_presence?->format('d/m/Y'),
            $feuille->centre?->code,
            $feuille->site?->code,
            $ligne->volontaire?->matricule,
            $ligne->volontaire?->user?->nomComplet(),
            $ligne->categorie?->libelle(),
            $ligne->statut?->libelle(),
            $ligne->motif_absence ?? '',
            $ligne->heure_arrivee_signalee?->format('H:i') ?? 'aucune',
            $ligne->distance_signalee ?? '',
            $feuille->statut,
            $feuille->superviseur?->user?->nomComplet(),
        ])->all();
    }

    /** @param  Collection<int, FeuillePresence>  $feuilles */
    private function totaux(Collection $feuilles): array
    {
        $lignes = $feuilles->flatMap->lignes;

        return [
            'feuilles' => $feuilles->count(),
            'agents' => $lignes->count(),
            'presents' => $lignes->filter(fn ($l) => $l->statut?->compteCommePresent())->count(),
            'absents' => $lignes->where('statut.value', 'absent')->count(),
            'absents_justifies' => $lignes->where('statut.value', 'absent_justifie')->count(),
        ];
    }

    /**
     * Une pièce opposable qui circule doit pouvoir être rattachée à qui l'a
     * produite, et quand.
     */
    private function journaliser(Collection $feuilles, User $auteur, string $format, string $intitule): void
    {
        activity('export')
            ->causedBy($auteur)
            ->withProperties([
                'format' => $format,
                'intitule' => $intitule,
                'feuilles' => $feuilles->pluck('id')->all(),
                'nombre' => $feuilles->count(),
            ])
            ->log('Export de la liste des présents');
    }
}
