<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Jobs\GenererExportsPlanifiesJob;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\ExportPlanifie;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Province;
use App\Models\RapportJournalier;
use App\Models\RapportOpkProduction;
use App\Models\Region;
use App\Models\Site;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Exports\ServiceExportsPlanifies;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Cadrage, tâche 18 — les exports planifiés.
 *
 * LA RÈGLE QUE CES TESTS PROTÈGENT AVANT TOUTES LES AUTRES : un fichier est
 * produit PAR PÉRIMÈTRE. Un chef d'antenne télécharge un fichier qui ne
 * contient que sa région — pas un fichier national filtré à l'affichage, qui
 * ferait reposer le cloisonnement sur la bonne foi du client.
 *
 * Et celle qui la suit : une journée sans donnée n'est pas une panne. Elle se
 * dit, au lieu de laisser un écran muet qu'on prendra pour une production ratée.
 */
beforeEach(function () {
    Storage::fake(ServiceExportsPlanifies::DISQUE);

    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->hier = now()->subDay()->toDateString();

    $this->admin = compteExport('+22670008001', 'administrateur_national');
    // Le code d'une région tient en trois caractères, comme en base.
    $this->regionA = territoireExport('NRD', 'Nord', $this->admin);
    $this->regionB = territoireExport('CAS', 'Cascades', $this->admin);

    $this->chefA = compteExport('+22670008002', 'chef_antenne_regional');
    $this->chefA->update(['region_id' => $this->regionA['region']->id]);
});

function telephoneExport(): string
{
    static $rang = 0;

    $rang++;

    return '+2267900'.str_pad((string) $rang, 4, '0', STR_PAD_LEFT);
}

function compteExport(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Exp',
        'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);

    $user->assignRole($role);
    $user->consentements()->create(['version_charte' => '2026.1', 'accepte_le' => now()]);

    return $user->fresh();
}

/** Une région avec juste ce qu'il faut pour porter un rapport. */
function territoireExport(string $code, string $nom, User $admin): array
{
    $region = Region::query()->create([
        'code' => $code, 'nom' => $nom,
        'population_totale' => 100000, 'nombre_sites_alloues' => 50,
    ]);
    $province = Province::query()->create([
        'region_id' => $region->id, 'code' => $code.'P', 'nom' => $nom.' Province',
    ]);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => $code.'C', 'nom' => $nom.' Ville', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => $nom.' Secteur 1', 'type_localite' => 'secteur',
        'population_totale' => 25000, 'quota_sites' => 1,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => $code.'-C001', 'nom' => 'Centre '.$nom,
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $region->id,
        'code' => $code.'-C001-S01', 'nom' => 'Site '.$nom,
        'statut' => 'ouvert', 'ordre_tournee' => 1,
    ]);
    $vague = VagueDeploiement::query()->create([
        'code' => $code.'-2026-V1', 'libelle' => 'Vague '.$nom,
        'region_id' => $region->id,
        'date_debut_prevue' => now()->subWeek()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $admin->id,
        'objectif_enregistrements_par_kit_jour' => 100,
    ]);

    return compact('region', 'province', 'commune', 'localite', 'centre', 'site', 'vague');
}

function rapportExport(array $territoire, string $date, int $enregistres, string $statut = 'vise'): RapportJournalier
{
    $operateur = compteExport(telephoneExport(), 'volontaire_operateur');

    $volontaire = Volontaire::query()->create([
        'user_id' => $operateur->id,
        'matricule' => 'PNVB-OPK'.str_pad((string) $operateur->id, 6, '0', STR_PAD_LEFT),
        'categorie' => CategorieVolontaire::Operateur->value,
        'statut' => 'operationnel',
    ]);

    $rapport = RapportJournalier::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'type' => 'opk',
        'date_rapport' => $date,
        'auteur_volontaire_id' => $volontaire->id,
        'vague_id' => $territoire['vague']->id,
        'site_id' => $territoire['site']->id,
        'centre_id' => $territoire['centre']->id,
        'region_id' => $territoire['region']->id,
        'statut' => $statut,
    ]);

    RapportOpkProduction::query()->create([
        'rapport_id' => $rapport->id,
        'objectif_enregistrements' => 100,
        'enregistrements_realises' => $enregistres,
    ]);

    return $rapport;
}

// ---------------------------------------------------------------------------
// La production
// ---------------------------------------------------------------------------

it('produit un fichier par périmètre, et dit « aucune donnée » au lieu de se taire', function () {
    rapportExport($this->regionA, $this->hier, 120);

    $bilan = app(ServiceExportsPlanifies::class)->genererJournee($this->hier);

    // 6 contenus × (1 national + 2 régions).
    expect(ExportPlanifie::query()->count())->toBe(18)
        ->and($bilan['fichiers'] + $bilan['vides'])->toBe(18);

    $national = ExportPlanifie::query()
        ->where('type', 'rapports')->where('portee', 'national')->first();

    expect($national->statut)->toBe('pret')->and($national->nb_lignes)->toBe(1);
    Storage::disk(ServiceExportsPlanifies::DISQUE)->assertExists($national->chemin);

    // La région sans activité a une ligne, pas un silence — et pas de fichier.
    $cascades = ExportPlanifie::query()
        ->where('type', 'rapports')
        ->where('region_id', $this->regionB['region']->id)
        ->first();

    expect($cascades->statut)->toBe('vide')
        ->and($cascades->chemin)->toBeNull()
        ->and($cascades->message)->toBe('Aucune donnée pour cette journée.');
});

it("n'exporte que les rapports visés", function () {
    rapportExport($this->regionA, $this->hier, 120);
    rapportExport($this->regionA, $this->hier, 999, 'brouillon');

    app(ServiceExportsPlanifies::class)->genererJournee($this->hier);

    $export = ExportPlanifie::query()
        ->where('type', 'rapports')->where('portee', 'national')->first();

    $contenu = Storage::disk(ServiceExportsPlanifies::DISQUE)->get($export->chemin);

    // Le brouillon n'a été contrôlé par personne : il n'entre pas dans un
    // fichier qui circule.
    expect($export->nb_lignes)->toBe(1)
        ->and($contenu)->toContain('120')
        ->and($contenu)->not->toContain('999');
});

it('rejoue une journée sans empiler un second fichier', function () {
    rapportExport($this->regionA, $this->hier, 50);

    $service = app(ServiceExportsPlanifies::class);
    $service->genererJournee($this->hier);

    $premier = ExportPlanifie::query()
        ->where('type', 'rapports')->where('portee', 'national')->first();

    $service->genererJournee($this->hier);

    expect(ExportPlanifie::query()->count())->toBe(18)
        ->and(ExportPlanifie::query()->where('type', 'rapports')->where('portee', 'national')->first()->id)
        ->toBe($premier->id);
});

it('purge les fichiers passés le délai de rétention', function () {
    rapportExport($this->regionA, $this->hier, 10);

    $service = app(ServiceExportsPlanifies::class);
    $service->genererJournee($this->hier);
    $service->genererJournee(now()->subDays(40)->toDateString());

    expect(ExportPlanifie::query()->count())->toBe(36);

    expect($service->purger(30))->toBe(18)
        ->and(ExportPlanifie::query()->count())->toBe(18);
});

it('ne produit rien quand le paramètre le désactive', function () {
    // Par le modèle, pas par une requête : c'est l'enregistrement qui vide le cache.
    Parametre::query()->where('cle', 'exports.actif')->first()->update(['valeur' => '0']);

    (new GenererExportsPlanifiesJob)->handle(app(ServiceExportsPlanifies::class));

    expect(ExportPlanifie::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Le périmètre et le droit
// ---------------------------------------------------------------------------

it("ne montre à un chef d'antenne que les fichiers de sa région", function () {
    app(ServiceExportsPlanifies::class)->genererJournee($this->hier);

    Sanctum::actingAs($this->chefA);

    $exports = $this->getJson('/api/v1/exports')->assertOk()->json('data.data');

    expect($exports)->toHaveCount(6)
        ->and(collect($exports)->pluck('portee')->unique()->all())->toBe(['region'])
        ->and(collect($exports)->pluck('region.code')->unique()->all())->toBe(['NRD']);
});

it("refuse le fichier d'une autre région", function () {
    app(ServiceExportsPlanifies::class)->genererJournee($this->hier);

    $cascades = ExportPlanifie::query()
        ->where('region_id', $this->regionB['region']->id)->firstOrFail();

    Sanctum::actingAs($this->chefA);

    $this->get("/api/v1/exports/{$cascades->id}/telecharger")->assertForbidden();
});

it("n'expose jamais le chemin du fichier sur le disque", function () {
    rapportExport($this->regionA, $this->hier, 10);
    app(ServiceExportsPlanifies::class)->genererJournee($this->hier);

    Sanctum::actingAs($this->admin);

    $premier = $this->getJson('/api/v1/exports')->assertOk()->json('data.data.0');

    expect($premier)->not->toHaveKey('chemin');
});

it("télécharge un fichier prêt, et explique quand il n'y a rien à télécharger", function () {
    rapportExport($this->regionA, $this->hier, 30);
    app(ServiceExportsPlanifies::class)->genererJournee($this->hier);

    Sanctum::actingAs($this->admin);

    $pret = ExportPlanifie::query()->where('statut', 'pret')->firstOrFail();

    $this->get("/api/v1/exports/{$pret->id}/telecharger")
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $vide = ExportPlanifie::query()->where('statut', 'vide')->firstOrFail();

    $this->getJson("/api/v1/exports/{$vide->id}/telecharger")
        ->assertStatus(404)
        ->assertJsonPath('message', 'Aucune donnée pour cette journée.');
});

it("n'ouvre les exports qu'à qui porte la permission", function () {
    $operateur = compteExport('+22670008009', 'volontaire_operateur');

    Sanctum::actingAs($operateur);

    $this->getJson('/api/v1/exports')->assertForbidden();
    $this->postJson('/api/v1/exports/produire')->assertForbidden();
});

it("laisse l'observateur lire les exports, mais jamais en produire", function () {
    app(ServiceExportsPlanifies::class)->genererJournee($this->hier);

    Sanctum::actingAs(compteExport('+22670008010', 'observateur'));

    $this->getJson('/api/v1/exports')->assertOk();
    $this->postJson('/api/v1/exports/produire')->assertForbidden();
});

it('refuse de produire la journée en cours, qui serait incomplète', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/exports/produire', ['date' => now()->toDateString()])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.date.0', "Un export ne se produit que sur une journée écoulée : celle d'aujourd'hui serait incomplète.");
});
