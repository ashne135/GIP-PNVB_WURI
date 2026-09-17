<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\DB;

/**
 * Le code d'une alerte — ALR-2026-000412.
 *
 * DÉFAUT CORRIGÉ ICI. Trois endroits calculaient ce code par
 * `Alerte::count() + 1`. Ce compte est faux dès qu'il sert :
 *
 *   - il RÉUTILISE un code si une alerte a été supprimée, alors que la colonne
 *     est UNIQUE : l'insertion échoue, et avec elle toute la transaction qui la
 *     portait — un remplacement d'agent, par exemple ;
 *   - il DONNE LE MÊME CODE à deux alertes émises dans la même seconde, ce qui
 *     est le cas normal quand un incident majeur prévient plusieurs échelons ;
 *   - il repart de 1 à chaque changement d'année tout en comptant les alertes
 *     des années précédentes, donc ne repart pas du tout.
 *
 * Le compteur est ici lu SOUS VERROU, sur le dernier code de l'année en cours,
 * dans la transaction de l'appelant. Deux alertes concurrentes se sérialisent
 * au lieu de se marcher dessus.
 */
class NumeroteurAlerte
{
    public function suivant(?int $annee = null): string
    {
        $annee ??= (int) now()->year;
        $prefixe = "ALR-{$annee}-";

        $dernier = DB::table('alertes')
            ->where('code', 'like', $prefixe.'%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->value('code');

        $rang = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

        return $prefixe.str_pad((string) $rang, 6, '0', STR_PAD_LEFT);
    }
}
