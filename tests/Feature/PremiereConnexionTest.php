<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Consentement;
use App\Models\User;
use App\Models\Volontaire;
use Database\Seeders\NomenclaturesIncidentSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;

/**
 * Première connexion : mot de passe initial à USAGE UNIQUE (section 6) et
 * acceptation de la charte avant toute collecte de position (section 8.4).
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->agent = User::query()->create([
        'telephone' => '+22670123456',
        'nom' => 'Kaboré', 'prenoms' => 'Issa',
        'password' => 'MotDePasseInitial#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => true,
    ]);
    $this->agent->assignRole('volontaire_superviseur');

    Volontaire::query()->create([
        'user_id' => $this->agent->id,
        'matricule' => 'PNVB-SUP000001',
        'categorie' => CategorieVolontaire::Superviseur->value,
        'statut' => 'operationnel',
    ]);
});

it('signale à la connexion les deux actions requises', function () {
    $reponse = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasseInitial#1',
    ])->assertOk();

    expect($reponse->json('data.actions_requises.changer_mot_de_passe'))->toBeTrue();
    expect($reponse->json('data.actions_requises.accepter_charte'))->toBeTrue();
    expect($reponse->json('data.actions_requises.version_charte'))->toBe('2026.1');
});

it('bloque le reste de l\'API tant que le mot de passe initial n\'est pas changé', function () {
    $jeton = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasseInitial#1',
    ])->json('data.jeton');

    $this->withToken($jeton)->getJson('/api/v1/referentiel/regions')
        ->assertStatus(403)
        ->assertJsonPath('data.action_requise', 'changer_mot_de_passe')
        ->assertJsonPath('message', "Vous devez d'abord changer votre mot de passe pour continuer.");
});

it('change le mot de passe et révoque les autres jetons', function () {
    $premierJeton = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456', 'mot_de_passe' => 'MotDePasseInitial#1',
    ])->json('data.jeton');

    $secondJeton = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456', 'mot_de_passe' => 'MotDePasseInitial#1',
    ])->json('data.jeton');

    $this->withToken($secondJeton)->postJson('/api/v1/mot-de-passe/changer', [
        'mot_de_passe_actuel' => 'MotDePasseInitial#1',
        'nouveau_mot_de_passe' => 'NouveauSecret2026',
        'nouveau_mot_de_passe_confirmation' => 'NouveauSecret2026',
    ])->assertOk()->assertJsonPath('message', 'Votre mot de passe a été changé.');

    expect($this->agent->fresh()->doit_changer_mot_de_passe)->toBeFalse();

    // Dans un test, l'application vit d'une requête à l'autre et le guard garde
    // en mémoire l'utilisateur déjà résolu. On l'oublie explicitement pour que
    // la requête suivante rejoue vraiment l'authentification par jeton — ce que
    // fait naturellement chaque requête HTTP en production.
    $this->app['auth']->forgetGuards();

    // Le mot de passe initial a pu circuler par bordereau papier : les autres
    // sessions ouvertes avec lui doivent tomber.
    $this->withToken($premierJeton)->getJson('/api/v1/moi')->assertStatus(401);
    $this->withToken($secondJeton)->getJson('/api/v1/moi')->assertOk();
});

it('refuse le changement si le mot de passe actuel est faux', function () {
    $jeton = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456', 'mot_de_passe' => 'MotDePasseInitial#1',
    ])->json('data.jeton');

    $this->withToken($jeton)->postJson('/api/v1/mot-de-passe/changer', [
        'mot_de_passe_actuel' => 'faux',
        'nouveau_mot_de_passe' => 'NouveauSecret2026',
        'nouveau_mot_de_passe_confirmation' => 'NouveauSecret2026',
    ])->assertStatus(422)->assertJsonPath('message', 'Le mot de passe actuel est incorrect.');
});

it('bloque l\'API tant que la charte n\'est pas acceptée, puis la débloque', function () {
    $this->agent->update(['doit_changer_mot_de_passe' => false]);

    $jeton = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456', 'mot_de_passe' => 'MotDePasseInitial#1',
    ])->json('data.jeton');

    $this->withToken($jeton)->getJson('/api/v1/referentiel/regions')
        ->assertStatus(403)
        ->assertJsonPath('data.action_requise', 'accepter_charte');

    $this->withToken($jeton)->postJson('/api/v1/charte/accepter')->assertOk();

    // Le consentement est TRACÉ : version, date, adresse IP.
    $consentement = Consentement::query()->where('user_id', $this->agent->id)->first();
    expect($consentement)->not->toBeNull();
    expect($consentement->version_charte)->toBe('2026.1');
    expect($consentement->accepte_le)->not->toBeNull();

    $this->withToken($jeton)->getJson('/api/v1/referentiel/regions')->assertOk();
});
