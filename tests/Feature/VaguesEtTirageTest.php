<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutAffectation;
use App\Enums\StatutCompte;
use App\Enums\StatutVague;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Kit;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\UniteSupervision;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Affectation\ServiceVagues;
use App\Services\Affectation\TirageAffectations;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Tâche 5 : vagues, affectation automatique sous contraintes, proposition avant
 * validation.
 *
 * POINT DE VIGILANCE : graine enregistrée, tirage reproductible et auditable.
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

    // Deux communes : de quoi éprouver la contrainte de proximité.
    $this->communeA = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $this->communeB = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BORO', 'nom' => 'Boromo', 'type' => 'rurale',
    ]);

    $this->admin = compteVague('+22670000002', 'administrateur_national');
    Sanctum::actingAs($this->admin);
});

function compteVague(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'TEST', 'prenoms' => ucfirst($role),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user;
}

/** Un centre avec sa localité et son site. */
function centreAvecSite(Commune $commune, int $numero, int $kits = 1): Centre
{
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $commune->region_id,
        'nom' => "{$commune->nom} localite {$numero}", 'type_localite' => 'village',
        'population_totale' => 1500, 'quota_sites' => 1,
    ]);

    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $commune->region_id,
        'code' => "BAN-{$commune->code}-C".str_pad((string) $numero, 3, '0', STR_PAD_LEFT),
        'nom' => "Centre {$commune->nom} {$numero}", 'nombre_kits' => $kits, 'statut' => 'planifie',
    ]);

    Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $commune->region_id,
        'code' => $centre->code.'-S01', 'nom' => "Site {$numero}", 'ordre_tournee' => 1,
    ]);

    return $centre;
}

/** Un vivier de volontaires opérationnels, avec les A-OPK sur leurs localités. */
function creerVivier(int $superviseurs, int $operateurs, array $localites = []): void
{
    $n = 0;

    foreach (range(1, $superviseurs) as $i) {
        $user = User::query()->create([
            'telephone' => '+2267'.str_pad((string) (1000000 + $n++), 7, '0', STR_PAD_LEFT),
            'nom' => 'SUP', 'prenoms' => "Agent {$i}", 'password' => 'x',
            'statut_compte' => StatutCompte::Inactif->value,
        ]);
        Volontaire::query()->create([
            'user_id' => $user->id, 'matricule' => sprintf('PNVB-SUP%06d', $i),
            'categorie' => 'superviseur', 'statut' => 'operationnel',
        ]);
    }

    foreach (range(1, $operateurs) as $i) {
        $user = User::query()->create([
            'telephone' => '+2267'.str_pad((string) (2000000 + $n++), 7, '0', STR_PAD_LEFT),
            'nom' => 'OPK', 'prenoms' => "Agent {$i}", 'password' => 'x',
            'statut_compte' => StatutCompte::Inactif->value,
        ]);
        Volontaire::query()->create([
            'user_id' => $user->id, 'matricule' => sprintf('PNVB-OPK%06d', $i),
            'categorie' => 'operateur', 'statut' => 'operationnel',
        ]);
    }

    foreach ($localites as $i => $localiteId) {
        $user = User::query()->create([
            'telephone' => '+2267'.str_pad((string) (3000000 + $n++), 7, '0', STR_PAD_LEFT),
            'nom' => 'AOPK', 'prenoms' => 'Agent '.($i + 1), 'password' => 'x',
            'statut_compte' => StatutCompte::Inactif->value,
        ]);
        Volontaire::query()->create([
            'user_id' => $user->id, 'matricule' => sprintf('PNVB-ASS%06d', $i + 1),
            'categorie' => 'assistant', 'statut' => 'operationnel', 'localite_id' => $localiteId,
        ]);
    }
}

function planifierVague(array $centres, $contexte): VagueDeploiement
{
    return app(ServiceVagues::class)->planifier([
        'libelle' => 'Vague de test',
        'region_id' => $contexte->region->id,
        'date_debut_prevue' => now()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
    ], collect($centres)->pluck('id')->all(), $contexte->admin);
}

// ---------------------------------------------------------------------------
// Reproductibilité — le point de vigilance
// ---------------------------------------------------------------------------

it('deux tirages avec la même graine donnent exactement le même résultat', function () {
    $centres = collect(range(1, 4))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 6, operateurs: 8);

    $vague = planifierVague($centres->all(), $this);
    $tirage = app(TirageAffectations::class);

    $premier = $tirage->tirer($vague->fresh(), 424242);
    $empreinte1 = collect($premier->superviseurs)->pluck('centres', 'matricule')->toArray();

    $second = $tirage->tirer($vague->fresh(), 424242);
    $empreinte2 = collect($second->superviseurs)->pluck('centres', 'matricule')->toArray();

    expect($empreinte1)->toBe($empreinte2);
    expect($premier->graine)->toBe(424242);

    // Et une graine différente donne, elle, un autre résultat.
    $autre = $tirage->tirer($vague->fresh(), 999999);
    $empreinte3 = collect($autre->superviseurs)->pluck('centres', 'matricule')->toArray();

    expect($empreinte3)->not->toBe($empreinte1);
});

it('enregistre la graine et la photo des paramètres sur la vague', function () {
    $centres = collect(range(1, 2))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 2, operateurs: 4);

    $vague = planifierVague($centres->all(), $this);

    app(TirageAffectations::class)->tirer($vague, 777);
    $vague->refresh();

    expect($vague->graine_tirage)->toBe(777);
    expect($vague->statut)->toBe(StatutVague::Proposee);

    // Les paramètres sont figés : rejouer plus tard, après qu'un seuil a changé,
    // doit redonner le même résultat.
    expect($vague->contraintes_tirage)->toHaveKeys([
        'centres_par_superviseur', 'distance_max_centres_km', 'kits_par_centre_max',
    ]);
    // Le paramètre repasse par JSON : on compare la valeur, pas son type.
    expect((float) $vague->contraintes_tirage['distance_max_centres_km'])->toBe(25.0);
});

it('rejoue un tirage et confirme qu\'il est identique', function () {
    $centres = collect(range(1, 4))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 5, operateurs: 6);

    $vague = planifierVague($centres->all(), $this);
    app(TirageAffectations::class)->tirer($vague, 123456);

    $reponse = $this->getJson("/api/v1/vagues/{$vague->id}/verifier-tirage")->assertOk();

    expect($reponse->json('data.identique'))->toBeTrue();
    expect($reponse->json('data.ecarts'))->toBeEmpty();
    expect($reponse->json('message'))->toContain('exactement le même résultat');
});

// ---------------------------------------------------------------------------
// Contraintes du tirage
// ---------------------------------------------------------------------------

it('donne exactement deux centres par superviseur', function () {
    $centres = collect(range(1, 6))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 5, operateurs: 8);

    $vague = planifierVague($centres->all(), $this);
    $resultat = app(TirageAffectations::class)->tirer($vague, 42);

    // 6 centres, appariés 2 par 2 : 3 unités de supervision.
    expect($resultat->superviseurs)->toHaveCount(3);

    foreach ($resultat->superviseurs as $superviseur) {
        expect($superviseur['centres'])->toHaveCount(2);
    }

    expect(UniteSupervision::query()->count())->toBe(3);
});

it('apparie en priorité les centres de la même commune', function () {
    // Quatre centres, deux par commune : l'appariement doit rester intra-commune.
    $centres = collect([
        centreAvecSite($this->communeA, 1),
        centreAvecSite($this->communeA, 2),
        centreAvecSite($this->communeB, 1),
        centreAvecSite($this->communeB, 2),
    ]);
    creerVivier(superviseurs: 4, operateurs: 6);

    $vague = planifierVague($centres->all(), $this);
    $resultat = app(TirageAffectations::class)->tirer($vague, 2026);

    expect($resultat->superviseurs)->toHaveCount(2);

    foreach ($resultat->superviseurs as $superviseur) {
        expect($superviseur['meme_commune'])->toBeTrue();
        expect($superviseur['contrainte_respectee'])->toBeTrue();
    }

    expect($resultat->contraintesNonSatisfaites())->toBe(0);
});

it('signale un centre resté seul quand leur nombre est impair', function () {
    $centres = collect(range(1, 3))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 3, operateurs: 4);

    $vague = planifierVague($centres->all(), $this);
    $resultat = app(TirageAffectations::class)->tirer($vague, 7);

    $messages = collect($resultat->anomalies)->pluck('message')->implode(' | ');
    expect($messages)->toContain('reste seul dans son unité de supervision');
    expect($resultat->contraintesNonSatisfaites())->toBe(1);
});

it('donne un opérateur par kit, deux pour un centre à deux kits', function () {
    $centres = collect([
        centreAvecSite($this->communeA, 1, kits: 2),
        centreAvecSite($this->communeA, 2, kits: 1),
    ]);
    creerVivier(superviseurs: 2, operateurs: 5);

    $vague = planifierVague($centres->all(), $this);
    $resultat = app(TirageAffectations::class)->tirer($vague, 55);

    // 2 kits + 1 kit = 3 opérateurs.
    expect($resultat->operateurs)->toHaveCount(3);
});

it('rattache les A-OPK à leur localité, sans les tirer au sort', function () {
    $centre = centreAvecSite($this->communeA, 1);
    $localite = Site::query()->where('centre_id', $centre->id)->value('localite_id');

    creerVivier(superviseurs: 1, operateurs: 2, localites: [$localite]);

    $vague = planifierVague([$centre], $this);
    $resultat = app(TirageAffectations::class)->tirer($vague, 11);

    expect($resultat->assistants)->toHaveCount(1);
    expect($resultat->assistants[0]['localite_id'])->toBe($localite);

    // L'A-OPK n'a pas de rang de tirage : il n'a pas été tiré.
    $affectation = Affectation::query()->where('role_terrain', 'assistant')->first();
    expect($affectation->rang_tirage)->toBeNull();
    expect($affectation->centre_id)->toBe($centre->id);
});

it('signale un vivier insuffisant sans bloquer le tirage', function () {
    $centres = collect(range(1, 4))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    // Un seul superviseur pour deux paires, deux opérateurs pour quatre kits.
    creerVivier(superviseurs: 1, operateurs: 2);

    $vague = planifierVague($centres->all(), $this);
    $resultat = app(TirageAffectations::class)->tirer($vague, 3);

    $messages = collect($resultat->anomalies)->pluck('message')->implode(' | ');
    expect($messages)->toContain('Plus aucun superviseur disponible');
    expect($messages)->toContain('Plus aucun opérateur disponible');

    // Le tirage aboutit malgré tout : l'administrateur décide ensuite.
    expect($resultat->superviseurs)->toHaveCount(1);
    expect($resultat->operateurs)->toHaveCount(2);
});

it('n\'affecte jamais un agent déjà engagé ailleurs', function () {
    $centres = collect(range(1, 2))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 1, operateurs: 2);

    $vague1 = planifierVague($centres->all(), $this);
    app(TirageAffectations::class)->tirer($vague1, 1);

    // Le vivier est vide : tous sont déjà proposés sur la première vague.
    $autresCentres = collect(range(3, 4))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    $vague2 = planifierVague($autresCentres->all(), $this);
    $resultat = app(TirageAffectations::class)->tirer($vague2, 2);

    expect($resultat->superviseurs)->toBeEmpty();
    expect($resultat->operateurs)->toBeEmpty();
    expect(collect($resultat->anomalies)->pluck('message')->implode(' '))
        ->toContain('Plus aucun superviseur disponible');
});

// ---------------------------------------------------------------------------
// Proposition et validation
// ---------------------------------------------------------------------------

it('n\'ouvre aucun accès tant que la proposition n\'est pas validée', function () {
    $centres = collect(range(1, 2))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 1, operateurs: 2);

    $vague = planifierVague($centres->all(), $this);
    $reponse = $this->postJson("/api/v1/vagues/{$vague->id}/tirer", ['graine' => 99])->assertOk();

    expect($reponse->json('message'))->toContain("Rien n'est encore notifié");

    // Les affectations existent, mais en PROPOSÉE.
    expect(Affectation::query()->where('statut', 'proposee')->count())->toBeGreaterThan(0);
    expect(Affectation::query()->where('statut', 'active')->count())->toBe(0);

    // Et aucun compte n'est ouvert.
    expect(User::query()->where('statut_compte', 'actif')->whereHas('volontaire')->count())->toBe(0);
});

it('la validation active les affectations et ouvre les accès', function () {
    $centre = centreAvecSite($this->communeA, 1);
    $localite = Site::query()->where('centre_id', $centre->id)->value('localite_id');
    creerVivier(superviseurs: 1, operateurs: 1, localites: [$localite]);

    Kit::query()->create(['reference' => 'KIT-001', 'etat' => 'fonctionnel']);

    $vague = planifierVague([$centre], $this);
    $this->postJson("/api/v1/vagues/{$vague->id}/tirer", ['graine' => 5])->assertOk();

    $reponse = $this->postJson("/api/v1/vagues/{$vague->id}/valider")->assertOk();

    expect($reponse->json('message'))->toContain('validée et active');
    expect($vague->fresh()->statut)->toBe(StatutVague::Active);

    expect(Affectation::query()->where('statut', 'proposee')->count())->toBe(0);
    expect(Affectation::query()->where('statut', 'active')->count())->toBe(3);

    // Les accès sont ouverts pour les agents engagés.
    expect(User::query()->where('statut_compte', 'actif')->whereHas('volontaire')->count())->toBe(3);

    // Le kit suit son opérateur.
    $kit = Kit::query()->first();
    expect($kit->volontaire_detenteur_id)->not->toBeNull();
    expect($kit->centre_courant_id)->toBe($centre->id);
});

it('permet d\'ajuster une affectation avant validation, pas après', function () {
    $centres = collect(range(1, 2))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 2, operateurs: 3);

    $vague = planifierVague($centres->all(), $this);
    app(TirageAffectations::class)->tirer($vague, 8);

    $affectation = Affectation::query()->where('role_terrain', 'operateur')->first();
    $remplacant = Volontaire::query()->disponiblesPourTirage()
        ->categorie(CategorieVolontaire::Operateur)->first();

    $this->putJson("/api/v1/vagues/{$vague->id}/affectations/{$affectation->id}", [
        'volontaire_id' => $remplacant->id,
    ])->assertOk();

    expect($affectation->fresh()->volontaire_id)->toBe($remplacant->id);
    expect($affectation->fresh()->origine)->toBe('ajustement_manuel');

    // Une fois validée, l'ajustement n'est plus possible : c'est un remplacement.
    $this->postJson("/api/v1/vagues/{$vague->id}/valider")->assertOk();

    $autre = Volontaire::query()->disponiblesPourTirage()
        ->categorie(CategorieVolontaire::Operateur)->first();

    if ($autre) {
        $this->putJson("/api/v1/vagues/{$vague->id}/affectations/{$affectation->id}", [
            'volontaire_id' => $autre->id,
        ])->assertStatus(403);
    }
});

it('refuse un ajustement qui changerait de catégorie', function () {
    $centres = collect(range(1, 2))->map(fn ($i) => centreAvecSite($this->communeA, $i));
    creerVivier(superviseurs: 3, operateurs: 3);

    $vague = planifierVague($centres->all(), $this);
    app(TirageAffectations::class)->tirer($vague, 6);

    $affectationOpk = Affectation::query()->where('role_terrain', 'operateur')->first();
    $superviseurLibre = Volontaire::query()->disponiblesPourTirage()
        ->categorie(CategorieVolontaire::Superviseur)->first();

    $reponse = $this->putJson("/api/v1/vagues/{$vague->id}/affectations/{$affectationOpk->id}", [
        'volontaire_id' => $superviseurLibre->id,
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('catégories sont étanches');
});

it('refuse la validation d\'une vague qui n\'est pas une proposition', function () {
    $centre = centreAvecSite($this->communeA, 1);
    $vague = planifierVague([$centre], $this);

    // Encore au brouillon : rien à valider.
    $this->postJson("/api/v1/vagues/{$vague->id}/valider")->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Clôture
// ---------------------------------------------------------------------------

it('la clôture recalcule les accès selon la catégorie', function () {
    $centre = centreAvecSite($this->communeA, 1);
    $localite = Site::query()->where('centre_id', $centre->id)->value('localite_id');
    creerVivier(superviseurs: 1, operateurs: 1, localites: [$localite]);

    $vague = planifierVague([$centre], $this);
    app(TirageAffectations::class)->tirer($vague, 4);
    app(ServiceVagues::class)->valider($vague->fresh(), $this->admin);

    $reponse = $this->postJson("/api/v1/vagues/{$vague->id}/cloturer")->assertOk();

    expect($reponse->json('message'))->toContain('accès recalculés');
    expect($vague->fresh()->statut)->toBe(StatutVague::Cloturee);
    expect(Affectation::query()->where('statut', StatutAffectation::Terminee->value)->count())->toBe(3);

    // Les catégories TOURNANTES gardent leur accès en DISPONIBLE…
    $operateur = Volontaire::query()->categorie(CategorieVolontaire::Operateur)->first();
    expect($operateur->user->fresh()->statut_compte)->toBe(StatutCompte::Disponible);

    // …l'A-OPK, rattaché à sa localité, voit le sien FERMÉ.
    $assistant = Volontaire::query()->categorie(CategorieVolontaire::Assistant)->first();
    expect($assistant->user->fresh()->statut_compte)->toBe(StatutCompte::Ferme);
});

it('signale les kits non restitués à la clôture', function () {
    $centre = centreAvecSite($this->communeA, 1);
    creerVivier(superviseurs: 1, operateurs: 1);
    Kit::query()->create(['reference' => 'KIT-001', 'etat' => 'fonctionnel']);

    $vague = planifierVague([$centre], $this);
    app(TirageAffectations::class)->tirer($vague, 9);
    app(ServiceVagues::class)->valider($vague->fresh(), $this->admin);

    $reponse = $this->postJson("/api/v1/vagues/{$vague->id}/cloturer")->assertOk();

    expect($reponse->json('data.bilan.kits_non_restitues'))->toBe(1);
    expect($reponse->json('message'))->toContain('kits ne sont ni restitués ni transférés');
});

// ---------------------------------------------------------------------------
// Droits et périmètre
// ---------------------------------------------------------------------------

it('refuse la planification hors de la région de la vague', function () {
    $autreRegion = Region::query()->create(['code' => 'KAD', 'nom' => 'Kadiogo', 'nombre_sites_alloues' => 2247]);
    $provinceKad = Province::query()->create([
        'region_id' => $autreRegion->id, 'code' => 'KADIOGO', 'nom' => 'Kadiogo',
    ]);
    $communeKad = Commune::query()->create([
        'province_id' => $provinceKad->id, 'region_id' => $autreRegion->id,
        'code' => 'OUAG', 'nom' => 'Ouagadougou', 'type' => 'urbaine',
    ]);
    $centreKad = centreAvecSite($communeKad, 1);

    $reponse = $this->postJson('/api/v1/vagues', [
        'libelle' => 'Vague incohérente',
        'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'centres' => [$centreKad->id],
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('ne sont pas dans la région de la vague');
});

it('seul un administrateur national planifie et valide', function () {
    $chef = compteVague('+22670000040', 'chef_antenne_regional');
    $centre = centreAvecSite($this->communeA, 1);

    Sanctum::actingAs($chef);

    $this->postJson('/api/v1/vagues', [
        'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'centres' => [$centre->id],
    ])->assertStatus(403);

    expect($chef->can('vagues.planifier'))->toBeFalse();
    expect($this->admin->can('vagues.planifier'))->toBeTrue();
    expect($this->admin->can('vagues.valider'))->toBeTrue();
});
