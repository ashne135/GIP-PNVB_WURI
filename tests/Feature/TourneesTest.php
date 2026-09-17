<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\FeuillePresence;
use App\Models\Kit;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * LE PASSAGE DU KIT SUR UN SITE (cadrage, sections 4, 7 et 8).
 *
 * LA RÈGLE QUE CES TESTS PROTÈGENT AVANT TOUTES LES AUTRES : seule la feuille
 * de présence validée fait foi. Dès qu'un superviseur a validé une feuille sur
 * un passage, la journée est attestée — la déplacer ou en changer l'opérateur
 * contredirait après coup ce qu'un responsable a signé.
 *
 * Les autres invariants : un kit ne couvre que les sites de SON centre, un site
 * n'est couvert qu'une fois par vague, et les catégories restent étanches — un
 * assistant ne tient jamais un kit.
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

    $this->centre = centreTournee($this->commune, $this->region, 'BAN-BAGA-C001', 'Centre de Bagassi');
    $this->site = siteTournee($this->centre, 'BAN-BAGA-C001-S01', 'Site Assio', 1);
    $this->autreSite = siteTournee($this->centre, 'BAN-BAGA-C001-S02', 'Site Kana', 2);

    $this->admin = compteTournee('+22670008001', 'administrateur_national');

    $this->chef = compteTournee('+22670008002', 'chef_antenne_regional');
    $this->chef->update(['region_id' => $this->region->id]);

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->subDays(5)->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    $this->operateur = volontaireTournee('+22670008010', CategorieVolontaire::Operateur);
    $this->affectation = affectationTournee($this->vague, $this->operateur, $this->centre, 'operateur');

    // LE PASSAGE PORTE UN KIT. Sans kit_id, Eloquent n'a aucun identifiant à
    // charger, la requête sur « kits » ne part jamais, et une colonne
    // inexistante reste invisible jusqu'à l'écran — c'est exactement ce qui
    // est arrivé.
    $this->kit = Kit::query()->create(['reference' => 'KIT-0001', 'etat' => 'fonctionnel']);

    $this->passage = TourneeSite::query()->create([
        'vague_id' => $this->vague->id, 'centre_id' => $this->centre->id, 'site_id' => $this->site->id,
        'kit_id' => $this->kit->id,
        'affectation_operateur_id' => $this->affectation->id, 'ordre' => 1,
        'date_debut' => now()->subDays(5)->toDateString(),
        'date_fin' => now()->addDays(5)->toDateString(),
        'statut' => 'en_cours',
    ]);
});

function compteTournee(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'TRN', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);
    $user->consentements()->create(['version_charte' => '2026.1', 'accepte_le' => now()]);

    return $user->fresh();
}

function volontaireTournee(string $telephone, CategorieVolontaire $categorie, ?int $localiteId = null): Volontaire
{
    $user = compteTournee($telephone, $categorie->role()->value);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
        'categorie' => $categorie->value, 'statut' => 'operationnel',
        'localite_id' => $localiteId,
    ]);
}

function centreTournee(Commune $commune, Region $region, string $code, string $nom): Centre
{
    return Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => $code, 'nom' => $nom, 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
}

function siteTournee(Centre $centre, string $code, string $nom, int $ordre): Site
{
    $localite = Localite::query()->create([
        'commune_id' => $centre->commune_id, 'region_id' => $centre->region_id,
        'nom' => $nom, 'type_localite' => 'village', 'population_totale' => 1200,
    ]);

    return Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $centre->region_id,
        'code' => $code, 'nom' => $nom, 'ordre_tournee' => $ordre, 'statut' => 'ouvert',
    ]);
}

function affectationTournee(
    VagueDeploiement $vague,
    Volontaire $volontaire,
    Centre $centre,
    string $role
): Affectation {
    return Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $volontaire->id,
        'role_terrain' => $role, 'centre_id' => $centre->id,
        'date_debut' => now()->subDays(5)->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
}

/** Une feuille SIGNÉE sur le passage : la journée est attestée. */
function feuilleValideeTournee(TourneeSite $passage, Volontaire $superviseur, string $date): FeuillePresence
{
    return FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'site_id' => $passage->site_id, 'centre_id' => $passage->centre_id,
        'vague_id' => $passage->vague_id, 'tournee_site_id' => $passage->id,
        'date_presence' => $date, 'statut' => 'validee',
        'superviseur_id' => $superviseur->id, 'valide_le' => now(),
    ]);
}

it('liste les passages du périmètre, avec le site et l’agent qui porte le kit', function () {
    Sanctum::actingAs($this->chef);

    $reponse = $this->getJson('/api/v1/tournees')->assertOk();

    expect($reponse->json('data.data'))->toHaveCount(1)
        ->and($reponse->json('data.data.0.site.nom'))->toBe('Site Assio')
        ->and($reponse->json('data.data.0.kit.reference'))->toBe('KIT-0001')
        ->and($reponse->json('data.data.0.affectation_operateur.volontaire.matricule'))
        ->toBe($this->operateur->matricule);
});

it('corrige le passage : le kit change de site et de dates', function () {
    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}", [
        'site_id' => $this->autreSite->id,
        'date_debut' => now()->toDateString(),
        'date_fin' => null,
        'ordre' => 2,
        'statut' => 'planifiee',
    ])
        ->assertOk()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Site Kana')
            && str_contains($message, 'sans date de fin'));

    $this->passage->refresh();

    expect($this->passage->site_id)->toBe($this->autreSite->id)
        ->and($this->passage->date_fin)->toBeNull()
        ->and($this->passage->ordre)->toBe(2);
});

it('REFUSE de déplacer un passage dont une journée est déjà attestée', function () {
    $superviseur = volontaireTournee('+22670008012', CategorieVolontaire::Superviseur);
    feuilleValideeTournee($this->passage, $superviseur, now()->subDay()->toDateString());

    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}", [
        'site_id' => $this->autreSite->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'seule la feuille validée fait foi'));

    // Rien n'a bougé : un refus ne laisse pas de correction partielle.
    expect($this->passage->fresh()->site_id)->toBe($this->site->id);
});

it('REFUSE d’en changer l’opérateur quand une journée est déjà attestée', function () {
    $superviseur = volontaireTournee('+22670008013', CategorieVolontaire::Superviseur);
    feuilleValideeTournee($this->passage, $superviseur, now()->subDay()->toDateString());

    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}/operateur", [
        'affectation_operateur_id' => null,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'seule la feuille validée fait foi'));

    expect($this->passage->fresh()->affectation_operateur_id)->toBe($this->affectation->id);
});

it('refuse un site qui n’appartient pas au centre du passage', function () {
    $autreCentre = centreTournee($this->commune, $this->region, 'BAN-BAGA-C002', 'Centre voisin');
    $siteVoisin = siteTournee($autreCentre, 'BAN-BAGA-C002-S01', 'Site voisin', 1);

    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}", ['site_id' => $siteVoisin->id])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'son propre centre'));
});

it('refuse un site déjà couvert par un autre passage de la même vague', function () {
    TourneeSite::query()->create([
        'vague_id' => $this->vague->id, 'centre_id' => $this->centre->id,
        'site_id' => $this->autreSite->id, 'ordre' => 2,
        'date_debut' => now()->addDays(6)->toDateString(), 'statut' => 'planifiee',
    ]);

    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}", ['site_id' => $this->autreSite->id])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'déjà couvert'));
});

it('désigne un autre opérateur du centre, et détache avec null', function () {
    $remplacant = volontaireTournee('+22670008014', CategorieVolontaire::Operateur);
    $affectation = affectationTournee($this->vague, $remplacant, $this->centre, 'operateur');

    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}/operateur", [
        'affectation_operateur_id' => $affectation->id,
    ])
        ->assertOk()
        ->assertJsonPath('message', fn ($message) => str_contains($message, $remplacant->matricule));

    expect($this->passage->fresh()->affectation_operateur_id)->toBe($affectation->id);

    $this->putJson("/api/v1/tournees/{$this->passage->id}/operateur", [
        'affectation_operateur_id' => null,
    ])
        ->assertOk()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'sans porteur'));

    expect($this->passage->fresh()->affectation_operateur_id)->toBeNull();
});

it('n’autorise jamais un assistant à tenir un kit : les catégories sont étanches', function () {
    $assistant = volontaireTournee('+22670008015', CategorieVolontaire::Assistant);
    $affectation = affectationTournee($this->vague, $assistant, $this->centre, 'assistant');

    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}/operateur", [
        'affectation_operateur_id' => $affectation->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'étanches'));
});

it('ne propose que les opérateurs actifs du centre du passage', function () {
    $autreCentre = centreTournee($this->commune, $this->region, 'BAN-BAGA-C003', 'Centre lointain');
    $ailleurs = volontaireTournee('+22670008016', CategorieVolontaire::Operateur);
    affectationTournee($this->vague, $ailleurs, $autreCentre, 'operateur');

    Sanctum::actingAs($this->chef);

    $candidats = $this->getJson("/api/v1/tournees/{$this->passage->id}/operateurs")
        ->assertOk()
        ->json('data');

    expect($candidats)->toHaveCount(1)
        ->and($candidats[0]['volontaire']['matricule'])->toBe($this->operateur->matricule);
});

it('ferme la correction à qui n’a pas le droit', function () {
    Sanctum::actingAs(compteTournee('+22670008019', 'volontaire_superviseur'));

    $this->putJson("/api/v1/tournees/{$this->passage->id}", ['ordre' => 3])
        ->assertForbidden();
});

it('ne corrige plus les passages d’une vague clôturée', function () {
    $this->vague->update(['statut' => 'cloturee']);

    Sanctum::actingAs($this->chef);

    $this->putJson("/api/v1/tournees/{$this->passage->id}", ['ordre' => 3])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'clôturée'));
});
