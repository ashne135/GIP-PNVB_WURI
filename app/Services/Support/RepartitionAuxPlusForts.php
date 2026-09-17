<?php

namespace App\Services\Support;

/**
 * Répartition d'un total entier entre plusieurs clés, au prorata d'un poids,
 * par la méthode des plus forts restes.
 *
 * Garantit que la somme des parts entières vaut EXACTEMENT le total demandé —
 * contrairement à un simple arrondi indépendant de chaque part, qui dérive.
 * Utilisé pour répartir les sites entre localités (RepartiteurSites), les
 * centres entre communes, les volontaires fictifs entre régions.
 */
class RepartitionAuxPlusForts
{
    /**
     * @param  array<string|int, float>  $poids  Poids positifs ou nuls, indexés par clé.
     * @return array<string|int, int>  Parts entières, mêmes clés, somme = $total.
     */
    public static function repartir(array $poids, int $total): array
    {
        $sommePoids = array_sum($poids);

        if ($poids === [] || $total <= 0 || $sommePoids <= 0) {
            return array_map(fn () => 0, $poids);
        }

        $parts = [];
        $restes = [];
        $attribue = 0;

        foreach ($poids as $cle => $valeur) {
            $part = ($valeur / $sommePoids) * $total;
            $entier = (int) floor($part);
            $parts[$cle] = $entier;
            $restes[$cle] = $part - $entier;
            $attribue += $entier;
        }

        arsort($restes);

        foreach (array_keys($restes) as $cle) {
            if ($attribue >= $total) {
                break;
            }

            $parts[$cle]++;
            $attribue++;
        }

        return $parts;
    }
}
