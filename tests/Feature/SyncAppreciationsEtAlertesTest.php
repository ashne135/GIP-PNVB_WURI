<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Affectation;
use App\Models\Alerte;
use App\Models\AppreciationReponse;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\RapportJournalier;
use App\Models\RapportSuiviAgent;
use App\Models\Region;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * DEUX GESTES DU TERRAIN, FAITS SANS RÉSEAU.
 *
 *   - le DROIT DE RÉPONSE de l'agent noté (cadrage, section 9) : horodaté, non
 *     modifiable, et jamais enregistré deux fois quand le téléphone renvoie ;
 *   - l'ACCUSÉ DE LECTURE d'une alerte : savoir qui a lu, et quand, à l'heure
 *     où il a lu — pas à l'heure où son téléphone a retrouvé du réseau.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $aujourdhui = now()->toDateString();

    $region = Region::query()->create(['code' => 'OUB', 'nom' => 'Oubri', 'nombre_sites_alloues' => 260]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'OUBR', 'nom' => 'Oubritenga']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'ZINI', 'nom' => 'Ziniaré', 'type' => 'urbaine',
    ]);
    Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'Gondé', 'type_localite' => 'village', 'population_totale' => 1800, 'quota_sites' => 1,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => 'OUB-ZINI-C001', 'nom' => 'Centre Ziniaré', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    $admin = compteGeste('+22670007600', 'administrateur_national');

    $vague = VagueDeploiement::query()->create([
        'code' => 'OUB-2026-V1', 'libelle' => 'Vague Oubri 1', 'region_id' => $region->id,
        'date_debut_prevue' => $aujourdhui, 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $admin->id,
    ]);

    $this->superviseur = compteGeste('+22670007601', 'volontaire_superviseur', CategorieVolontaire::Superviseur);
    $this->operateur = compteGeste('+22670007602', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $this->autreOperateur = compteGeste('+22670007603', 'volontaire_operateur', CategorieVolontaire::Operateur);

    foreach ([$this->operateur, $this->autreOperateur] as $agent) {
        Affectation::query()->create([
            'vague_id' => $vague->id, 'volontaire_id' => $agent->volontaire->id,
            'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $centre->id,
            'date_debut' => $aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
        ]);
    }

    // Le rapport du superviseur, visé, qui apprécie l'opérateur.
    $rapport = RapportJournalier::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'type' => 'superviseur',
        'date_rapport' => $aujourdhui,
        'auteur_volontaire_id' => $this->superviseur->volontaire->id,
        'vague_id' => $vague->id,
        'centre_id' => $centre->id,
        'region_id' => $region->id,
        'statut' => 'vise',
    ]);

    $this->suivi = RapportSuiviAgent::query()->create([
        'rapport_id' => $rapport->id,
        'volontaire_id' => $this->operateur->volontaire->id,
        'categorie_agent' => 'opk',
        'production' => 'peu_satisfaisant',
        'anomalies' => ['retard'],
        'observation' => 'Arrivé à 9 h 40 sur le site.',
    ]);

    $this->alerte = Alerte::query()->create([
        'code' => 'ALR-2026-990001',
        'type' => 'descendante',
        'titre' => 'Changement de site demain',
        'message' => 'Le kit se déplace demain sur le site de Gondé.',
        'niveau' => 'important',
        'portee' => 'volontaire',
        'volontaire_id' => $this->operateur->volontaire->id,
        'publiee_le' => now()->subHour(),
    ]);
});

function compteGeste(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Geste', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
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

function lotGestes(array $elements): array
{
    return ['uuid_lot' => (string) Str::uuid(), 'elements' => $elements];
}

it("enregistre la réponse écrite hors ligne, une seule fois, à l'heure où elle a été écrite", function () {
    Sanctum::actingAs($this->operateur);

    $ecriteLe = now()->subHours(3)->startOfMinute();

    $element = [
        'type' => 'reponse_appreciation',
        'uuid_client' => (string) Str::uuid(),
        'suivi_agent_id' => $this->suivi->id,
        'reponse' => "Crevaison sur la route de Gondé : j'ai prévenu mon superviseur par téléphone à 8 h.",
        'horodatage_telephone' => $ecriteLe->toIso8601String(),
    ];

    $this->postJson('/api/v1/sync', lotGestes([$element]))
        ->assertOk()
        ->assertJsonPath('data.acceptes.0.action', 'cree');

    // Nouveau lot, même réponse : le téléphone n'avait pas vu l'accusé.
    $this->postJson('/api/v1/sync', lotGestes([$element]))
        ->assertOk()
        ->assertJsonPath('data.acceptes.0.action', 'existant');

    $reponse = AppreciationReponse::query()->sole();

    expect($reponse->volontaire_id)->toBe($this->operateur->volontaire->id)
        ->and($reponse->repondu_le->format('Y-m-d H:i'))->toBe($ecriteLe->format('Y-m-d H:i'));
});

it("refuse qu'un autre agent réponde à la place de l'agent noté", function () {
    Sanctum::actingAs($this->autreOperateur);

    $this->postJson('/api/v1/sync', lotGestes([[
        'type' => 'reponse_appreciation',
        'uuid_client' => (string) Str::uuid(),
        'suivi_agent_id' => $this->suivi->id,
        'reponse' => 'Je réponds à sa place.',
    ]]))
        ->assertOk()
        ->assertJsonPath('data.rejetes.0.code', 'droit_refuse');

    expect(AppreciationReponse::query()->count())->toBe(0);
});

it("garde l'accusé de lecture d'une alerte lue hors ligne, à l'heure de la lecture", function () {
    Sanctum::actingAs($this->operateur);

    $lueLe = now()->subMinutes(40)->startOfMinute();

    $element = [
        'type' => 'lecture_alerte',
        'alerte_id' => $this->alerte->id,
        'horodatage_telephone' => $lueLe->toIso8601String(),
    ];

    $this->postJson('/api/v1/sync', lotGestes([$element]))
        ->assertOk()
        ->assertJsonPath('data.acceptes.0.action', 'cree');

    $this->postJson('/api/v1/sync', lotGestes([$element]))
        ->assertOk()
        ->assertJsonPath('data.acceptes.0.action', 'existant');

    $lectures = DB::table('alerte_lectures')->where('alerte_id', $this->alerte->id)->get();

    expect($lectures)->toHaveCount(1)
        ->and(substr((string) $lectures->first()->lu_le, 0, 16))->toBe($lueLe->format('Y-m-d H:i'));
});

it("annonce les deux nouveaux gestes parmi les types que le serveur sait recevoir", function () {
    Sanctum::actingAs($this->operateur);

    $types = $this->getJson('/api/v1/sync/types')->assertOk()->json('data.types');

    expect($types)->toContain('reponse_appreciation')
        ->and($types)->toContain('lecture_alerte');
});
