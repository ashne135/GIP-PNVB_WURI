<?php

use App\Enums\StatutCompte;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * LA PUBLICATION D'UNE ALERTE DESCENDANTE (cadrage, section 10).
 *
 * LA RÈGLE QUE CES TESTS PROTÈGENT AVANT TOUTES LES AUTRES : on ne publie que
 * dans son périmètre. Le chef d'antenne porte le droit de publier — mais une
 * alerte nationale partirait aux douze régions, et le cadrage l'interdit.
 *
 * Et celle qui la suit : une alerte publiée à la main est toujours de type
 * « descendante ». Laisser choisir le type permettrait de fabriquer une alerte
 * qui ressemble à une détection automatique du système.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->regionA = territoireAlerte('NRD', 'Nord');
    $this->regionB = territoireAlerte('SUD', 'Sud-Ouest');

    $this->national = compteAlerte('+22670007001', 'administrateur_national');

    $this->chefA = compteAlerte('+22670007002', 'chef_antenne_regional');
    $this->chefA->update(['region_id' => $this->regionA['region']->id]);
});

function compteAlerte(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'Alr', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function territoireAlerte(string $code, string $nom): array
{
    $region = Region::query()->create(['code' => $code, 'nom' => $nom, 'nombre_sites_alloues' => 100]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => $code.'P', 'nom' => $nom.' Province']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => $code.'C', 'nom' => $nom.' Ville', 'type' => 'urbaine',
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => $code.'-C001', 'nom' => 'Centre '.$nom, 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    return compact('region', 'commune', 'centre');
}

it('publie une alerte nationale, numérotée et signée par son émetteur', function () {
    Sanctum::actingAs($this->national);

    $reponse = $this->postJson('/api/v1/alertes', [
        'titre' => 'Fermeture exceptionnelle des centres',
        'message' => 'Les centres resteront fermés le 2 novembre.',
        'niveau' => 'important',
        'portee' => 'nationale',
    ])->assertCreated();

    expect($reponse->json('data.code'))->toStartWith('ALR-')
        // Publiée par un humain : jamais déguisée en détection automatique.
        ->and($reponse->json('data.type'))->toBe('descendante')
        ->and($reponse->json('data.emetteur_user_id'))->toBe($this->national->id)
        ->and($reponse->json('message'))->toContain('toutes les régions');
});

it("refuse à un chef d'antenne de publier au national ou vers un rôle", function () {
    Sanctum::actingAs($this->chefA);

    foreach (['nationale', 'role'] as $portee) {
        $this->postJson('/api/v1/alertes', [
            'titre' => 'Consigne', 'message' => 'Message de consigne.',
            'niveau' => 'info', 'portee' => $portee,
            ...($portee === 'role' ? ['role_cible' => 'volontaire_superviseur'] : []),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'douze régions'));
    }

    expect(Alerte::query()->count())->toBe(0);
});

it("laisse un chef d'antenne publier dans sa région, jamais dans une autre", function () {
    Sanctum::actingAs($this->chefA);

    $this->postJson('/api/v1/alertes', [
        'titre' => 'Réunion régionale', 'message' => 'Réunion lundi à 9 h.',
        'niveau' => 'info', 'portee' => 'regionale',
        'region_id' => $this->regionA['region']->id,
    ])->assertCreated();

    $this->postJson('/api/v1/alertes', [
        'titre' => 'Chez le voisin', 'message' => 'Message hors périmètre.',
        'niveau' => 'info', 'portee' => 'regionale',
        'region_id' => $this->regionB['region']->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Vous ne pouvez publier une alerte que dans votre propre région.');

    expect(Alerte::query()->count())->toBe(1);
});

it("refuse un centre d'une autre région", function () {
    Sanctum::actingAs($this->chefA);

    $this->postJson('/api/v1/alertes', [
        'titre' => 'Consigne', 'message' => 'Message de consigne.',
        'niveau' => 'info', 'portee' => 'centre',
        'centre_id' => $this->regionB['centre']->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', "Ce centre n'appartient pas à votre région.");
});

it('publie vers un centre de sa région, et NOMME ce centre', function () {
    Sanctum::actingAs($this->chefA);

    $reponse = $this->postJson('/api/v1/alertes', [
        'titre' => 'Rupture de consommables',
        'message' => 'Un réapprovisionnement arrive mardi.',
        'niveau' => 'important',
        'portee' => 'centre',
        'centre_id' => $this->regionA['centre']->id,
    ])->assertCreated();

    // Le message de retour NOMME le centre destinataire, ce qui oblige le
    // chargement de la relation à s'exécuter. Sans ce cas en succès, la portée
    // « centre » n'était éprouvée qu'en refus : le chargement ne partait
    // jamais, et une colonne erronée serait restée invisible jusqu'à l'écran.
    expect($reponse->json('message'))->toContain('NRD-C001')
        ->and($reponse->json('data.portee'))->toBe('centre')
        ->and($reponse->json('data.centre_id'))->toBe($this->regionA['centre']->id);
});

it('exige la cible que la portée annonce', function () {
    Sanctum::actingAs($this->national);

    $this->postJson('/api/v1/alertes', [
        'titre' => 'Sans cible', 'message' => 'Message.',
        'niveau' => 'info', 'portee' => 'regionale',
    ])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.region_id.0', 'Choisissez la région destinataire.');
});

it("refuse une échéance déjà passée, qui ne s'afficherait jamais", function () {
    Sanctum::actingAs($this->national);

    $this->postJson('/api/v1/alertes', [
        'titre' => 'Expirée', 'message' => 'Message.',
        'niveau' => 'info', 'portee' => 'nationale',
        'expire_le' => now()->subDay()->toIso8601String(),
    ])->assertStatus(422);
});

it("n'ouvre la publication qu'à qui porte la permission", function () {
    Sanctum::actingAs(compteAlerte('+22670007009', 'volontaire_superviseur'));

    $this->postJson('/api/v1/alertes', [
        'titre' => 'Consigne', 'message' => 'Message.',
        'niveau' => 'info', 'portee' => 'nationale',
    ])->assertForbidden();
});

it('rend l’alerte régionale visible au chef de cette région, et invisible à l’autre', function () {
    Sanctum::actingAs($this->national);

    $this->postJson('/api/v1/alertes', [
        'titre' => 'Consigne du Nord', 'message' => 'Message régional.',
        'niveau' => 'info', 'portee' => 'regionale',
        'region_id' => $this->regionA['region']->id,
    ])->assertCreated();

    Sanctum::actingAs($this->chefA);
    expect($this->getJson('/api/v1/alertes')->assertOk()->json('data.data'))->toHaveCount(1);

    $chefB = compteAlerte('+22670007003', 'chef_antenne_regional');
    $chefB->update(['region_id' => $this->regionB['region']->id]);

    Sanctum::actingAs($chefB);
    expect($this->getJson('/api/v1/alertes')->assertOk()->json('data.data'))->toBeEmpty();
});
