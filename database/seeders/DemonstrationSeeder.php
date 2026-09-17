<?php

namespace Database\Seeders;

use App\Services\Demonstration\GenerateurActiviteDemonstration;
use App\Services\Demonstration\GenerateurCentresEtSites;
use App\Services\Demonstration\GenerateurKitsFictifs;
use App\Services\Demonstration\GenerateurVagueDemonstration;
use App\Services\Demonstration\GenerateurVolontairesFictifs;
use Illuminate\Database\Seeder;

/**
 * Données fictives cohérentes, pour développer et démontrer (cadrage, Partie B
 * livrable 5, et Partie D).
 *
 * Chaque enregistrement produit ici porte est_fictif = true : le tableau de
 * bord pourra, référentiel par référentiel, le purger au chargement du réel
 * (centres et sites : fichier réel du client ; volontaires : import des
 * retenus — tâche 3 ; composition des kits : canevas de traçabilité client).
 *
 * Ordre imposé : le référentiel réel (régions à localités) doit déjà être en
 * base — centres et sites en dérivent ; les volontaires et les kits doivent
 * exister avant la vague de démonstration, qui les affecte.
 */
class DemonstrationSeeder extends Seeder
{
    public function run(): void
    {
        $sortie = $this->command;

        $resultatCentres = (new GenerateurCentresEtSites)->generer();
        $sortie?->info(sprintf(
            '  Centres et sites : %s centres, %s sites (%d communes sans site alloué)',
            number_format($resultatCentres['centres'], 0, ',', ' '),
            number_format($resultatCentres['sites'], 0, ',', ' '),
            $resultatCentres['communes_sans_site']
        ));

        $nombreKits = (new GenerateurKitsFictifs)->generer();
        $sortie?->info('  Kits : '.number_format($nombreKits, 0, ',', ' '));

        $resultatVolontaires = (new GenerateurVolontairesFictifs)->generer();
        $sortie?->info('  Volontaires : '.number_format($resultatVolontaires['crees'], 0, ',', ' ').' au total');

        foreach ($resultatVolontaires['par_categorie'] as $categorie => $effectif) {
            $sortie?->line(sprintf(
                '    %-12s %4d recrutés — %4d opérationnels, %3d réserve',
                ucfirst($categorie),
                $effectif['recrutes'],
                $effectif['operationnels'],
                $effectif['reserve']
            ));
        }

        $resultatVague = (new GenerateurVagueDemonstration)->generer();

        if ($resultatVague === null) {
            $sortie?->warn(
                '  Vague de démonstration ignorée : région introuvable ou aucun compte '
                .'administrateur national. Exécutez ComptesAdministrationSeeder avant celui-ci.'
            );

            return;
        }

        $sortie?->info(sprintf(
            '  Vague de démonstration : %s (%s) — %d centres ouverts, %d agents engagés',
            $resultatVague['vague'],
            $resultatVague['region'],
            $resultatVague['centres'],
            $resultatVague['agents_engages']
        ));

        // Sans rapport visé ni feuille validée, le tableau de bord resterait vide.
        try {
            $activite = app(GenerateurActiviteDemonstration::class)->generer(14);

            $sortie?->info(sprintf(
                '  Activité de démonstration : %d jours ouvrés, %d rapports visés, %d feuilles validées, '
                .'%d incidents (vague reculée de %d jours)',
                $activite['jours'],
                $activite['rapports'],
                $activite['feuilles'],
                $activite['incidents'],
                $activite['decalage_jours']
            ));
        } catch (\DomainException $e) {
            $sortie?->warn('  Activité de démonstration ignorée : '.$e->getMessage());
        }
    }
}
