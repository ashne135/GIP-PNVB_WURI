<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\EcartPresence;
use App\Models\FeuillePresence;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * TRAITER UN ÉCART DE PRÉSENCE (décision du client, 17/09/2026).
 *
 * Ce qu'on protège : un droit propre (ecarts.traiter), la région du chef
 * d'antenne, un commentaire obligatoire qui S'AJOUTE aux précédents, et un
 * écart clos qui ne bouge plus.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->bankui = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 10]);
    $this->nando = Region::query()->create(['code' => 'NAN', 'nom' => 'Nando', 'nombre_sites_alloues' => 10]);

    $this->chef = compteEcart('+22670055001', 'CHEF', 'chef_antenne_regional', $this->bankui->id);
    $this->chefNando = compteEcart('+22670055002', 'CHEFN', 'chef_antenne_regional', $this->nando->id);
    $this->observateur = compteEcart('+22670055003', 'OBS', 'observateur');

    $this->ecart = ecartDans($this->bankui, '+22670055010');
});

function compteEcart(string $telephone, string $nom, string $role, ?int $regionId = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => $nom, 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false, 'region_id' => $regionId,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function ecartDans(Region $region, string $telephone): EcartPresence
{
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'P'.$region->code, 'nom' => 'P '.$region->nom]);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'C'.substr($region->code, 0, 3), 'nom' => 'C '.$region->nom, 'type' => 'rurale',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'L '.$region->nom, 'type_localite' => 'village',
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => $region->code.'-C001', 'nom' => 'Centre '.$region->nom, 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $region->id,
        'code' => $region->code.'-C001-S01', 'nom' => 'Site '.$region->nom, 'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $agent = compteEcart($telephone, 'AGENT', 'volontaire_operateur');
    $volontaire = Volontaire::query()->create([
        'user_id' => $agent->id, 'matricule' => 'PNVB-OPK'.substr($telephone, -6),
        'categorie' => CategorieVolontaire::Operateur->value, 'statut' => 'operationnel',
    ]);
    $superviseurUser = compteEcart('+2267'.substr($telephone, -7, 6).'9', 'SUP', 'volontaire_superviseur');
    $superviseur = Volontaire::query()->create([
        'user_id' => $superviseurUser->id, 'matricule' => 'PNVB-SUP'.substr($telephone, -6),
        'categorie' => CategorieVolontaire::Superviseur->value, 'statut' => 'operationnel',
    ]);

    $vague = VagueDeploiement::query()->create([
        'code' => $region->code.'-V1', 'libelle' => 'Vague', 'region_id' => $region->id,
        'date_debut_prevue' => now()->subDays(3)->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $agent->id,
    ]);

    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(), 'site_id' => $site->id, 'centre_id' => $centre->id,
        'vague_id' => $vague->id, 'date_presence' => now()->subDay()->toDateString(),
        'statut' => 'validee', 'superviseur_id' => $superviseur->id, 'valide_le' => now(),
    ]);

    return EcartPresence::query()->create([
        'feuille_presence_id' => $feuille->id, 'volontaire_id' => $volontaire->id,
        'region_id' => $region->id, 'date_constat' => now()->subDay()->toDateString(),
        'type_ecart' => 'present_sans_releve', 'nb_releves_zone' => 0, 'statut' => 'ouvert',
    ]);
}

it('laisse le chef d\'antenne examiner puis clore, commentaires cumulés', function () {
    Sanctum::actingAs($this->chef);

    $this->postJson("/api/v1/presence/ecarts/{$this->ecart->id}/traiter", ['statut' => 'examine'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.commentaire.0', 'Écrivez ce que vous avez constaté : le commentaire est obligatoire.');

    $this->postJson("/api/v1/presence/ecarts/{$this->ecart->id}/traiter", [
        'statut' => 'examine', 'commentaire' => 'Téléphone en panne ce jour-là.',
    ])->assertOk()->assertJsonPath('message', 'Écart marqué comme examiné.');

    $this->postJson("/api/v1/presence/ecarts/{$this->ecart->id}/traiter", [
        'statut' => 'clos', 'commentaire' => 'Présence confirmée par le superviseur.',
    ])->assertOk();

    $ecart = $this->ecart->fresh();
    expect($ecart->statut)->toBe('clos');
    expect($ecart->examine_par)->toBe($this->chef->id);

    $lignes = explode("\n", $ecart->commentaire);
    expect($lignes)->toHaveCount(2);
    expect($lignes[0])->toContain('Test CHEF')->toContain('Téléphone en panne');
    expect($lignes[1])->toContain('Présence confirmée');

    expect(Activity::query()->where('description', 'Écart de présence clos')->where('causer_id', $this->chef->id)->exists())
        ->toBeTrue();

    // Clos : plus rien ne bouge.
    $this->postJson("/api/v1/presence/ecarts/{$this->ecart->id}/traiter", [
        'statut' => 'examine', 'commentaire' => 'Réouverture tentée.',
    ])->assertStatus(422)->assertJsonPath('message', 'Cet écart est déjà clos : il ne peut plus être modifié.');
});

it('refuse l\'écart d\'une autre région, et l\'observateur', function () {
    Sanctum::actingAs($this->chefNando);
    $this->postJson("/api/v1/presence/ecarts/{$this->ecart->id}/traiter", [
        'statut' => 'examine', 'commentaire' => 'Pas ma région pourtant.',
    ])->assertForbidden();

    Sanctum::actingAs($this->observateur);
    $this->postJson("/api/v1/presence/ecarts/{$this->ecart->id}/traiter", [
        'statut' => 'examine', 'commentaire' => 'Lecture seule pourtant.',
    ])->assertForbidden();

    expect($this->ecart->fresh()->statut)->toBe('ouvert');
});

it('filtre la liste et nomme la région et l\'examinateur, jamais une position', function () {
    $autre = ecartDans($this->nando, '+22670055020');
    $autre->update(['type_ecart' => 'releve_zone_declare_absent']);

    Sanctum::actingAs($this->chef);
    $this->postJson("/api/v1/presence/ecarts/{$this->ecart->id}/traiter", [
        'statut' => 'examine', 'commentaire' => 'Vérification en cours.',
    ])->assertOk();

    $liste = $this->getJson('/api/v1/presence/ecarts?statut=examine')->assertOk();

    // Le chef de Bankui ne voit que Bankui, même sans filtre de région.
    expect($liste->json('data.total'))->toBe(1);
    expect($liste->json('data.data.0.region.nom'))->toBe('Bankui');
    expect($liste->json('data.data.0.examine_par.nom'))->toBe('CHEF');
    expect($liste->getContent())->not->toContain('latitude')->not->toContain('longitude');

    $admin = compteEcart('+22670055004', 'ADMIN', 'administrateur_national');
    Sanctum::actingAs($admin);
    $parType = $this->getJson('/api/v1/presence/ecarts?type_ecart=releve_zone_declare_absent')->assertOk();
    expect($parType->json('data.total'))->toBe(1);
    expect($parType->json('data.data.0.region.nom'))->toBe('Nando');
});
