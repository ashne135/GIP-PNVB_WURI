<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Region;
use App\Models\User;
use App\Models\Volontaire;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Authentification par NUMÉRO DE TÉLÉPHONE (cadrage, section 6).
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);

    $this->region = Region::query()->create([
        'code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 661,
    ]);

    $this->agent = User::query()->create([
        'telephone' => '+22670123456',
        'nom' => 'Ouédraogo',
        'prenoms' => 'Aminata',
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $this->agent->assignRole('volontaire_operateur');

    Volontaire::query()->create([
        'user_id' => $this->agent->id,
        'matricule' => 'PNVB-OPK000001',
        'categorie' => CategorieVolontaire::Operateur->value,
        'statut' => 'operationnel',
    ]);

    RateLimiter::clear('connexion:+22670123456|127.0.0.1');
});

it('connecte un agent avec son numéro de téléphone et renvoie un jeton', function () {
    $reponse = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ]);

    $reponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Connexion réussie.')
        ->assertJsonStructure(['success', 'message', 'data' => ['jeton']]);

    expect($reponse->json('data.jeton'))->not->toBeEmpty();
});

it('annonce au client si le signal hors zone est bloqué', function () {
    $connecter = fn () => $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ])->assertOk();

    // Par défaut le blocage est inactif : l'agent SIGNALE, le serveur constate
    // l'écart, et seul le superviseur valide.
    expect($connecter()->json('data.reglages.bloquer_signal_hors_zone'))->toBeFalse();

    App\Models\Parametre::query()->create([
        'cle' => 'presence.bloquer_signal_hors_zone', 'valeur' => '1', 'type_valeur' => 'booleen',
        'groupe' => 'presence', 'libelle' => 'Refuser un signal hors zone',
        'modifiable_par' => 'super_administrateur',
    ]);

    RateLimiter::clear('connexion:+22670123456|127.0.0.1');

    // Le téléphone doit le savoir : sans cela il confirmerait une arrivée que
    // le serveur refusera, et l'agent ne l'apprendrait qu'au retour du réseau.
    expect($connecter()->json('data.reglages.bloquer_signal_hors_zone'))->toBeTrue();
});

it('accepte le numéro quelle que soit sa forme de saisie', function (string $saisie) {
    $this->postJson('/api/v1/connexion', [
        'telephone' => $saisie,
        'mot_de_passe' => 'MotDePasse#1',
    ])->assertOk()->assertJsonPath('success', true);
})->with([
    '70 12 34 56',
    '70123456',
    '070123456',
    '+226 70 12 34 56',
    '0022670123456',
]);

it('renvoie rôles, permissions et périmètre à la connexion', function () {
    $reponse = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ])->assertOk();

    // Le point de vigilance de la tâche 2 : les trois sont présents.
    expect($reponse->json('data.roles'))->toContain('volontaire_operateur');
    expect($reponse->json('data.permissions'))->toContain('rapports.saisir');
    expect($reponse->json('data.perimetre.niveau'))->toBe('lui_meme');

    // Et la fiche volontaire, dont le mobile a besoin dès le premier écran.
    expect($reponse->json('data.volontaire.matricule'))->toBe('PNVB-OPK000001');
    expect($reponse->json('data.volontaire.categorie'))->toBe('operateur');
});

it('refuse un mot de passe incorrect sans révéler si le numéro existe', function () {
    $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'mauvais',
    ])->assertStatus(401)->assertJsonPath('message', 'Numéro de téléphone ou mot de passe incorrect.');

    // Numéro inexistant : message strictement identique.
    $this->postJson('/api/v1/connexion', [
        'telephone' => '+22679999999',
        'mot_de_passe' => 'peu importe',
    ])->assertStatus(401)->assertJsonPath('message', 'Numéro de téléphone ou mot de passe incorrect.');
});

it('refuse la connexion d\'un compte inactif en expliquant pourquoi', function () {
    $this->agent->update(['statut_compte' => StatutCompte::Inactif->value]);

    $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ])->assertStatus(403)
        ->assertJsonPath('data.statut_compte', 'inactif')
        ->assertJsonFragment(['message' => "Votre compte n'est pas encore activé. Il le sera dès votre affectation à une mission."]);
});

it('refuse la connexion d\'un assistant dont l\'accès est fermé entre deux vagues', function () {
    $this->agent->update(['statut_compte' => StatutCompte::Ferme->value]);

    $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ])->assertStatus(403)->assertJsonPath('data.statut_compte', 'ferme');
});

it('laisse se connecter un agent en statut disponible, entre deux vagues', function () {
    $this->agent->update(['statut_compte' => StatutCompte::Disponible->value]);

    $reponse = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ])->assertOk();

    // Il se connecte, mais ne peut rien saisir.
    expect($reponse->json('data.utilisateur.peut_saisir'))->toBeFalse();
});

it('limite les tentatives de connexion répétées', function () {
    foreach (range(1, 5) as $ignore) {
        $this->postJson('/api/v1/connexion', [
            'telephone' => '+22670123456',
            'mot_de_passe' => 'mauvais',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ])->assertStatus(429)->assertJsonPath('success', false);
});

it('répond en français simple quand le formulaire est incomplet', function () {
    $reponse = $this->postJson('/api/v1/connexion', [])->assertStatus(422);

    expect($reponse->json('message'))->toBe('Certaines informations sont incorrectes ou manquantes.');
    expect($reponse->json('data.erreurs.telephone.0'))->toBe('Entrez votre numéro de téléphone.');
    expect($reponse->json('data.erreurs.mot_de_passe.0'))->toBe('Entrez votre mot de passe.');
});

it('déconnecte en révoquant le jeton utilisé', function () {
    $jeton = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670123456',
        'mot_de_passe' => 'MotDePasse#1',
    ])->json('data.jeton');

    $this->withToken($jeton)->postJson('/api/v1/deconnexion')->assertOk();

    // Dans un test, l'application vit d'une requête à l'autre et le guard garde
    // en mémoire l'utilisateur déjà résolu. On l'oublie explicitement pour que
    // la requête suivante rejoue vraiment l'authentification par jeton — ce que
    // fait naturellement chaque requête HTTP en production.
    $this->app['auth']->forgetGuards();

    // Le jeton ne vaut plus rien.
    $this->withToken($jeton)->getJson('/api/v1/moi')->assertStatus(401);
});
