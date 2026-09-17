<?php

namespace App\Jobs;

use App\Services\Kits\ServiceAlertesKits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Les photos de constat annoncées par le téléphone et jamais arrivées
 * (cadrage, sections 11.8 et 13).
 *
 * L'alerte « photos manquantes » n'est pas levée à l'enregistrement d'un
 * mouvement dont les photos suivent : elles partent après les données. Ce job
 * la lève une fois l'échéance passée.
 */
class AlerterPhotosKitsManquantesJob implements ShouldQueue
{
    use Queueable;

    public function handle(ServiceAlertesKits $alertes): void
    {
        $resultat = $alertes->alerterPhotosNonRecues();

        if ($resultat['signales'] > 0) {
            Log::info('photos_kits_non_recues', $resultat);
        }
    }
}
