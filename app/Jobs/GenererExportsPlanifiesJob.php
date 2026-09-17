<?php

namespace App\Jobs;

use App\Models\Parametre;
use App\Services\Exports\ServiceExportsPlanifies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * La production nocturne des exports (cadrage, tâche 18).
 *
 * LA JOURNÉE PRODUITE EST LA VEILLE, jamais le jour même : un export du jour en
 * cours serait incomplet par construction, et son fichier laisserait croire le
 * contraire.
 *
 * L'activation est un paramètre : désactivée, la plateforme ne produit plus
 * aucun fichier et les exports restent disponibles à la demande, depuis les
 * écrans concernés. La purge suit dans la foulée — garder indéfiniment des
 * fichiers nominatifs n'a aucun intérêt et finit par peser.
 */
class GenererExportsPlanifiesJob implements ShouldQueue
{
    use Queueable;

    public function handle(ServiceExportsPlanifies $exports): void
    {
        if (! Parametre::booleen('exports.actif', true)) {
            return;
        }

        $bilan = $exports->genererJournee(now()->subDay()->toDateString());
        $purges = $exports->purger(Parametre::entier('exports.retention_jours', 30));

        Log::info('exports_planifies', [...$bilan, 'purges' => $purges]);
    }
}
