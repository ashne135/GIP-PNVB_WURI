<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Region;
use App\Models\User;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * LES COMPTES D'ADMINISTRATION.
 *
 * Ce qu'on protège :
 *   - la séparation des pouvoirs : seul le super administrateur crée un compte ;
 *   - le mot de passe provisoire : rendu une fois, jamais journalisé ;
 *   - la fermeture : motif, sessions coupées, connexion refusée ;
 *   - les garde-fous : ni soi-même, ni le dernier super administrateur, ni un
 *     compte de volontaire.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->region = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 10]);

    $this->dsi = compteAdm('+22670033001', 'DSI', 'super_administrateur');
    $this->national = compteAdm('+22670033002', 'NATIONAL', 'administrateur_national');

    Sanctum::actingAs($this->dsi);
});

function compteAdm(string $telephone, string $nom, string $role, ?int $regionId = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => $nom, 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false, 'region_id' => $regionId,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function creerChefAdm(array $surcharges = [])
{
    return test()->postJson('/api/v1/administration/comptes', [
        'nom' => 'Kaboré',
        'prenoms' => 'Awa',
        'telephone' => '70 44 55 66',
        'email' => 'Awa.Kabore@pnvb.bf',
        'role' => 'chef_antenne_regional',
        'region_id' => test()->region->id,
        ...$surcharges,
    ]);
}

it('crée un chef d\'antenne et rend le mot de passe provisoire une seule fois', function () {
    $reponse = creerChefAdm()->assertCreated();

    $motDePasse = $reponse->json('data.mot_de_passe_provisoire');
    expect($motDePasse)->toBeString()->and(strlen($motDePasse))->toBeGreaterThanOrEqual(10);
    expect($reponse->json('message'))->toContain('ne sera plus jamais affiché');

    $compte = User::query()->where('telephone', '+22670445566')->firstOrFail();
    expect($compte->nom)->toBe('KABORÉ');
    expect($compte->email)->toBe('awa.kabore@pnvb.bf');
    expect($compte->hasRole('chef_antenne_regional'))->toBeTrue();
    expect($compte->region_id)->toBe($this->region->id);
    expect($compte->doit_changer_mot_de_passe)->toBeTrue();
    expect(Hash::check($motDePasse, $compte->password))->toBeTrue();

    // Le mot de passe n'apparaît nulle part au journal.
    $journal = Activity::query()->get()->toJson();
    expect($journal)->not->toContain($motDePasse);

    // Et la liste ne le rend jamais.
    $liste = $this->getJson('/api/v1/administration/comptes')->assertOk();
    expect($liste->getContent())->not->toContain($motDePasse);
    expect(collect($liste->json('data.comptes.data'))->pluck('telephone'))->toContain('+22670445566');
});

it('exige une région pour un chef d\'antenne, et n\'en garde aucune pour un rôle national', function () {
    creerChefAdm(['region_id' => null])->assertStatus(422)->assertJsonValidationErrors('region_id', 'data.erreurs');

    creerChefAdm(['role' => 'observateur', 'telephone' => '70445567', 'email' => null])->assertCreated();

    expect(User::query()->where('telephone', '+22670445567')->value('region_id'))->toBeNull();
});

it('refuse un rôle de volontaire et un numéro déjà pris', function () {
    creerChefAdm(['role' => 'volontaire_operateur'])->assertStatus(422)->assertJsonValidationErrors('role', 'data.erreurs');
    creerChefAdm(['telephone' => '70033002'])->assertStatus(422)->assertJsonValidationErrors('telephone', 'data.erreurs');
});

it('réserve la gestion des comptes au super administrateur', function () {
    Sanctum::actingAs($this->national);

    $this->getJson('/api/v1/administration/comptes')->assertForbidden();
    // Le refus arrive AVANT la validation : un formulaire vide ne dit pas « champ manquant ».
    $this->postJson('/api/v1/administration/comptes', [])->assertForbidden();
});

it('ferme un compte avec motif, coupe ses sessions et refuse sa connexion', function () {
    $chef = compteAdm('+22670033003', 'CHEF', 'chef_antenne_regional', $this->region->id);
    $chef->createToken('mobile');

    $this->postJson("/api/v1/administration/comptes/{$chef->id}/fermer", ['motif' => ''])
        ->assertStatus(422)->assertJsonValidationErrors('motif', 'data.erreurs');

    $this->postJson("/api/v1/administration/comptes/{$chef->id}/fermer", ['motif' => 'Départ du projet'])
        ->assertOk();

    expect($chef->fresh()->statut_compte)->toBe(StatutCompte::Ferme);
    expect($chef->tokens()->count())->toBe(0);

    $journal = Activity::query()->where('description', "Compte d'administration fermé")->firstOrFail();
    expect($journal->properties['motif'])->toBe('Départ du projet');
    expect($journal->causer_id)->toBe($this->dsi->id);

    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/connexion', ['telephone' => '+22670033003', 'mot_de_passe' => 'MotDePasse#1'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Votre compte a été fermé par l\'administration. Adressez-vous au super administrateur.');

    Sanctum::actingAs($this->dsi);
    $this->postJson("/api/v1/administration/comptes/{$chef->id}/rouvrir", ['motif' => 'Retour en poste'])->assertOk();
    expect($chef->fresh()->statut_compte)->toBe(StatutCompte::Actif);
});

it('ne laisse ni se fermer soi-même, ni fermer le dernier super administrateur', function () {
    $this->postJson("/api/v1/administration/comptes/{$this->dsi->id}/fermer", ['motif' => 'Essai de fermeture'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Vous ne pouvez pas fermer votre propre compte.');

    $autreDsi = compteAdm('+22670033004', 'DSI2', 'super_administrateur');

    // Deux super administrateurs : l'un peut fermer l'autre…
    $this->postJson("/api/v1/administration/comptes/{$autreDsi->id}/fermer", ['motif' => 'Doublon de compte'])->assertOk();

    // …mais le dernier actif ne se ferme pas. Par l'écran, l'auteur est
    // lui-même super administrateur : le garde-fou se vérifie donc au service.
    expect(fn () => app(\App\Services\Comptes\ServiceComptesAdministration::class)
        ->fermer($this->dsi, 'Dernier compte', $this->national))
        ->toThrow(DomainException::class, 'dernier super administrateur actif');

    expect($this->dsi->fresh()->statut_compte)->toBe(StatutCompte::Actif);
});

it('refuse de changer son propre rôle', function () {
    $this->putJson("/api/v1/administration/comptes/{$this->dsi->id}", [
        'nom' => 'DSI', 'prenoms' => 'Test', 'role' => 'observateur',
    ])->assertStatus(422)->assertJsonPath('message', 'Vous ne pouvez pas changer votre propre rôle : demandez-le à un autre super administrateur.');
});

it('change le rôle d\'un compte et coupe ses sessions', function () {
    $national = $this->national;
    $national->createToken('web');

    $this->putJson("/api/v1/administration/comptes/{$national->id}", [
        'nom' => 'National', 'prenoms' => 'Admin', 'role' => 'controleur_terrain',
        'region_id' => $this->region->id, 'telephone' => '+22670099999',
    ])->assertStatus(422)->assertJsonValidationErrors('telephone', 'data.erreurs');

    $this->putJson("/api/v1/administration/comptes/{$national->id}", [
        'nom' => 'National', 'prenoms' => 'Admin', 'role' => 'controleur_terrain',
        'region_id' => $this->region->id,
    ])->assertOk()->assertJsonPath('data.compte.role', 'controleur_terrain');

    $national->refresh();
    expect($national->hasRole('controleur_terrain'))->toBeTrue();
    expect($national->hasRole('administrateur_national'))->toBeFalse();
    expect($national->region_id)->toBe($this->region->id);
    expect($national->tokens()->count())->toBe(0);
});

it('réinitialise un mot de passe perdu, et l\'ancien cesse de marcher', function () {
    $reponse = $this->postJson("/api/v1/administration/comptes/{$this->national->id}/mot-de-passe")->assertOk();

    $nouveau = $reponse->json('data.mot_de_passe_provisoire');
    $compte = $this->national->fresh();

    expect(Hash::check($nouveau, $compte->password))->toBeTrue();
    expect(Hash::check('MotDePasse#1', $compte->password))->toBeFalse();
    expect($compte->doit_changer_mot_de_passe)->toBeTrue();
});

it('ne touche jamais un compte de volontaire', function () {
    $agent = compteAdm('+22670033010', 'AGENT', 'volontaire_operateur');
    Volontaire::query()->create([
        'user_id' => $agent->id, 'matricule' => 'PNVB-OPK033010',
        'categorie' => CategorieVolontaire::Operateur->value, 'statut' => 'operationnel',
    ]);

    $this->postJson("/api/v1/administration/comptes/{$agent->id}/fermer", ['motif' => 'Tentative manuelle'])
        ->assertStatus(422);

    expect($agent->fresh()->statut_compte)->toBe(StatutCompte::Actif);

    $liste = $this->getJson('/api/v1/administration/comptes')->assertOk();
    expect(collect($liste->json('data.comptes.data'))->pluck('telephone'))->not->toContain('+22670033010');
});
