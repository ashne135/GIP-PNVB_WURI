<?php

namespace App\Jobs;

use App\Services\Incidents\MoteurEscalade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Le moteur d'escalade, passé à intervalle court.
 *
 * L'ÉCHÉANCE EST POSÉE À LA DÉCLARATION, pas calculée par ce job : si le
 * planificateur s'arrête une nuit, aucune échéance n'est perdue — elles sont
 * simplement traitées au redémarrage, et les alertes partent en retard plutôt
 * que jamais.
 */
class EscaladerIncidentsJob implements ShouldQueue
{
    use Queueable;

    public function handle(MoteurEscalade $moteur): void
    {
        $resultat = $moteur->executer();

        if ($resultat['examines'] === 0) {
            return;
        }

        Log::info('incidents_escalade', $resultat);
    }
}
