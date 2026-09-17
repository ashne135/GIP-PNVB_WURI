<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Jobs\AlerterKitsNonRestituesJob;
use App\Models\Affectation;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Kit;
use App\Models\KitMouvement;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\UniteSupervision;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Kits\ServiceAlertesKits;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Cadrage, section 13 — Parc de kits, mouvements et non-restitution.
 *
 * Deux règles structurent tout le module, et ces tests les protègent :
 *
 *   LE KIT SUIT LA PERSONNE, PAS LE SITE. Un changement de site n'est pas une
 *   restitution ; un redéploiement d'une région à l'autre non plus.
 *
 *   LE JOURNAL ET LE PARC NE DIVERGENT JAMAIS. Aucun chemin n'écrit le
 *   détenteur d'un kit sans laisser de trace, et réciproquement.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->aujourdhui = now()->toDateString();

    $this->region = Region::query()->create(
        ['code' => 'SUM', 'nom' => 'Soum', 'nombre_sites_alloues' => 180]
    );
    $province = Province::query()->create(
        ['region_id' => $this->region->id, 'code' => 'YATE', 'nom' => 'Yatenga']
    );
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'OUAH', 'nom' => 'Ouahigouya', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $this->region->id,
        'nom' => 'Somyaga', 'type_localite' => 'village',
        'population_totale' => 2600, 'quota_sites' => 1,
    ]);
    $this->centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $this->region->id,
        'code' => 'SUM-OUAH-C001', 'nom' => 'Centre Ouahigouya',
        'nombre_kits' => 2, 'statut' => 'ouvert',
    ]);
    $this->site = Site::query()->create([
        'centre_id' => $this->centre->id, 'localite_id' => $localite->id,
        'region_id' => $this->region->id,
        'code' => 'SUM-OUAH-C001-S01', 'nom' => 'Site Somyaga', 'statut' => 'ouvert', 'ordre_tournee' => 1,
    ]);
    $this->autreSite = Site::query()->create([
        'centre_id' => $this->centre->id, 'localite_id' => $localite->id,
        'region_id' => $this->region->id,
        'code' => 'SUM-OUAH-C001-S02', 'nom' => 'Site Rambo', 'statut' => 'ouvert', 'ordre_tournee' => 2,
    ]);

    $this->admin = compteKit('+22670003900', 'administrateur_national');

    $this->chefAntenne = compteKit('+22670003901', 'chef_antenne_regional');
    $this->chefAntenne->update(['region_id' => $this->region->id]);

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'SUM-2026-V1', 'libelle' => 'Vague Soum 1', 'region_id' => $this->region->id,
        'date_debut_prevue' => $this->aujourdhui, 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    $this->superviseur = compteKit('+22670003902', 'volontaire_superviseur', CategorieVolontaire::Superviseur);
    UniteSupervision::query()->create([
        'vague_id' => $this->vague->id,
        'volontaire_superviseur_id' => $this->superviseur->volontaire->id,
        'centre_principal_id' => $this->centre->id,
        'meme_commune' => true, 'contrainte_respectee' => true,
    ]);

    $this->operateur = compteKit('+22670003903', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $this->affectation = Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->operateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $this->centre->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $this->autreOperateur = compteKit('+22670003904', 'volontaire_operateur', CategorieVolontaire::Operateur);
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->autreOperateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $this->centre->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $this->kit = Kit::query()->create([
        'reference' => 'KIT-SUM-001', 'etat' => 'fonctionnel',
        'centre_courant_id' => $this->centre->id,
    ]);
});

function compteKit(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Kit', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
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

/** Remet le kit de l'essai à l'opérateur, comme le ferait l'administration. */
function remettreLeKit($contexte, array $remplacements = []): void
{
    Sanctum::actingAs($contexte->admin);

    $contexte->postJson("/api/v1/kits/{$contexte->kit->id}/mouvements", array_merge([
        'type' => 'remise',
        'volontaire_destination_id' => $contexte->operateur->volontaire->id,
        'site_id' => $contexte->site->id,
        'etat_constate' => 'bon',
        'photo_source' => 'kits/remise-source.jpg',
        'photo_destination' => 'kits/remise-destination.jpg',
    ], $remplacements))->assertStatus(201);
}

// ---------------------------------------------------------------------------
// Le parc
// ---------------------------------------------------------------------------

it('ajoute un kit au parc et le rend disponible', function () {
    Sanctum::actingAs($this->admin);

    $kit = $this->postJson('/api/v1/kits', [
        'reference' => 'KIT-SUM-002',
        'composition' => ['Tablette', 'Imprimante', 'Batterie', 'Panneau solaire'],
        'centre_courant_id' => $this->centre->id,
    ])->assertStatus(201)->json('data');

    expect($kit['etat'])->toBe('fonctionnel')
        ->and($kit['volontaire_detenteur_id'])->toBeNull()
        ->and($kit['composition'])->toHaveCount(4);
});

it('refuse deux kits portant la même référence', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/kits', ['reference' => 'KIT-SUM-001'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.reference.0', 'Cette référence est déjà utilisée par un autre kit.');
});

it("ne laisse pas corriger un détenteur en silence", function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->admin);

    // La route de modification n'accepte ni le détenteur, ni la perte.
    $this->putJson("/api/v1/kits/{$this->kit->id}", [
        'volontaire_detenteur_id' => $this->autreOperateur->volontaire->id,
        'etat' => 'perdu',
    ])->assertStatus(422);

    expect($this->kit->fresh()->volontaire_detenteur_id)->toBe($this->operateur->volontaire->id);
});

it('donne la synthèse du parc dans mon périmètre', function () {
    remettreLeKit($this);
    Kit::query()->create(['reference' => 'KIT-SUM-003', 'etat' => 'panne',
        'centre_courant_id' => $this->centre->id]);

    Sanctum::actingAs($this->chefAntenne);

    $synthese = $this->getJson('/api/v1/kits/synthese')->assertOk()->json('data');

    expect($synthese['total'])->toBe(2)
        ->and($synthese['attribues'])->toBe(1)
        ->and($synthese['disponibles'])->toBe(0)
        ->and($synthese['par_etat']['panne'])->toBe(1);
});

// ---------------------------------------------------------------------------
// Les mouvements
// ---------------------------------------------------------------------------

it('remet un kit à un agent, avec état constaté et trace', function () {
    remettreLeKit($this);

    $kit = $this->kit->fresh();

    expect($kit->volontaire_detenteur_id)->toBe($this->operateur->volontaire->id)
        ->and($kit->site_courant_id)->toBe($this->site->id)
        ->and($kit->centre_courant_id)->toBe($this->centre->id);

    $mouvement = KitMouvement::query()->first();

    expect($mouvement->type->value)->toBe('remise')
        // Personne ne détenait le kit avant : la source est vide, pas inventée.
        ->and($mouvement->volontaire_source_id)->toBeNull()
        ->and($mouvement->volontaire_destination_id)->toBe($this->operateur->volontaire->id)
        ->and($mouvement->etat_constate)->toBe('bon')
        ->and($mouvement->vague_id)->toBe($this->vague->id);
});

it("exige l'état constaté dès que du matériel change de mains", function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'remise',
        'volontaire_destination_id' => $this->operateur->volontaire->id,
    ])->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($this->kit->fresh()->volontaire_detenteur_id)->toBeNull();
});

it("refuse qu'un agent détienne deux kits à la fois", function () {
    remettreLeKit($this);

    $second = Kit::query()->create([
        'reference' => 'KIT-SUM-004', 'etat' => 'fonctionnel',
        'centre_courant_id' => $this->centre->id,
    ]);

    Sanctum::actingAs($this->admin);

    $reponse = $this->postJson("/api/v1/kits/{$second->id}/mouvements", [
        'type' => 'remise',
        'volontaire_destination_id' => $this->operateur->volontaire->id,
        'etat_constate' => 'bon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('détient déjà le kit KIT-SUM-001');
    expect($second->fresh()->volontaire_detenteur_id)->toBeNull();
});

it("refuse de remettre un kit déjà détenu : c'est un transfert", function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->admin);

    $reponse = $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'remise',
        'volontaire_destination_id' => $this->autreOperateur->volontaire->id,
        'etat_constate' => 'bon',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('Passez par un transfert');
});

it('LE KIT SUIT LA PERSONNE : un changement de site ne change pas de détenteur', function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'changement_site',
        'site_id' => $this->autreSite->id,
        'commentaire' => 'Le kit suit son opérateur sur le site suivant de la tournée.',
    ])->assertStatus(201);

    $kit = $this->kit->fresh();

    expect($kit->site_courant_id)->toBe($this->autreSite->id)
        // Le détenteur est inchangé : ce n'est pas une restitution.
        ->and($kit->volontaire_detenteur_id)->toBe($this->operateur->volontaire->id);
});

it('transfère un kit entre deux agents, avec la source prise du parc', function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'transfert',
        'volontaire_destination_id' => $this->autreOperateur->volontaire->id,
        'etat_constate' => 'usage',
        'photo_source' => 'kits/a.jpg',
        'photo_destination' => 'kits/b.jpg',
        // Le client prétend une autre source : elle doit être ignorée.
        'volontaire_source_id' => $this->superviseur->volontaire->id,
    ])->assertStatus(201);

    $mouvement = KitMouvement::query()->where('type', 'transfert')->first();

    expect($mouvement->volontaire_source_id)->toBe($this->operateur->volontaire->id)
        ->and($mouvement->volontaire_destination_id)->toBe($this->autreOperateur->volontaire->id)
        ->and($this->kit->fresh()->volontaire_detenteur_id)
        ->toBe($this->autreOperateur->volontaire->id);
});

it('restitue un kit : il retourne au parc, libre', function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'restitution',
        'etat_constate' => 'bon',
        'photo_source' => 'kits/rendu.jpg',
        'photo_destination' => 'kits/recu.jpg',
    ])->assertStatus(201);

    $kit = $this->kit->fresh();

    expect($kit->volontaire_detenteur_id)->toBeNull()
        ->and($kit->site_courant_id)->toBeNull()
        ->and($kit->etat)->toBe('fonctionnel')
        ->and($kit->estSolde())->toBeTrue();
});

it("ne remet pas au parc comme fonctionnel un kit rendu endommagé", function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'restitution',
        'etat_constate' => 'endommage',
        'photo_source' => 'kits/rendu.jpg',
        'photo_destination' => 'kits/recu.jpg',
        'commentaire' => 'Écran fendu, imprimante bloquée.',
    ])->assertStatus(201);

    expect($this->kit->fresh()->etat)->toBe('panne');
});

it('enregistre une panne sans retirer le kit à son détenteur', function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->operateur);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'panne',
        'commentaire' => "L'imprimante ne répond plus depuis ce matin.",
    ])->assertStatus(201);

    $kit = $this->kit->fresh();

    // L'agent a toujours le matériel entre les mains : il reste responsable.
    expect($kit->etat)->toBe('panne')
        ->and($kit->volontaire_detenteur_id)->toBe($this->operateur->volontaire->id);
});

it('alerte immédiatement sur une perte, et libère le détenteur', function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->operateur);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'perte_vol',
        'circonstance' => 'vol',
        'commentaire' => 'Sac arraché sur le trajet de retour, plainte déposée à la gendarmerie.',
    ])->assertStatus(201);

    $kit = $this->kit->fresh();

    expect($kit->etat)->toBe('vole')
        // Libéré, sans quoi il ne pourrait jamais recevoir un autre kit.
        ->and($kit->volontaire_detenteur_id)->toBeNull();

    $alerte = Alerte::query()->where('kit_id', $kit->id)->first();

    expect($alerte)->not->toBeNull()
        ->and($alerte->niveau)->toBe('critique')
        ->and($alerte->titre)->toContain('Kit déclaré volé')
        ->and($alerte->message)->toContain('plainte déposée')
        ->and($alerte->code)->toStartWith('ALR-');
});

it('signale un mouvement sans les deux photos, sans le bloquer', function () {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'remise',
        'volontaire_destination_id' => $this->operateur->volontaire->id,
        'etat_constate' => 'bon',
        // Une seule photo : le geste passe, mais il ne passe pas inaperçu.
        'photo_source' => 'kits/remise.jpg',
    ])->assertStatus(201);

    expect($this->kit->fresh()->volontaire_detenteur_id)->toBe($this->operateur->volontaire->id);

    $alerte = Alerte::query()->where('kit_id', $this->kit->id)->first();

    expect($alerte->titre)->toContain('Mouvement de kit sans photo')
        ->and($alerte->role_cible)->toBe('administrateur_national');
});

it("garde l'historique complet du kit, dans l'ordre", function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'changement_site', 'site_id' => $this->autreSite->id,
    ])->assertStatus(201);
    $this->postJson("/api/v1/kits/{$this->kit->id}/mouvements", [
        'type' => 'restitution', 'etat_constate' => 'usage',
        'photo_source' => 'a.jpg', 'photo_destination' => 'b.jpg',
    ])->assertStatus(201);

    $fiche = $this->getJson("/api/v1/kits/{$this->kit->id}")->assertOk()->json('data');

    // Le plus récent d'abord : c'est l'ordre dans lequel on consulte une fiche.
    expect(collect($fiche['mouvements'])->pluck('type')->all())
        ->toBe(['restitution', 'changement_site', 'remise']);
});

// ---------------------------------------------------------------------------
// Non-restitution
// ---------------------------------------------------------------------------

it("ne réclame pas un kit le soir même de la fin de mission", function () {
    remettreLeKit($this);

    $this->affectation->update(['statut' => 'terminee', 'date_fin' => now()->toDateString()]);

    // Le délai de grâce paramétré est de trois jours.
    expect(app(ServiceAlertesKits::class)->kitsNonRestitues())->toHaveCount(0);
});

it('réclame nominativement un kit non rendu passé le délai', function () {
    remettreLeKit($this);

    $this->affectation->update([
        'statut' => 'terminee', 'date_fin' => now()->subDays(5)->toDateString(),
    ]);

    $resultat = app(ServiceAlertesKits::class)->alerterNonRestitues();

    expect($resultat['signales'])->toBe(1);

    $alerte = Alerte::query()->where('type', 'kit_non_restitue')->first();

    expect($alerte->titre)->toContain('KIT-SUM-001')
        // Nominative : « 14 kits non restitués » n'aide personne à les chercher.
        ->and($alerte->message)->toContain($this->operateur->volontaire->matricule)
        ->and($alerte->message)->toContain(now()->subDays(5)->format('d/m/Y'))
        ->and($alerte->portee)->toBe('regionale')
        ->and($alerte->region_id)->toBe($this->region->id);
});

it("ne réclame pas le kit d'un agent qui a repris du service ailleurs", function () {
    remettreLeKit($this);

    $this->affectation->update([
        'statut' => 'terminee', 'date_fin' => now()->subDays(10)->toDateString(),
    ]);

    // Redéployé sur une nouvelle vague : le cadrage dit que le kit le suit.
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->operateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $this->centre->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    expect(app(ServiceAlertesKits::class)->kitsNonRestitues())->toHaveCount(0);
});

it('ne réalerte pas chaque jour sur le même kit', function () {
    remettreLeKit($this);

    $this->affectation->update([
        'statut' => 'terminee', 'date_fin' => now()->subDays(5)->toDateString(),
    ]);

    $premier = app(ServiceAlertesKits::class)->alerterNonRestitues();
    $second = app(ServiceAlertesKits::class)->alerterNonRestitues();

    expect($premier['signales'])->toBe(1)
        ->and($second['signales'])->toBe(0)
        ->and($second['deja_signales'])->toBe(1)
        ->and(Alerte::query()->where('type', 'kit_non_restitue')->count())->toBe(1);
});

it('le job quotidien rattrape les fins de mission hors clôture', function () {
    remettreLeKit($this);

    $this->affectation->update([
        'statut' => 'terminee', 'date_fin' => now()->subDays(4)->toDateString(),
    ]);

    (new AlerterKitsNonRestituesJob)->handle(app(ServiceAlertesKits::class));

    expect(Alerte::query()->where('type', 'kit_non_restitue')->count())->toBe(1);
});

it('respecte le délai paramétré, sans le coder en dur', function () {
    remettreLeKit($this);

    $this->affectation->update([
        'statut' => 'terminee', 'date_fin' => now()->subDay()->toDateString(),
    ]);

    expect(app(ServiceAlertesKits::class)->kitsNonRestitues())->toHaveCount(0);

    Parametre::query()->where('cle', 'kits.delai_alerte_non_restitue_jours')
        ->first()->update(['valeur' => 0]);

    expect(app(ServiceAlertesKits::class)->kitsNonRestitues())->toHaveCount(1);
});

it('expose au chef d\'antenne les kits à aller récupérer', function () {
    remettreLeKit($this);

    $this->affectation->update([
        'statut' => 'terminee', 'date_fin' => now()->subDays(6)->toDateString(),
    ]);

    Sanctum::actingAs($this->chefAntenne);

    $kits = $this->getJson('/api/v1/kits/non-restitues')->assertOk()->json('data');

    expect($kits)->toHaveCount(1)
        ->and($kits[0]['reference'])->toBe('KIT-SUM-001')
        ->and($kits[0]['detenteur']['matricule'])->toBe($this->operateur->volontaire->matricule);
});

// ---------------------------------------------------------------------------
// Droit et périmètre
// ---------------------------------------------------------------------------

it("n'expose à un agent que le kit qu'il détient", function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->autreOperateur);

    expect($this->getJson('/api/v1/kits')->assertOk()->json('data.data'))->toHaveCount(0);
    $this->getJson("/api/v1/kits/{$this->kit->id}")->assertForbidden();

    Sanctum::actingAs($this->operateur);
    expect($this->getJson('/api/v1/kits')->assertOk()->json('data.data'))->toHaveCount(1);
});

it("interdit d'ajouter un kit au parc à qui ne gère pas le parc", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/kits', ['reference' => 'KIT-SUM-009'])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Remontée hors ligne
// ---------------------------------------------------------------------------

it('remonte un mouvement déclaré hors ligne, désigné par la référence du kit', function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->operateur);

    $element = [
        'type' => 'mouvement_kit',
        'uuid_client' => (string) Str::uuid(),
        // La référence est ce qui est écrit sur la mallette : le téléphone
        // peut l'avoir lue sans avoir jamais synchronisé le parc.
        'kit_reference' => 'KIT-SUM-001',
        'type_mouvement' => 'panne',
        'commentaire' => 'Batterie hors service, constat fait sur place.',
        'horodatage_telephone' => now()->subHours(6)->toIso8601String(),
    ];

    $lot = fn () => $this->postJson('/api/v1/sync', [
        'uuid_lot' => (string) Str::uuid(),
        'elements' => [$element],
    ]);

    expect($lot()->assertOk()->json('data.acceptes.0.action'))->toBe('cree');

    $kit = $this->kit->fresh();
    expect($kit->etat)->toBe('panne')
        // Une panne ne retire pas le kit à son détenteur.
        ->and($kit->volontaire_detenteur_id)->toBe($this->operateur->volontaire->id);

    $mouvement = KitMouvement::query()->where('type', 'panne')->first();
    expect($mouvement->horodatage_telephone)->not->toBeNull()
        ->and($mouvement->commentaire)->toContain('Batterie hors service');

    // Rejeu : ni doublon, ni effet rejoué.
    expect($lot()->assertOk()->json('data.acceptes.0.action'))->toBe('existant')
        ->and(KitMouvement::query()->where('type', 'panne')->count())->toBe(1);
});

it("refuse un mouvement hors ligne sur un kit hors de mon périmètre", function () {
    remettreLeKit($this);

    // Un opérateur d'une autre région, sans aucun rattachement à ce kit.
    $ailleurs = compteKit('+22670003905', 'volontaire_operateur', CategorieVolontaire::Operateur);

    Sanctum::actingAs($ailleurs);

    $donnees = $this->postJson('/api/v1/sync', [
        'uuid_lot' => (string) Str::uuid(),
        'elements' => [[
            'type' => 'mouvement_kit',
            'uuid_client' => (string) Str::uuid(),
            'kit_reference' => 'KIT-SUM-001',
            'type_mouvement' => 'perte_vol',
            'commentaire' => 'Tentative depuis un autre périmètre.',
        ]],
    ])->assertOk()->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('droit_refuse')
        ->and($this->kit->fresh()->etat)->toBe('fonctionnel');
});

it("refuse proprement un mouvement hors ligne sans type", function () {
    remettreLeKit($this);
    Sanctum::actingAs($this->operateur);

    $donnees = $this->postJson('/api/v1/sync', [
        'uuid_lot' => (string) Str::uuid(),
        'elements' => [[
            'type' => 'mouvement_kit',
            'uuid_client' => (string) Str::uuid(),
            'kit_reference' => 'KIT-SUM-001',
        ]],
    ])->assertOk()->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('donnees_invalides')
        ->and($donnees['rejetes'][0]['details'])->toHaveKey('type_mouvement')
        ->and($donnees['rejetes'][0]['reessayer'])->toBeFalse();
});

it("annonce le mouvement de kit parmi les types que le serveur sait recevoir", function () {
    Sanctum::actingAs($this->operateur);

    expect($this->getJson('/api/v1/sync/types')->assertOk()->json('data.types'))
        ->toContain('mouvement_kit');
});
