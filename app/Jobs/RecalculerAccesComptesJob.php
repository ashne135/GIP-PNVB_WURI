<?php

namespace App\Jobs;

use App\Services\Comptes\ServiceCycleDeVieCompte;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job planifié de recalcul des accès (cadrage, section 6).
 *
 * L'ouverture et la fermeture des accès sont pilotées par CE job, qui
 * s'exécute :
 *   - à l'ouverture d'une vague (déclenché explicitement par le service de
 *     validation de vague, pour chaque affectation qui devient active) ;
 *   - à la clôture d'une vague (idem, à la fermeture) ;
 *   - QUOTIDIENNEMENT en filet de sécurité (voir routes/console.php), pour
 *     rattraper tout écart — une vague dont la date de fin est dépassée sans
 *     clôture explicite, par exemple.
 *
 * Aucun changement d'accès n'est fait à la main.
 */
class RecalculerAccesComptesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  int[]|null  $idsVolontaires  Limite le recalcul à ces volontaires.
     *                                       Null : recalcule tout le monde (filet
     *                                       de sécurité quotidien).
     */
    public function __construct(private readonly ?array $idsVolontaires = null)
    {
    }

    public function handle(ServiceCycleDeVieCompte $service): void
    {
        \App\Models\Volontaire::query()
            ->with('user')
            ->when($this->idsVolontaires !== null, fn ($q) => $q->whereIn('id', $this->idsVolontaires))
            ->whereHas('user', fn ($q) => $q->where('statut_compte', '!=', 'inactif'))
            ->chunkById(500, function ($volontaires) use ($service) {
                foreach ($volontaires as $volontaire) {
                    $service->recalculer($volontaire);
                }
            });
    }
}
