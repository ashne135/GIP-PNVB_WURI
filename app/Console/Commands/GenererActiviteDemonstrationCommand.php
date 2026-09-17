<?php

namespace App\Console\Commands;

use App\Services\Demonstration\GenerateurActiviteDemonstration;
use Illuminate\Console\Command;

/**
 * Fabrique l'activité fictive de la vague de démonstration.
 *
 *   php artisan pnvb:demo-activite              14 jours
 *   php artisan pnvb:demo-activite --jours=30
 *   php artisan pnvb:demo-activite --refaire    remplace l'activité existante
 *
 * Refusée en production : elle fabrique des rapports visés et des feuilles de
 * présence validées qui n'ont jamais eu lieu.
 */
class GenererActiviteDemonstrationCommand extends Command
{
    protected $signature = 'pnvb:demo-activite
                            {--jours=14 : Nombre de jours écoulés à remplir (1 à 60)}
                            {--refaire : Remplace l’activité de démonstration existante}';

    protected $description = 'Génère une activité fictive (rapports visés, feuilles validées, incidents) sur la vague de démonstration';

    public function handle(GenerateurActiviteDemonstration $generateur): int
    {
        if (app()->environment('production')) {
            $this->error('Refusé en production : cette commande fabrique des données fictives.');

            return self::FAILURE;
        }

        try {
            $resultat = $generateur->generer((int) $this->option('jours'), (bool) $this->option('refaire'));
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['', 'Nombre'], [
            ['Vague', $resultat['vague']],
            ['Recul de la vague (jours)', $resultat['decalage_jours']],
            ['Jours ouvrés remplis', $resultat['jours']],
            ['Rapports visés', $resultat['rapports']],
            ['Feuilles de présence validées', $resultat['feuilles']],
            ['Incidents', $resultat['incidents']],
        ]);

        $this->info('Activité générée, agrégats et couverture recalculés. Tout est marqué fictif.');

        return self::SUCCESS;
    }
}
