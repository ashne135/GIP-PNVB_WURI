<?php

use App\Enums\StatutCompte;
use App\Models\Consentement;
use App\Models\Parametre;
use App\Models\User;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * LA CHARTE SERVIE PAR LE SERVEUR.
 *
 * Un seul texte par version, le même pour le web et le mobile — et jamais un
 * texte de repli : faire accepter une autre version que celle affichée, c'est
 * enregistrer un consentement à un texte non lu.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $compte = function (string $telephone, string $role): User {
        $user = User::query()->create([
            'telephone' => $telephone, 'nom' => 'Charte', 'prenoms' => 'Test',
            'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
            'doit_changer_mot_de_passe' => false,
        ]);
        $user->assignRole($role);

        return $user;
    };

    $this->agent = $compte('+22670008801', 'volontaire_operateur');
    $this->superAdmin = $compte('+22670008802', 'super_administrateur');
});

it('rend le texte de la version courante', function () {
    Sanctum::actingAs($this->agent);

    $texte = $this->getJson('/api/v1/charte')->assertOk()->json('data');

    expect($texte['version'])->toBe('2026.1')
        ->and($texte['provisoire'])->toBeTrue()
        ->and($texte['titre'])->toBe('Charte du volontaire')
        ->and($texte['articles'])->toHaveCount(7)
        ->and($texte['engagement'])->not->toBeEmpty();
});

it("dit que le texte manque plutôt que d'en montrer un autre", function () {
    Parametre::query()->where('cle', 'comptes.charte_version_courante')->first()->update(['valeur' => '2099.1']);

    Sanctum::actingAs($this->agent);

    $this->getJson('/api/v1/charte')
        ->assertStatus(503)
        ->assertJsonPath('message', "Le texte de la charte n'est pas disponible pour le moment. Prévenez votre superviseur.");
});

it("refuse d'enregistrer l'acceptation d'une version qui n'est pas celle affichée", function () {
    Sanctum::actingAs($this->agent);

    $this->postJson('/api/v1/charte/accepter', ['version_charte' => '2025.9'])
        ->assertStatus(409)
        ->assertJsonPath('data.version_courante', '2026.1');

    expect(Consentement::query()->count())->toBe(0);

    $this->postJson('/api/v1/charte/accepter', ['version_charte' => '2026.1'])->assertOk();

    expect(Consentement::query()->where('version_charte', '2026.1')->count())->toBe(1);
});

it("refuse de changer de version de charte tant que son texte n'existe pas", function () {
    Sanctum::actingAs($this->superAdmin);

    $this->putJson('/api/v1/parametres/comptes.charte_version_courante', ['valeur' => '2099.1'])
        ->assertStatus(422)
        ->assertJsonPath(
            'data.erreurs.valeur.0',
            "Aucun texte de charte n'existe pour la version « 2099.1 » : déposez-le avant de changer de version."
        );

    expect(Parametre::valeur('comptes.charte_version_courante'))->toBe('2026.1');
});
