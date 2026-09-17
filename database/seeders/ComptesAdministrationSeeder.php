<?php

namespace Database\Seeders;

use App\Enums\RolePnvb;
use App\Enums\StatutCompte;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Comptes d'administration, un par acteur web du cadrage (section 5), pour que
 * la plateforme soit utilisable dès l'installation.
 *
 * ATTENTION — PRODUCTION : ces comptes utilisent des numéros de téléphone et
 * mots de passe PLACEHOLDER, marqués est_fictif = true comme tout le reste des
 * données de démonstration. Le cadrage ne prévoit pas d'import pour ce niveau
 * de compte (ils ne viennent pas du recrutement Google Forms) : en production,
 * le SUPER ADMINISTRATEUR réel doit être créé par une procédure dédiée, hors
 * seeder, et ces comptes de démonstration purgés.
 *
 * Idempotent : recherche par téléphone avant création.
 */
class ComptesAdministrationSeeder extends Seeder
{
    public function run(): void
    {
        $regionDemo = Region::query()->where('code', config('pnvb.demonstration.region_vague', 'BAN'))->first()
            ?? Region::query()->first();

        $comptes = [
            [
                'telephone' => '+22670000001',
                'email' => 'dsi@pnvb.bf',
                'nom' => 'Super',
                'prenoms' => 'Administrateur',
                'role' => RolePnvb::SuperAdministrateur,
                'region_id' => null,
            ],
            [
                'telephone' => '+22670000002',
                'email' => 'administrateur.national@pnvb.bf',
                'nom' => 'National',
                'prenoms' => 'Administrateur',
                'role' => RolePnvb::AdministrateurNational,
                'region_id' => null,
            ],
            [
                'telephone' => '+22670000003',
                'email' => 'chef.antenne@pnvb.bf',
                'nom' => 'Antenne',
                'prenoms' => "Chef d'",
                'role' => RolePnvb::ChefAntenneRegional,
                'region_id' => $regionDemo?->id,
            ],
            [
                'telephone' => '+22670000004',
                'email' => 'observateur@pnvb.bf',
                'nom' => 'Observateur',
                'prenoms' => 'WURI',
                'role' => RolePnvb::Observateur,
                'region_id' => null,
            ],
        ];

        foreach ($comptes as $donnees) {
            $role = $donnees['role'];
            unset($donnees['role']);

            $user = User::query()->updateOrCreate(
                ['telephone' => $donnees['telephone']],
                [
                    ...$donnees,
                    'password' => 'ChangerMoi#2026',
                    'statut_compte' => StatutCompte::Actif->value,
                    'doit_changer_mot_de_passe' => true,
                    'est_fictif' => true,
                ]
            );

            $user->syncRoles([$role->value]);
        }

        $this->command?->info('  Comptes d\'administration : '.count($comptes).' (mot de passe : ChangerMoi#2026)');
        $this->command?->warn(
            '  Placeholder de démonstration : à remplacer par une procédure de création dédiée en production.'
        );
    }
}
