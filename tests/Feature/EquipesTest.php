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
use App\Models\TourneeSite;
use App\Models\UniteSupervision;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * LES ÉQUIPES DÉPLOYÉES — qui travaille où (cadrage, sections 4 et 7).
 *
 * DEUX SUBTILITÉS DU MODÈLE QUE CES TESTS FIGENT :
 *
 *  1. LE SUPERVISEUR N'A PAS DE CENTRE. Son affectation porte une unité de
 *     supervision et laisse centre_id nul. Le périmètre régional filtrait
 *     pourtant sur le seul centre_id : un chef d'antenne ne voyait AUCUN
 *     superviseur de sa propre région. C'est la régression que le dernier
 *     test de ce fichier interdit de réintroduire.
 *
 *  2. LE SITE DÉPEND DU JOUR. Il vient de la tournée du kit pour un opérateur,
 *     de sa localité pour un A-OPK, et n'existe pas pour un superviseur, qui
 *     couvre deux centres. Un site « en général » serait une invention.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->region = Region::query()->create([
        'code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 100,
    ]);
    $province = Province::query()->create([
        'region_id' => $this->region->id, 'code' => 'BALE', 'nom' => 'Bale',
    ]);
    $this->commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);

    $this->centreA = centreEq($this->commune, 'BAN-BAGA-C001', 'Centre de Bagassi');
    $this->centreB = centreEq($this->commune, 'BAN-BAGA-C002', 'Centre de Boromo');

    $this->localite = Localite::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1500,
    ]);

    $this->siteAssio = Site::query()->create([
        'centre_id' => $this->centreA->id, 'localite_id' => $this->localite->id,
        'region_id' => $this->region->id, 'code' => 'BAN-BAGA-C001-S01',
        'nom' => 'Site Assio', 'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $this->admin = compteEq('+22670011001', 'administrateur_national');
    $this->chef = compteEq('+22670011002', 'chef_antenne_regional');
    $this->chef->update(['region_id' => $this->region->id]);

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->subDays(5)->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    // L'OPÉRATEUR : rattaché au centre, son site vient de la tournée du kit.
    $this->operateur = volontaireEq('+22670011010', CategorieVolontaire::Operateur);
    $this->affectationOpk = Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->operateur->id,
        'role_terrain' => 'operateur', 'centre_id' => $this->centreA->id,
        'date_debut' => now()->subDays(5)->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
    TourneeSite::query()->create([
        'vague_id' => $this->vague->id, 'centre_id' => $this->centreA->id,
        'site_id' => $this->siteAssio->id, 'affectation_operateur_id' => $this->affectationOpk->id,
        'ordre' => 1, 'date_debut' => now()->subDays(5)->toDateString(),
        'date_fin' => now()->addDays(5)->toDateString(), 'statut' => 'en_cours',
    ]);

    // L'A-OPK : rattaché en permanence à la localité de son site.
    $this->assistant = volontaireEq('+22670011011', CategorieVolontaire::Assistant, $this->localite->id);
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->assistant->id,
        'role_terrain' => 'assistant', 'centre_id' => $this->centreA->id,
        'localite_id' => $this->localite->id,
        'date_debut' => now()->subDays(5)->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    // LE SUPERVISEUR : aucun centre_id, une unité couvrant DEUX centres.
    $this->superviseur = volontaireEq('+22670011012', CategorieVolontaire::Superviseur);
    $unite = UniteSupervision::query()->create([
        'vague_id' => $this->vague->id,
        'volontaire_superviseur_id' => $this->superviseur->id,
        'centre_principal_id' => $this->centreA->id,
        'centre_secondaire_id' => $this->centreB->id,
    ]);
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->superviseur->id,
        'role_terrain' => 'superviseur', 'unite_supervision_id' => $unite->id,
        'date_debut' => now()->subDays(5)->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
});

function compteEq(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'EQP', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function volontaireEq(string $telephone, CategorieVolontaire $categorie, ?int $localiteId = null): Volontaire
{
    $user = compteEq($telephone, $categorie->role()->value);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
        'categorie' => $categorie->value, 'statut' => 'operationnel',
        'localite_id' => $localiteId,
    ]);
}

function centreEq(Commune $commune, string $code, string $nom): Centre
{
    return Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $commune->region_id,
        'code' => $code, 'nom' => $nom, 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
}

/** La ligne d'un agent, retrouvée par son matricule. */
function ligneDe(array $lignes, string $matricule): ?array
{
    return collect($lignes)->first(fn ($l) => $l['volontaire']['matricule'] === $matricule);
}

it('rend les agents déployés avec téléphone, rôle, commune et centre', function () {
    Sanctum::actingAs($this->admin);

    $lignes = $this->getJson('/api/v1/equipes')->assertOk()->json('data.data');

    expect($lignes)->toHaveCount(3);

    $opk = ligneDe($lignes, $this->operateur->matricule);

    expect($opk['volontaire']['user']['telephone'])->toBe('+22670011010')
        ->and($opk['role_terrain'])->toBe('operateur')
        ->and($opk['centre']['code'])->toBe('BAN-BAGA-C001')
        ->and($opk['centre']['commune']['nom'])->toBe('Bagassi');
});

it('donne à l’opérateur le site de la tournée de son kit ce jour-là', function () {
    Sanctum::actingAs($this->admin);

    $lignes = $this->getJson('/api/v1/equipes')->assertOk()->json('data.data');

    expect(ligneDe($lignes, $this->operateur->matricule)['site_du_jour']['nom'])->toBe('Site Assio');

    // Hors de la fenêtre du passage, l'agent n'est nulle part : on ne lui
    // invente pas un site « en général ».
    $horsTournee = $this->getJson('/api/v1/equipes?date='.now()->addDays(30)->toDateString())
        ->assertOk()->json('data.data');

    expect(ligneDe($horsTournee, $this->operateur->matricule)['site_du_jour'])->toBeNull();
});

it('donne à l’A-OPK le site de sa localité', function () {
    Sanctum::actingAs($this->admin);

    $lignes = $this->getJson('/api/v1/equipes')->assertOk()->json('data.data');

    expect(ligneDe($lignes, $this->assistant->matricule)['site_du_jour']['nom'])->toBe('Site Assio');
});

it('n’invente aucun site pour le superviseur, mais rend ses deux centres', function () {
    Sanctum::actingAs($this->admin);

    $ligne = ligneDe($this->getJson('/api/v1/equipes')->assertOk()->json('data.data'),
        $this->superviseur->matricule);

    expect($ligne['site_du_jour'])->toBeNull()
        ->and($ligne['centre'])->toBeNull()
        ->and($ligne['unite_supervision']['centre_principal']['code'])->toBe('BAN-BAGA-C001')
        ->and($ligne['unite_supervision']['centre_secondaire']['code'])->toBe('BAN-BAGA-C002');
});

it('LAISSE LE CHEF D’ANTENNE VOIR LES SUPERVISEURS DE SA RÉGION', function () {
    Sanctum::actingAs($this->chef);

    $lignes = $this->getJson('/api/v1/equipes')->assertOk()->json('data.data');

    // L'affectation d'un superviseur n'a pas de centre_id : filtrer le
    // périmètre régional sur ce seul champ l'excluait silencieusement.
    expect($lignes)->toHaveCount(3)
        ->and(ligneDe($lignes, $this->superviseur->matricule))->not->toBeNull();
});

it('ne montre que les affectations ACTIVES', function () {
    $reserviste = volontaireEq('+22670011013', CategorieVolontaire::Operateur);
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $reserviste->id,
        'role_terrain' => 'operateur', 'centre_id' => $this->centreA->id,
        'date_debut' => now()->toDateString(), 'statut' => 'proposee', 'origine' => 'tirage_auto',
    ]);

    Sanctum::actingAs($this->admin);

    $lignes = $this->getJson('/api/v1/equipes')->assertOk()->json('data.data');

    expect($lignes)->toHaveCount(3)
        ->and(ligneDe($lignes, $reserviste->matricule))->toBeNull();
});

it('filtre par rôle et cherche par nom, matricule ou téléphone', function () {
    Sanctum::actingAs($this->admin);

    expect($this->getJson('/api/v1/equipes?role=assistant')->assertOk()->json('data.data'))
        ->toHaveCount(1);

    expect($this->getJson('/api/v1/equipes?recherche=011012')->assertOk()->json('data.data'))
        ->toHaveCount(1);
});

it('exige le droit de consulter les affectations', function () {
    Sanctum::actingAs(compteEq('+22670011019', 'volontaire_assistant'));

    $this->getJson('/api/v1/equipes')->assertForbidden();
});
