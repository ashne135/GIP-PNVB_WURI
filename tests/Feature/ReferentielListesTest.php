<?php

use App\Enums\StatutCompte;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Les listes de communes et de localités qui alimentent les formulaires du
 * référentiel. Elles respectent le périmètre comme le reste : un chef
 * d'antenne ne choisit pas une commune de la région d'à côté.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $territoire = function (string $code, string $nom): array {
        $region = Region::query()->create(['code' => $code, 'nom' => $nom, 'nombre_sites_alloues' => 100]);
        $province = Province::query()->create(['region_id' => $region->id, 'code' => $code.'P', 'nom' => $nom.' Province']);
        $commune = Commune::query()->create([
            'province_id' => $province->id, 'region_id' => $region->id,
            'code' => $code.'C', 'nom' => $nom.' Ville', 'type' => 'urbaine',
        ]);
        Localite::query()->create([
            'commune_id' => $commune->id, 'region_id' => $region->id, 'nom' => 'Secteur 1 '.$nom,
            'type_localite' => 'secteur', 'population_totale' => 5200, 'quota_sites' => 2,
        ]);

        return compact('region', 'commune');
    };

    $this->a = $territoire('NAK', 'Nakambe');
    $this->b = $territoire('SAH', 'Sahel');

    $compte = function (string $telephone, string $role): User {
        $user = User::query()->create([
            'telephone' => $telephone, 'nom' => 'Ref', 'prenoms' => $role,
            'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
            'doit_changer_mot_de_passe' => false,
        ]);
        $user->assignRole($role);

        return $user;
    };

    $this->national = $compte('+22670008901', 'administrateur_national');
    $this->chef = $compte('+22670008902', 'chef_antenne_regional');
    $this->chef->update(['region_id' => $this->a['region']->id]);
});

it("liste les communes d'une région pour les formulaires", function () {
    Sanctum::actingAs($this->national);

    $communes = $this->getJson('/api/v1/referentiel/communes?region_id='.$this->a['region']->id)
        ->assertOk()->json('data');

    expect($communes)->toHaveCount(1)
        ->and($communes[0]['nom'])->toBe('Nakambe Ville');
});

it("limite les communes au périmètre du chef d'antenne", function () {
    Sanctum::actingAs($this->chef);

    $noms = collect($this->getJson('/api/v1/referentiel/communes')->assertOk()->json('data'))->pluck('nom');

    expect($noms->all())->toBe(['Nakambe Ville']);
});

it('exige la commune pour lister les localités', function () {
    Sanctum::actingAs($this->national);

    $this->getJson('/api/v1/referentiel/localites')
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.commune_id.0', 'Précisez la commune : la liste complète compte plus de 7 000 localités.');
});

it("refuse les localités d'une commune hors périmètre", function () {
    Sanctum::actingAs($this->chef);

    $this->getJson('/api/v1/referentiel/localites?commune_id='.$this->b['commune']->id)->assertForbidden();
});

it('retrouve un volontaire par son matricule, son nom ou son téléphone', function () {
    $user = User::query()->create([
        'telephone' => '+22671009001', 'nom' => 'Compaoré', 'prenoms' => 'Salif',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole('volontaire_operateur');

    \App\Models\Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-OPK004242',
        'categorie' => 'operateur',
        'statut' => 'operationnel',
    ]);

    Sanctum::actingAs($this->national);

    // Sans recherche, ajuster une affectation obligerait à parcourir des pages
    // entières pour retrouver un seul agent.
    foreach (['OPK004242', 'Compaoré', '71009001'] as $terme) {
        $trouves = $this->getJson('/api/v1/volontaires?recherche='.urlencode($terme))
            ->assertOk()->json('data.data');

        expect($trouves)->toHaveCount(1)
            ->and($trouves[0]['matricule'])->toBe('PNVB-OPK004242');
    }

    expect($this->getJson('/api/v1/volontaires?recherche=introuvable')
        ->assertOk()->json('data.data'))->toBeEmpty();
});

it('rend les localités avec leur population', function () {
    Sanctum::actingAs($this->national);

    $localite = $this->getJson('/api/v1/referentiel/localites?commune_id='.$this->a['commune']->id)
        ->assertOk()->json('data.0');

    expect($localite['nom'])->toBe('Secteur 1 Nakambe')
        ->and($localite['population_totale'])->toBe(5200)
        ->and($localite['type_localite'])->toBe('secteur');
});
