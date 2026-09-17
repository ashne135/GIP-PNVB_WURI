<?php

namespace App\Services\Affectation;

use App\Models\Centre;
use Illuminate\Support\Collection;
use Random\Randomizer;

/**
 * Appariement des centres deux par deux, sous CONTRAINTE DE PROXIMITÉ
 * (cadrage, section 7).
 *
 * « Les 2 centres d'un même superviseur doivent être GÉOGRAPHIQUEMENT PROCHES :
 * même commune si possible, sinon distance maximale paramétrable. Un tirage
 * purement aléatoire donnerait à un superviseur deux centres distants de
 * 200 km, qu'il ne pourrait pas superviser. »
 *
 * L'appariement procède par cercles concentriques, du plus contraint au moins
 * contraint, et ne DÉGRADE qu'après avoir épuisé le niveau précédent :
 *
 *   1. même COMMUNE          — l'idéal, un superviseur à pied d'œuvre
 *   2. même PROVINCE, sous la distance maximale
 *   3. même PROVINCE, distance inconnue (coordonnées absentes)
 *   4. par défaut            — apparié quand même, mais SIGNALÉ
 *
 * Le quatrième cercle ne rejette pas la paire : laisser un centre sans
 * superviseur serait pire que de signaler un trajet long. La proposition
 * affiche l'anomalie, et l'administrateur ajuste avant de valider.
 */
class AppariementCentres
{
    /**
     * @param  Collection<int, Centre>  $centres
     * @return array<int, array{
     *     centre_a: Centre, centre_b: Centre|null,
     *     meme_commune: bool, distance_km: float|null,
     *     contrainte_respectee: bool, motif: string|null
     * }>
     */
    public function apparier(Collection $centres, Randomizer $tirage, float $distanceMaxKm): array
    {
        // L'ordre de départ est FIGÉ par l'identifiant, puis mélangé par le
        // tirage : sans cela, l'ordre implicite de la base ferait diverger deux
        // exécutions de la même graine.
        $restants = $centres->sortBy('id')->values()->all();
        $restants = $tirage->shuffleArray($restants);

        $paires = [];

        // ---- Cercle 1 : même commune ----
        $paires = [...$paires, ...$this->apparierDansLeGroupe(
            $restants,
            fn (Centre $a, Centre $b) => $a->commune_id === $b->commune_id,
            'meme_commune'
        )];

        $restants = $this->nonApparies($restants, $paires);

        // ---- Cercle 2 et 3 : même province, distance acceptable ou inconnue ----
        $paires = [...$paires, ...$this->apparierDansLeGroupe(
            $restants,
            function (Centre $a, Centre $b) use ($distanceMaxKm) {
                if ($a->commune?->province_id !== $b->commune?->province_id) {
                    return false;
                }

                $distance = $a->distanceKmVers($b);

                // Distance inconnue : le référentiel ne porte pas de
                // coordonnées. On accepte sur la seule proximité
                // administrative, et l'anomalie le dira.
                return $distance === null || $distance <= $distanceMaxKm;
            },
            'meme_province'
        )];

        $restants = $this->nonApparies($restants, $paires);

        // ---- Cercle 4 : par défaut, et signalé ----
        while (count($restants) >= 2) {
            $a = array_shift($restants);
            $b = array_shift($restants);

            $paires[] = $this->construirePaire($a, $b, $distanceMaxKm);
        }

        // Un centre esseulé : il aura un superviseur pour lui tout seul.
        if ($restants !== []) {
            $seul = array_shift($restants);

            $paires[] = [
                'centre_a' => $seul,
                'centre_b' => null,
                'meme_commune' => false,
                'distance_km' => null,
                'contrainte_respectee' => false,
                'motif' => "Le centre {$seul->code} reste seul dans son unité de supervision : "
                    .'le nombre de centres ouverts est impair.',
            ];
        }

        return $paires;
    }

    /**
     * Apparie les centres qui satisfont un critère, en parcourant la liste dans
     * l'ordre déjà mélangé — donc de façon reproductible.
     *
     * @param  Centre[]  $centres
     */
    private function apparierDansLeGroupe(array $centres, callable $critere, string $niveau): array
    {
        $paires = [];
        $pris = [];

        foreach ($centres as $i => $a) {
            if (isset($pris[$a->id])) {
                continue;
            }

            foreach ($centres as $j => $b) {
                if ($j <= $i || isset($pris[$b->id]) || $a->id === $b->id) {
                    continue;
                }

                if (! $critere($a, $b)) {
                    continue;
                }

                $pris[$a->id] = true;
                $pris[$b->id] = true;
                $paires[] = $this->construirePaire($a, $b, PHP_FLOAT_MAX, $niveau);
                break;
            }
        }

        return $paires;
    }

    /** @param  Centre[]  $centres */
    private function nonApparies(array $centres, array $paires): array
    {
        $apparies = [];

        foreach ($paires as $paire) {
            $apparies[$paire['centre_a']->id] = true;

            if ($paire['centre_b']) {
                $apparies[$paire['centre_b']->id] = true;
            }
        }

        return array_values(array_filter($centres, fn (Centre $c) => ! isset($apparies[$c->id])));
    }

    private function construirePaire(
        Centre $a,
        Centre $b,
        float $distanceMaxKm,
        ?string $niveau = null
    ): array {
        $memeCommune = $a->commune_id === $b->commune_id;
        $distance = $a->distanceKmVers($b);
        $motif = null;

        if ($memeCommune) {
            $respectee = true;
        } elseif ($distance !== null) {
            $respectee = $distance <= $distanceMaxKm;
            $motif = $respectee ? null : sprintf(
                'Les centres %s et %s sont distants de %.1f km, au-delà du maximum de %.0f km.',
                $a->code, $b->code, $distance, $distanceMaxKm
            );
        } else {
            // Sans coordonnées, la distance ne peut pas être vérifiée. On
            // accepte si la province est la même, on signale sinon : c'est un
            // fait à porter à la connaissance de l'administrateur, pas une
            // approximation à masquer.
            $memeProvince = $a->commune?->province_id === $b->commune?->province_id;
            $respectee = $memeProvince;
            $motif = $memeProvince
                ? null
                : "Les centres {$a->code} et {$b->code} ne sont ni dans la même commune ni dans "
                    .'la même province, et leurs coordonnées sont inconnues : proximité invérifiable.';
        }

        return [
            'centre_a' => $a,
            'centre_b' => $b,
            'meme_commune' => $memeCommune,
            'distance_km' => $distance,
            'contrainte_respectee' => $respectee,
            'motif' => $motif,
        ];
    }
}
