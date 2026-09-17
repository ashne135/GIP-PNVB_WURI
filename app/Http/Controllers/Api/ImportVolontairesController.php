<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\TeleverserVolontairesRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Import;
use App\Services\Import\CanevasVolontaires;
use App\Services\Import\ServiceImportVolontaires;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import des RETENUS et de la LISTE D'ATTENTE (cadrage, sections 2 et 6).
 *
 * Le parcours est en trois temps, et l'API les sépare explicitement :
 *
 *   POST   /imports/volontaires            téléverse et ANALYSE — rien n'est écrit
 *   GET    /imports/{import}/apercu        les lignes valides et celles en erreur
 *   POST   /imports/{import}/confirmer     applique, et seulement alors
 *
 * « Aucun import partiel silencieux. Rien n'est écrit tant que l'aperçu n'est
 * pas confirmé. »
 */
class ImportVolontairesController extends Controller
{
    public function __construct(private readonly ServiceImportVolontaires $service)
    {
    }

    /** Historique des imports, du plus récent au plus ancien. */
    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Import::class);

        $imports = Import::query()
            ->with(['televersePar:id,nom,prenoms', 'confirmePar:id,nom,prenoms'])
            ->whereIn('type_referentiel', ['volontaires_retenus', 'volontaires_reserve'])
            ->latest()
            ->paginate(min($requete->integer('par_page', 20), 100));

        return ReponseApi::succes('Imports récupérés.', $imports);
    }

    /** Temps 1 : téléversement et analyse. Aucun compte n'est créé ici. */
    public function televerser(TeleverserVolontairesRequest $requete): JsonResponse
    {
        $this->authorize('create', Import::class);

        try {
            $import = $this->service->analyser(
                $requete->file('fichier'),
                $requete->validated('type'),
                $requete->validated('mode'),
                $requete->user()
            );
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

        return ReponseApi::succes(
            $this->resumerAnalyse($import),
            ['import' => $import],
            201
        );
    }

    /**
     * Temps 2 : l'aperçu. Les lignes en erreur viennent EN PREMIER — ce sont
     * elles que l'administrateur doit regarder avant de décider.
     */
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

    /** Temps 3 : confirmation. C'est ici, et seulement ici, qu'on écrit. */
    public function confirmer(Request $requete, Import $import): JsonResponse
    {
        $this->authorize('confirmer', $import);

        try {
            $import = $this->service->confirmer($import, $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $crees = $import->resume['comptes_crees'] ?? 0;
        $ignorees = $import->lignes_erreur;

        $message = "{$crees} comptes créés, en statut inactif.";

        if ($ignorees > 0) {
            $message .= " {$ignorees} lignes en erreur ont été ignorées : "
                .'téléchargez le compte rendu pour les corriger.';
        }

        $message .= " L'accès s'ouvrira pour chacun à son affectation.";

        return ReponseApi::succes($message, ['import' => $import]);
    }

    public function annuler(Import $import): JsonResponse
    {
        $this->authorize('delete', $import);

        try {
            $this->service->annuler($import);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Import annulé.');
    }

    /**
     * Compte rendu téléchargeable (cadrage, section 2).
     *
     * Un CSV plutôt qu'un Excel : il s'ouvre partout, se relit sur un poste
     * modeste, et se renvoie tel quel à la personne qui a produit le fichier.
     */
    public function compteRendu(Import $import): StreamedResponse
    {
        $this->authorize('view', $import);

        $nom = 'compte-rendu-import-'.$import->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($import) {
            $sortie = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel affiche les accents de travers.
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, ['Ligne', 'Statut', 'Motif', 'Nom', 'Prénoms', 'Téléphone',
                'Email', 'Catégorie', 'Niveau d\'étude', 'Diplôme', 'Localité'], ';');

            $import->lignes()->orderBy('numero_ligne')->chunk(500, function ($lignes) use ($sortie) {
                foreach ($lignes as $ligne) {
                    $d = $ligne->donnees;

                    fputcsv($sortie, [
                        $ligne->numero_ligne,
                        $ligne->valide ? 'Importée' : 'En erreur',
                        // Une ligne valide peut porter un avertissement : son
                        // profil n'a pas été appliqué faute du niveau exigé.
                        $ligne->motif_erreur ?? ($d['profil_ecarte'] ?? ''),
                        $d['nom'] ?? '',
                        $d['prenoms'] ?? '',
                        $d['telephone'] ?? '',
                        $d['email'] ?? '',
                        $d['categorie'] ?? '',
                        $d['niveau_etude']
                            ? \App\Enums\NiveauEtude::from($d['niveau_etude'])->libelle()
                            : '',
                        $d['diplome'] ?? '',
                        $this->localiteDeLaLigne($d),
                    ], ';');
                }
            });

            fclose($sortie);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Modèle de fichier à remplir : les colonnes du fichier réel du client
     * (cadrage v2, section 6), dans son ordre, avec une ligne par profil.
     */
    public function modele(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, CanevasVolontaires::entetesDuModele(), ';');

            foreach (CanevasVolontaires::lignesDuModele() as $ligne) {
                fputcsv($sortie, $ligne, ';');
            }

            fclose($sortie);
        }, 'modele-import-volontaires.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * La localité telle que le fichier la nomme. Le canevas n'a pas de colonne
     * « localité » : il en a trois — village, secteur, quartier — dont une
     * seule est remplie. Lire une clé « localite » rendait toujours du vide.
     */
    private function localiteDeLaLigne(array $donnees): string
    {
        foreach (CanevasVolontaires::COLONNES_LOCALITE as $colonne) {
            $nom = trim((string) ($donnees[$colonne] ?? ''));

            if ($nom !== '') {
                $commune = trim((string) ($donnees['commune'] ?? ''));

                return $commune !== '' ? "{$nom} ({$commune})" : $nom;
            }
        }

        return '';
    }

    private function resumerAnalyse(Import $import): string
    {
        $message = "Fichier lu : {$import->lignes_valides} lignes valides";

        if ($import->lignes_erreur > 0) {
            $message .= ", {$import->lignes_erreur} lignes en erreur";
        }

        $ecartes = $import->resume['profils_ecartes'] ?? 0;

        if ($ecartes > 0) {
            $message .= ". {$ecartes} profils n'ont pas été appliqués faute du niveau d'étude exigé : "
                .'ces fiches arriveront « à qualifier »';
        }

        $sansCourriel = $import->resume['sans_courriel'] ?? 0;

        if ($sansCourriel > 0) {
            $message .= ". {$sansCourriel} volontaires sans adresse de courriel : "
                .'ils recevront leurs identifiants par SMS ou par bordereau en formation';
        }

        return $message.'. Rien n\'a encore été enregistré : vérifiez l\'aperçu puis confirmez.';
    }
}
