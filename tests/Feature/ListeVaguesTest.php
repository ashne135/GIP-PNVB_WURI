<?php

use App\Enums\StatutCompte;
use App\Models\Region;
use App\Models\User;
use App\Models\VagueDeploiement;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Non-régression : la liste des vagues.
 *
 * VaguesController::index chargeait la relation `creePar`, absente du modèle :
 * GET /api/v1/vagues répondait une erreur 500. Aucun test ne couvrait cette
 * route, et c'est le back-office (tâche 14) qui l'a révélé en l'appelant.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->admin = User::query()->create([
        'telephone' => '+22670005900',
        'nom' => 'Kaboré', 'prenoms' => 'Aminata',
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $this->admin->assignRole('administrateur_national');

    $region = Region::query()->create(['code' => 'CEN', 'nom' => 'Centre', 'nombre_sites_alloues' => 900]);

    VagueDeploiement::query()->create([
        'code' => 'CEN-2026-V1', 'libelle' => 'Vague Centre 1', 'region_id' => $region->id,
        'date_debut_prevue' => now()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'brouillon', 'cree_par' => $this->admin->id,
    ]);
});

it('liste les vagues sans erreur, avec leur auteur', function () {
    Sanctum::actingAs($this->admin);

    $vague = $this->getJson('/api/v1/vagues')->assertOk()->json('data.data.0');

    expect($vague['code'])->toBe('CEN-2026-V1')
        // La relation se sérialise en cree_par et recouvre la clé étrangère :
        // le client reçoit la personne, pas son identifiant.
        ->and($vague['cree_par']['nom'])->toBe('Kaboré')
        ->and($vague['cree_par']['prenoms'])->toBe('Aminata')
        ->and($vague['region']['nom'])->toBe('Centre');
});
