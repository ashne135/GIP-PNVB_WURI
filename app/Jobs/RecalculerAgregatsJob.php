<?php

namespace App\Jobs;

use App\Services\Agregats\CalculateurAgregats;
use App\Services\Agregats\CalculateurCouverture;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recalcul nocturne des agrégats.
 *
 * DEUX JOURNÉES SONT RECALCULÉES, pas une : celle de la veille, et celle
 * d'avant-veille. Un rapport peut être visé avec un jour de retard — c'est
 * même le cas normal quand un superviseur vise le matin les rapports de la
 * veille — et seuls les rapports VISÉS alimentent les agrégats. Ne recalculer
 * que la veille figerait définitivement une journée incomplète.
 *
 * Le recalcul est idempotent : rejouer une journée écrase ses agrégats sans
 * jamais les cumuler.
 */
class RecalculerAgregatsJob implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>|null  $dates */
    public function __construct(private readonly ?array $dates = null) {}

    public function handle(CalculateurAgregats $agregats, CalculateurCouverture $couverture): void
    {
        $dates = $this->dates ?? [
            now()->subDay()->toDateString(),
            now()->subDays(2)->toDateString(),
        ];

        $resultats = [];

        foreach ($dates as $date) {
            $resultats[$date] = $agregats->recalculerJournee($date);
        }

        // La couverture cumulée est recalculée APRÈS les journées : elle les lit.
        $resultats['couverture'] = $couverture->recalculer();

        Log::info('agregats_recalcules', $resultats);
    }
}
