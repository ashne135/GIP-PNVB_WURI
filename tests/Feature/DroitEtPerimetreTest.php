<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\UniteSupervision;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * LES DEUX MÉCANISMES, DISTINCTS ET TOUS LES DEUX OBLIGATOIRES
 * (cadrage, section 5).
 *
 *   - le DROIT     : rôles et permissions Spatie, vérifiés par les Policies
 *   - le PÉRIMÈTRE : scope Eloquent appliqué dans chaque requête
 *
 * Ces tests vérifient qu'aucun des deux ne suffit seul : un agent qui a le
 * droit de consulter des centres ne doit voir QUE les siens.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    // Deux régions, pour pouvoir vérifier qu'on ne voit pas celle d'à côté.
    $this->bankui = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 661]);
    $this->kadiogo = Region::query()->create(['code' => 'KAD', 'nom' => 'Kadiogo', 'nombre_sites_alloues' => 2247]);

    $this->centreBankui = creerCentreComplet($this->bankui, 'BALE', 'BAGASSI', 'BAGA', 1);
    $this->centreBankui2 = creerCentreComplet($this->bankui, 'BALE', 'BOROMO', 'BORO', 2);
    $this->centreKadiogo = creerCentreComplet($this->kadiogo, 'KADIOGO', 'OUAGADOUGOU', 'OUAG', 1);
});

/** Fabrique un centre complet, avec sa chaîne territoriale et un site. */
function creerCentreComplet(Region $region, string $province, string $commune, string $code, int $numero): Centre
{
    $prov = Province::query()->firstOrCreate(
        ['region_id' => $region->id, 'nom' => $province],
        ['code' => substr($province, 0, 8)]
    );

    $com = Commune::query()->firstOrCreate(
        ['province_id' => $prov->id, 'nom' => $commune],
        ['region_id' => $region->id, 'code' => $code, 'type' => 'rurale']
    );

    $loc = Localite::query()->create([
        'commune_id' => $com->id, 'region_id' => $region->id,
        'nom' => $commune.' localité '.$numero, 'type' => 'village',
        'population_totale' => 1500, 'quota_sites' => 1,
    ]);

    $centre = Centre::query()->create([
        'commune_id' => $com->id, 'region_id' => $region->id,
        'code' => "{$region->code}-{$code}-C".str_pad((string) $numero, 3, '0', STR_PAD_LEFT),
        'nom' => 'Centre '.$commune, 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $loc->id, 'region_id' => $region->id,
        'code' => $centre->code.'-S01', 'nom' => 'Site 1', 'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    return $centre;
}

/** Crée un compte connectable, avec sa fiche volontaire si une catégorie est donnée. */
function creerCompte(string $telephone, string $role, ?CategorieVolontaire $categorie = null, ?int $regionId = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Test', 'prenoms' => ucfirst($role),
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
        'region_id' => $regionId,
    ]);
    $user->assignRole($role);

    if ($categorie) {
        Volontaire::query()->create([
            'user_id' => $user->id,
            'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
            'categorie' => $categorie->value,
            'statut' => 'operationnel',
        ]);

        // La charte est acceptée : ces tests portent sur le droit et le
        // périmètre, pas sur le parcours de première connexion.
        $user->consentements()->create([
            'version_charte' => '2026.1',
            'accepte_le' => now(),
        ]);
    }

    return $user;
}

it('le chef d\'antenne ne voit que les centres de sa région', function () {
    $chef = creerCompte('+22670000010', 'chef_antenne_regional', null, $this->bankui->id);

    Sanctum::actingAs($chef);
    $reponse = $this->getJson('/api/v1/referentiel/centres')->assertOk();

    $codes = collect($reponse->json('data.data'))->pluck('code');

    expect($codes)->toHaveCount(2);
    expect($codes)->each->toStartWith('BAN-');
    expect($codes)->not->toContain('KAD-OUAG-C001');
});

it('le superviseur ne voit que les centres de son unité de supervision', function () {
    $superviseur = creerCompte('+22670000011', 'volontaire_superviseur', CategorieVolontaire::Superviseur);

    $vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague test', 'region_id' => $this->bankui->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $superviseur->id,
    ]);

    // Il supervise UN SEUL des deux centres de Bankui.
    UniteSupervision::query()->create([
        'vague_id' => $vague->id,
        'volontaire_superviseur_id' => $superviseur->volontaire->id,
        'centre_principal_id' => $this->centreBankui->id,
    ]);

    Sanctum::actingAs($superviseur);
    $reponse = $this->getJson('/api/v1/referentiel/centres')->assertOk();
    $codes = collect($reponse->json('data.data'))->pluck('code');

    expect($codes)->toHaveCount(1);
    expect($codes->first())->toBe($this->centreBankui->code);
});

it('l\'administrateur national voit tous les centres', function () {
    $admin = creerCompte('+22670000012', 'administrateur_national');

    Sanctum::actingAs($admin);
    $reponse = $this->getJson('/api/v1/referentiel/centres')->assertOk();

    expect($reponse->json('data.total'))->toBe(3);
});

it('l\'opérateur n\'a pas le droit de consulter le référentiel', function () {
    $operateur = creerCompte('+22670000013', 'volontaire_operateur', CategorieVolontaire::Operateur);

    // Le DROIT manque : la Policy refuse avant même toute question de périmètre.
    Sanctum::actingAs($operateur);
    $this->getJson('/api/v1/referentiel/centres')
        ->assertStatus(403)
        ->assertJsonPath('message', "Vous n'avez pas les droits pour faire cette action.");
});

it('l\'opérateur ne voit que sa propre fiche de volontaire', function () {
    $operateur = creerCompte('+22670000014', 'volontaire_operateur', CategorieVolontaire::Operateur);
    creerCompte('+22670000015', 'volontaire_operateur', CategorieVolontaire::Operateur);

    // Il n'a pas volontaires.consulter : même sa propre liste lui est fermée.
    Sanctum::actingAs($operateur);
    $this->getJson('/api/v1/volontaires')->assertStatus(403);

    // En revanche le scope, lui, ne lui rend que sa fiche.
    expect(Volontaire::query()->perimetre($operateur)->count())->toBe(1);
    expect(Volontaire::query()->perimetre($operateur)->first()->user_id)->toBe($operateur->id);
});

it('un utilisateur non authentifié ne voit rien du tout', function () {
    $this->getJson('/api/v1/referentiel/centres')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Vous devez être connecté pour faire cette action.');

    // Et le scope lui-même refuse de renvoyer quoi que ce soit sans utilisateur.
    expect(Centre::query()->perimetre(null)->count())->toBe(0);
});

it('l\'observateur consulte mais le serveur refuse toute écriture', function () {
    $observateur = creerCompte('+22670000016', 'observateur');

    // Lecture : autorisée.
    Sanctum::actingAs($observateur);
    $this->getJson('/api/v1/referentiel/centres')->assertOk();

    // Écriture : refusée par le serveur, quelle que soit la route.
    Sanctum::actingAs($observateur);
    $this->postJson('/api/v1/charte/accepter')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Votre profil est en consultation seule : vous ne pouvez pas modifier de données.');
});

it('la séparation des pouvoirs : seul le super administrateur attribue les rôles', function () {
    $admin = creerCompte('+22670000017', 'administrateur_national');
    $dsi = creerCompte('+22670000018', 'super_administrateur');

    expect($admin->can('roles.attribuer'))->toBeFalse();
    expect($dsi->can('roles.attribuer'))->toBeTrue();

    // L'administrateur national ne modifie pas non plus les seuils du dispositif.
    expect($admin->can('parametres.modifier'))->toBeFalse();
    expect($admin->can('parametres.consulter'))->toBeTrue();
    expect($dsi->can('parametres.modifier'))->toBeTrue();
});

it('la catégorie d\'un volontaire ne peut jamais changer', function () {
    $operateur = creerCompte('+22670000019', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $volontaire = $operateur->volontaire;

    // Le modèle refuse, quelle que soit l'origine de l'écriture.
    expect(fn () => $volontaire->update(['categorie' => CategorieVolontaire::Superviseur->value]))
        ->toThrow(DomainException::class);

    // Et aucun rôle ne porte cette capacité, pas même le super administrateur.
    $dsi = creerCompte('+22670000020', 'super_administrateur');
    expect($dsi->can('changerCategorie', $volontaire))->toBeFalse();
});

it('le périmètre renvoyé à la connexion correspond au niveau du rôle', function () {
    $chef = creerCompte('+22670000021', 'chef_antenne_regional', null, $this->bankui->id);

    $reponse = $this->postJson('/api/v1/connexion', [
        'telephone' => '+22670000021', 'mot_de_passe' => 'MotDePasse#1',
    ])->assertOk();

    expect($reponse->json('data.perimetre.niveau'))->toBe('sa_region');
    expect($reponse->json('data.perimetre.regions'))->toHaveCount(1);
    expect($reponse->json('data.perimetre.regions.0.code'))->toBe('BAN');

    // Deux centres dans sa région, un seul site chacun.
    expect($reponse->json('data.perimetre.nombre_sites'))->toBe(2);
});
