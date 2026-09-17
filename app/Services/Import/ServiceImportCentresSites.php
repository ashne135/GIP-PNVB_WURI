<?php

namespace App\Services\Import;

use App\Models\Centre;
use App\Models\Commune;
use App\Models\Import;
use App\Models\ImportLigne;
use App\Models\Localite;
use App\Models\Site;
use App\Models\User;
use App\Services\Referentiel\PurgeurCentresEtSites;
use App\Services\Referentiel\ServiceCentresEtSites;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import des CENTRES et SITES (cadrage, section 2).
 *
 * Même déroulé en trois temps que l'import des volontaires — analyser, montrer
 * l'aperçu, confirmer — et la même règle : « Aucun import partiel silencieux.
 * Rien n'est écrit tant que l'aperçu n'est pas confirmé. »
 *
 * Deux modes :
 *   COMPLÉTER  ajoute aux centres et sites existants ;
 *   REMPLACER  purge d'abord le jeu FICTIF, et refuse si des données réelles en
 *              dépendent (voir PurgeurCentresEtSites).
 *
 * Une ligne du fichier = un SITE. Le centre nommé sur la ligne est créé s'il
 * n'existe pas, réutilisé sinon : c'est ce qui permet au client de fournir un
 * seul fichier plutôt que deux.
 */
class ServiceImportCentresSites
{
    public function __construct(
        private readonly LecteurFichierGenerique $lecteur = new LecteurFichierGenerique,
        private readonly PurgeurCentresEtSites $purgeur = new PurgeurCentresEtSites,
        private readonly ServiceCentresEtSites $centres = new ServiceCentresEtSites,
    ) {
    }

    public function analyser(UploadedFile $fichier, string $mode, User $auteur): Import
    {
        $empreinte = hash_file('sha256', $fichier->getRealPath());

        $dejaApplique = Import::query()
            ->where('fichier_empreinte', $empreinte)
            ->where('statut', 'applique')
            ->first();

        if ($dejaApplique) {
            throw new \DomainException(
                'Ce fichier a déjà été importé le '
                .$dejaApplique->confirme_le?->format('d/m/Y à H:i').'.'
            );
        }

        $chemin = $fichier->store('imports/centres-sites');

        $import = Import::query()->create([
            'type_referentiel' => 'centres',
            'mode' => $mode,
            'fichier_nom' => $fichier->getClientOriginalName(),
            'fichier_chemin' => $chemin,
            'fichier_empreinte' => $empreinte,
            'statut' => 'analyse',
            'televerse_par' => $auteur->id,
        ]);

        $lu = $this->lecteur->lire(Storage::path($chemin), CanevasCentresSites::class);

        if ($lu['ligne_entete'] === null || $lu['manquantes'] !== []) {
            $import->update([
                'statut' => 'echec',
                'resume' => [
                    'colonnes_manquantes' => $lu['manquantes'],
                    'message' => $lu['manquantes'] === []
                        ? "Aucune ligne d'en-tête n'a été reconnue dans ce fichier."
                        : 'Colonnes obligatoires introuvables : '.implode(', ', $lu['manquantes'])
                            .'. Téléchargez le modèle pour voir les intitulés attendus.',
                ],
            ]);

            return $import->fresh();
        }

        $validateur = new ValidateurLigneCentreSite;
        $lignes = [];
        $valides = 0;
        $enErreur = 0;
        $centresDistincts = [];

        foreach ($lu['lignes'] as $ligne) {
            $resultat = $validateur->valider($ligne['donnees'], $ligne['numero']);

            if ($resultat['valide']) {
                $valides++;
                $centresDistincts[$resultat['donnees']['cle_centre']] = true;
            } else {
                $enErreur++;
            }

            $lignes[] = [
                'import_id' => $import->id,
                'numero_ligne' => $ligne['numero'],
                'donnees' => json_encode($resultat['donnees'], JSON_UNESCAPED_UNICODE),
                'valide' => $resultat['valide'],
                'motif_erreur' => $resultat['motif'] ? Str::limit($resultat['motif'], 250, '') : null,
                'action' => $resultat['valide'] ? 'creation' : 'erreur',
            ];
        }

        foreach (array_chunk($lignes, 500) as $lot) {
            ImportLigne::query()->insert($lot);
        }

        $import->update([
            'statut' => 'apercu_pret',
            'lignes_total' => count($lu['lignes']),
            'lignes_valides' => $valides,
            'lignes_erreur' => $enErreur,
            'resume' => [
                'ligne_entete' => $lu['ligne_entete'],
                'colonnes_reconnues' => array_values($lu['entetes']),
                'centres_distincts' => count($centresDistincts),
                'purge_prevue' => $mode === 'remplacer' ? $this->purgeur->apercu() : [],
                'obstacles_purge' => $mode === 'remplacer' ? $this->purgeur->obstacles() : [],
            ],
        ]);

        return $import->fresh();
    }

    public function confirmer(Import $import, User $auteur): Import
    {
        if ($import->statut !== 'apercu_pret') {
            throw new \DomainException("Cet import n'est pas en attente de confirmation.");
        }

        if ($import->lignes_valides === 0) {
            throw new \DomainException(
                'Aucune ligne valide à importer : corrigez le fichier et téléversez-le à nouveau.'
            );
        }

        $purge = [];
        $centresCrees = 0;
        $sitesCrees = 0;

        DB::transaction(function () use ($import, $auteur, &$purge, &$centresCrees, &$sitesCrees) {
            if ($import->mode === 'remplacer') {
                $purge = $this->purgeur->purger();
            }

            // Les centres déjà vus dans CE fichier, pour ne pas en créer deux
            // fois le même quand il porte plusieurs sites.
            $centresParCle = [];

            $import->lignes()
                ->where('valide', true)
                ->orderBy('numero_ligne')
                ->chunkById(200, function ($lignes) use ($auteur, &$centresParCle, &$centresCrees, &$sitesCrees) {
                    foreach ($lignes as $ligne) {
                        $donnees = $ligne->donnees;
                        $cle = $donnees['cle_centre'];

                        if (! isset($centresParCle[$cle])) {
                            $commune = Commune::query()->find($donnees['commune_id']);

                            $centre = Centre::query()
                                ->where('commune_id', $commune->id)
                                ->where('nom', $donnees['nom_centre'])
                                ->first();

                            if (! $centre) {
                                $centre = $this->centres->creerCentre($commune, [
                                    'code' => $donnees['code_centre'] ?: null,
                                    'nom' => $donnees['nom_centre'],
                                    'nombre_kits' => $donnees['nombre_kits'] ?: 1,
                                    'statut' => 'planifie',
                                    'est_fictif' => false,
                                ], $auteur);
                                $centresCrees++;
                            }

                            $centresParCle[$cle] = $centre;
                        }

                        $this->centres->creerSite(
                            $centresParCle[$cle],
                            Localite::query()->find($donnees['localite_id']),
                            [
                                'nom' => $donnees['nom_site'],
                                'ordre_tournee' => $donnees['ordre_tournee'] ?: null,
                                'latitude' => $donnees['latitude'],
                                'longitude' => $donnees['longitude'],
                                'statut' => 'planifie',
                                'est_fictif' => false,
                            ],
                            $auteur
                        );
                        $sitesCrees++;
                    }
                });

            $import->update([
                'statut' => 'applique',
                'confirme_par' => $auteur->id,
                'confirme_le' => now(),
                'resume' => [
                    ...($import->resume ?? []),
                    'centres_crees' => $centresCrees,
                    'sites_crees' => $sitesCrees,
                    'purge_effectuee' => $purge,
                ],
            ]);
        });

        activity('import')
            ->causedBy($auteur)
            ->performedOn($import)
            ->withProperties([
                'mode' => $import->mode,
                'centres_crees' => $centresCrees,
                'sites_crees' => $sitesCrees,
                'purge' => $purge,
            ])
            ->log("Import de centres et sites confirmé : {$centresCrees} centres, {$sitesCrees} sites");

        return $import->fresh();
    }

    public function annuler(Import $import): void
    {
        if ($import->statut === 'applique') {
            throw new \DomainException('Cet import a déjà été appliqué : il ne peut plus être annulé.');
        }

        Storage::delete($import->fichier_chemin);
        $import->update(['statut' => 'annule']);
    }
}
