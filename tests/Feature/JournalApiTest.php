<?php

use App\Enums\StatutCompte;
use App\Models\User;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * L'ÉCRAN DU JOURNAL D'ACTIVITÉ (cadrage, section 5).
 *
 * LA RÈGLE QUE CES TESTS PROTÈGENT D'ABORD : le journal est réservé au SUPER
 * ADMINISTRATEUR. Il traverse les douze régions et nomme des personnes ; même
 * l'administrateur national n'y a pas accès, et c'est la séparation des
 * pouvoirs voulue par le cadrage — celui qui agit n'est pas celui qui relit.
 *
 * LA SECONDE : un acte SANS AUTEUR reste lisible en tant que tel. Quand le
 * planificateur nocturne recalcule un accès, personne n'a rien décidé ; le
 * filtre « sans auteur » existe précisément pour isoler ce qui s'est fait tout
 * seul, au lieu de le confondre avec un oubli d'enregistrement.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    // LA CRÉATION D'UN COMPTE EST ELLE-MÊME JOURNALISÉE. Sans couper la
    // journalisation ici, les deux comptes de la mise en place compteraient
    // parmi les actes qu'on éprouve, et le test mesurerait ses propres
    // fixtures au lieu de l'écran.
    activity()->disableLogging();
    $this->superAdmin = compteJrn('+22670010001', 'super_administrateur');
    $this->national = compteJrn('+22670010002', 'administrateur_national');
    activity()->enableLogging();

    // Trois actes : deux signés, un fait par le système.
    activity('referentiel')->causedBy($this->national)->log('Centre créé');
    activity('vague')->causedBy($this->national)
        ->withProperties(['graine' => 777])
        ->log('Tirage effectué avec la graine 777');
    activity('compte')->withProperties(['evenement' => 'recalcul_planifie'])
        ->log('Changement de statut de compte : actif → disponible');
});

function compteJrn(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'JAPI', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

it('rend le journal au super administrateur, le plus récent d’abord', function () {
    Sanctum::actingAs($this->superAdmin);

    $reponse = $this->getJson('/api/v1/journal')->assertOk();

    expect($reponse->json('data.data'))->toHaveCount(3)
        // Le dernier acte posé arrive en tête : on ouvre un journal pour savoir
        // ce qui vient de se passer.
        ->and($reponse->json('data.data.0.log_name'))->toBe('compte')
        ->and($reponse->json('data.data.2.description'))->toBe('Centre créé');
});

it('FERME le journal à l’administrateur national', function () {
    Sanctum::actingAs($this->national);

    // Il a pourtant posé deux des trois actes : agir et relire sont deux
    // pouvoirs distincts.
    $this->getJson('/api/v1/journal')->assertForbidden();
    $this->getJson('/api/v1/journal/journaux')->assertForbidden();
});

it('nomme l’auteur quand il y en a un', function () {
    Sanctum::actingAs($this->superAdmin);

    $acte = collect($this->getJson('/api/v1/journal')->assertOk()->json('data.data'))
        ->firstWhere('description', 'Centre créé');

    expect($acte['causer']['nom'])->toBe('JAPI')
        ->and($acte['causer']['id'])->toBe($this->national->id);
});

it('isole ce qui s’est fait sans auteur', function () {
    Sanctum::actingAs($this->superAdmin);

    $actes = $this->getJson('/api/v1/journal?sans_auteur=1')->assertOk()->json('data.data');

    expect($actes)->toHaveCount(1)
        ->and($actes[0]['causer'])->toBeNull()
        ->and($actes[0]['properties']['evenement'])->toBe('recalcul_planifie');
});

it('filtre par journal et cherche dans l’intitulé', function () {
    Sanctum::actingAs($this->superAdmin);

    $parJournal = $this->getJson('/api/v1/journal?log=vague')->assertOk()->json('data.data');
    expect($parJournal)->toHaveCount(1)
        ->and($parJournal[0]['properties']['graine'])->toBe(777);

    $parRecherche = $this->getJson('/api/v1/journal?recherche=Tirage')->assertOk()->json('data.data');
    expect($parRecherche)->toHaveCount(1);
});

it('annonce les journaux présents avec leur volume, sans les coder en dur', function () {
    Sanctum::actingAs($this->superAdmin);

    $journaux = collect($this->getJson('/api/v1/journal/journaux')->assertOk()->json('data'))
        ->pluck('total', 'log_name');

    expect($journaux->all())->toBe(['compte' => 1, 'referentiel' => 1, 'vague' => 1]);
});

it('exige une authentification', function () {
    $this->getJson('/api/v1/journal')->assertUnauthorized();
});
