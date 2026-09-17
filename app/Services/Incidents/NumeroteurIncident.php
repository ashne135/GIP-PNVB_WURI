<?php

namespace App\Services\Incidents;

use App\Models\Incident;

/**
 * Le numéro de fiche — INC-2026-000123 — attribué automatiquement (section A).
 *
 * L'unicité est garantie par la base, pas par la confiance : la colonne est
 * UNIQUE, et le compteur est lu sous VERROU D'ÉCRITURE dans la transaction
 * appelante. Deux téléphones qui déclarent au même instant obtiennent bien deux
 * numéros différents — sans cela, le second verrait sa déclaration refusée par
 * une collision, au pire moment.
 *
 * Le compteur repart à 1 chaque année : le numéro se lit et se dicte au
 * téléphone, et l'année y sert de contexte.
 */
class NumeroteurIncident
{
    public function suivant(?int $annee = null): string
    {
        $annee ??= (int) now()->year;
        $prefixe = "INC-{$annee}-";

        // lockForUpdate : le verrou tient jusqu'à la fin de la transaction de
        // l'appelant, qui insère l'incident. Deux déclarations concurrentes se
        // sérialisent au lieu de lire le même dernier numéro.
        $dernier = Incident::query()
            ->where('numero', 'like', $prefixe.'%')
            ->orderByDesc('numero')
            ->lockForUpdate()
            ->value('numero');

        $rang = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

        return $prefixe.str_pad((string) $rang, 6, '0', STR_PAD_LEFT);
    }
}
