<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\SignalArrivee;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Affectation\ServiceVagues;
use App\Services\Comptes\ServiceAccesRattrapage;
use App\Services\Comptes\ServiceCycleDeVieCompte;
use Carbon\CarbonInterface;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * LE RATTRAPAGE APRÈS LA FIN D'UNE MISSION.
 *
 * Un agent travaille le dernier jour sans réseau ; la vague est clôturée avant
 * que son téléphone ait pu envoyer. Deux règles protègent à la fois sa journée
 * et la fermeture réelle de son accès :
 *
 *   - un élément est jugé À LA DATE DE L'ACTION : l'affectation qui couvrait ce
 *     jour-là compte, même terminée, pendant le délai de rattrapage ;
 *   - le jeton d'un accès fermé ne sert plus qu'à envoyer la file, pour des
 *     actions antérieures à la fermeture, et expire au terme du délai.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    // La mission a commencé la veille : une action faite il y a quelques minutes
    // tombe toujours dans ses jours, quelle que soit l'heure du test.
    $debut = now()->subDay()->toDateString();

    $region = Region::query()->create(['code' => 'NAZ', 'nom' => 'Nazinon', 'nombre_sites_alloues' => 300]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'BAZE', 'nom' => 'Bazega']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'KOMB', 'nom' => 'Kombissiri', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'Toece', 'type_localite' => 'village',
        'population_totale' => 2100, 'quota_sites' => 1,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => 'NAZ-KOMB-C001', 'nom' => 'Centre Kombissiri',
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $this->site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $region->id,
        'code' => 'NAZ-KOMB-C001-S01', 'nom' => 'Site Toece', 'statut' => 'ouvert',
        'latitude' => 12.0660, 'longitude' => -1.3420, 'rayon_zone_metres' => 500,
    ]);

    $this->admin = compteRattrapage('+22670009900', 'administrateur_national');

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'NAZ-2026-V1', 'libelle' => 'Vague Nazinon 1', 'region_id' => $region->id,
        'date_debut_prevue' => $debut, 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    $this->operateur = compteRattrapage('+22670009902', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $affectationOpk = Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->operateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $centre->id,
        'date_debut' => $debut, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
    TourneeSite::query()->create([
        'vague_id' => $this->vague->id, 'centre_id' => $centre->id, 'site_id' => $this->site->id,
        'affectation_operateur_id' => $affectationOpk->id, 'ordre' => 1,
        'date_debut' => $debut, 'date_fin' => now()->addDays(6)->toDateString(),
        'statut' => 'en_cours',
    ]);

    $this->assistant = compteRattrapage('+22670009903', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $this->assistant->volontaire->update(['localite_id' => $localite->id]);
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->assistant->volontaire->id,
        'role_terrain' => CategorieVolontaire::Assistant->value, 'centre_id' => $centre->id,
        'localite_id' => $localite->id,
        'date_debut' => $debut, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
});

function compteRattrapage(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Rattrapage', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
        // Identifiants déjà remis : rouvrir l'accès ne relance aucun envoi.
        'etat_remise' => 'premiere_connexion_effectuee',
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

/** Le signal d'arrivée tel que le téléphone le met en file, avec l'heure du geste. */
function signalRattrapage(CarbonInterface $geste, array $remplacements = []): array
{
    return array_merge([
        'type' => 'signal_arrivee',
        'uuid_client' => (string) Str::uuid(),
        'horodatage_action' => $geste->toIso8601String(),
        'type_signal' => 'arrivee',
        'latitude' => 12.0661,
        'longitude' => -1.3421,
        'horodatage_telephone' => $geste->toIso8601String(),
        'precision_gps' => 10,
    ], $remplacements);
}

function lotRattrapage(array $elements): array
{
    return ['uuid_lot' => (string) Str::uuid(), 'elements' => $elements];
}

function cloturerLaVague(): void
{
    app(ServiceVagues::class)->cloturer(test()->vague->fresh(), test()->admin);
}

// ---------------------------------------------------------------------------
// Le jeton d'un accès fermé
// ---------------------------------------------------------------------------

it("restreint le jeton d'un assistant à la fermeture de son accès, sans le révoquer", function () {
    $this->assistant->createToken('telephone');

    cloturerLaVague();

    $assistant = $this->assistant->fresh();
    $jeton = $assistant->tokens()->first();

    expect($assistant->statut_compte)->toBe(StatutCompte::Ferme)
        ->and($assistant->acces_ferme_le)->not->toBeNull()
        ->and($jeton)->not->toBeNull()
        ->and($jeton->abilities)->toBe([ServiceAccesRattrapage::CAPACITE])
        // Le délai est un paramètre : 7 jours par défaut.
        ->and($jeton->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString());
});

it("ne touche pas au jeton d'un opérateur, qui reste disponible entre deux vagues", function () {
    $this->operateur->createToken('telephone');

    cloturerLaVague();

    $operateur = $this->operateur->fresh();

    expect($operateur->statut_compte)->toBe(StatutCompte::Disponible)
        ->and($operateur->acces_ferme_le)->toBeNull()
        ->and($operateur->tokens()->first()->abilities)->toBe(['*']);
});

it("ne laisse au jeton restreint que l'envoi de la file", function () {
    $jeton = $this->assistant->createToken('telephone')->plainTextToken;

    cloturerLaVague();

    $this->withToken($jeton)->getJson('/api/v1/referentiel/regions')
        ->assertForbidden()
        ->assertJsonPath('data.action_requise', 'acces_ferme');

    app('auth')->forgetGuards();

    $this->withToken($jeton)->getJson('/api/v1/sync/types')->assertOk();
});

it('accepte en rattrapage une action faite pendant la mission', function () {
    $jeton = $this->assistant->createToken('telephone')->plainTextToken;
    $geste = now()->subMinutes(30);

    cloturerLaVague();

    $donnees = $this->withToken($jeton)
        ->postJson('/api/v1/sync', lotRattrapage([signalRattrapage($geste, ['site_id' => $this->site->id])]))
        ->assertOk()
        ->json('data');

    expect($donnees['nb_acceptes'])->toBe(1)
        ->and($donnees['acceptes'][0]['action'])->toBe('cree')
        ->and(SignalArrivee::query()->count())->toBe(1);
});

it("refuse en rattrapage une action postérieure à la fermeture, ou sans heure", function () {
    $jeton = $this->assistant->createToken('telephone')->plainTextToken;

    cloturerLaVague();

    $apres = signalRattrapage(now()->addMinutes(5), ['site_id' => $this->site->id]);
    $sansHeure = signalRattrapage(now()->subMinutes(30), ['site_id' => $this->site->id]);
    unset($sansHeure['horodatage_action']);

    $donnees = $this->withToken($jeton)
        ->postJson('/api/v1/sync', lotRattrapage([$apres, $sansHeure]))
        ->assertOk()
        ->json('data');

    expect($donnees['nb_rejetes'])->toBe(2)
        ->and(collect($donnees['rejetes'])->pluck('code')->unique()->values()->all())->toBe(['acces_ferme'])
        // Le temps n'y changera rien : le téléphone ne doit pas réessayer.
        ->and($donnees['rejetes'][0]['reessayer'])->toBeFalse()
        ->and(SignalArrivee::query()->count())->toBe(0);
});

it('coupe tout à la fin du délai de rattrapage', function () {
    $jeton = $this->assistant->createToken('telephone')->plainTextToken;

    cloturerLaVague();

    $this->travel(8)->days();

    $this->withToken($jeton)->getJson('/api/v1/sync/types')->assertUnauthorized();
});

it("rend ses droits au jeton quand l'accès se rouvre avant la fin du délai", function () {
    $jeton = $this->assistant->createToken('telephone')->plainTextToken;

    cloturerLaVague();

    app(ServiceCycleDeVieCompte::class)->ouvrirPourAffectation($this->assistant->volontaire->fresh());

    $enBase = $this->assistant->tokens()->first();

    expect($this->assistant->fresh()->acces_ferme_le)->toBeNull()
        ->and($enBase->abilities)->toBe(['*'])
        ->and($enBase->expires_at)->toBeNull();

    // Plus aucun refus « accès fermé » : la route répond selon les droits du rôle.
    $reponse = $this->withToken($jeton)->getJson('/api/v1/referentiel/regions');

    expect($reponse->json('data.action_requise'))->not->toBe('acces_ferme');
});

// ---------------------------------------------------------------------------
// Juger l'action à la date où elle a été faite
// ---------------------------------------------------------------------------

it("juge un élément à la date de l'action, même après la clôture de la vague", function () {
    $geste = now()->subMinutes(30);

    cloturerLaVague();

    // L'opérateur reste disponible : son jeton n'est pas restreint.
    Sanctum::actingAs($this->operateur->fresh());

    $donnees = $this->postJson('/api/v1/sync', lotRattrapage([signalRattrapage($geste)]))
        ->assertOk()
        ->json('data');

    expect($donnees['nb_acceptes'])->toBe(1);

    // La tournée du jour du geste retrouve le site, et la vague reste la bonne.
    $signal = SignalArrivee::query()->first();

    expect($signal->site_id)->toBe($this->site->id)
        ->and($signal->vague_id)->toBe($this->vague->id);
});

it('refuse une action dont la mission est terminée depuis plus que le délai', function () {
    $geste = now()->subMinutes(30);

    cloturerLaVague();

    $this->travel(8)->days();

    Sanctum::actingAs($this->operateur->fresh());

    $donnees = $this->postJson('/api/v1/sync', lotRattrapage([signalRattrapage($geste)]))
        ->assertOk()
        ->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('regle_metier')
        ->and(SignalArrivee::query()->count())->toBe(0);
});

it("ne compte jamais une affectation proposée comme une mission", function () {
    $propose = compteRattrapage('+22670009904', 'volontaire_operateur', CategorieVolontaire::Operateur);

    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $propose->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $this->site->centre_id,
        'date_debut' => now()->subDay()->toDateString(), 'statut' => 'proposee', 'origine' => 'tirage_auto',
    ]);

    Sanctum::actingAs($propose);

    $donnees = $this->postJson('/api/v1/sync', lotRattrapage([
        signalRattrapage(now()->subMinutes(30), ['site_id' => $this->site->id]),
    ]))->assertOk()->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('regle_metier');
});
