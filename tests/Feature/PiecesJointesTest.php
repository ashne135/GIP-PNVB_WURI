<?php

use App\Enums\CategorieVolontaire;
use App\Enums\GraviteIncident;
use App\Enums\StatutCompte;
use App\Jobs\AlerterPhotosKitsManquantesJob;
use App\Models\Affectation;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Kit;
use App\Models\KitMouvement;
use App\Models\Localite;
use App\Models\NatureIncident;
use App\Models\PieceJointe;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\NomenclaturesIncidentSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * LES PHOTOS DU TERRAIN (cadrage, sections 11.8, 12 et 13).
 *
 * Elles partent APRÈS leur fiche, compressées sur le téléphone. Ces tests
 * protègent ce qui en découle : une photo renvoyée n'est jamais enregistrée
 * deux fois, une fiche pas encore arrivée n'est pas un refus définitif,
 * l'alerte « photos manquantes » attend les photos annoncées, et une photo
 * n'est servie qu'à qui peut voir sa fiche.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);
    $this->seed(NomenclaturesIncidentSeeder::class);

    Storage::fake('local');

    $aujourdhui = now()->toDateString();

    $region = Region::query()->create(['code' => 'GOU', 'nom' => 'Goulmou', 'nombre_sites_alloues' => 220]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'GOUR', 'nom' => 'Gourma']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'FADA', 'nom' => 'Fada', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'Natiaboani', 'type_localite' => 'village',
        'population_totale' => 3100, 'quota_sites' => 1,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => 'GOU-FADA-C001', 'nom' => 'Centre Fada', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $this->site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $region->id,
        'code' => 'GOU-FADA-C001-S01', 'nom' => 'Site Natiaboani', 'statut' => 'ouvert',
        'latitude' => 12.0610, 'longitude' => 0.3580, 'rayon_zone_metres' => 500,
    ]);

    $this->admin = comptePhoto('+22670006900', 'administrateur_national');

    $vague = VagueDeploiement::query()->create([
        'code' => 'GOU-2026-V1', 'libelle' => 'Vague Goulmou 1', 'region_id' => $region->id,
        'date_debut_prevue' => $aujourdhui, 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    foreach (['operateur' => '+22670006901', 'autreOperateur' => '+22670006902'] as $propriete => $telephone) {
        $this->{$propriete} = comptePhoto($telephone, 'volontaire_operateur', CategorieVolontaire::Operateur);
        Affectation::query()->create([
            'vague_id' => $vague->id, 'volontaire_id' => $this->{$propriete}->volontaire->id,
            'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $centre->id,
            'date_debut' => $aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
        ]);
    }

    $this->kit = Kit::query()->create([
        'reference' => 'KIT-GOU-001', 'etat' => 'fonctionnel', 'centre_courant_id' => $centre->id,
    ]);

    // L'administration remet le kit à l'opérateur, photos comprises.
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'remise',
        'volontaire_destination_id' => $this->operateur->volontaire->id,
        'site_id' => $this->site->id,
        'etat_constate' => 'bon',
        'photo_source' => 'kits/remise-source.jpg',
        'photo_destination' => 'kits/remise-destination.jpg',
    ])->assertStatus(201);
});

function comptePhoto(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Photo', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);
    $user->consentements()->create(['version_charte' => '2026.1', 'accepte_le' => now()]);

    if ($categorie) {
        Volontaire::query()->create([
            'user_id' => $user->id,
            'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -5),
            'categorie' => $categorie->value,
            'statut' => 'operationnel',
        ]);
    }

    return $user->fresh(['volontaire']);
}

/** La restitution du kit déclarée hors ligne, photos annoncées. */
function restitutionHorsLigne(array $remplacements = []): array
{
    return array_merge([
        'type' => 'mouvement_kit',
        'uuid_client' => (string) Str::uuid(),
        'kit_reference' => 'KIT-GOU-001',
        'type_mouvement' => 'restitution',
        'etat_constate' => 'bon',
        'photos_a_suivre' => true,
        'horodatage_telephone' => now()->toIso8601String(),
    ], $remplacements);
}

function lotPhotos(array $elements): array
{
    return ['uuid_lot' => (string) Str::uuid(), 'elements' => $elements];
}

/** Une photo telle que le téléphone l'envoie : compressée, en JPEG. */
function deposerPhoto(string $elementUuid, string $role, ?string $uuidFichier = null): TestResponse
{
    return test()->post('/api/v1/sync/fichiers', [
        'uuid_fichier' => $uuidFichier ?? (string) Str::uuid(),
        'element_uuid' => $elementUuid,
        'role' => $role,
        'fichier' => UploadedFile::fake()->image('constat.jpg', 1200, 900)->size(180),
        'horodatage_telephone' => now()->toIso8601String(),
    ], ['Accept' => 'application/json']);
}

function alertesPhotosManquantes(): int
{
    return Alerte::query()->where('titre', 'Mouvement de kit sans photo : KIT-GOU-001')->count();
}

it('rattache la photo au mouvement de kit remonté hors ligne, sans doublon au renvoi', function () {
    Sanctum::actingAs($this->operateur);

    $element = restitutionHorsLigne();
    $this->postJson('/api/v1/sync', lotPhotos([$element]))->assertOk()->assertJsonPath('data.nb_acceptes', 1);

    $uuidFichier = (string) Str::uuid();

    deposerPhoto($element['uuid_client'], 'constat_source', $uuidFichier)
        ->assertCreated()
        ->assertJsonPath('data.action', 'cree');

    // Le réseau tombe au moment de la réponse : le téléphone renvoie la photo.
    deposerPhoto($element['uuid_client'], 'constat_source', $uuidFichier)
        ->assertOk()
        ->assertJsonPath('data.action', 'existant');

    $mouvement = KitMouvement::query()->where('uuid_client', $element['uuid_client'])->first();

    expect(PieceJointe::query()->count())->toBe(1)
        ->and($mouvement->photo_source_chemin)->not->toBeNull()
        ->and($mouvement->photos_attendues_jusqu_au)->not->toBeNull();

    Storage::disk('local')->assertExists($mouvement->photo_source_chemin);
});

it("attend l'échéance avant d'alerter sur des photos annoncées, et n'alerte qu'une fois", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/sync', lotPhotos([restitutionHorsLigne()]))->assertOk();

    // Les photos sont prises, simplement pas encore parties : pas d'alerte.
    expect(alertesPhotosManquantes())->toBe(0);

    AlerterPhotosKitsManquantesJob::dispatchSync();
    expect(alertesPhotosManquantes())->toBe(0);

    // Délai paramétré de 24 heures dépassé, et toujours rien.
    $this->travel(25)->hours();

    AlerterPhotosKitsManquantesJob::dispatchSync();
    AlerterPhotosKitsManquantesJob::dispatchSync();

    expect(alertesPhotosManquantes())->toBe(1);
});

it("n'alerte pas quand les photos annoncées arrivent dans le délai", function () {
    Sanctum::actingAs($this->operateur);

    $element = restitutionHorsLigne();
    $this->postJson('/api/v1/sync', lotPhotos([$element]))->assertOk();

    deposerPhoto($element['uuid_client'], 'constat_source')->assertCreated();
    deposerPhoto($element['uuid_client'], 'constat_destination')->assertCreated();

    $this->travel(25)->hours();
    AlerterPhotosKitsManquantesJob::dispatchSync();

    expect(alertesPhotosManquantes())->toBe(0);
});

it("garde l'alerte immédiate quand aucune photo n'est annoncée", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/sync', lotPhotos([restitutionHorsLigne(['photos_a_suivre' => false])]))->assertOk();

    expect(alertesPhotosManquantes())->toBe(1);
});

it("dit de réessayer une photo dont la fiche n'est pas encore arrivée", function () {
    Sanctum::actingAs($this->operateur);

    deposerPhoto((string) Str::uuid(), 'constat_source')
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'introuvable_serveur')
        ->assertJsonPath('data.reessayer', true);

    // Rien d'enregistré, et aucun fichier orphelin sur le disque.
    expect(PieceJointe::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it("attache une preuve à un incident et ne la sert qu'à qui peut voir la fiche", function () {
    Sanctum::actingAs($this->operateur);

    $uuid = (string) Str::uuid();

    $this->postJson('/api/v1/incidents', [
        'uuid_client' => $uuid,
        'site_id' => $this->site->id,
        'natures' => [NatureIncident::query()->value('id')],
        'recit' => 'La tablette est tombée pendant le transport, écran fissuré.',
        'gravite' => GraviteIncident::Modere->value,
        'preuves' => ['photo'],
    ])->assertSuccessful();

    $piece = deposerPhoto($uuid, 'preuve_incident')->assertCreated()->json('data');

    expect($piece)->not->toHaveKey('chemin');

    $this->get("/api/v1/pieces-jointes/{$piece['id']}")->assertOk();

    Sanctum::actingAs($this->autreOperateur);

    $this->getJson("/api/v1/pieces-jointes/{$piece['id']}")->assertForbidden();
});

it("refuse un fichier qui n'est pas une photo", function () {
    Sanctum::actingAs($this->operateur);

    $element = restitutionHorsLigne();
    $this->postJson('/api/v1/sync', lotPhotos([$element]))->assertOk();

    $this->post('/api/v1/sync/fichiers', [
        'uuid_fichier' => (string) Str::uuid(),
        'element_uuid' => $element['uuid_client'],
        'role' => 'constat_source',
        'fichier' => UploadedFile::fake()->create('releve.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.fichier.0', 'Seules les photos JPEG, PNG ou WebP sont acceptées.');
});

it("refuse une photo sur le mouvement de kit d'un autre agent", function () {
    Sanctum::actingAs($this->operateur);

    $element = restitutionHorsLigne();
    $this->postJson('/api/v1/sync', lotPhotos([$element]))->assertOk();

    Sanctum::actingAs($this->autreOperateur);

    deposerPhoto($element['uuid_client'], 'constat_source')->assertForbidden();

    expect(PieceJointe::query()->count())->toBe(0);
});
