<?php

namespace App\Services\Import;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutVolontaire;
use App\Models\Import;
use App\Models\ImportLigne;
use App\Models\User;
use App\Models\Volontaire;
use App\Services\Comptes\ServiceCycleDeVieCompte;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import des RETENUS et de la LISTE D'ATTENTE (cadrage, section 6).
 *
 * Déroulé imposé par le cadrage (section 2), en trois temps séparés :
 *
 *   1. analyser()  — le fichier est lu et validé, LIGNE PAR LIGNE, et le
 *                    résultat est conservé. RIEN n'est écrit dans le registre.
 *   2. l'aperçu    — l'administrateur voit les lignes valides et les lignes en
 *                    erreur AVEC LEUR MOTIF, et décide.
 *   3. confirmer() — seulement alors, les comptes sont créés.
 *
 * « Aucun import partiel silencieux. Rien n'est écrit tant que l'aperçu n'est
 * pas confirmé. »
 *
 * Les comptes sont créés en statut INACTIF : c'est l'AFFECTATION qui ouvrira
 * l'accès, jamais l'import.
 */
class ServiceImportVolontaires
{
    public function __construct(
        private readonly LecteurFichierVolontaires $lecteur = new LecteurFichierVolontaires,
        private readonly PurgeurVolontairesFictifs $purgeur = new PurgeurVolontairesFictifs,
    ) {
    }

    /**
     * Temps 1 : téléverse, lit, valide — et n'écrit que l'aperçu.
     *
     * @param  string  $type  volontaires_retenus ou volontaires_reserve
     * @param  string  $mode  completer ou remplacer
     */
    public function analyser(UploadedFile $fichier, string $type, string $mode, User $auteur): Import
    {
        $empreinte = hash_file('sha256', $fichier->getRealPath());

        // Le même fichier téléversé deux fois est un accident fréquent quand
        // plusieurs personnes disposent du même export.
        $dejaApplique = Import::query()
            ->where('fichier_empreinte', $empreinte)
            ->where('statut', 'applique')
            ->first();

        if ($dejaApplique) {
            throw new \DomainException(
                'Ce fichier a déjà été importé le '
                .$dejaApplique->confirme_le?->format('d/m/Y à H:i').'. '
                .'Si vous voulez le réimporter, supprimez d\'abord cet import de l\'historique.'
            );
        }

        $chemin = $fichier->store('imports/volontaires');

        $import = Import::query()->create([
            'type_referentiel' => $type,
            'mode' => $mode,
            'fichier_nom' => $fichier->getClientOriginalName(),
            'fichier_chemin' => $chemin,
            'fichier_empreinte' => $empreinte,
            'statut' => 'analyse',
            'televerse_par' => $auteur->id,
        ]);

        $lu = $this->lecteur->lire(Storage::path($chemin));

        if ($lu['ligne_entete'] === null || $lu['manquantes'] !== []) {
            return $this->marquerEnEchec($import, $lu['manquantes']);
        }

        $validateur = new ValidateurLigneVolontaire;
        $lignes = [];
        $valides = 0;
        $enErreur = 0;
        $motifs = [];
        $sansCourriel = 0;
        $aQualifier = 0;
        $profilsEcartes = 0;

        foreach ($lu['lignes'] as $ligne) {
            $resultat = $validateur->valider($ligne['donnees'], $ligne['numero']);

            if ($resultat['valide']) {
                $valides++;

                if (($resultat['donnees']['email'] ?? null) === null) {
                    $sansCourriel++;
                }

                // Colonne Profil vide : la ligne est acceptée, mais elle devra
                // être qualifiée avant toute affectation (cadrage v2, section 6).
                if ($resultat['a_qualifier']) {
                    $aQualifier++;
                }

                // Le profil du fichier n'a pas été appliqué : le niveau d'étude
                // ne le permet pas (décision du client, 17/09/2026).
                if (($resultat['donnees']['profil_ecarte'] ?? null) !== null) {
                    $profilsEcartes++;
                }
            } else {
                $enErreur++;
                $motifs[] = $resultat['motif'];
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
                'sans_courriel' => $sansCourriel,
                'a_qualifier' => $aQualifier,
                'profils_ecartes' => $profilsEcartes,
                'motifs_frequents' => $this->motifsFrequents($motifs),
                'purge_prevue' => $mode === 'remplacer' ? Volontaire::query()->fictifs()->count() : 0,
                'obstacles_purge' => $mode === 'remplacer' ? $this->purgeur->obstacles() : [],
            ],
        ]);

        return $import->fresh();
    }

    /**
     * Temps 3 : l'administrateur a vu l'aperçu et confirme.
     * Seules les lignes VALIDES sont appliquées ; les lignes en erreur sont
     * laissées de côté et restent consultables dans le compte rendu.
     */
    public function confirmer(Import $import, User $auteur): Import
    {
        if ($import->statut !== 'apercu_pret') {
            throw new \DomainException("Cet import n'est pas en attente de confirmation.");
        }

        if ($import->lignes_valides === 0) {
            throw new \DomainException("Aucune ligne valide à importer : corrigez le fichier et téléversez-le à nouveau.");
        }

        $obstacles = $import->mode === 'remplacer' ? $this->purgeur->obstacles() : [];

        if ($obstacles !== []) {
            throw new \DomainException(implode(' ', $obstacles));
        }

        $statutVolontaire = $import->type_referentiel === 'volontaires_retenus'
            ? StatutVolontaire::Operationnel
            : StatutVolontaire::Reserve;

        $cycleDeVie = app(ServiceCycleDeVieCompte::class);
        $purge = [];
        $crees = 0;

        DB::transaction(function () use ($import, $auteur, $statutVolontaire, $cycleDeVie, &$purge, &$crees) {
            if ($import->mode === 'remplacer') {
                $purge = $this->purgeur->purger();
            }

            $compteurs = $this->compteursMatricule();

            $import->lignes()
                ->where('valide', true)
                ->orderBy('numero_ligne')
                ->chunkById(200, function ($lignes) use ($statutVolontaire, $cycleDeVie, $import, $auteur, &$compteurs, &$crees) {
                    foreach ($lignes as $ligne) {
                        $donnees = $ligne->donnees;

                        // La colonne Profil peut être vide : la fiche arrive
                        // alors « à qualifier », catégorie nulle, et reçoit un
                        // matricule PROVISOIRE que la qualification remplacera.
                        $categorie = $donnees['categorie']
                            ? CategorieVolontaire::from($donnees['categorie'])
                            : null;

                        $prefixe = $categorie?->prefixeMatricule() ?? 'AQU';
                        $compteurs[$prefixe] = ($compteurs[$prefixe] ?? 0) + 1;

                        $cycleDeVie->creerCompteInactif(
                            [
                                'telephone' => $donnees['telephone'],
                                'email' => $donnees['email'],
                                'nom' => $donnees['nom'],
                                'prenoms' => $donnees['prenoms'],
                                // Mot de passe provisoire jamais communiqué : le
                                // compte est inactif, et la cascade de remise en
                                // générera un nouveau au moment de l'envoi.
                                'password' => Str::password(16),
                                'region_id' => null,
                                'est_fictif' => false,
                            ],
                            [
                                'matricule' => sprintf('PNVB-%s%06d', $prefixe, $compteurs[$prefixe]),
                                'numero_cnib' => $donnees['numero_cnib'] ?? null,
                                'date_etablissement_cnib' => $donnees['date_etablissement_cnib'] ?? null,
                                'categorie' => $categorie?->value,
                                'statut' => $statutVolontaire->value,
                                'localite_id' => $donnees['localite_id'] ?? null,
                                'region_origine_id' => $donnees['region_origine_id'] ?? null,
                                'sexe' => $donnees['sexe'] ?? null,
                                'date_naissance' => $donnees['date_naissance'] ?? null,
                                'lieu_naissance' => $donnees['lieu_naissance'] ?? null,
                                'niveau_etude' => $donnees['niveau_etude'] ?? null,
                                'diplome' => $donnees['diplome'] ?? null,
                                'motif_reserve' => $statutVolontaire === StatutVolontaire::Reserve
                                    ? 'non_mobilise'
                                    : null,
                                'import_id' => $import->id,
                                'est_fictif' => false,
                            ],
                            $auteur
                        );

                        $crees++;
                    }
                });

            $import->update([
                'statut' => 'applique',
                'confirme_par' => $auteur->id,
                'confirme_le' => now(),
                'resume' => [
                    ...($import->resume ?? []),
                    'comptes_crees' => $crees,
                    'purge_effectuee' => $purge,
                ],
            ]);
        });

        activity('import')
            ->causedBy($auteur)
            ->performedOn($import)
            ->withProperties([
                'type' => $import->type_referentiel,
                'mode' => $import->mode,
                'comptes_crees' => $crees,
                'lignes_ignorees' => $import->lignes_erreur,
            ])
            ->log("Import confirmé : {$crees} comptes créés en statut inactif");

        return $import->fresh();
    }

    /** Annule un import qui n'a pas encore été appliqué. */
    public function annuler(Import $import): void
    {
        if ($import->statut === 'applique') {
            throw new \DomainException('Cet import a déjà été appliqué : il ne peut plus être annulé.');
        }

        Storage::delete($import->fichier_chemin);
        $import->update(['statut' => 'annule']);
    }

    /**
     * Les matricules reprennent la numérotation là où elle s'est arrêtée, pour
     * qu'un second import ne réutilise pas des numéros déjà attribués.
     */
    private function compteursMatricule(): array
    {
        $compteurs = [];
        $prefixes = array_map(
            fn (CategorieVolontaire $c) => $c->prefixeMatricule(),
            CategorieVolontaire::cases()
        );
        $prefixes[] = 'AQU';

        foreach ($prefixes as $prefixe) {
            $dernier = Volontaire::query()
                ->where('matricule', 'like', "PNVB-{$prefixe}%")
                ->orderByDesc('matricule')
                ->value('matricule');

            $compteurs[$prefixe] = $dernier ? (int) substr($dernier, -6) : 0;
        }

        return $compteurs;
    }

    private function marquerEnEchec(Import $import, array $manquantes): Import
    {
        $libelles = implode(', ', $manquantes);

        $import->update([
            'statut' => 'echec',
            'resume' => [
                'colonnes_manquantes' => $manquantes,
                'message' => $manquantes === []
                    ? "Aucune ligne d'en-tête n'a été reconnue dans ce fichier."
                    : "Colonnes obligatoires introuvables : {$libelles}. "
                        .'Téléchargez le modèle de fichier pour voir les intitulés attendus.',
            ],
        ]);

        return $import->fresh();
    }

    /** @param string[] $motifs */
    private function motifsFrequents(array $motifs): array
    {
        $comptes = array_count_values(array_map(
            fn ($m) => Str::limit((string) $m, 90, '…'),
            $motifs
        ));
        arsort($comptes);

        return array_slice($comptes, 0, 5, true);
    }
}
