<?php

namespace App\Services\Demonstration;

use App\Models\Commune;
use App\Models\Localite;
use App\Models\Parametre;
use App\Services\Referentiel\CodificateurTerritorial;
use App\Services\Support\RepartitionAuxPlusForts;
use Illuminate\Support\Facades\DB;

/**
 * Dérive centres et sites du référentiel réel, pour développer et démontrer
 * (cadrage, Partie D : « Centres — dérivés des localités, avec codification
 * automatique — en attendant votre liste réelle ou la règle de regroupement »).
 *
 * RÈGLE DE REGROUPEMENT RETENUE, FAUTE DE RÈGLE CLIENT :
 *   Nombre de centres = paramètre dispositif.nombre_kits_principaux (966),
 *   réparti entre communes au prorata de leur nombre de sites, aux plus forts
 *   restes. Chaque centre reçoit 1 kit — le cas « 2 kits, 2 sites en parallèle »
 *   n'est pas simulé ici, il reste possible en base (centres.nombre_kits) mais
 *   n'est pas produit par CE générateur.
 *
 *   Cette règle fait correspondre EXACTEMENT le nombre de centres générés aux
 *   effectifs du cadrage : 966 centres, 966 OPK opérationnels, 966 assistants
 *   opérationnels, 483 superviseurs opérationnels (966 ÷ 2).
 *
 * LES 32 KITS DE ZONE À DÉFIS SÉCURITAIRES NE SONT PAS GÉNÉRÉS ICI : le
 * cadrage laisse ouverte la liste des 16 communes concernées (Partie D,
 * « Ce qui reste ouvert »). Les générer supposerait de désigner ces communes
 * sans base documentaire — ce générateur s'en abstient.
 *
 * Les sites d'une commune sont répartis en séquence entre ses centres, à
 * quotas égaux : c'est la table centres.code_ordre qui sert de rang pour la
 * tournée (site.ordre_tournee).
 */
class GenerateurCentresEtSites
{
    public function __construct(
        private readonly CodificateurTerritorial $codificateur = new CodificateurTerritorial,
    ) {
    }

    /** @return array{centres: int, sites: int, communes_sans_site: int} */
    public function generer(): array
    {
        $nombreCentresCible = Parametre::entier('dispositif.nombre_kits_principaux', 966);

        $communes = Commune::query()
            ->select('communes.id', 'communes.region_id', 'communes.code as commune_code', 'regions.code as region_code')
            ->join('regions', 'regions.id', '=', 'communes.region_id')
            ->get()
            ->map(function ($commune) {
                $commune->total_sites = (int) Localite::query()
                    ->where('commune_id', $commune->id)
                    ->sum('quota_sites');

                return $commune;
            })
            ->filter(fn ($commune) => $commune->total_sites > 0)
            ->values();

        $poidsParCommune = $communes->pluck('total_sites', 'id')->all();
        $centresParCommune = RepartitionAuxPlusForts::repartir($poidsParCommune, $nombreCentresCible);

        $compteurCentres = 0;
        $compteurSites = 0;
        $communesSansSite = 0;

        foreach ($communes as $commune) {
            $nombreCentres = max(1, $centresParCommune[$commune->id] ?? 0);

            $localites = Localite::query()
                ->where('commune_id', $commune->id)
                ->where('quota_sites', '>', 0)
                ->orderByDesc('quota_sites')
                ->get(['id', 'quota_sites']);

            if ($localites->isEmpty()) {
                $communesSansSite++;

                continue;
            }

            // Numérotation des centres existants de cette commune : jamais
            // régénérée pour un centre déjà en base (codes figés à vie).
            $numeroSuivant = 1 + (int) DB::table('centres')
                ->where('commune_id', $commune->id)
                ->count();

            $centresCrees = [];

            for ($i = 0; $i < $nombreCentres; $i++) {
                $code = $this->codificateur->codeCentre(
                    $commune->region_code,
                    $commune->commune_code,
                    $numeroSuivant + $i
                );

                $idCentre = DB::table('centres')->insertGetId([
                    'commune_id' => $commune->id,
                    'region_id' => $commune->region_id,
                    'code' => $code,
                    'nom' => "Centre {$code}",
                    'nombre_kits' => 1,
                    'est_permanent' => false,
                    'statut' => 'planifie',
                    'est_fictif' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $centresCrees[] = $idCentre;
                $compteurCentres++;
            }

            // Répartition des sites de la commune entre ses centres, au
            // prorata du quota de chaque localité, aux plus forts restes —
            // pour que chaque centre reçoive une charge de sites équilibrée.
            $poidsLocalites = $localites->pluck('quota_sites', 'id')->all();
            $sitesParCentre = RepartitionAuxPlusForts::repartir(
                array_combine($centresCrees, array_fill(0, count($centresCrees), 1.0)),
                (int) $localites->sum('quota_sites')
            );

            $fileCentres = $this->genererFileRoundRobin($centresCrees, $sitesParCentre);
            $rangParCentre = array_fill_keys($centresCrees, 0);
            $lignesSites = [];
            $curseurFile = 0;

            foreach ($localites as $localite) {
                for ($n = 1; $n <= $localite->quota_sites; $n++) {
                    $idCentre = $fileCentres[$curseurFile % count($fileCentres)];
                    $curseurFile++;

                    $rangParCentre[$idCentre]++;
                    $codeCentre = DB::table('centres')->where('id', $idCentre)->value('code');

                    $lignesSites[] = [
                        'centre_id' => $idCentre,
                        'localite_id' => $localite->id,
                        'region_id' => $commune->region_id,
                        'code' => $this->codificateur->codeSite($codeCentre, $rangParCentre[$idCentre]),
                        'nom' => "Site {$n}",
                        'rayon_zone_metres' => Parametre::entier('presence.rayon_zone_site_metres', 500),
                        'ordre_tournee' => $rangParCentre[$idCentre],
                        'statut' => 'planifie',
                        'est_fictif' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $compteurSites++;
                }
            }

            foreach (array_chunk($lignesSites, 500) as $lot) {
                DB::table('sites')->insert($lot);
            }
        }

        return [
            'centres' => $compteurCentres,
            'sites' => $compteurSites,
            'communes_sans_site' => $communesSansSite,
        ];
    }

    /**
     * File dans laquelle chaque centre apparaît autant de fois que son quota
     * de sites : la parcourir en boucle distribue les sites d'une commune
     * entre ses centres au prorata, sans dépendre de l'ordre des localités.
     *
     * @param  int[]  $centres
     * @param  array<int, int>  $quotas
     * @return int[]
     */
    private function genererFileRoundRobin(array $centres, array $quotas): array
    {
        $file = [];

        foreach ($centres as $idCentre) {
            $repetitions = max(1, $quotas[$idCentre] ?? 1);

            for ($i = 0; $i < $repetitions; $i++) {
                $file[] = $idCentre;
            }
        }

        return $file;
    }
}
