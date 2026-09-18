<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Kit;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * SUPPRIMER POUR DE BON — et refuser plus souvent qu'accepter.
 *
 * Ce qu'on protège :
 *
 *   - une ligne ENGAGÉE ne s'efface pas, et le refus NOMME l'obstacle : une
 *     feuille de présence ne disparaît pas parce qu'on efface un site ;
 *   - un lot n'est jamais tout ou rien : ce qui peut partir part, ce qui reste
 *     est nommé avec son motif ;
 *   - ce qui est DÉTACHÉ n'est pas supprimé : un kit survit à son centre ;
 *   - le droit est À PART : un chef d'antenne ferme et retire, il n'efface pas ;
 *   - la TRACE survit à la ligne, puisque la ligne, elle, ne survit pas.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->region = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 10]);
    $province = Province::query()->create(['region_id' => $this->region->id, 'code' => 'BALE', 'nom' => 'Balé']);
    $this->commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $this->localite = Localite::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1000, 'quota_sites' => 1,
    ]);

    $this->admin = compteEffacement('+22670077001', 'administrateur_national');
    $this->chef = compteEffacement('+22670077002', 'chef_antenne_regional');
    $this->chef->update(['region_id' => $this->region->id]);

    Sanctum::actingAs($this->admin);
});

function compteEffacement(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'SUP', 'prenoms' => ucfirst($role),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function centreEffacement(string $suffixe = '001'): Centre
{
    return Centre::query()->create([
        'commune_id' => test()->commune->id, 'region_id' => test()->region->id,
        'code' => 'BAN-BAGA-C'.$suffixe, 'nom' => 'Centre '.$suffixe,
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
}

function siteEffacement(Centre $centre, string $suffixe = '01'): Site
{
    return Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => test()->localite->id,
        'region_id' => test()->region->id, 'code' => $centre->code.'-S'.$suffixe,
        'nom' => 'Site '.$suffixe, 'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);
}

function ficheEffacement(string $telephone): Volontaire
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'AGENT', 'prenoms' => 'Essai',
        'password' => 'Provisoire#123', 'statut_compte' => StatutCompte::Inactif->value,
    ]);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-OPK'.substr($telephone, -6),
        'categorie' => CategorieVolontaire::Operateur->value,
        'statut' => 'operationnel',
        'niveau_etude' => 'bac',
    ]);
}

it('supprime une fiche d\'essai, son compte, et laisse une trace au journal', function () {
    $fiche = ficheEffacement('+22670077010');
    $userId = $fiche->user_id;

    $reponse = $this->postJson('/api/v1/suppressions', [
        'famille' => 'volontaire',
        'ids' => [$fiche->id],
    ])->assertOk();

    expect($reponse->json('data.supprimes.0.libelle'))->toBe($fiche->matricule)
        ->and($reponse->json('data.refusees'))->toBe([])
        ->and(Volontaire::query()->whereKey($fiche->id)->exists())->toBeFalse()
        // Le compte part avec la fiche : sans elle, il ne se connecterait à rien.
        ->and(User::query()->whereKey($userId)->exists())->toBeFalse();

    // La ligne n'existe plus : le journal est le seul endroit qui sait.
    $trace = Activity::query()->where('log_name', 'suppression')->sole();
    expect($trace->causer_id)->toBe($this->admin->id)
        ->and($trace->getExtraProperty('libelle'))->toBe($fiche->matricule);
});

it('refuse d\'effacer une fiche engagée, et nomme ce qui la retient', function () {
    $fiche = ficheEffacement('+22670077011');
    $centre = centreEffacement();

    $vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addDays(5)->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    DB::table('affectations')->insert([
        'vague_id' => $vague->id, 'volontaire_id' => $fiche->id, 'role_terrain' => 'operateur',
        'centre_id' => $centre->id, 'date_debut' => now()->toDateString(), 'statut' => 'active',
        'origine' => 'tirage_auto', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $reponse = $this->postJson('/api/v1/suppressions', [
        'famille' => 'volontaire',
        'ids' => [$fiche->id],
    ])->assertOk();

    expect($reponse->json('data.supprimes'))->toBe([])
        ->and($reponse->json('data.refusees.0.motif'))->toContain('1 affectations')
        ->and(Volontaire::query()->whereKey($fiche->id)->exists())->toBeTrue();
});

it('supprime ce qui peut l\'être et nomme le reste, sans refuser tout le lot', function () {
    $libre = ficheEffacement('+22670077012');
    $engagee = ficheEffacement('+22670077013');

    // Un kit détenu retient sa fiche : on ne supprime pas quelqu'un qui a du matériel.
    Kit::query()->create([
        'reference' => 'KIT-0001', 'etat' => 'fonctionnel',
        'volontaire_detenteur_id' => $engagee->id,
    ]);

    $reponse = $this->postJson('/api/v1/suppressions', [
        'famille' => 'volontaire',
        'ids' => [$libre->id, $engagee->id, 99999],
    ])->assertOk();

    expect(collect($reponse->json('data.supprimes'))->pluck('id')->all())->toBe([$libre->id]);

    $refusees = collect($reponse->json('data.refusees'))->keyBy('id');

    expect($refusees[$engagee->id]['motif'])->toContain('kits détenus')
        // L'identifiant inconnu ne disparaît pas du compte rendu en silence.
        ->and($refusees[99999]['motif'])->toContain('Introuvable');
});

it('efface un centre avec ses sites, et DÉTACHE le kit sans le détruire', function () {
    $centre = centreEffacement();
    $site = siteEffacement($centre);

    $kit = Kit::query()->create([
        'reference' => 'KIT-0002', 'etat' => 'fonctionnel',
        'centre_courant_id' => $centre->id, 'site_courant_id' => $site->id,
    ]);

    $this->postJson('/api/v1/suppressions', ['famille' => 'centre', 'ids' => [$centre->id]])
        ->assertOk()
        ->assertJsonPath('data.refusees', []);

    expect(Centre::query()->whereKey($centre->id)->exists())->toBeFalse()
        ->and(Site::query()->whereKey($site->id)->exists())->toBeFalse();

    // LE KIT SURVIT : il appartient à l'agent, pas au centre.
    $kit->refresh();
    expect($kit->exists)->toBeTrue()
        ->and($kit->centre_courant_id)->toBeNull()
        ->and($kit->site_courant_id)->toBeNull();
});

it('refuse d\'effacer un centre dont un site porte une feuille de présence', function () {
    $centre = centreEffacement();
    $site = siteEffacement($centre);

    $vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V2', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addDays(5)->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    DB::table('feuilles_presence')->insert([
        'uuid_client' => (string) \Illuminate\Support\Str::uuid(),
        'superviseur_id' => ficheEffacement('+22670077020')->id,
        'site_id' => $site->id, 'centre_id' => $centre->id, 'vague_id' => $vague->id,
        'date_presence' => now()->toDateString(), 'statut' => 'brouillon',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $reponse = $this->postJson('/api/v1/suppressions', ['famille' => 'centre', 'ids' => [$centre->id]])
        ->assertOk();

    // Le centre lui-même est retenu, ET son site l'est aussi : les deux sont dits.
    expect($reponse->json('data.refusees.0.motif'))->toContain('feuilles de présence')
        ->and(Centre::query()->whereKey($centre->id)->exists())->toBeTrue()
        ->and(Site::query()->whereKey($site->id)->exists())->toBeTrue();
});

it('supprime un kit tout juste ajouté, jamais un kit qui a bougé', function () {
    $neuf = Kit::query()->create(['reference' => 'KIT-0003', 'etat' => 'fonctionnel']);
    $utilise = Kit::query()->create(['reference' => 'KIT-0004', 'etat' => 'fonctionnel']);

    DB::table('kit_mouvements')->insert([
        'kit_id' => $utilise->id, 'type' => 'remise', 'effectue_par' => $this->admin->id,
        'effectue_le' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $reponse = $this->postJson('/api/v1/suppressions', [
        'famille' => 'kit',
        'ids' => [$neuf->id, $utilise->id],
    ])->assertOk();

    expect(collect($reponse->json('data.supprimes'))->pluck('libelle')->all())->toBe(['KIT-0003'])
        ->and($reponse->json('data.refusees.0.motif'))->toContain('1 mouvements');
});

it('n\'ouvre la suppression qu\'à l\'administration nationale', function () {
    $fiche = ficheEffacement('+22670077014');

    Sanctum::actingAs($this->chef);

    $this->postJson('/api/v1/suppressions', ['famille' => 'volontaire', 'ids' => [$fiche->id]])
        ->assertStatus(403);

    expect(Volontaire::query()->whereKey($fiche->id)->exists())->toBeTrue();
});

it('refuse une famille qu\'on n\'efface pas ici', function () {
    $this->postJson('/api/v1/suppressions', ['famille' => 'vague', 'ids' => [1]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('famille', 'data.erreurs');
});
