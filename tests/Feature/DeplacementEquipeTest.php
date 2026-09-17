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
use App\Models\UniteSupervision;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * DÉPLACER UN AGENT DÉPLOYÉ (cadrage, sections 4, 6, 13 et 15).
 *
 * CE QUE CES TESTS PROTÈGENT, dans l'ordre d'importance :
 *
 *  1. UNE JOURNÉE ATTESTÉE NE SE DÉPLACE PAS. Seule la feuille validée fait
 *     foi ; déplacer un agent dont le superviseur a déjà signé la journée
 *     reviendrait à contredire cette signature après coup.
 *  2. LES CATÉGORIES RESTENT ÉTANCHES ET L'A-OPK NE BOUGE JAMAIS. Il est
 *     recruté localement et rattaché à sa localité — le cadrage l'exclut du
 *     redéploiement, quel que soit le confort d'interface.
 *  3. LE KIT SUIT SON PORTEUR, et les passages à venir de l'ancien centre
 *     cessent de le désigner. Un kit resté au mauvais centre, ou un passage
 *     nommant un agent parti, sont deux incohérences silencieuses.
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

    $this->centreA = centreDep($this->commune, 'BAN-BAGA-C001', 'Centre de Bagassi');
    $this->centreB = centreDep($this->commune, 'BAN-BAGA-C002', 'Centre de Boromo');
    // Un centre RÉEL, mais hors de cette vague : y envoyer un agent le
    // laisserait sans aucun passage de kit.
    $this->centreHorsVague = centreDep($this->commune, 'BAN-BAGA-C003', 'Centre hors vague');

    $this->localite = Localite::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1500,
    ]);
    $this->siteA = Site::query()->create([
        'centre_id' => $this->centreA->id, 'localite_id' => $this->localite->id,
        'region_id' => $this->region->id, 'code' => 'BAN-BAGA-C001-S01',
        'nom' => 'Site Assio', 'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $this->admin = compteDep('+22670012001', 'administrateur_national');
    $this->chef = compteDep('+22670012002', 'chef_antenne_regional');
    $this->chef->update(['region_id' => $this->region->id]);

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->subDays(5)->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);
    $this->vague->centres()->attach([$this->centreA->id, $this->centreB->id]);

    // L'opérateur, son kit et le passage qu'il tient.
    $this->operateur = volontaireDep('+22670012010', CategorieVolontaire::Operateur);
    $this->affectationOpk = affectationDep($this->vague, $this->operateur, $this->centreA, 'operateur');

    $this->kit = Kit::query()->create([
        'reference' => 'KIT-0001', 'etat' => 'fonctionnel',
        'volontaire_detenteur_id' => $this->operateur->id,
        'centre_courant_id' => $this->centreA->id,
        'site_courant_id' => $this->siteA->id,
    ]);

    $this->passage = TourneeSite::query()->create([
        'vague_id' => $this->vague->id, 'centre_id' => $this->centreA->id,
        'site_id' => $this->siteA->id, 'kit_id' => $this->kit->id,
        'affectation_operateur_id' => $this->affectationOpk->id, 'ordre' => 1,
        'date_debut' => now()->toDateString(), 'date_fin' => now()->addDays(5)->toDateString(),
        'statut' => 'en_cours',
    ]);

    // L'A-OPK, qui ne se redéploie jamais.
    $this->assistant = volontaireDep('+22670012011', CategorieVolontaire::Assistant, $this->localite->id);
    $this->affectationAopk = affectationDep(
        $this->vague, $this->assistant, $this->centreA, 'assistant', $this->localite->id
    );

    // Le superviseur, rattaché à une unité et non à un centre.
    $this->superviseur = volontaireDep('+22670012012', CategorieVolontaire::Superviseur);
    $unite = UniteSupervision::query()->create([
        'vague_id' => $this->vague->id,
        'volontaire_superviseur_id' => $this->superviseur->id,
        'centre_principal_id' => $this->centreA->id,
        'centre_secondaire_id' => $this->centreB->id,
    ]);
    $this->affectationSup = Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->superviseur->id,
        'role_terrain' => 'superviseur', 'unite_supervision_id' => $unite->id,
        'date_debut' => now()->subDays(5)->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
});

function compteDep(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'DEP', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function volontaireDep(string $telephone, CategorieVolontaire $categorie, ?int $localiteId = null): Volontaire
{
    $user = compteDep($telephone, $categorie->role()->value);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
        'categorie' => $categorie->value, 'statut' => 'operationnel',
        'localite_id' => $localiteId,
    ]);
}

function centreDep(Commune $commune, string $code, string $nom): Centre
{
    return Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $commune->region_id,
        'code' => $code, 'nom' => $nom, 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
}

function affectationDep(
    VagueDeploiement $vague,
    Volontaire $volontaire,
    Centre $centre,
    string $role,
    ?int $localiteId = null
): Affectation {
    return Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $volontaire->id,
        'role_terrain' => $role, 'centre_id' => $centre->id, 'localite_id' => $localiteId,
        'date_debut' => now()->subDays(5)->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
}

function deplacer(int $affectationId, int $destinationId, string $motif = 'Renfort demandé par le terrain')
{
    return test()->putJson("/api/v1/equipes/affectations/{$affectationId}/centre", [
        'centre_destination_id' => $destinationId,
        'motif' => $motif,
    ]);
}

it('déplace un opérateur : son kit le suit et ses passages à venir le libèrent', function () {
    Sanctum::actingAs($this->admin);

    deplacer($this->affectationOpk->id, $this->centreB->id)
        ->assertOk()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'BAN-BAGA-C002')
            && str_contains($m, 'kit'));

    expect($this->affectationOpk->fresh()->centre_id)->toBe($this->centreB->id);

    // LE KIT SUIT SON PORTEUR, et n'est plus posé sur un site de l'ancien centre.
    $kit = $this->kit->fresh();
    expect($kit->centre_courant_id)->toBe($this->centreB->id)
        ->and($kit->site_courant_id)->toBeNull();

    // Le passage à venir ne désigne plus un agent qui n'y travaille plus.
    expect($this->passage->fresh()->affectation_operateur_id)->toBeNull();
});

it('REFUSE de redéployer un A-OPK', function () {
    Sanctum::actingAs($this->admin);

    deplacer($this->affectationAopk->id, $this->centreB->id)
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'jamais redéployé'));

    expect($this->affectationAopk->fresh()->centre_id)->toBe($this->centreA->id);
});

it('refuse de déplacer un superviseur, qui dépend d’une unité', function () {
    Sanctum::actingAs($this->admin);

    deplacer($this->affectationSup->id, $this->centreB->id)
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'unité de supervision'));
});

it('refuse un centre qui ne fait pas partie de la vague', function () {
    Sanctum::actingAs($this->admin);

    deplacer($this->affectationOpk->id, $this->centreHorsVague->id)
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'ne fait pas partie de cette vague'));

    expect($this->affectationOpk->fresh()->centre_id)->toBe($this->centreA->id);
});

it('REFUSE quand une journée à venir est déjà attestée par une feuille validée', function () {
    FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'site_id' => $this->siteA->id, 'centre_id' => $this->centreA->id,
        'vague_id' => $this->vague->id, 'tournee_site_id' => $this->passage->id,
        'date_presence' => now()->toDateString(), 'statut' => 'validee',
        'superviseur_id' => $this->superviseur->id, 'valide_le' => now(),
    ]);

    Sanctum::actingAs($this->admin);

    deplacer($this->affectationOpk->id, $this->centreB->id)
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'seule la feuille validée fait foi'));

    expect($this->affectationOpk->fresh()->centre_id)->toBe($this->centreA->id);
});

it('exige un motif, qui sera lu longtemps après la décision', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson("/api/v1/equipes/affectations/{$this->affectationOpk->id}/centre", [
        'centre_destination_id' => $this->centreB->id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.motif.0', 'Indiquez le motif du déplacement : il sera lu bien après votre décision.');
});

it('ferme le déplacement à qui ne porte pas le droit', function () {
    // Le chef d'antenne consulte et corrige des passages, mais ne déplace pas
    // un agent déployé : cette décision reste nationale.
    Sanctum::actingAs($this->chef);

    deplacer($this->affectationOpk->id, $this->centreB->id)->assertForbidden();
});

it('déplace l’ÉQUIPE d’un centre, et dit ce qui reste sur place', function () {
    $second = volontaireDep('+22670012013', CategorieVolontaire::Operateur);
    affectationDep($this->vague, $second, $this->centreA, 'operateur');

    Sanctum::actingAs($this->admin);

    $reponse = $this->putJson("/api/v1/equipes/centres/{$this->centreA->id}/deplacer", [
        'centre_destination_id' => $this->centreB->id,
        'motif' => 'Réaffectation de la commune',
    ])->assertOk();

    expect($reponse->json('data.deplaces'))->toHaveCount(2)
        // L'A-OPK reste, et le message le DIT : annoncer « équipe déplacée »
        // en le laissant derrière en silence serait une demi-vérité.
        ->and($reponse->json('data.assistants_restes'))->toBe(1)
        ->and($reponse->json('message'))->toContain('restent sur place');

    expect($this->affectationOpk->fresh()->centre_id)->toBe($this->centreB->id)
        ->and($this->affectationAopk->fresh()->centre_id)->toBe($this->centreA->id);
});
