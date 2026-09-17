<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutAffectation;
use App\Enums\StatutCompte;
use App\Enums\StatutVolontaire;
use App\Models\Affectation;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Kit;
use App\Models\KitMouvement;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Remplacement;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Affectation\ServiceRemplacements;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Tâche 6 : remplacements et gestion de la réserve.
 *
 * POINT DE VIGILANCE : transfert du kit obligatoire, motif obligatoire.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->region = Region::query()->create([
        'code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 661,
    ]);
    $province = Province::query()->create([
        'region_id' => $this->region->id, 'code' => 'BALE', 'nom' => 'Bale',
    ]);
    $this->commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $this->localite = Localite::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1549,
    ]);
    $this->autreLocalite = Localite::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'nom' => 'Bagassi centre', 'type_localite' => 'village', 'population_totale' => 3992,
    ]);
    $this->centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre de Bagassi', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    $this->admin = compteRemp('+22670000002', 'administrateur_national');

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    Sanctum::actingAs($this->admin);
});

function compteRemp(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'TEST', 'prenoms' => ucfirst($role),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user;
}

/** Un volontaire, avec son compte, dans le statut voulu. */
function volontaire(
    string $telephone,
    CategorieVolontaire $categorie,
    StatutVolontaire $statut = StatutVolontaire::Operationnel,
    ?int $localiteId = null,
    StatutCompte $statutCompte = StatutCompte::Actif
): Volontaire {
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'AGENT', 'prenoms' => 'Test',
        'password' => 'x', 'statut_compte' => $statutCompte->value,
    ]);
    $user->assignRole($categorie->role()->value);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
        'categorie' => $categorie->value,
        'statut' => $statut->value,
        'localite_id' => $localiteId,
        'motif_reserve' => $statut === StatutVolontaire::Reserve ? 'non_mobilise' : null,
    ]);
}

/** Affecte un volontaire sur le centre de test. */
function affecter(Volontaire $v, $contexte, ?Kit $kit = null): Affectation
{
    return Affectation::query()->create([
        'vague_id' => $contexte->vague->id,
        'volontaire_id' => $v->id,
        'role_terrain' => $v->categorie->value,
        'centre_id' => $contexte->centre->id,
        'localite_id' => $v->localite_id,
        'kit_id' => $kit?->id,
        'date_debut' => now()->toDateString(),
        'statut' => StatutAffectation::Active->value,
        'origine' => 'tirage_auto',
    ]);
}

// ---------------------------------------------------------------------------
// Les deux obligations
// ---------------------------------------------------------------------------

it('refuse un remplacement sans motif', function () {
    $sortant = volontaire('+22670000010', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000011', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    $affectation = affecter($sortant, $this);

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
    ])->assertStatus(422)
        ->assertJsonPath('data.erreurs.motif.0',
            'Indiquez le motif du remplacement : désistement, abandon, indisponibilité ou performance.');

    expect(Remplacement::count())->toBe(0);
    expect($affectation->fresh()->statut)->toBe(StatutAffectation::Active);
});

it('refuse un remplacement sans état du kit quand le sortant en détient un', function () {
    $sortant = volontaire('+22670000012', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000013', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);

    $kit = Kit::query()->create([
        'reference' => 'KIT-001', 'etat' => 'fonctionnel',
        'volontaire_detenteur_id' => $sortant->id,
    ]);
    $affectation = affecter($sortant, $this, $kit);

    $reponse = $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
        'motif' => 'abandon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('détient un kit : constatez son état');

    // Rien n'a bougé : le kit reste au sortant.
    expect($kit->fresh()->volontaire_detenteur_id)->toBe($sortant->id);
    expect(Remplacement::count())->toBe(0);
});

it('transfère le kit du remplacé au remplaçant, avec état et photos', function () {
    $sortant = volontaire('+22670000014', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000015', CategorieVolontaire::Operateur, StatutVolontaire::Reserve,
        statutCompte: StatutCompte::Ferme);

    $kit = Kit::query()->create([
        'reference' => 'KIT-001', 'etat' => 'fonctionnel',
        'volontaire_detenteur_id' => $sortant->id,
    ]);
    $affectation = affecter($sortant, $this, $kit);

    $reponse = $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
        'motif' => 'indisponibilite',
        'commentaire' => 'Hospitalisé depuis lundi.',
        'etat_kit_constate' => 'usage',
        'photo_source' => 'kits/constat-sortant.jpg',
        'photo_destination' => 'kits/constat-entrant.jpg',
    ])->assertStatus(201);

    expect($reponse->json('message'))->toContain('Le kit lui a été transféré');

    // Le kit a changé de mains — et un agent n'en détient qu'un.
    $kit->refresh();
    expect($kit->volontaire_detenteur_id)->toBe($entrant->id);
    expect($kit->centre_courant_id)->toBe($this->centre->id);

    // Le mouvement porte l'état constaté et les deux photos.
    $mouvement = KitMouvement::query()->first();
    expect($mouvement->type->value)->toBe('transfert');
    expect($mouvement->volontaire_source_id)->toBe($sortant->id);
    expect($mouvement->volontaire_destination_id)->toBe($entrant->id);
    expect($mouvement->etat_constate)->toBe('usage');
    expect($mouvement->photo_source_chemin)->toBe('kits/constat-sortant.jpg');
    expect($mouvement->photo_destination_chemin)->toBe('kits/constat-entrant.jpg');

    // Le remplacement référence bien ce mouvement.
    expect(Remplacement::query()->first()->kit_mouvement_id)->toBe($mouvement->id);
});

it('alerte l\'administration quand les photos de constat manquent', function () {
    $sortant = volontaire('+22670000016', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000017', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);

    $kit = Kit::query()->create([
        'reference' => 'KIT-001', 'etat' => 'fonctionnel', 'volontaire_detenteur_id' => $sortant->id,
    ]);
    $affectation = affecter($sortant, $this, $kit);

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
        'motif' => 'abandon',
        'etat_kit_constate' => 'bon',
        // Aucune photo : le transfert passe, mais il ne passe pas inaperçu.
    ])->assertStatus(201);

    $alerte = Alerte::query()->where('kit_id', $kit->id)->first();
    expect($alerte)->not->toBeNull();
    expect($alerte->titre)->toContain('Mouvement de kit sans photo');
    expect($alerte->role_cible)->toBe('administrateur_national');
    expect($alerte->message)->toContain('engagent la responsabilité');
});

// ---------------------------------------------------------------------------
// Les quatre effets du remplacement
// ---------------------------------------------------------------------------

it('produit les quatre effets du cadrage en une seule opération', function () {
    $sortant = volontaire('+22670000018', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000019', CategorieVolontaire::Operateur, StatutVolontaire::Reserve,
        statutCompte: StatutCompte::Ferme);
    $affectation = affecter($sortant, $this);

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
        'motif' => 'desistement',
    ])->assertStatus(201);

    // 1. Le réserviste prend l'affectation, son accès s'ouvre.
    $entrant->refresh();
    expect($entrant->statut)->toBe(StatutVolontaire::Operationnel);
    expect($entrant->user->fresh()->statut_compte)->toBe(StatutCompte::Actif);

    $nouvelle = Affectation::query()->where('volontaire_id', $entrant->id)->first();
    expect($nouvelle->statut)->toBe(StatutAffectation::Active);
    expect($nouvelle->centre_id)->toBe($this->centre->id);
    expect($nouvelle->origine)->toBe('remplacement');
    expect($nouvelle->affectation_remplacee_id)->toBe($affectation->id);

    // 2. L'agent remplacé bascule en réserve avec son motif.
    $sortant->refresh();
    expect($sortant->statut)->toBe(StatutVolontaire::Reserve);
    expect($sortant->motif_reserve->value)->toBe('desistement');
    expect($sortant->date_entree_reserve)->not->toBeNull();

    // 3. L'ancienne affectation est close, pas supprimée : l'historique reste.
    expect($affectation->fresh()->statut)->toBe(StatutAffectation::Remplacee);
    expect($affectation->fresh()->date_fin)->not->toBeNull();

    // 4. L'accès du sortant suit la règle de sa catégorie : un opérateur est
    //    une catégorie TOURNANTE, il passe DISPONIBLE entre deux vagues.
    expect($sortant->user->fresh()->statut_compte)->toBe(StatutCompte::Disponible);
});

it('ferme l\'accès d\'un A-OPK remplacé, au lieu de le laisser disponible', function () {
    $sortant = volontaire('+22670000020', CategorieVolontaire::Assistant,
        localiteId: $this->localite->id);
    $entrant = volontaire('+22670000021', CategorieVolontaire::Assistant, StatutVolontaire::Reserve,
        localiteId: $this->localite->id, statutCompte: StatutCompte::Ferme);
    $affectation = affecter($sortant, $this);

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
        'motif' => 'performance',
    ])->assertStatus(201);

    // L'A-OPK n'est pas une catégorie tournante : son accès se ferme.
    expect($sortant->fresh()->user->fresh()->statut_compte)->toBe(StatutCompte::Ferme);
    expect($entrant->fresh()->user->fresh()->statut_compte)->toBe(StatutCompte::Actif);
});

// ---------------------------------------------------------------------------
// Étanchéité des catégories et éligibilité
// ---------------------------------------------------------------------------

it('refuse un remplaçant d\'une autre catégorie', function () {
    $sortant = volontaire('+22670000022', CategorieVolontaire::Operateur);
    $assistant = volontaire('+22670000023', CategorieVolontaire::Assistant, StatutVolontaire::Reserve,
        localiteId: $this->localite->id);
    $affectation = affecter($sortant, $this);

    $reponse = $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $assistant->id,
        'motif' => 'abandon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('catégories sont étanches');
    expect(Remplacement::count())->toBe(0);
});

it('refuse un remplaçant qui n\'est pas dans la réserve', function () {
    $sortant = volontaire('+22670000024', CategorieVolontaire::Operateur);
    $operationnel = volontaire('+22670000025', CategorieVolontaire::Operateur);
    $affectation = affecter($sortant, $this);

    $reponse = $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $operationnel->id,
        'motif' => 'abandon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain("n'est pas dans la réserve");
});

it('refuse de faire remplacer un A-OPK par un réserviste d\'une autre localité', function () {
    $sortant = volontaire('+22670000026', CategorieVolontaire::Assistant,
        localiteId: $this->localite->id);
    $ailleurs = volontaire('+22670000027', CategorieVolontaire::Assistant, StatutVolontaire::Reserve,
        localiteId: $this->autreLocalite->id);
    $affectation = affecter($sortant, $this);

    $reponse = $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $ailleurs->id,
        'motif' => 'abandon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('rattaché en permanence à sa localité');
});

it('refuse un réserviste déjà engagé sur une autre vague', function () {
    $sortant = volontaire('+22670000028', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000029', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);

    // Il est déjà proposé ailleurs.
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $entrant->id,
        'role_terrain' => 'operateur', 'centre_id' => $this->centre->id,
        'date_debut' => now()->toDateString(), 'statut' => StatutAffectation::Proposee->value,
        'origine' => 'tirage_auto',
    ]);

    $affectation = affecter($sortant, $this);

    $reponse = $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
        'motif' => 'abandon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain("est déjà engagé sur une vague");
});

// ---------------------------------------------------------------------------
// Réserve et historique
// ---------------------------------------------------------------------------

it('liste les remplaçants possibles pour une affectation', function () {
    $sortant = volontaire('+22670000030', CategorieVolontaire::Operateur);
    volontaire('+22670000031', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    volontaire('+22670000032', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    // Une autre catégorie : ne doit pas apparaître.
    volontaire('+22670000033', CategorieVolontaire::Superviseur, StatutVolontaire::Reserve);

    $kit = Kit::query()->create([
        'reference' => 'KIT-001', 'etat' => 'fonctionnel', 'volontaire_detenteur_id' => $sortant->id,
    ]);
    $affectation = affecter($sortant, $this, $kit);

    $reponse = $this->getJson("/api/v1/affectations/{$affectation->id}/remplacants")->assertOk();

    expect($reponse->json('data.candidats.total'))->toBe(2);
    expect($reponse->json('data.detient_un_kit'))->toBeTrue();
    expect($reponse->json('message'))->toContain('2 réservistes peuvent prendre');
});

it('expose le vivier de réserve avec sa répartition', function () {
    volontaire('+22670000034', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    volontaire('+22670000035', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    volontaire('+22670000036', CategorieVolontaire::Superviseur, StatutVolontaire::Reserve);

    $reponse = $this->getJson('/api/v1/reserve')->assertOk();
    expect($reponse->json('data.reservistes.total'))->toBe(3);

    $filtre = $this->getJson('/api/v1/reserve?categorie=operateur')->assertOk();
    expect($filtre->json('data.reservistes.total'))->toBe(2);
});

it('conserve tous les passages d\'un agent remobilisé', function () {
    $sortant = volontaire('+22670000037', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000038', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    $affectation = affecter($sortant, $this);

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id,
        'motif' => 'indisponibilite',
    ])->assertStatus(201);

    // L'agent écarté garde la trace de son passage.
    $reponse = $this->getJson("/api/v1/volontaires/{$sortant->id}/passages")->assertOk();

    expect($reponse->json('data.passages'))->toHaveCount(1);
    expect($reponse->json('data.passages.0.statut'))->toBe('remplacee');
    expect($reponse->json('data.remplacements_subis'))->toHaveCount(1);
    expect($reponse->json('data.remplacements_subis.0.motif'))->toBe('indisponibilite');

    // Et le remplaçant garde la trace du remplacement qu'il a assuré.
    $vueEntrant = $this->getJson("/api/v1/volontaires/{$entrant->id}/passages")->assertOk();
    expect($vueEntrant->json('data.remplacements_assures'))->toHaveCount(1);
});

it('permet de remobiliser plus tard un agent passé en réserve', function () {
    $sortant = volontaire('+22670000039', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000040', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    $affectation = affecter($sortant, $this);

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id, 'motif' => 'desistement',
    ])->assertStatus(201);

    // Le sortant est maintenant en réserve : il apparaît dans le vivier.
    expect($sortant->fresh()->statut)->toBe(StatutVolontaire::Reserve);

    $vivier = $this->getJson('/api/v1/reserve?categorie=operateur')->assertOk();
    expect(collect($vivier->json('data.reservistes.data'))->pluck('id'))->toContain($sortant->id);

    // Il peut reprendre du service : c'est lui qui remplace, cette fois.
    $nouvelleAffectation = Affectation::query()->where('volontaire_id', $entrant->id)->first();

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $nouvelleAffectation->id,
        'volontaire_entrant_id' => $sortant->id,
        'motif' => 'abandon',
    ])->assertStatus(201);

    expect($sortant->fresh()->statut)->toBe(StatutVolontaire::Operationnel);
    expect($sortant->fresh()->user->statut_compte)->toBe(StatutCompte::Actif);

    // Deux passages désormais dans son historique.
    expect(Affectation::query()->where('volontaire_id', $sortant->id)->count())->toBe(2);
});

it('refuse un remplacement sur une affectation déjà close', function () {
    $sortant = volontaire('+22670000041', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000042', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);

    $affectation = affecter($sortant, $this);
    $affectation->update(['statut' => StatutAffectation::Terminee->value]);

    $reponse = $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id, 'motif' => 'abandon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain("il n'y a rien à remplacer");
});

it('interdit le remplacement à qui n\'a pas le droit de décider', function () {
    $sortant = volontaire('+22670000043', CategorieVolontaire::Operateur);
    $entrant = volontaire('+22670000044', CategorieVolontaire::Operateur, StatutVolontaire::Reserve);
    $affectation = affecter($sortant, $this);

    $chef = compteRemp('+22670000045', 'chef_antenne_regional');
    Sanctum::actingAs($chef);

    expect($chef->can('remplacements.decider'))->toBeFalse();

    $this->postJson('/api/v1/remplacements', [
        'affectation_id' => $affectation->id,
        'volontaire_entrant_id' => $entrant->id, 'motif' => 'abandon',
    ])->assertStatus(403);

    expect(Remplacement::count())->toBe(0);
});
