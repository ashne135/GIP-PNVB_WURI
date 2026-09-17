<?php

use App\Enums\StatutCompte;
use App\Models\NatureIncident;
use App\Models\User;
use Database\Seeders\NomenclaturesIncidentSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * LES LISTES DU CANEVAS D'INCIDENT.
 *
 * Ce qu'on protège : le code ne bouge pas, rien ne se supprime, une entrée
 * désactivée quitte les nouveaux formulaires, et seule l'administration
 * nationale y touche.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);
    $this->seed(NomenclaturesIncidentSeeder::class);

    $this->national = compteNom('+22670044001', 'administrateur_national');
    Sanctum::actingAs($this->national);
});

function compteNom(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'NOM', 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

it('liste les quatre listes, entrées inactives comprises', function () {
    NatureIncident::query()->where('code', 'transport')->update(['actif' => false]);

    $reponse = $this->getJson('/api/v1/incidents-nomenclatures')->assertOk();

    expect(collect($reponse->json('data'))->pluck('cle')->all())
        ->toBe(['natures', 'impacts', 'mesures', 'destinataires']);
    expect(count($reponse->json('data.0.entrees')))->toBe(17);
    expect(collect($reponse->json('data.0.entrees'))->firstWhere('code', 'transport')['actif'])->toBeFalse();
});

it('ajoute une entrée avec un code dérivé du libellé, unique dans sa liste', function () {
    $reponse = $this->postJson('/api/v1/incidents-nomenclatures/natures', [
        'libelle' => 'Vol de matériel',
        'libelle_moore' => 'Zu-bʋʋdo',
    ])->assertCreated();

    expect($reponse->json('data.code'))->toBe('vol_de_materiel');
    expect($reponse->json('data.ordre'))->toBe(18);

    // Même libellé : refusé. Libellé voisin donnant le même code : suffixé.
    $this->postJson('/api/v1/incidents-nomenclatures/natures', ['libelle' => 'Vol de matériel'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.libelle.0', 'Cette liste contient déjà une entrée portant ce libellé.');

    $voisin = $this->postJson('/api/v1/incidents-nomenclatures/natures', ['libelle' => 'Vol de matériel !'])
        ->assertCreated();
    expect($voisin->json('data.code'))->toBe('vol_de_materiel_2');
});

it('corrige un libellé sans toucher au code', function () {
    $nature = NatureIncident::query()->where('code', 'transport')->firstOrFail();

    $this->putJson("/api/v1/incidents-nomenclatures/natures/{$nature->id}", [
        'libelle' => 'Transport et véhicule', 'code' => 'vehicule',
    ])->assertStatus(422)->assertJsonPath('data.erreurs.code.0', 'Le code d\'une entrée ne change pas : il sert aux statistiques.');

    $this->putJson("/api/v1/incidents-nomenclatures/natures/{$nature->id}", [
        'libelle' => 'Transport et véhicule', 'libelle_dioula' => 'Mobili',
    ])->assertOk();

    expect($nature->fresh()->only(['code', 'libelle', 'libelle_dioula']))->toBe([
        'code' => 'transport', 'libelle' => 'Transport et véhicule', 'libelle_dioula' => 'Mobili',
    ]);
});

it('désactive une entrée : elle quitte le canevas des nouveaux formulaires', function () {
    $nature = NatureIncident::query()->where('code', 'transport')->firstOrFail();

    $this->putJson("/api/v1/incidents-nomenclatures/natures/{$nature->id}", [
        'libelle' => $nature->libelle, 'actif' => false,
    ])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'désactivé'));

    $canevas = $this->getJson('/api/v1/incidents/canevas')->assertOk();
    expect(collect($canevas->json('data.natures'))->pluck('code'))->not->toContain('transport');
});

it('ne connaît pas de liste inventée et ne supprime rien', function () {
    $this->postJson('/api/v1/incidents-nomenclatures/gravites', ['libelle' => 'Test'])->assertNotFound();

    $nature = NatureIncident::query()->firstOrFail();
    $this->deleteJson("/api/v1/incidents-nomenclatures/natures/{$nature->id}")->assertStatus(405);
});

it('réserve les listes à l\'administration nationale', function () {
    Sanctum::actingAs(compteNom('+22670044002', 'chef_antenne_regional'));

    $this->getJson('/api/v1/incidents-nomenclatures')->assertForbidden();
    $this->postJson('/api/v1/incidents-nomenclatures/natures', ['libelle' => 'Essai'])->assertForbidden();
});
