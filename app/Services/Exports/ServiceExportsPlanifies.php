<?php

namespace App\Services\Exports;

use App\Models\ExportPlanifie;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * LA PRODUCTION DES EXPORTS PLANIFIÉS (cadrage, tâche 18).
 *
 * Ce service ne sait rien du métier : il demande ses lignes à une source, les
 * enveloppe, écrit le fichier et range sa ligne en base. Ajouter un contenu se
 * fait donc par une classe, sans toucher ici.
 *
 * TROIS DÉCISIONS QUI TIENNENT LE RESTE :
 *
 *   1. UN FICHIER PAR PÉRIMÈTRE — un national, un par région. Produire un seul
 *      fichier national et le filtrer à la lecture obligerait à faire confiance
 *      au filtre ; un chef d'antenne télécharge un fichier qui ne contient que
 *      sa région, point.
 *
 *   2. UNE JOURNÉE SANS DONNÉE N'EST PAS UNE PANNE. La ligne est écrite avec le
 *      statut « vide » et aucun fichier : l'écran dit « rien ce jour-là » au lieu
 *      de rester muet, ce qui ressemblerait à une production ratée.
 *
 *   3. LE NOM DU FICHIER EST DÉTERMINISTE. Rejouer une journée réécrit le même
 *      fichier au lieu d'en empiler un second, et ne laisse aucun orphelin sur
 *      le disque.
 */
class ServiceExportsPlanifies
{
    public const DISQUE = 'local';

    /** @var array<int, SourceExport> */
    private readonly array $sources;

    public function __construct(
        SourceRapports $rapports,
        SourcePresences $presences,
        SourceIncidents $incidents,
        SourceTableauBord $tableauBord,
        SourceCouverture $couverture,
        SourceKits $kits,
    ) {
        $this->sources = [$rapports, $presences, $incidents, $tableauBord, $couverture, $kits];
    }

    /**
     * Produit le jeu complet d'une journée : le national, puis chaque région.
     *
     * @return array{date: string, fichiers: int, vides: int}
     */
    public function genererJournee(string $date, ?User $auteur = null): array
    {
        $regions = Region::query()->orderBy('code')->get();
        $fichiers = 0;
        $vides = 0;

        foreach ($this->sources as $source) {
            foreach ([null, ...$regions] as $region) {
                $export = $this->produire($source, $region, $date, $date, $auteur);

                $export->statut === 'pret' ? $fichiers++ : $vides++;
            }
        }

        return ['date' => $date, 'fichiers' => $fichiers, 'vides' => $vides];
    }

    /**
     * Efface les fichiers passés le délai de rétention, et leur ligne.
     *
     * Un export est une photographie, pas une archive : les données restent en
     * base, et une journée effacée se reproduit à la demande.
     */
    public function purger(int $jours): int
    {
        $limite = now()->subDays($jours)->toDateString();

        $anciens = ExportPlanifie::query()->whereDate('date_debut', '<', $limite)->get();

        foreach ($anciens as $export) {
            if (filled($export->chemin)) {
                Storage::disk(self::DISQUE)->delete($export->chemin);
            }

            $export->delete();
        }

        return $anciens->count();
    }

    // ------------------------------------------------------------------

    private function produire(
        SourceExport $source,
        ?Region $region,
        string $du,
        string $au,
        ?User $auteur
    ): ExportPlanifie {
        $lignes = collect($source->lignes($region?->id, $du, $au));
        $nom = $this->nomDuFichier($source, $region, $du);
        $chemin = $lignes->isEmpty() ? null : $this->ecrire($nom, $source, $region, $du, $au, $lignes);

        return ExportPlanifie::query()->updateOrCreate(
            [
                'type' => $source->cle(),
                'portee' => $region ? 'region' : 'national',
                'region_id' => $region?->id,
                'date_debut' => $du,
            ],
            [
                'date_fin' => $au,
                'nom_fichier' => $nom,
                'chemin' => $chemin,
                'format' => 'csv',
                'taille_octets' => $chemin ? (int) Storage::disk(self::DISQUE)->size($chemin) : 0,
                'nb_lignes' => $lignes->count(),
                'statut' => $chemin ? 'pret' : 'vide',
                'message' => $chemin ? null : 'Aucune donnée pour cette journée.',
                'genere_le' => now(),
                'genere_par' => $auteur?->id,
            ]
        );
    }

    /** @param  Collection<int, array<int, string|int|float|null>>  $lignes */
    private function ecrire(
        string $nom,
        SourceExport $source,
        ?Region $region,
        string $du,
        string $au,
        Collection $lignes
    ): string {
        $tampon = fopen('php://temp', 'w+');

        // BOM UTF-8 : sans lui, Excel massacre les accents.
        fwrite($tampon, "\xEF\xBB\xBF");

        fputcsv($tampon, ['GIP-PNVB — Projet WURI — '.$source->libelle()], ';');
        fputcsv($tampon, [$this->intitule($region, $du, $au)], ';');
        fputcsv($tampon, ['Produit le', now()->format('d/m/Y à H:i')], ';');
        fputcsv($tampon, [], ';');
        fputcsv($tampon, $source->colonnes(), ';');

        foreach ($lignes as $ligne) {
            fputcsv($tampon, $ligne, ';');
        }

        rewind($tampon);
        $chemin = 'exports/'.now()->format('Y/m').'/'.$nom;
        Storage::disk(self::DISQUE)->put($chemin, stream_get_contents($tampon));
        fclose($tampon);

        return $chemin;
    }

    private function nomDuFichier(SourceExport $source, ?Region $region, string $du): string
    {
        return $source->cle().'-'.($region?->code ?? 'national').'-'.$du.'.csv';
    }

    private function intitule(?Region $region, string $du, string $au): string
    {
        $perimetre = $region ? 'Région '.$region->nom : 'National — toutes régions';

        return $du === $au
            ? $perimetre.' — journée du '.$du
            : $perimetre.' — du '.$du.' au '.$au;
    }
}
