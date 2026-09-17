<?php

namespace App\Services\Referentiel;

/**
 * Répartition des sites entre les localités d'une région.
 *
 * Le fichier source porte un prorata INTRA-RÉGIONAL, mais ses formules ne sont
 * pas calculées : seules 556 valeurs sur 7 453 sont lisibles. Le quota est donc
 * recalculé ici, selon la règle validée :
 *
 *   1. quota brut = population de la localité ÷ (population de la région ÷ sites de la région)
 *   2. PLANCHER de 1 site par localité
 *   3. le solde régional est réparti aux PLUS FORTS RESTES
 *
 * La somme des quotas d'une région vaut exactement son nombre de sites alloués.
 *
 * CAS LIMITE : deux régions comptent plus de localités que de sites (Goulmou,
 * 290 localités pour 126 sites ; Koulsé, 985 pour 765). Le plancher de 1 y est
 * arithmétiquement impossible. La méthode sert alors les localités les plus
 * peuplées et SIGNALE les localités laissées sans site — elle ne dépasse jamais
 * le total régional en silence.
 */
class RepartiteurSites
{
    /**
     * @param  array<int, array{cle: string|int, population: int}>  $localites
     * @return array{quotas: array<string|int, array{brut: float, entier: int}>, non_couvertes: int, population: int}
     */
    public function repartir(array $localites, int $sitesRegion): array
    {
        $populationRegion = array_sum(array_column($localites, 'population'));
        $nombreLocalites = count($localites);

        if ($nombreLocalites === 0 || $sitesRegion <= 0) {
            return ['quotas' => [], 'non_couvertes' => 0, 'population' => $populationRegion];
        }

        $diviseur = $populationRegion > 0 ? $populationRegion / $sitesRegion : 0.0;

        $bruts = [];
        foreach ($localites as $localite) {
            $bruts[$localite['cle']] = $diviseur > 0
                ? $localite['population'] / $diviseur
                : 0.0;
        }

        if ($nombreLocalites > $sitesRegion) {
            return $this->servirLesPlusPeuplees($localites, $bruts, $sitesRegion, $populationRegion);
        }

        // Plancher de 1 site par localité, puis solde aux plus forts restes.
        $solde = $sitesRegion - $nombreLocalites;
        $residuels = [];

        foreach ($bruts as $cle => $brut) {
            $residuels[$cle] = max(0.0, $brut - 1.0);
        }

        $sommeResiduels = array_sum($residuels);
        $quotas = [];
        $restes = [];
        $attribues = 0;

        foreach ($bruts as $cle => $brut) {
            $part = $sommeResiduels > 0 ? ($residuels[$cle] / $sommeResiduels) * $solde : 0.0;
            $entier = (int) floor($part);

            $quotas[$cle] = ['brut' => round($brut, 6), 'entier' => 1 + $entier];
            $restes[$cle] = $part - $entier;
            $attribues += 1 + $entier;
        }

        // Les sièges restants vont aux plus forts restes.
        arsort($restes);

        foreach (array_keys($restes) as $cle) {
            if ($attribues >= $sitesRegion) {
                break;
            }

            $quotas[$cle]['entier']++;
            $attribues++;
        }

        return ['quotas' => $quotas, 'non_couvertes' => 0, 'population' => $populationRegion];
    }

    /**
     * Plus de localités que de sites : on sert les plus peuplées, une seule fois
     * chacune, et on compte celles qui restent sans site.
     */
    private function servirLesPlusPeuplees(
        array $localites,
        array $bruts,
        int $sitesRegion,
        int $populationRegion
    ): array {
        usort($localites, fn ($a, $b) => $b['population'] <=> $a['population']);

        $quotas = [];
        $rang = 0;

        foreach ($localites as $localite) {
            $cle = $localite['cle'];
            $quotas[$cle] = [
                'brut' => round($bruts[$cle], 6),
                'entier' => $rang < $sitesRegion ? 1 : 0,
            ];
            $rang++;
        }

        return [
            'quotas' => $quotas,
            'non_couvertes' => max(0, count($localites) - $sitesRegion),
            'population' => $populationRegion,
        ];
    }
}
