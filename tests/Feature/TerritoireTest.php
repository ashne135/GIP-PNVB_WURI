<?php

use App\Enums\StatutCompte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * LE RÉFÉRENTIEL TERRITORIAL CORRIGÉ À L'ÉCRAN (dérogation au cadrage,
 * décision du client du 17/09/2026).
 *
 * LE JEU D'ESSAI est calculé à la main pour que les quotas se vérifient :
 * 5 sites pour 5 000 habitants, soit 1 site pour 1 000.
 *
 *     Kahin  3 000 hab.  quota 3
 *     Assio  1 000 hab.  quota 1
 *     Boni   1 000 hab.  quota 1
 *
 * Ce qu'on protège : la simulation n'écrit rien ; les quotas se recalculent
 * sur toute la région et leur somme reste égale aux sites alloués ; les
 * populations remontent ; une baisse de quota sous les sites déjà ouverts est
 * signalée ; les codes et le rattachement ne changent pas ; seule
 * l'administration nationale corrige.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->region = Region::query()->create([
        'code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 5,
        'population_hommes' => 2500, 'population_femmes' => 2500, 'population_totale' => 5000,
    ]);
    $this->province = Province::query()->create([
        'region_id' => $this->region->id, 'code' => 'BALE', 'nom' => 'Bale',
        'population_hommes' => 2500, 'population_femmes' => 2500, 'population_totale' => 5000,
    ]);
    $this->commune = Commune::query()->create([
        'province_id' => $this->province->id, 'region_id' => $this->region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
        'population_hommes' => 2600, 'population_femmes' => 2600, 'population_totale' => 5200,
        'population_localites' => 5000,
    ]);

    $this->kahin = localiteTer($this->commune, 'Kahin', 1500, 1500, 3);
    $this->assio = localiteTer($this->commune, 'Assio', 500, 500, 1);
    $this->boni = localiteTer($this->commune, 'Boni', 500, 500, 1);

    $this->national = compteTer('+22670066001', 'administrateur_national');
    $this->chef = compteTer('+22670066002', 'chef_antenne_regional', $this->region->id);

    Sanctum::actingAs($this->national);
});

function compteTer(string $telephone, string $role, ?int $regionId = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'TER', 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false, 'region_id' => $regionId,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function localiteTer(Commune $commune, string $nom, int $hommes, int $femmes, int $quota): Localite
{
    return Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $commune->region_id,
        'nom' => $nom, 'type_localite' => 'village',
        'population_hommes' => $hommes, 'population_femmes' => $femmes,
        'population_totale' => $hommes + $femmes,
        'quota_sites_brut' => ($hommes + $femmes) / 1000, 'quota_sites' => $quota,
    ]);
}

function ouvrirSitesTer(Localite $localite, int $nombre): void
{
    $centre = Centre::query()->firstOrCreate(['code' => 'BAN-BAGA-C001'], [
        'commune_id' => $localite->commune_id, 'region_id' => $localite->region_id,
        'nom' => 'Centre de Bagassi', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    for ($n = 1; $n <= $nombre; $n++) {
        Site::query()->create([
            'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $localite->region_id,
            'code' => sprintf('BAN-BAGA-C001-S%02d', Site::query()->count() + 1),
            'nom' => "Site {$localite->nom} {$n}", 'ordre_tournee' => Site::query()->count() + 1, 'statut' => 'ouvert',
        ]);
    }
}

it('simule sans rien écrire, puis enregistre et recalcule toute la région', function () {
    ouvrirSitesTer($this->kahin, 3);

    // Assio passe de 1 000 à 3 000 habitants : Kahin perd un site au profit d'Assio.
    $apercu = $this->putJson("/api/v1/referentiel/territoire/localites/{$this->assio->id}", [
        'population_hommes' => 1500, 'population_femmes' => 1500, 'simulation' => true,
    ])->assertOk();

    expect($apercu->json('data.simulation'))->toBeTrue();
    expect($apercu->json('message'))->toStartWith('Aperçu : rien n\'est encore enregistré.');

    $changements = collect($apercu->json('data.changements'))->keyBy('localite');
    expect($changements->keys()->sort()->values()->all())->toBe(['Assio', 'Kahin']);
    expect($changements['Kahin'])->toMatchArray(['quota_avant' => 3, 'quota_apres' => 2, 'sites_existants' => 3]);
    expect($changements['Assio'])->toMatchArray(['quota_avant' => 1, 'quota_apres' => 2]);

    // Kahin a déjà 3 sites pour un quota qui tomberait à 2 : c'est signalé.
    expect(collect($apercu->json('data.alertes'))->pluck('localite')->all())->toBe(['Kahin']);
    expect($apercu->json('message'))->toContain('Attention');

    // Rien n'a bougé.
    expect($this->assio->fresh()->population_totale)->toBe(1000);
    expect($this->kahin->fresh()->quota_sites)->toBe(3);
    expect(Activity::query()->where('log_name', 'referentiel')->count())->toBe(0);

    // Pour de vrai, cette fois.
    $this->putJson("/api/v1/referentiel/territoire/localites/{$this->assio->id}", [
        'population_hommes' => 1500, 'population_femmes' => 1500,
    ])->assertOk()->assertJsonPath('data.simulation', false);

    expect($this->assio->fresh()->population_totale)->toBe(3000);
    expect(Localite::query()->where('region_id', $this->region->id)->sum('quota_sites'))->toEqual(5);
    expect($this->kahin->fresh()->quota_sites)->toBe(2);
    expect($this->assio->fresh()->quota_sites)->toBe(2);

    // Les populations remontent ; la population DÉCLARÉE de la commune reste.
    $commune = $this->commune->fresh();
    expect($commune->population_localites)->toBe(7000);
    expect($commune->population_totale)->toBe(5200);
    expect($this->province->fresh()->population_totale)->toBe(7000);
    expect($this->region->fresh()->only(['population_hommes', 'population_femmes', 'population_totale']))
        ->toBe(['population_hommes' => 3500, 'population_femmes' => 3500, 'population_totale' => 7000]);

    $journal = Activity::query()->where('log_name', 'referentiel')->sole();
    expect($journal->causer_id)->toBe($this->national->id);
    expect($journal->properties['avant'])->toMatchArray(['population_hommes' => 500, 'population_femmes' => 500]);
    expect($journal->properties['apres'])->toMatchArray(['population_hommes' => 1500, 'population_femmes' => 1500]);
    expect($journal->properties['quotas_modifies'])->toBe(2);
});

it('ne déplace aucun quota quand la population ne change pas', function () {
    $reponse = $this->putJson("/api/v1/referentiel/territoire/localites/{$this->boni->id}", [
        'latitude' => 11.95, 'longitude' => -3.01, 'type_localite' => 'secteur',
    ])->assertOk();

    expect($reponse->json('data.changements'))->toBe([]);
    expect($this->boni->fresh()->type_localite)->toBe('secteur');
});

it('répartit les sites d\'une région quand leur nombre change', function () {
    $this->putJson("/api/v1/referentiel/territoire/regions/{$this->region->id}", [
        'nombre_sites_alloues' => 7,
    ])->assertOk();

    expect(Localite::query()->where('region_id', $this->region->id)->sum('quota_sites'))->toEqual(7);
    expect($this->kahin->fresh()->quota_sites)->toBe(4);
});

it('ajoute une localité qui prend sa part des sites', function () {
    $this->postJson('/api/v1/referentiel/territoire/localites', [
        'commune_id' => $this->commune->id, 'nom' => 'Assio',
        'type_localite' => 'village', 'population_hommes' => 10, 'population_femmes' => 10,
    ])->assertStatus(422)->assertJsonPath('data.erreurs.nom.0', 'Ce nom existe déjà à cet endroit du référentiel.');

    $reponse = $this->postJson('/api/v1/referentiel/territoire/localites', [
        'commune_id' => $this->commune->id, 'nom' => 'Dora',
        'type_localite' => 'village', 'population_hommes' => 0, 'population_femmes' => 0,
    ])->assertCreated();

    $dora = Localite::query()->where('nom', 'Dora')->sole();
    // Plancher d'un site par localité : Kahin cède le sien.
    expect($dora->quota_sites)->toBe(1);
    expect($dora->est_fictif)->toBeFalse();
    expect($this->kahin->fresh()->quota_sites)->toBe(2);
    expect(collect($reponse->json('data.changements'))->pluck('localite')->sort()->values()->all())
        ->toBe(['Dora', 'Kahin']);
    expect(Localite::query()->where('region_id', $this->region->id)->sum('quota_sites'))->toEqual(5);
});

it('corrige une commune sans toucher aux quotas', function () {
    $this->putJson("/api/v1/referentiel/territoire/communes/{$this->commune->id}", [
        'population_hommes' => 2500, 'population_femmes' => 2500,
        'type' => 'urbaine', 'est_zone_defis_securitaires' => true,
    ])->assertOk();

    expect($this->commune->fresh()->only(['population_totale', 'type']))
        ->toBe(['population_totale' => 5000, 'type' => 'urbaine']);
    expect($this->kahin->fresh()->quota_sites)->toBe(3);
});

it('ne change ni un code, ni la commune d\'une localité', function () {
    $this->putJson("/api/v1/referentiel/territoire/communes/{$this->commune->id}", ['code' => 'XXXX'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.code.0', 'Un code ne change jamais : il figure sur des documents imprimés.');

    $this->putJson("/api/v1/referentiel/territoire/localites/{$this->boni->id}", ['commune_id' => 999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('commune_id', 'data.erreurs');

    $this->putJson("/api/v1/referentiel/territoire/provinces/{$this->province->id}", ['nom' => 'Balé'])->assertOk();
    expect($this->province->fresh()->only(['code', 'nom']))->toBe(['code' => 'BALE', 'nom' => 'Balé']);
});

it('laisse le chef d\'antenne consulter sa région, sans rien corriger', function () {
    $ailleurs = Region::query()->create(['code' => 'NAN', 'nom' => 'Nando', 'nombre_sites_alloues' => 3]);

    Sanctum::actingAs($this->chef);

    $regions = $this->getJson('/api/v1/referentiel/territoire/regions')->assertOk();
    expect(collect($regions->json('data'))->pluck('nom')->all())->toBe(['Bankui']);
    expect($regions->json('data.0.somme_quotas'))->toEqual(5);
    expect($regions->json('data.0.localites_count'))->toBe(3);
    expect($regions->json('data.0'))->not->toHaveKey('contour_geojson');

    $this->getJson('/api/v1/referentiel/territoire/localites?commune_id='.$this->commune->id)
        ->assertOk()->assertJsonPath('data.total', 3);

    $this->putJson("/api/v1/referentiel/territoire/localites/{$this->boni->id}", ['population_hommes' => 1])
        ->assertForbidden();
    $this->putJson("/api/v1/referentiel/territoire/regions/{$ailleurs->id}", ['nombre_sites_alloues' => 9])
        ->assertForbidden();
});

it('repère les communes en écart et les localités dont le quota n\'est pas atteint', function () {
    ouvrirSitesTer($this->kahin, 3);
    ouvrirSitesTer($this->assio, 1);

    $communes = $this->getJson('/api/v1/referentiel/territoire/communes?avec_ecart=1')->assertOk();
    expect(collect($communes->json('data.data'))->pluck('nom')->all())->toBe(['Bagassi']);

    $restantes = $this->getJson('/api/v1/referentiel/territoire/localites?quota_non_atteint=1')->assertOk();
    expect(collect($restantes->json('data.data'))->pluck('nom')->all())->toBe(['Boni']);
});
