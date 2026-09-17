<?php

namespace Database\Seeders;

use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Services\Referentiel\CodificateurTerritorial;
use App\Services\Referentiel\LecteurReferentielTerritorial;
use App\Services\Referentiel\RepartiteurSites;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chargement du référentiel territorial depuis le classeur hiérarchique.
 *
 * Crée 12 régions, 34 provinces, 355 communes et 7 453 localités, et stocke la
 * population qui servira de dénominateur au taux de couverture.
 *
 * IDEMPOTENT : rejouable sans doublon ni renumérotation. Les codes de commune
 * déjà attribués sont relus avant génération — ils apparaissent sur des
 * documents papier et ne doivent jamais changer.
 *
 * Ce seeder ne produit AUCUNE donnée fictive : tout ce qu'il écrit vient du
 * fichier et porte est_fictif = false.
 */
class ReferentielTerritorialSeeder extends Seeder
{
    public function __construct(
        private readonly CodificateurTerritorial $codificateur = new CodificateurTerritorial,
        private readonly RepartiteurSites $repartiteur = new RepartiteurSites,
    ) {
    }

    public function run(): void
    {
        $chemin = config('pnvb.fichier_referentiel');

        if (! $chemin || ! is_file($chemin)) {
            $this->command?->warn(
                '  Référentiel territorial ignoré : renseignez PNVB_FICHIER_REFERENTIEL dans .env '
                .'avec le chemin du classeur hiérarchique.'
            );

            return;
        }

        $this->command?->info('  Lecture du classeur… (cela prend une trentaine de secondes)');

        $lecteur = new LecteurReferentielTerritorial($this->codificateur);
        $lu = $lecteur->lire($chemin);

        $this->rappelerCodesExistants();

        $ecartsPopulation = [];
        $regionsSansPlancher = [];

        foreach ($lu['regions'] as $donneesRegion) {
            $resultat = $this->chargerRegion($donneesRegion);
            $ecartsPopulation = [...$ecartsPopulation, ...$resultat['ecarts']];

            if ($resultat['non_couvertes'] > 0) {
                $regionsSansPlancher[$donneesRegion['nom']] = $resultat['non_couvertes'];
            }
        }

        $this->afficherCompteRendu($lu, $ecartsPopulation, $regionsSansPlancher);
    }

    /**
     * Les codes de commune déjà en base sont déclarés au codificateur pour que
     * la résolution des collisions reprenne là où elle s'était arrêtée.
     */
    private function rappelerCodesExistants(): void
    {
        Commune::query()
            ->join('regions', 'regions.id', '=', 'communes.region_id')
            ->select('regions.code as code_region', 'communes.code as code_commune')
            ->cursor()
            ->each(fn ($ligne) => $this->codificateur->declarerCodeExistant(
                $ligne->code_region,
                $ligne->code_commune
            ));
    }

    /** @return array{ecarts: array, non_couvertes: int} */
    private function chargerRegion(array $donneesRegion): array
    {
        // 1. Quotas de sites, calculés sur l'ensemble des localités de la région.
        $localitesPourRepartition = [];

        foreach ($donneesRegion['provinces'] as $cleProvince => $province) {
            foreach ($province['communes'] as $cleCommune => $commune) {
                foreach ($commune['localites'] as $cleLocalite => $localite) {
                    $localitesPourRepartition[] = [
                        'cle' => "{$cleProvince}|{$cleCommune}|{$cleLocalite}",
                        'population' => $localite['population_hommes'] + $localite['population_femmes'],
                    ];
                }
            }
        }

        $sitesRegion = (int) $donneesRegion['sites_declares'];
        $repartition = $this->repartiteur->repartir($localitesPourRepartition, $sitesRegion);
        $quotas = $repartition['quotas'];

        // 2. Région.
        $region = Region::query()->updateOrCreate(
            ['code' => $donneesRegion['code']],
            [
                'nom' => $donneesRegion['nom'],
                'nombre_sites_alloues' => $sitesRegion,
                'population_totale' => $repartition['population'],
                'est_fictif' => false,
            ]
        );

        $ecarts = [];
        $hommesRegion = 0;
        $femmesRegion = 0;

        foreach ($donneesRegion['provinces'] as $cleProvince => $donneesProvince) {
            $province = Province::query()->updateOrCreate(
                ['region_id' => $region->id, 'nom' => $donneesProvince['nom']],
                ['code' => $donneesProvince['code'], 'est_fictif' => false]
            );

            $hommesProvince = 0;
            $femmesProvince = 0;

            foreach ($donneesProvince['communes'] as $cleCommune => $donneesCommune) {
                $resultat = $this->chargerCommune(
                    $region,
                    $province,
                    $donneesCommune,
                    $quotas,
                    "{$cleProvince}|{$cleCommune}"
                );

                $hommesProvince += $resultat['hommes'];
                $femmesProvince += $resultat['femmes'];

                if ($resultat['ecart'] !== 0) {
                    $ecarts[] = [
                        'region' => $donneesRegion['nom'],
                        'commune' => $donneesCommune['nom'],
                        'declaree' => $donneesCommune['population_declaree'],
                        'somme_localites' => $resultat['hommes'] + $resultat['femmes'],
                        'ecart' => $resultat['ecart'],
                    ];
                }
            }

            $province->update([
                'population_hommes' => $hommesProvince,
                'population_femmes' => $femmesProvince,
                'population_totale' => $hommesProvince + $femmesProvince,
            ]);

            $hommesRegion += $hommesProvince;
            $femmesRegion += $femmesProvince;
        }

        $region->update([
            'population_hommes' => $hommesRegion,
            'population_femmes' => $femmesRegion,
            'population_totale' => $hommesRegion + $femmesRegion,
        ]);

        return ['ecarts' => $ecarts, 'non_couvertes' => $repartition['non_couvertes']];
    }

    /** @return array{hommes: int, femmes: int, ecart: int} */
    private function chargerCommune(
        Region $region,
        Province $province,
        array $donneesCommune,
        array $quotas,
        string $prefixeCle
    ): array {
        $existante = Commune::query()
            ->where('province_id', $province->id)
            ->where('nom', $donneesCommune['nom'])
            ->first();

        // Le code d'une commune déjà enregistrée n'est jamais régénéré.
        $code = $existante?->code
            ?? $this->codificateur->codeCommune($region->code, $donneesCommune['nom']);

        $commune = Commune::query()->updateOrCreate(
            ['province_id' => $province->id, 'nom' => $donneesCommune['nom']],
            [
                'region_id' => $region->id,
                'code' => $code,
                'type' => $donneesCommune['type'],
                'population_hommes' => $donneesCommune['population_hommes_declares'],
                'population_femmes' => $donneesCommune['population_femmes_declarees'],
                'population_totale' => $donneesCommune['population_declaree'],
                'est_fictif' => false,
            ]
        );

        $maintenant = now();
        $lignes = [];
        $hommes = 0;
        $femmes = 0;

        foreach ($donneesCommune['localites'] as $cleLocalite => $localite) {
            $quota = $quotas["{$prefixeCle}|{$cleLocalite}"] ?? ['brut' => 0, 'entier' => 0];
            $total = $localite['population_hommes'] + $localite['population_femmes'];

            $lignes[] = [
                'commune_id' => $commune->id,
                'region_id' => $region->id,
                'nom' => $localite['nom'],
                // Renommee par la migration v2 (2026_09_12_100000) : la colonne
                // s'appelle type_localite. Le lecteur du classeur garde la cle
                // interne « type ».
                'type_localite' => $localite['type'],
                'population_hommes' => $localite['population_hommes'],
                'population_femmes' => $localite['population_femmes'],
                'population_totale' => $total,
                'quota_sites_brut' => $quota['brut'],
                'quota_sites' => $quota['entier'],
                'est_fictif' => false,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];

            $hommes += $localite['population_hommes'];
            $femmes += $localite['population_femmes'];
        }

        foreach (array_chunk($lignes, 400) as $lot) {
            Localite::upsert(
                $lot,
                ['commune_id', 'nom'],
                [
                    'region_id', 'type_localite', 'population_hommes', 'population_femmes',
                    'population_totale', 'quota_sites_brut', 'quota_sites', 'updated_at',
                ]
            );
        }

        $commune->update(['population_localites' => $hommes + $femmes]);

        return [
            'hommes' => $hommes,
            'femmes' => $femmes,
            'ecart' => ($hommes + $femmes) - (int) $donneesCommune['population_declaree'],
        ];
    }

    private function afficherCompteRendu(array $lu, array $ecarts, array $regionsSansPlancher): void
    {
        $sortie = $this->command;

        if (! $sortie) {
            return;
        }

        $sortie->newLine();
        $sortie->info(sprintf(
            '  Chargé : %d régions · %d provinces · %d communes · %d localités',
            Region::count(),
            Province::count(),
            Commune::count(),
            Localite::count()
        ));

        $sitesAlloues = (int) Region::sum('nombre_sites_alloues');
        $sitesRepartis = (int) Localite::sum('quota_sites');

        $sortie->info(sprintf(
            '  Sites : %s alloués au plan projet, %s répartis entre les localités',
            number_format($sitesAlloues, 0, ',', ' '),
            number_format($sitesRepartis, 0, ',', ' ')
        ));

        if ($sitesAlloues !== $sitesRepartis) {
            $sortie->warn("  Écart de répartition : {$sitesAlloues} attendus, {$sitesRepartis} répartis.");
        }

        // Le plancher de 1 site par localité est arithmétiquement impossible
        // là où il y a plus de localités que de sites.
        if ($regionsSansPlancher !== []) {
            $sortie->newLine();
            $sortie->warn('  PLANCHER DE 1 SITE PAR LOCALITÉ NON APPLICABLE dans ces régions :');

            foreach ($regionsSansPlancher as $nomRegion => $nombre) {
                $sortie->warn("    {$nomRegion} : {$nombre} localités restent sans site.");
            }

            $sortie->warn('    Les sites ont été attribués aux localités les plus peuplées.');
            $sortie->warn('    À arbitrer : relever le quota régional, ou desservir ces localités');
            $sortie->warn('    depuis le site d\'une localité voisine.');
        }

        if ($ecarts !== []) {
            $sortie->newLine();
            $sortie->warn(sprintf(
                '  %d communes présentent un écart entre la population de leur ligne '
                .'et la somme de leurs localités.',
                count($ecarts)
            ));

            usort($ecarts, fn ($a, $b) => abs($b['ecart']) <=> abs($a['ecart']));

            foreach (array_slice($ecarts, 0, 5) as $ecart) {
                $sortie->warn(sprintf(
                    '    %-28s %-12s déclarée %9s, localités %9s, écart %+d',
                    $ecart['commune'],
                    $ecart['region'],
                    number_format($ecart['declaree'], 0, ',', ' '),
                    number_format($ecart['somme_localites'], 0, ',', ' '),
                    $ecart['ecart']
                ));
            }

            $sortie->warn('    La somme des localités fait foi : c\'est elle qui porte le taux de couverture.');
        }

        if ($lu['anomalies'] !== []) {
            $sortie->newLine();
            $sortie->warn(sprintf('  %d anomalies de lecture :', count($lu['anomalies'])));

            foreach (array_slice($lu['anomalies'], 0, 8) as $anomalie) {
                $sortie->warn('    '.$anomalie);
            }
        }
    }
}
