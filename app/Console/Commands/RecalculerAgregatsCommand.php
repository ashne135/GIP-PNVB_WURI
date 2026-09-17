<?php

namespace App\Console\Commands;

use App\Services\Agregats\CalculateurAgregats;
use App\Services\Agregats\CalculateurCouverture;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recalcul des agrégats sur une période.
 *
 * À quoi ça sert, concrètement : le job nocturne ne reprend que les deux
 * dernières journées. Quand une correction touche un mois passé — un lot de
 * rapports visés en retard, une feuille de présence corrigée par le chef
 * d'antenne — c'est cette commande qui rattrape la période, sans quoi le
 * tableau de bord garderait des chiffres qu'on sait faux.
 *
 *   php artisan pnvb:recalculer-agregats --du=2026-09-01 --au=2026-09-14
 */
class RecalculerAgregatsCommand extends Command
{
    protected $signature = 'pnvb:recalculer-agregats
                            {--du= : Premier jour (AAAA-MM-JJ), par défaut il y a 30 jours}
                            {--au= : Dernier jour (AAAA-MM-JJ), par défaut hier}';

    protected $description = 'Recalcule les agrégats et la couverture sur une période';

    public function handle(CalculateurAgregats $agregats, CalculateurCouverture $couverture): int
    {
        $au = Carbon::parse($this->option('au') ?: now()->subDay()->toDateString());
        $du = Carbon::parse($this->option('du') ?: $au->copy()->subDays(29)->toDateString());

        if ($du->greaterThan($au)) {
            $this->error('La date de début est postérieure à la date de fin.');

            return self::FAILURE;
        }

        $jours = $du->diffInDays($au) + 1;
        $barre = $this->output->createProgressBar((int) $jours);
        $barre->start();

        $totaux = ['sites' => 0, 'centres' => 0, 'regions' => 0];

        for ($jour = $du->copy(); $jour->lessThanOrEqualTo($au); $jour->addDay()) {
            $resultat = $agregats->recalculerJournee($jour->toDateString());

            foreach ($totaux as $cle => $valeur) {
                $totaux[$cle] = $valeur + $resultat[$cle];
            }

            $barre->advance();
        }

        $barre->finish();
        $this->newLine(2);

        // La couverture cumulée se recalcule une seule fois, à la fin : elle lit
        // les journées qu'on vient d'écrire.
        $this->info('Recalcul de la couverture cumulée…');
        $couverture = $couverture->recalculer($au->toDateString());

        $this->table(
            ['Niveau', 'Lignes écrites'],
            [
                ['Sites-jours', $totaux['sites']],
                ['Centres-jours', $totaux['centres']],
                ['Régions-jours', $totaux['regions']],
                ['Couverture des localités', $couverture['localites']],
                ['Mois-régions', $couverture['regions_mois']],
            ]
        );

        $this->info("Période du {$du->toDateString()} au {$au->toDateString()} recalculée.");

        return self::SUCCESS;
    }
}
