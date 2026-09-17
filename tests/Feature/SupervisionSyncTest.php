<?php

use App\Enums\StatutCompte;
use App\Models\SyncLot;
use App\Models\User;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * LA SUPERVISION DES SYNCHRONISATIONS — outil de diagnostic du DSI.
 *
 * Ce qu'on protège : réservée au droit journal.consulter, et un rejet n'y est
 * décrit que par son type, son code et son motif — jamais par ses données.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->dsi = compteSup('+22670077001', 'DSI', 'super_administrateur');
    $this->agent = compteSup('+22670077010', 'OUEDRAOGO', 'volontaire_operateur');

    SyncLot::query()->create([
        'user_id' => $this->agent->id, 'uuid_lot' => (string) Str::uuid(),
        'recu_le' => now()->subHour(), 'nb_elements' => 2, 'nb_acceptes' => 2, 'nb_rejetes' => 0,
        'detail' => ['acceptes' => [], 'rejetes' => []], 'duree_ms' => 120,
    ]);

    SyncLot::query()->create([
        'user_id' => $this->agent->id, 'uuid_lot' => (string) Str::uuid(),
        'recu_le' => now(), 'nb_elements' => 2, 'nb_acceptes' => 1, 'nb_rejetes' => 1,
        'detail' => [
            'acceptes' => [['rang' => 0, 'uuid_client' => 'a', 'type' => 'signal_arrivee', 'id' => 5, 'action' => 'cree']],
            'rejetes' => [[
                'rang' => 1, 'uuid_client' => 'b', 'type' => 'releve_position',
                'code' => 'regle_metier', 'motif' => 'Relevé hors des heures de service.',
                'reessayer' => false,
                'details' => ['latitude' => 11.9456789, 'longitude' => -3.0021],
            ]],
        ],
        'duree_ms' => 340,
    ]);
});

function compteSup(string $telephone, string $nom, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => $nom, 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

it('liste les lots, rejets nommés, sans jamais livrer leurs données', function () {
    Sanctum::actingAs($this->dsi);

    $reponse = $this->getJson('/api/v1/sync/supervision')->assertOk();

    expect($reponse->json('data.total'))->toBe(2);
    expect($reponse->json('data.data.0.user.nom'))->toBe('OUEDRAOGO');
    expect($reponse->json('data.data.0.rejets.0'))->toBe([
        'rang' => 1,
        'type' => 'releve_position',
        'code' => 'regle_metier',
        'code_libelle' => 'Règle métier non satisfaite',
        'motif' => 'Relevé hors des heures de service.',
        'reessayer' => false,
    ]);

    // Ni les coordonnées, ni l'identifiant du téléphone.
    expect($reponse->getContent())->not->toContain('11.9456789')->not->toContain('uuid');
});

it('isole les lots en échec et retrouve un agent', function () {
    Sanctum::actingAs($this->dsi);

    $this->getJson('/api/v1/sync/supervision?avec_rejets=1')->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson('/api/v1/sync/supervision?recherche=70077010')->assertOk()->assertJsonPath('data.total', 2);
    $this->getJson('/api/v1/sync/supervision?recherche=inconnu')->assertOk()->assertJsonPath('data.total', 0);
});

it('reste fermée à qui n\'a pas le droit du journal', function () {
    Sanctum::actingAs(compteSup('+22670077002', 'NAT', 'administrateur_national'));
    $this->getJson('/api/v1/sync/supervision')->assertForbidden();

    Sanctum::actingAs($this->agent);
    $this->getJson('/api/v1/sync/supervision')->assertForbidden();
});
