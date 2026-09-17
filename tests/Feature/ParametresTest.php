<?php

use App\Enums\StatutCompte;
use App\Models\Parametre;
use App\Models\User;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * Les paramètres du dispositif.
 *
 * Deux choses protégées ici : la séparation des pouvoirs — le national
 * consulte, seul le super administrateur modifie — et la validité de chaque
 * valeur, parce qu'un seuil mal saisi ne lève aucune erreur : il change en
 * silence le comportement du tirage, de l'escalade ou de la présence.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $compte = function (string $telephone, string $role): User {
        $user = User::query()->create([
            'telephone' => $telephone, 'nom' => 'Param', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
            'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
            'doit_changer_mot_de_passe' => false,
        ]);
        $user->assignRole($role);

        return $user;
    };

    $this->superAdmin = $compte('+22670007901', 'super_administrateur');
    $this->national = $compte('+22670007902', 'administrateur_national');
    $this->chefAntenne = $compte('+22670007903', 'chef_antenne_regional');
});

it("montre les paramètres au national, en consultation seule", function () {
    Sanctum::actingAs($this->national);

    $donnees = $this->getJson('/api/v1/parametres')->assertOk()->json('data');

    expect($donnees['peut_modifier'])->toBeFalse();

    $seuil = collect($donnees['parametres'])->firstWhere('cle', 'affectation.centres_par_superviseur');

    // La valeur est typée : un entier, pas la chaîne stockée en base.
    expect($seuil['valeur'])->toBe(2)
        ->and($seuil['groupe'])->toBe('affectation');
});

it('refuse la modification au national : seul le super administrateur modifie', function () {
    Sanctum::actingAs($this->national);

    $this->putJson('/api/v1/parametres/affectation.centres_par_superviseur', ['valeur' => 3])
        ->assertForbidden();

    expect(Parametre::entier('affectation.centres_par_superviseur'))->toBe(2);
});

it("refuse la consultation à qui n'a pas le droit", function () {
    Sanctum::actingAs($this->chefAntenne);

    $this->getJson('/api/v1/parametres')->assertForbidden();
});

it('applique immédiatement une modification du super administrateur, et la journalise', function () {
    // La valeur est mise en cache à la première lecture…
    expect(Parametre::entier('affectation.centres_par_superviseur'))->toBe(2);

    Sanctum::actingAs($this->superAdmin);

    $this->putJson('/api/v1/parametres/affectation.centres_par_superviseur', ['valeur' => 3])
        ->assertOk()
        ->assertJsonPath('data.valeur', 3);

    // …et le cache est vidé : le code lit la nouvelle valeur tout de suite.
    expect(Parametre::entier('affectation.centres_par_superviseur'))->toBe(3);

    $trace = Activity::query()->where('log_name', 'parametre')->latest('id')->first();

    expect($trace->causer_id)->toBe($this->superAdmin->id)
        ->and($trace->properties['ancienne_valeur'])->toBe('2')
        ->and($trace->properties['nouvelle_valeur'])->toBe('3');
});

it('refuse une valeur du mauvais type', function () {
    Sanctum::actingAs($this->superAdmin);

    $this->putJson('/api/v1/parametres/affectation.centres_par_superviseur', ['valeur' => 'deux'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.valeur.0', 'Ce paramètre attend un nombre entier.');

    $this->putJson('/api/v1/parametres/incidents.delai_escalade_minutes.niveau_4', ['valeur' => -5])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.valeur.0', 'Ce paramètre ne peut pas être négatif.');
});

it("contrôle le format d'une heure de service", function () {
    Sanctum::actingAs($this->superAdmin);

    $this->putJson('/api/v1/parametres/presence.heure_debut_service', ['valeur' => '7h'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.valeur.0', 'Indiquez une heure au format HH:MM, par exemple 07:30.');

    $this->putJson('/api/v1/parametres/presence.heure_debut_service', ['valeur' => '07:15'])->assertOk();

    expect(Parametre::valeur('presence.heure_debut_service'))->toBe('07:15');
});

it('refuse une matrice de notification vide, ou un rôle qui alerterait tout le pays', function () {
    Sanctum::actingAs($this->superAdmin);

    $url = '/api/v1/parametres/incidents.notification.niveau_1';

    $this->putJson($url, ['valeur' => []])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.valeur.0', 'Cochez au moins un rôle : une matrice vide ne préviendrait personne.');

    // Un opérateur serait prévenu au national : les 966 du pays.
    $this->putJson($url, ['valeur' => ['volontaire_superviseur', 'volontaire_operateur']])
        ->assertStatus(422);

    $this->putJson($url, ['valeur' => ['volontaire_superviseur', 'chef_antenne_regional']])
        ->assertOk()
        ->assertJsonPath('data.valeur', ['volontaire_superviseur', 'chef_antenne_regional']);
});

it('enregistre un booléen sans ambiguïté', function () {
    Sanctum::actingAs($this->superAdmin);

    $this->putJson('/api/v1/parametres/comptes.canal_secours_sms', ['valeur' => false])
        ->assertOk()
        ->assertJsonPath('data.valeur', false);

    expect(Parametre::booleen('comptes.canal_secours_sms', true))->toBeFalse();
});

it("ne journalise rien quand la valeur n'a pas changé", function () {
    Sanctum::actingAs($this->superAdmin);

    $this->putJson('/api/v1/parametres/affectation.centres_par_superviseur', ['valeur' => 2])
        ->assertOk()
        ->assertJsonPath('message', 'Aucune modification : la valeur est identique.');

    expect(Activity::query()->where('log_name', 'parametre')->count())->toBe(0);
});
