<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Incident;
use App\Models\Kit;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * LE PILOTAGE DU TABLEAU DE BORD : ce qui appelle une action.
 *
 * Ce qu'on protège :
 *   - les files d'attente sont lues EN DIRECT, pas dans les agrégats de la nuit ;
 *   - chaque compteur respecte le périmètre : un chef d'antenne ne compte pas
 *     la région voisine ;
 *   - la vague rend ses effectifs par catégorie — des personnes distinctes
 *     d'une même région, qui s'additionnent sans mentir.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->bankui = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 10]);
    $this->nando = Region::query()->create(['code' => 'NAN', 'nom' => 'Nando', 'nombre_sites_alloues' => 10]);

    $this->admin = comptePil('+22670099001', 'administrateur_national');
    $this->chefNando = comptePil('+22670099002', 'chef_antenne_regional');
    $this->chefNando->update(['region_id' => $this->nando->id]);

    $this->centre = centrePil($this->bankui);

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Première vague', 'region_id' => $this->bankui->id,
        'date_debut_prevue' => now()->subDays(3)->toDateString(),
        'date_fin_prevue' => now()->addDays(10)->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    \Illuminate\Support\Facades\DB::table('vague_centres')->insert([
        'vague_id' => $this->vague->id, 'centre_id' => $this->centre->id,
        'date_ouverture' => now()->subDays(3)->toDateString(), 'statut' => 'ouvert',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Deux agents déployés, et une fiche encore sans profil.
    $superviseur = volontairePil('+22670099010', CategorieVolontaire::Superviseur);
    $operateur = volontairePil('+22670099011', CategorieVolontaire::Operateur);
    $this->aQualifier = volontairePil('+22670099012', null);

    foreach ([[$superviseur, 'superviseur'], [$operateur, 'operateur']] as [$volontaire, $role]) {
        Affectation::query()->create([
            'vague_id' => $this->vague->id, 'volontaire_id' => $volontaire->id,
            'role_terrain' => $role, 'centre_id' => $this->centre->id,
            'date_debut' => now()->subDays(3)->toDateString(),
            'statut' => 'active', 'origine' => 'tirage_auto',
        ]);
    }

    Kit::query()->create(['reference' => 'KIT-0001', 'centre_courant_id' => $this->centre->id, 'etat' => 'fonctionnel']);
    Kit::query()->create(['reference' => 'KIT-0002', 'centre_courant_id' => $this->centre->id, 'etat' => 'panne']);
});

function comptePil(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'PIL', 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function centrePil(Region $region): Centre
{
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'P'.$region->code, 'nom' => 'P '.$region->nom]);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'C'.substr($region->code, 0, 3), 'nom' => 'Commune '.$region->nom, 'type' => 'rurale',
    ]);

    return Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => $region->code.'-C001', 'nom' => 'Centre '.$region->nom,
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
}

function volontairePil(string $telephone, ?CategorieVolontaire $categorie): Volontaire
{
    $user = comptePil($telephone, $categorie?->role()->value ?? 'volontaire_operateur');

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-'.($categorie?->prefixeMatricule() ?? 'AQU').substr($telephone, -6),
        'categorie' => $categorie?->value,
        'statut' => 'operationnel',
        'niveau_etude' => $categorie === null ? null : 'licence',
    ]);
}

it('rend la vague en cours avec ses effectifs par catégorie', function () {
    Sanctum::actingAs($this->admin);

    $reponse = $this->getJson('/api/v1/tableau-bord/pilotage')->assertOk();

    expect($reponse->json('data.vague.code'))->toBe('BAN-2026-V1');
    expect($reponse->json('data.vague.region'))->toBe('Bankui');
    expect($reponse->json('data.vague.agents'))->toBe([
        'superviseur' => 1, 'operateur' => 1, 'assistant' => 0, 'total' => 2,
    ]);
    expect($reponse->json('data.vague.centres'))->toBe(['total' => 1, 'ouverts' => 1]);
    expect($reponse->json('data.vague.jours_restants'))->toBe(10);
});

it('compte les files d\'attente qui appellent une action', function () {
    Sanctum::actingAs($this->admin);

    Incident::query()->create([
        'uuid_client' => (string) Illuminate\Support\Str::uuid(),
        'numero' => 'INC-2026-0001',
        'declarant_user_id' => $this->admin->id, 'centre_id' => $this->centre->id,
        'region_id' => $this->bankui->id, 'gravite' => 4, 'statut' => 'nouveau',
        'declare_le' => now()->subDay(),
        'recit' => 'Le kit ne démarre plus depuis ce matin.',
        'echeance_escalade' => now()->subHour(),
    ]);

    $reponse = $this->getJson('/api/v1/tableau-bord/pilotage')->assertOk();

    expect($reponse->json('data.volontaires.a_qualifier'))->toBe(1);
    expect($reponse->json('data.volontaires.sans_niveau'))->toBe(1);
    // Les trois fiches naissent sans identifiants remis.
    expect($reponse->json('data.volontaires.identifiants_non_remis'))->toBe(3);
    expect($reponse->json('data.terrain.incidents_ouverts'))->toBe(1);
    expect($reponse->json('data.terrain.incidents_critiques'))->toBe(1);
    expect($reponse->json('data.terrain.incidents_en_retard'))->toBe(1);
    expect($reponse->json('data.materiel'))->toMatchArray([
        'total' => 2, 'disponibles' => 1, 'hors_service' => 1, 'non_restitues' => 0,
    ]);
});

it('ne compte que sa région pour un chef d\'antenne', function () {
    Sanctum::actingAs($this->chefNando);

    $reponse = $this->getJson('/api/v1/tableau-bord/pilotage')->assertOk();

    // La vague et les kits de Bankui ne sont pas les siens.
    expect($reponse->json('data.vague'))->toBeNull();
    expect($reponse->json('data.materiel.total'))->toBe(0);
    expect($reponse->json('data.volontaires.a_qualifier'))->toBe(0);
});

it('reste fermé à qui n\'a pas le droit du tableau de bord', function () {
    Sanctum::actingAs($this->aQualifier->user);

    $this->getJson('/api/v1/tableau-bord/pilotage')->assertForbidden();
});
