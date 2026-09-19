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
use Spatie\Activitylog\Models\Activity;

/**
 * RATTACHER UN A-OPK À UN SITE.
 *
 * L'A-OPK n'est jamais rattaché à un opérateur : il l'est à sa LOCALITÉ. Le
 * jour où le kit passe sur le site de sa localité, l'opérateur de ce kit
 * devient son supérieur — et la semaine suivante ce n'est plus le même.
 *
 * Ce qu'on protège :
 *   - le rattachement désigne un SITE, et le serveur en tire la localité ;
 *   - la chaîne est RENDUE VISIBLE : centre, superviseur de l'unité, opérateur
 *     dont le kit s'y trouve aujourd'hui ;
 *   - un A-OPK sans site dans sa localité est SIGNALÉ — c'est l'explication de
 *     son absence de tous les tirages ;
 *   - déplacer un agent DÉJÀ DÉPLOYÉ exige un motif, et déplace aussi son
 *     affectation en cours ;
 *   - seuls les A-OPK se rattachent ainsi.
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

    $this->assio = localiteRattachement('Assio', $this->commune, $this->region);
    $this->zakin = localiteRattachement('Zakin', $this->commune, $this->region);

    $this->centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre de Bagassi', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    $this->site = Site::query()->create([
        'centre_id' => $this->centre->id, 'localite_id' => $this->assio->id,
        'region_id' => $this->region->id, 'code' => 'BAN-BAGA-C001-S01', 'nom' => 'Site Assio',
        'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $this->admin = compteRattachement('+22670066001', 'administrateur_national');
    Sanctum::actingAs($this->admin);
});

function localiteRattachement(string $nom, Commune $commune, Region $region): Localite
{
    return Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => $nom, 'type_localite' => 'village', 'population_totale' => 1200, 'quota_sites' => 1,
    ]);
}

function compteRattachement(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'RAT', 'prenoms' => ucfirst($role),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function aopk(string $telephone, ?int $localiteId): Volontaire
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'ASSISTANT', 'prenoms' => 'Test',
        'password' => 'Provisoire#123', 'statut_compte' => StatutCompte::Inactif->value,
    ]);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-ASS'.substr($telephone, -6),
        'categorie' => CategorieVolontaire::Assistant->value,
        'statut' => 'operationnel',
        'niveau_etude' => 'quatrieme',
        'localite_id' => $localiteId,
    ]);
}

it('signale un A-OPK dont la localité n\'a aucun site : sa chaîne est rompue', function () {
    aopk('+22670066010', $this->zakin->id);
    aopk('+22670066011', $this->assio->id);

    $donnees = $this->getJson('/api/v1/volontaires/rattachement')->assertOk()->json('data');

    $parMatricule = collect($donnees['assistants'])->keyBy('matricule');

    // Zakin n'a pas de site : aucun kit n'y passera, donc aucun opérateur ne
    // sera son supérieur. C'est CE signal qui explique son absence des tirages.
    expect($parMatricule['PNVB-ASS066010']['rattachement_rompu'])->toBeTrue()
        ->and($parMatricule['PNVB-ASS066010']['localite'])->toBe('Zakin')
        ->and($parMatricule['PNVB-ASS066011']['rattachement_rompu'])->toBeFalse()
        ->and($parMatricule['PNVB-ASS066011']['site']['code'])->toBe('BAN-BAGA-C001-S01');
});

it('montre pour chaque site son centre, son superviseur et l\'opérateur du jour', function () {
    $vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addDays(10)->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    $superviseur = Volontaire::query()->create([
        'user_id' => compteRattachement('+22670066020', 'volontaire_superviseur')->id,
        'matricule' => 'PNVB-SUP000001', 'categorie' => CategorieVolontaire::Superviseur->value,
        'statut' => 'operationnel', 'niveau_etude' => 'licence',
    ]);
    UniteSupervision::query()->create([
        'vague_id' => $vague->id, 'volontaire_superviseur_id' => $superviseur->id,
        'centre_principal_id' => $this->centre->id, 'meme_commune' => true, 'contrainte_respectee' => true,
    ]);

    $operateur = Volontaire::query()->create([
        'user_id' => compteRattachement('+22670066021', 'volontaire_operateur')->id,
        'matricule' => 'PNVB-OPK000001', 'categorie' => CategorieVolontaire::Operateur->value,
        'statut' => 'operationnel', 'niveau_etude' => 'bac',
    ]);
    $affectationOpk = Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $operateur->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $this->centre->id,
        'date_debut' => now()->toDateString(), 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
    TourneeSite::query()->create([
        'vague_id' => $vague->id, 'centre_id' => $this->centre->id, 'site_id' => $this->site->id,
        'affectation_operateur_id' => $affectationOpk->id, 'ordre' => 1,
        'date_debut' => now()->toDateString(), 'date_fin' => now()->addDays(5)->toDateString(),
        'statut' => 'en_cours',
    ]);

    $site = collect($this->getJson('/api/v1/volontaires/rattachement')->assertOk()->json('data.sites'))->first();

    expect($site['centre']['code'])->toBe('BAN-BAGA-C001')
        ->and($site['superviseur']['matricule'])->toBe('PNVB-SUP000001')
        ->and($site['operateur_du_jour']['matricule'])->toBe('PNVB-OPK000001');
});

it('rattache un lot d\'A-OPK au site choisi, en prenant sa localité', function () {
    $premier = aopk('+22670066030', $this->zakin->id);
    $second = aopk('+22670066031', null);

    $reponse = $this->postJson('/api/v1/volontaires/rattachement', [
        'site_id' => $this->site->id,
        'volontaire_ids' => [$premier->id, $second->id],
    ])->assertOk();

    expect($reponse->json('data.refuses'))->toBe([])
        ->and($premier->fresh()->localite_id)->toBe($this->assio->id)
        ->and($second->fresh()->localite_id)->toBe($this->assio->id)
        // Aucun n'était déployé : aucun déplacement d'affectation.
        ->and(collect($reponse->json('data.rattaches'))->pluck('deplace')->all())->toBe([false, false]);
});

it('exige un motif pour déplacer un A-OPK déjà déployé, et déplace son affectation', function () {
    $fiche = aopk('+22670066040', $this->zakin->id);

    $vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V2', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addDays(10)->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);
    $autreCentre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->region->id,
        'code' => 'BAN-BAGA-C002', 'nom' => 'Second centre', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $affectation = Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $fiche->id,
        'role_terrain' => CategorieVolontaire::Assistant->value, 'centre_id' => $autreCentre->id,
        'localite_id' => $this->zakin->id,
        'date_debut' => now()->toDateString(), 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    // Sans motif : refusé, et la fiche ne bouge pas.
    $sansMotif = $this->postJson('/api/v1/volontaires/rattachement', [
        'site_id' => $this->site->id, 'volontaire_ids' => [$fiche->id],
    ])->assertOk();

    expect($sansMotif->json('data.refuses.0.motif'))->toContain('motif de déplacement')
        ->and($fiche->fresh()->localite_id)->toBe($this->zakin->id);

    // Avec motif : la fiche ET l'affectation suivent.
    $this->postJson('/api/v1/volontaires/rattachement', [
        'site_id' => $this->site->id,
        'volontaire_ids' => [$fiche->id],
        'motif' => 'Le village de Zakin n\'a pas de site : regroupement sur Assio.',
    ])->assertOk()->assertJsonPath('data.rattaches.0.deplace', true);

    $affectation->refresh();
    expect($fiche->fresh()->localite_id)->toBe($this->assio->id)
        ->and($affectation->localite_id)->toBe($this->assio->id)
        ->and($affectation->centre_id)->toBe($this->centre->id);

    $trace = Activity::query()->where('log_name', 'affectation')->latest('id')->first();
    expect($trace->description)->toContain('déplacé')->toContain('Zakin');
});

it('refuse de rattacher qui n\'est pas un A-OPK', function () {
    $operateur = Volontaire::query()->create([
        'user_id' => compteRattachement('+22670066050', 'volontaire_operateur')->id,
        'matricule' => 'PNVB-OPK000002', 'categorie' => CategorieVolontaire::Operateur->value,
        'statut' => 'operationnel', 'niveau_etude' => 'bac',
    ]);

    $reponse = $this->postJson('/api/v1/volontaires/rattachement', [
        'site_id' => $this->site->id, 'volontaire_ids' => [$operateur->id],
    ])->assertOk();

    expect($reponse->json('data.refuses.0.motif'))->toContain('Seuls les A-OPK')
        ->and($operateur->fresh()->localite_id)->toBeNull();
});

it('reste fermé à qui ne modifie pas les fiches', function () {
    $chef = compteRattachement('+22670066060', 'chef_antenne_regional');
    $chef->update(['region_id' => $this->region->id]);

    Sanctum::actingAs($chef);

    $this->getJson('/api/v1/volontaires/rattachement')->assertStatus(403);
    $this->postJson('/api/v1/volontaires/rattachement', [
        'site_id' => $this->site->id, 'volontaire_ids' => [1],
    ])->assertStatus(403);
});
