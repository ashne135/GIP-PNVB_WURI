<?php

namespace App\Jobs;

use App\Services\Kits\ServiceAlertesKits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Balayage quotidien des kits non restitués (cadrage, section 13).
 *
 * La clôture d'une vague publie déjà ses alertes. Ce job est le FILET : il
 * rattrape les agents dont l'affectation s'est terminée sans clôture de vague —
 * un remplacement, une fin de mission isolée — et les kits dont le délai de
 * restitution expire des jours après la clôture.
 */
class AlerterKitsNonRestituesJob implements ShouldQueue
{
    use Queueable;

    public function handle(ServiceAlertesKits $alertes): void
    {
        $resultat = $alertes->alerterNonRestitues();

        if ($resultat['signales'] === 0 && $resultat['deja_signales'] === 0) {
            return;
        }

        Log::info('kits_non_restitues', $resultat);
    }
}
