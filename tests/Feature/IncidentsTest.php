<?php

use App\Enums\CategorieVolontaire;
use App\Enums\GraviteIncident;
use App\Enums\StatutCompte;
use App\Jobs\EscaladerIncidentsJob;
use App\Models\Affectation;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Localite;
use App\Models\NatureIncident;
use App\Models\Parametre;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\UniteSupervision;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Incidents\MoteurEscalade;
use Database\Seeders\NomenclaturesIncidentSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Cadrage, section 10 — Incidents et moteur d'escalade.
 *
 * Ces tests protègent les règles qui font la différence entre un dispositif
 * d'alerte utile et un dispositif que tout le monde apprend à ignorer :
 * on n'alerte que le territoire concerné, l'escalade ne falsifie pas la
 * déclaration du témoin, la prise en charge arrête le compteur, et l'escalade
 * s'arrête au sommet.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);
    $this->seed(NomenclaturesIncidentSeeder::class);

    $this->aujourdhui = now()->toDateString();

    // ----- Région du Nakambé, où se produit l'incident -----
    $this->region = Region::query()->create(
        ['code' => 'NAK', 'nom' => 'Nakambe', 'nombre_sites_alloues' => 500]
    );
    $province = Province::query()->create(
        ['region_id' => $this->region->id, 'code' => 'KOUR', 'nom' => 'Kourweogo']
    );
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BOUS', 'nom' => 'Boussé', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $this->region->id,
        'nom' => 'Niou', 'type_localite' => 'village',
        'population_totale' => 4200, 'quota_sites' => 1,
    ]);
    $this->centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $this->region->id,
        'code' => 'NAK-BOUS-C001', 'nom' => 'Centre Boussé',
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $this->site = Site::query()->create([
        'centre_id' => $this->centre->id, 'localite_id' => $localite->id,
        'region_id' => $this->region->id,
        'code' => 'NAK-BOUS-C001-S01', 'nom' => 'Site Niou', 'statut' => 'ouvert',
        'latitude' => 12.6600, 'longitude' => -1.8900, 'rayon_zone_metres' => 500,
    ]);

    // ----- Une seconde région, pour vérifier qu'on ne l'alerte jamais -----
    $this->autreRegion = Region::query()->create(
        ['code' => 'SAH', 'nom' => 'Sahel', 'nombre_sites_alloues' => 200]
    );

    $this->admin = compteIncident('+22670002900', 'administrateur_national');

    $vague = VagueDeploiement::query()->create([
        'code' => 'NAK-2026-V1', 'libelle' => 'Vague Nakambe 1', 'region_id' => $this->region->id,
        'date_debut_prevue' => $this->aujourdhui, 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    // Le superviseur du centre concerné : premier maillon de la matrice.
    $this->superviseur = compteIncident('+22670002901', 'volontaire_superviseur', CategorieVolontaire::Superviseur);
    UniteSupervision::query()->create([
        'vague_id' => $vague->id,
        'volontaire_superviseur_id' => $this->superviseur->volontaire->id,
        'centre_principal_id' => $this->centre->id,
        'meme_commune' => true, 'contrainte_respectee' => true,
    ]);

    // Un superviseur d'un AUTRE centre : il ne doit jamais être alerté.
    $autreCentre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $this->region->id,
        'code' => 'NAK-BOUS-C002', 'nom' => 'Centre Boussé 2',
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $this->superviseurAilleurs = compteIncident(
        '+22670002902', 'volontaire_superviseur', CategorieVolontaire::Superviseur
    );
    UniteSupervision::query()->create([
        'vague_id' => $vague->id,
        'volontaire_superviseur_id' => $this->superviseurAilleurs->volontaire->id,
        'centre_principal_id' => $autreCentre->id,
        'meme_commune' => true, 'contrainte_respectee' => true,
    ]);

    // Les deux chefs d'antenne : celui de la région, et celui d'ailleurs.
    $this->chefAntenne = compteIncident('+22670002903', 'chef_antenne_regional');
    $this->chefAntenne->update(['region_id' => $this->region->id]);

    $this->chefAilleurs = compteIncident('+22670002904', 'chef_antenne_regional');
    $this->chefAilleurs->update(['region_id' => $this->autreRegion->id]);

    // L'opérateur déclarant, affecté au centre.
    $this->operateur = compteIncident('+22670002905', 'volontaire_operateur', CategorieVolontaire::Operateur);
    Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $this->operateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $this->centre->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $this->natures = NatureIncident::query()->pluck('id', 'code');
});

function compteIncident(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Inc', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
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

/** Une déclaration telle que le formulaire l'envoie. */
function declaration(array $remplacements = []): array
{
    return array_merge([
        'uuid_client' => (string) Str::uuid(),
        'site_id' => test()->site->id,
        'natures' => [test()->natures['panne_informatique']],
        'recit' => 'Le kit ne démarre plus depuis 9h, la tablette reste sur écran noir.',
        'gravite' => GraviteIncident::Modere->value,
        'toujours_en_cours' => 'oui',
    ], $remplacements);
}

// ---------------------------------------------------------------------------
// Déclaration : le canevas, et ce que l'agent ne saisit pas
// ---------------------------------------------------------------------------

it('sert au formulaire les quatre listes du canevas', function () {
    Sanctum::actingAs($this->operateur);

    $donnees = $this->getJson('/api/v1/incidents/canevas')->assertOk()->json('data');

    expect($donnees['natures'])->toHaveCount(17)
        ->and($donnees['impacts'])->toHaveCount(12)
        ->and($donnees['mesures'])->toHaveCount(8)
        ->and($donnees['destinataires'])->toHaveCount(8)
        ->and($donnees['gravites'])->toHaveCount(4)
        ->and($donnees['gravites'][3]['libelle'])->toBe('Niveau 4 — critique');
});

it("déduit tout ce que le canevas marque « automatique »", function () {
    Sanctum::actingAs($this->operateur);

    $incident = $this->postJson('/api/v1/incidents', declaration())
        ->assertStatus(201)
        ->json('data');

    expect($incident['numero'])->toBe('INC-'.now()->year.'-000001')
        // Le déclarant ne retape ni son numéro, ni sa position administrative.
        ->and($incident['declarant_telephone'])->toBe($this->operateur->telephone)
        ->and($incident['centre_id'])->toBe($this->centre->id)
        ->and($incident['region_id'])->toBe($this->region->id)
        ->and($incident['site_id'])->toBe($this->site->id)
        // Les coordonnées du site viennent du référentiel.
        ->and((float) $incident['latitude_site'])->toBe(12.66)
        ->and($incident['statut'])->toBe('nouveau');
});

it("numérote les fiches en série, sans trou ni collision", function () {
    Sanctum::actingAs($this->operateur);

    foreach (range(1, 3) as $rang) {
        $this->postJson('/api/v1/incidents', declaration())->assertStatus(201);
    }

    expect(Incident::query()->orderBy('id')->pluck('numero')->all())->toBe([
        'INC-'.now()->year.'-000001',
        'INC-'.now()->year.'-000002',
        'INC-'.now()->year.'-000003',
    ]);
});

it("pose l'échéance d'escalade à la déclaration, d'après le paramètre du niveau", function () {
    Sanctum::actingAs($this->operateur);

    $incident = $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Critique->value,
        'danger_immediat' => true,
    ]))->assertStatus(201)->json('data');

    $modele = Incident::query()->find($incident['id']);

    // 30 minutes pour un incident critique : la valeur vient des paramètres.
    expect($modele->declare_le->diffInMinutes($modele->echeance_escalade))->toBe(30.0);

    // Et si la coordination change le paramètre, la suivante en tient compte.
    Parametre::query()->where('cle', 'incidents.delai_escalade_minutes.niveau_4')
        ->first()->update(['valeur' => 10]);

    $second = $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Critique->value,
    ]))->assertStatus(201)->json('data');

    $suivant = Incident::query()->find($second['id']);
    expect($suivant->declare_le->diffInMinutes($suivant->echeance_escalade))->toBe(10.0);
});

it("attache les quatre listes à cases multiples du canevas", function () {
    Sanctum::actingAs($this->operateur);

    $incident = $this->postJson('/api/v1/incidents', declaration([
        'natures' => [$this->natures['menace_agression'], $this->natures['securite_personnes']],
        'impacts' => \App\Models\ImpactIncident::query()->limit(2)->pluck('id')->all(),
        'mesures' => \App\Models\MesureIncident::query()->limit(3)->pluck('id')->all(),
        'personnes_informees' => \App\Models\DestinataireIncident::query()->limit(2)->pluck('id')->all(),
        'preuves' => ['photo', 'document'],
    ]))->assertStatus(201)->json('data');

    $modele = Incident::query()->find($incident['id']);

    expect($modele->natures)->toHaveCount(2)
        ->and($modele->impacts)->toHaveCount(2)
        ->and($modele->mesures)->toHaveCount(3)
        ->and($modele->personnesInformees)->toHaveCount(2)
        ->and($modele->preuves)->toBe(['photo', 'document']);
});

it("refuse qu'un déclarant se nomme lui-même responsable du traitement", function () {
    Sanctum::actingAs($this->operateur);

    $incident = $this->postJson('/api/v1/incidents', declaration([
        // Section J : réservée aux responsables habilités.
        'statut' => 'cloture',
        'responsable_traitement_user_id' => $this->operateur->id,
        'rapport_cloture' => "Rien à signaler, j'ai tout réglé.",
    ]))->assertStatus(201)->json('data');

    $modele = Incident::query()->find($incident['id']);

    expect($modele->statut)->toBe('nouveau')
        ->and($modele->responsable_traitement_user_id)->toBeNull()
        ->and($modele->rapport_cloture)->toBeNull();
});

it('exige un récit et au moins une nature', function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/incidents', [
        'uuid_client' => (string) Str::uuid(),
        'gravite' => 2,
    ])->assertStatus(422)
        ->assertJsonPath('data.erreurs.recit.0', "Racontez ce qui s'est passé.")
        ->assertJsonPath('data.erreurs.natures.0', "Indiquez au moins une nature d'incident.");
});

// ---------------------------------------------------------------------------
// La matrice de notification : le bon territoire, et lui seul
// ---------------------------------------------------------------------------

it("n'alerte que le superviseur du centre concerné", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Mineur->value,
    ]))->assertStatus(201);

    $notification = IncidentAction::query()->where('type_action', 'notification')->first();
    $prevenus = collect($notification->destinataires['personnes'])->pluck('user_id');

    expect($prevenus)->toContain($this->superviseur->id)
        // Le superviseur d'un autre centre n'a rien à voir avec cet incident.
        ->not->toContain($this->superviseurAilleurs->id)
        // Niveau 1 : la hiérarchie régionale n'est pas encore concernée.
        ->not->toContain($this->chefAntenne->id);
});

it("n'alerte jamais le chef d'antenne d'une autre région", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Majeur->value,
    ]))->assertStatus(201);

    $prevenus = collect(
        IncidentAction::query()->where('type_action', 'notification')->first()->destinataires['personnes']
    )->pluck('user_id');

    expect($prevenus)->toContain($this->chefAntenne->id)
        ->and($prevenus)->not->toContain($this->chefAilleurs->id)
        // Niveau 3 : le national entre dans la boucle.
        ->and($prevenus)->toContain($this->admin->id);
});

it("ne prévient jamais le déclarant de sa propre déclaration", function () {
    // Le superviseur déclare lui-même : il ne doit pas s'auto-alerter.
    Sanctum::actingAs($this->superviseur);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Mineur->value,
    ]))->assertStatus(201);

    $prevenus = collect(
        IncidentAction::query()->where('type_action', 'notification')->first()->destinataires['personnes']
    )->pluck('user_id');

    expect($prevenus)->not->toContain($this->superviseur->id);
});

it("publie une alerte visible par le seul territoire concerné", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Majeur->value,
    ]))->assertStatus(201);

    // Le superviseur du centre : destinataire direct.
    Sanctum::actingAs($this->superviseur);
    expect($this->getJson('/api/v1/alertes')->assertOk()->json('data.data'))->toHaveCount(1);

    // Le chef d'antenne de la region n'est rattache a aucun centre, mais son
    // perimetre les couvre : il doit voir l'alerte que la matrice lui destine.
    Sanctum::actingAs($this->chefAntenne);
    expect($this->getJson('/api/v1/alertes')->assertOk()->json('data.data'))->toHaveCount(1);

    // Celui d'une autre region ne voit rien, malgre le meme role.
    Sanctum::actingAs($this->chefAilleurs);
    expect($this->getJson('/api/v1/alertes')->assertOk()->json('data.data'))->toHaveCount(0);
});

it("garde la trace nominative de qui a été prévenu, et quand", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Majeur->value,
    ]))->assertStatus(201);

    $notification = IncidentAction::query()->where('type_action', 'notification')->first();

    expect($notification->user_id)->toBeNull()          // acteur SYSTÈME
        ->and($notification->effectue_le)->not->toBeNull()
        ->and($notification->destinataires['personnes'][0])->toHaveKeys(['user_id', 'nom', 'roles'])
        ->and($notification->destinataires['alerte'])->toStartWith('ALR-');
});

it("n'envoie de SMS qu'à partir du niveau paramétré", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Mineur->value,
    ]))->assertStatus(201);

    expect(IncidentAction::query()->where('type_action', 'notification')
        ->first()->destinataires['sms_envoyes'])->toBe(0);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Critique->value,
    ]))->assertStatus(201);

    expect(IncidentAction::query()->where('type_action', 'notification')
        ->latest('id')->first()->destinataires['sms_envoyes'])->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Le moteur d'escalade
// ---------------------------------------------------------------------------

it("remonte un incident que personne n'a pris en charge dans le délai", function () {
    Sanctum::actingAs($this->operateur);

    $id = $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Modere->value,
    ]))->assertStatus(201)->json('data.id');

    // Rien n'a bougé : le moteur n'escalade pas avant l'échéance.
    app(MoteurEscalade::class)->executer();
    expect(Incident::query()->find($id)->niveau_escalade)->toBe(0);

    // Quatre heures plus tard, toujours personne.
    $this->travel(5)->hours();
    $resultat = app(MoteurEscalade::class)->executer();

    expect($resultat['escalades'])->toBe(1);

    $incident = Incident::query()->find($id);

    expect($incident->niveau_escalade)->toBe(1)
        ->and($incident->derniere_escalade_le)->not->toBeNull()
        // La nouvelle échéance repart de maintenant, pas de la déclaration.
        ->and($incident->echeance_escalade->isFuture())->toBeTrue();
});

it("ne touche jamais à la gravité déclarée par le témoin", function () {
    Sanctum::actingAs($this->operateur);

    $id = $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Mineur->value,
    ]))->assertStatus(201)->json('data.id');

    $this->travel(9)->hours();
    app(MoteurEscalade::class)->executer();

    $incident = Incident::query()->find($id);

    // Le niveau de notification monte, la gravité reste celle qu'on a vue.
    expect($incident->gravite)->toBe(GraviteIncident::Mineur)
        ->and($incident->niveau_escalade)->toBe(1);

    // Et l'escalade a bien élargi le cercle des prévenus.
    $escalade = IncidentAction::query()->where('type_action', 'escalade')->latest('id')->first();
    $prevenus = collect($escalade->destinataires['personnes'])->pluck('user_id');

    expect($prevenus)->toContain($this->superviseur->id)
        ->toContain($this->chefAntenne->id);
});

it("arrête l'escalade dès qu'un responsable se nomme", function () {
    Sanctum::actingAs($this->operateur);

    $id = $this->postJson('/api/v1/incidents', declaration())->assertStatus(201)->json('data.id');

    Sanctum::actingAs($this->superviseur);
    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")->assertOk();

    $incident = Incident::query()->find($id);

    expect($incident->statut)->toBe('pris_en_charge')
        ->and($incident->responsable_traitement_user_id)->toBe($this->superviseur->id)
        // Plus d'échéance : le compteur est arrêté.
        ->and($incident->echeance_escalade)->toBeNull();

    $this->travel(2)->days();
    expect(app(MoteurEscalade::class)->executer()['escalades'])->toBe(0);
});

it("cesse de remonter une fois au sommet, sans boucler indéfiniment", function () {
    Sanctum::actingAs($this->operateur);

    $id = $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Critique->value,
    ]))->assertStatus(201)->json('data.id');

    // Quatre passages du moteur, espacés d'une heure chacun.
    foreach (range(1, 5) as $tour) {
        $this->travel(1)->hours();
        app(MoteurEscalade::class)->executer();
    }

    $incident = Incident::query()->find($id);

    expect($incident->niveau_escalade)->toBe(3)
        // Échéance effacée : plus aucune alerte ne partira.
        ->and($incident->echeance_escalade)->toBeNull()
        // L'incident reste ouvert et visible, il n'a pas été clos d'office.
        ->and($incident->statut)->toBe('nouveau');

    expect(IncidentAction::query()->where('incident_id', $id)
        ->where('type_action', 'escalade')->count())->toBe(3);
});

it('expose au responsable ce qui attend une prise en charge', function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Majeur->value,
    ]))->assertStatus(201);
    $this->postJson('/api/v1/incidents', declaration())->assertStatus(201);

    $this->travel(3)->hours();
    app(MoteurEscalade::class)->executer();

    Sanctum::actingAs($this->chefAntenne);

    $enRetard = $this->getJson('/api/v1/incidents/en-retard')->assertOk()->json('data');

    // Seul le majeur a dépassé son délai de 2 h ; le modéré en a 4.
    expect($enRetard)->toHaveCount(1)
        ->and($enRetard[0]['gravite'])->toBe(GraviteIncident::Majeur->value);
});

it('le job planifié fait tourner le moteur', function () {
    Sanctum::actingAs($this->operateur);

    $id = $this->postJson('/api/v1/incidents', declaration([
        'gravite' => GraviteIncident::Critique->value,
    ]))->assertStatus(201)->json('data.id');

    $this->travel(40)->minutes();

    (new EscaladerIncidentsJob)->handle(app(MoteurEscalade::class));

    expect(Incident::query()->find($id)->niveau_escalade)->toBe(1);
});

// ---------------------------------------------------------------------------
// Le traitement — section J
// ---------------------------------------------------------------------------

it("refuse la prise en charge d'un incident déjà pris par un autre", function () {
    Sanctum::actingAs($this->operateur);
    $id = $this->postJson('/api/v1/incidents', declaration())->assertStatus(201)->json('data.id');

    Sanctum::actingAs($this->superviseur);
    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")->assertOk();

    Sanctum::actingAs($this->chefAntenne);
    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('suit le cycle de traitement et refuse les sauts', function () {
    Sanctum::actingAs($this->operateur);
    $id = $this->postJson('/api/v1/incidents', declaration())->assertStatus(201)->json('data.id');

    Sanctum::actingAs($this->chefAntenne);

    // On ne clôt pas un incident que personne n'a pris en charge.
    $this->postJson("/api/v1/incidents/{$id}/cloturer", [
        'rapport_cloture' => 'Fiche ouverte par erreur, aucune suite.',
    ])->assertForbidden();

    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/avancer", [
        'statut' => 'en_cours',
        'mesures_correctives' => 'Kit de secours acheminé depuis le chef-lieu.',
    ])->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/avancer", ['statut' => 'resolu'])->assertOk();

    $incident = Incident::query()->find($id);
    expect($incident->statut)->toBe('resolu')
        ->and($incident->resolu_le)->not->toBeNull()
        ->and($incident->mesures_correctives)->toContain('Kit de secours');
});

it('exige un rapport de clôture qui dise ce qui a été fait', function () {
    Sanctum::actingAs($this->operateur);
    $id = $this->postJson('/api/v1/incidents', declaration())->assertStatus(201)->json('data.id');

    Sanctum::actingAs($this->chefAntenne);
    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/avancer", ['statut' => 'resolu'])->assertOk();

    $this->postJson("/api/v1/incidents/{$id}/cloturer", ['rapport_cloture' => 'ok'])
        ->assertStatus(422);

    $this->postJson("/api/v1/incidents/{$id}/cloturer", [
        'rapport_cloture' => 'Kit remplacé le lendemain, production reprise sans perte de dossiers.',
    ])->assertOk();

    expect(Incident::query()->find($id)->statut)->toBe('cloture');
});

it("rouvre un incident clos en effaçant sa date de résolution", function () {
    Sanctum::actingAs($this->operateur);
    $id = $this->postJson('/api/v1/incidents', declaration())->assertStatus(201)->json('data.id');

    Sanctum::actingAs($this->chefAntenne);
    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/avancer", ['statut' => 'resolu'])->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/cloturer", [
        'rapport_cloture' => 'Panne réparée sur place par le technicien.',
    ])->assertOk();

    $this->postJson("/api/v1/incidents/{$id}/avancer", [
        'statut' => 'en_cours',
        'commentaire' => 'La panne est revenue le surlendemain.',
    ])->assertOk();

    $incident = Incident::query()->find($id);

    expect($incident->statut)->toBe('en_cours')
        // Garder une date de résolution fausserait tous les délais.
        ->and($incident->resolu_le)->toBeNull();

    expect(IncidentAction::query()->where('incident_id', $id)
        ->where('type_action', 'reouverture')->count())->toBe(1);
});

it("garde l'historique complet de la fiche, du signalement à la clôture", function () {
    Sanctum::actingAs($this->operateur);
    $id = $this->postJson('/api/v1/incidents', declaration())->assertStatus(201)->json('data.id');

    Sanctum::actingAs($this->superviseur);
    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/commenter", [
        'commentaire' => 'Technicien prévenu, passage prévu demain matin.',
    ])->assertStatus(201);

    $fiche = $this->getJson("/api/v1/incidents/{$id}")->assertOk()->json('data');

    expect(collect($fiche['actions'])->pluck('type_action')->all())
        ->toBe(['creation', 'notification', 'prise_en_charge', 'commentaire']);
});

// ---------------------------------------------------------------------------
// Droit et périmètre
// ---------------------------------------------------------------------------

it("n'expose à un déclarant que ses propres incidents", function () {
    Sanctum::actingAs($this->operateur);
    $this->postJson('/api/v1/incidents', declaration())->assertStatus(201);

    Sanctum::actingAs($this->superviseurAilleurs);
    $sien = $this->postJson('/api/v1/incidents', declaration([
        'site_id' => null, 'centre_id' => null,
        'recit' => 'Conflit verbal à l\'entrée du centre, sans suite.',
    ]))->assertStatus(201)->json('data.id');

    $ids = collect($this->getJson('/api/v1/incidents')->assertOk()->json('data.data'))->pluck('id');

    expect($ids)->toContain($sien)->toHaveCount(1);
});

it("interdit de traiter un incident à qui n'a pas le droit", function () {
    Sanctum::actingAs($this->operateur);
    $id = $this->postJson('/api/v1/incidents', declaration())->assertStatus(201)->json('data.id');

    // L'opérateur a incidents.declarer, pas incidents.traiter.
    $this->postJson("/api/v1/incidents/{$id}/prendre-en-charge")->assertForbidden();
});

// ---------------------------------------------------------------------------
// Remontée hors ligne
// ---------------------------------------------------------------------------

it("remonte un incident déclaré hors ligne, sans renotifier au rejeu", function () {
    Sanctum::actingAs($this->operateur);

    $element = [
        'type' => 'incident',
        ...declaration([
            'gravite' => GraviteIncident::Majeur->value,
            'survenu_le' => now()->subDay()->toIso8601String(),
        ]),
    ];

    $lot = fn () => $this->postJson('/api/v1/sync', [
        'uuid_lot' => (string) Str::uuid(),
        'elements' => [$element],
    ]);

    $premier = $lot()->assertOk()->json('data');
    expect($premier['acceptes'][0]['action'])->toBe('cree');

    $second = $lot()->assertOk()->json('data');

    expect($second['acceptes'][0]['action'])->toBe('existant')
        ->and(Incident::query()->count())->toBe(1)
        // La hiérarchie n'est pas réveillée deux fois pour le même fait.
        ->and(IncidentAction::query()->where('type_action', 'notification')->count())->toBe(1)
        ->and(Alerte::query()->count())->toBe(1);
});

it("annonce l'incident parmi les types que le serveur sait recevoir", function () {
    Sanctum::actingAs($this->operateur);

    expect($this->getJson('/api/v1/sync/types')->assertOk()->json('data.types'))
        ->toContain('incident');
});

it("fait courir l'échéance depuis l'arrivée, pas depuis l'heure du téléphone", function () {
    Sanctum::actingAs($this->operateur);

    $this->postJson('/api/v1/sync', [
        'uuid_lot' => (string) Str::uuid(),
        'elements' => [[
            'type' => 'incident',
            ...declaration([
                'gravite' => GraviteIncident::Critique->value,
                // Survenu la veille, dans une zone sans réseau.
                'survenu_le' => now()->subDay()->toIso8601String(),
                'horodatage_telephone' => now()->subDay()->toIso8601String(),
            ]),
        ]],
    ])->assertOk();

    $incident = Incident::query()->first();

    // Personne n'aurait pu le prendre en charge plus tôt : il n'arrive pas
    // déjà en retard.
    expect($incident->echeance_escalade->isFuture())->toBeTrue()
        ->and($incident->survenu_le->isYesterday())->toBeTrue();
});
