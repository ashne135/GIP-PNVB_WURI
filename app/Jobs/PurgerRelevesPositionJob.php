<?php

namespace App\Jobs;

use App\Models\RelevePosition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Purge quotidienne des relevés de position (cadrage, section 8.4).
 *
 * Conservation LIMITÉE et PARAMÉTRABLE (défaut 90 jours), purge automatique.
 * Chaque relevé porte sa propre date de purge, calculée à l'écriture : ce job
 * ne fait que supprimer ce qui est échu, il ne recalcule aucune échéance.
 */
class PurgerRelevesPositionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        RelevePosition::query()->aPurger()->chunkById(1000, function ($lot) {
            RelevePosition::query()->whereIn('id', $lot->pluck('id'))->delete();
        });
    }
}
