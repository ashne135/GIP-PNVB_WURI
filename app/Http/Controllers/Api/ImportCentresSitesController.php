<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\Import;
use App\Services\Import\CanevasCentresSites;
use App\Services\Import\ServiceImportCentresSites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import des CENTRES et SITES, en deux modes (cadrage, section 2).
 *
 *   POST   /imports/centres-sites          téléverse et ANALYSE — rien n'est écrit
 *   GET    /imports/centres-sites/{i}/apercu    lignes valides et lignes en erreur
 *   POST   /imports/centres-sites/{i}/confirmer applique, et seulement alors
 *
 * Le mode REMPLACER purge le jeu fictif avant d'écrire. L'aperçu annonce
 * exactement ce que la purge va emporter, et la refuse si une donnée réelle en
 * dépend : une feuille de présence validée ne disparaît pas parce qu'on
 * recharge un référentiel.
 */
class ImportCentresSitesController extends Controller
{
    public function __construct(private readonly ServiceImportCentresSites $service)
    {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Import::class);

        $imports = Import::query()
            ->with(['televersePar:id,nom,prenoms', 'confirmePar:id,nom,prenoms'])
            ->whereIn('type_referentiel', ['centres', 'sites'])
            ->latest()
            ->paginate(min($requete->integer('par_page', 20), 100));

        return ReponseApi::succes('Imports récupérés.', $imports);
    }

    public function televerser(Request $requete): JsonResponse
    {
        $this->autoriserImport($requete);

        $valide = $requete->validate([
            'fichier' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'mode' => ['required', Rule::in(['completer', 'remplacer'])],
        ], [
            'fichier.required' => 'Choisissez le fichier à importer.',
            'fichier.mimes' => 'Le fichier doit être un classeur Excel (.xlsx, .xls) ou un fichier CSV.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 20 Mo.',
            'mode.required' => 'Choisissez le mode : compléter le référentiel, '
                .'ou remplacer le jeu de démonstration.',
        ]);

        try {
            $import = $this->service->analyser($requete->file('fichier'), $valide['mode'], $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        if ($import->statut === 'echec') {
            return ReponseApi::echec(
                $import->resume['message'] ?? "Ce fichier n'a pas pu être lu.",
                ['import' => $import],
                422
            );
        }

        return ReponseApi::succes($this->resumerAnalyse($import), ['import' => $import], 201);
    }

    public function apercu(Request $requete, Import $import): JsonResponse
    {
        $this->authorize('view', $import);

        $lignes = $import->lignes()
            ->orderBy('valide')
            ->orderBy('numero_ligne')
            ->when($requete->boolean('erreurs_seulement'), fn ($q) => $q->where('valide', false))
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Aperçu de l\'import.', [
            'import' => $import,
            'lignes' => $lignes,
        ]);
    }

    public function confirmer(Request $requete, Import $import): JsonResponse
    {
        $this->autoriserImport($requete);

        try {
            $import = $this->service->confirmer($import, $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $centres = $import->resume['centres_crees'] ?? 0;
        $sites = $import->resume['sites_crees'] ?? 0;
        $message = "{$centres} centres et {$sites} sites créés, avec leur codification définitive.";

        if ($import->lignes_erreur > 0) {
            $message .= " {$import->lignes_erreur} lignes en erreur ont été ignorées : "
                .'téléchargez le compte rendu pour les corriger.';
        }

        return ReponseApi::succes($message, ['import' => $import]);
    }

    public function annuler(Request $requete, Import $import): JsonResponse
    {
        $this->autoriserImport($requete);

        try {
            $this->service->annuler($import);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Import annulé.');
    }

    public function compteRendu(Import $import): StreamedResponse
    {
        $this->authorize('view', $import);

        $nom = 'compte-rendu-centres-sites-'.$import->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($import) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, ['Ligne', 'Statut', 'Motif', 'Region', 'Commune',
                'Localité', 'Centre', 'Site'], ';');

            $import->lignes()->orderBy('numero_ligne')->chunk(500, function ($lignes) use ($sortie) {
                foreach ($lignes as $ligne) {
                    $d = $ligne->donnees;

                    fputcsv($sortie, [
                        $ligne->numero_ligne,
                        $ligne->valide ? 'Importée' : 'En erreur',
                        $ligne->motif_erreur ?? '',
                        $d['region'] ?? '',
                        $d['commune'] ?? '',
                        $d['localite'] ?? '',
                        $d['nom_centre'] ?? '',
                        $d['nom_site'] ?? '',
                    ], ';');
                }
            });

            fclose($sortie);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Modèle de fichier à remplir : les codes ne sont pas demandés, ils sont générés. */
    public function modele(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, CanevasCentresSites::entetesDuModele(), ';');

            foreach (CanevasCentresSites::lignesDuModele() as $ligne) {
                fputcsv($sortie, $ligne, ';');
            }

            fclose($sortie);
        }, 'modele-import-centres-sites.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resumerAnalyse(Import $import): string
    {
        $message = "Fichier lu : {$import->lignes_valides} sites valides";
        $centres = $import->resume['centres_distincts'] ?? 0;

        if ($centres > 0) {
            $message .= ", répartis sur {$centres} centres";
        }

        if ($import->lignes_erreur > 0) {
            $message .= ", {$import->lignes_erreur} lignes en erreur";
        }

        $obstacles = $import->resume['obstacles_purge'] ?? [];

        if ($obstacles !== []) {
            return $message.'. ATTENTION : la purge du jeu de démonstration est impossible. '
                .implode(' ', $obstacles);
        }

        $purge = $import->resume['purge_prevue'] ?? [];

        if ($purge !== []) {
            $message .= '. Le mode « remplacer » supprimera '
                .($purge['centres'] ?? 0).' centres et '.($purge['sites'] ?? 0).' sites de démonstration';
        }

        return $message.'. Rien n\'a encore été enregistré : vérifiez l\'aperçu puis confirmez.';
    }

    private function autoriserImport(Request $requete): void
    {
        abort_unless($requete->user()->can('referentiel.importer'), 403);
    }
}
