<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orchestration du peuplement.
 *
 * L'ordre compte : les rôles et les paramètres d'abord, le référentiel réel
 * ensuite, la démonstration en dernier — elle s'appuie sur tout le reste.
 *
 * La démonstration est écartée hors environnement local ou de test : on ne veut
 * pas de 2 778 volontaires fictifs sur le serveur de production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Rôles et permissions');
        $this->call(RolesEtPermissionsSeeder::class);

        $this->command?->info('Paramètres du dispositif');
        $this->call(ParametresSeeder::class);

        $this->command?->info('Nomenclatures du module incidents');
        $this->call(NomenclaturesIncidentSeeder::class);

        $this->command?->info('Référentiel territorial');
        $this->call(ReferentielTerritorialSeeder::class);

        $this->command?->info('Comptes d\'administration');
        $this->call(ComptesAdministrationSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->command?->info('Données de démonstration');
            $this->call(DemonstrationSeeder::class);
        }
    }
}
