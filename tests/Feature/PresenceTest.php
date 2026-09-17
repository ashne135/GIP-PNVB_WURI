<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Jobs\RapprocherPresencesJob;
use App\Models\Affectation;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\EcartPresence;
use App\Models\FeuillePresence;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Province;
use App\Models\Region;
use App\Models\RelevePosition;
use App\Models\SignalArrivee;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Presence\ServiceRelevesPosition;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Tâche 7 : les QUATRE mécanismes de présence, à ne jamais confondre.
 *
 * POINT DE VIGILANCE : une feuille par site et par jour ; seule la feuille
 * validée fait foi.
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
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $this->localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $this->region->id,
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1549,
    ]);
    $this->centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $this->region->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre de Bagassi', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    // Un site géolocalisé : la distance est calculée côté serveur.
    $this->site = Site::query()->create([
        'centre_id' => $this->centre->id, 'localite_id' => $this->localite->id,
        'region_id' => $this->region->id, 'code' => 'BAN-BAGA-C001-S01', 'nom' => 'Site Assio',
        'latitude' => 11.9456, 'longitude' => -3.0021,
        'rayon_zone_metres' => 500, 'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $this->admin = comptePresence('+22670000002', 'administrateur_national');

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->subDays(5)->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    // L'opérateur, son affectation et la tournée qui l'amène sur le site.
    $this->operateur = volontairePresence('+22670000010', CategorieVolontaire::Operateur);
    $this->affectationOpk = Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->operateur->id,
        'role_terrain' => 'operateur', 'centre_id' => $this->centre->id,
        'date_debut' => now()->subDays(5)->toDateString(), 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
    TourneeSite::query()->create([
        'vague_id' => $this->vague->id, 'centre_id' => $this->centre->id, 'site_id' => $this->site->id,
        'affectation_operateur_id' => $this->affectationOpk->id, 'ordre' => 1,
        'date_debut' => now()->subDays(5)->toDateString(), 'date_fin' => now()->addDays(5)->toDateString(),
        'statut' => 'en_cours',
    ]);

    // L'A-OPK, rattaché en permanence à la localité du site.
    $this->assistant = volontairePresence('+22670000011', CategorieVolontaire::Assistant,
        localiteId: $this->localite->id);
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $this->assistant->id,
        'role_terrain' => 'assistant', 'centre_id' => $this->centre->id,
        'localite_id' => $this->localite->id,
        'date_debut' => now()->subDays(5)->toDateString(), 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    // Le superviseur du centre.
    $this->superviseur = volontairePresence('+22670000012', CategorieVolontaire::Superviseur);
    \App\Models\UniteSupervision::query()->create([
        'vague_id' => $this->vague->id,
        'volontaire_superviseur_id' => $this->superviseur->id,
        'centre_principal_id' => $this->centre->id,
    ]);
});

/**
 * LE PÉRIMÈTRE DU SITE, quand le dispositif est réglé pour l'imposer.
 *
 * Par défaut le paramètre est à FAUX : l'agent SIGNALE, le serveur constate
 * l'écart, et seul le superviseur valide. C'est le cadrage, et c'est ce que
 * vérifie le premier test.
 *
 * Activé, le serveur REFUSE — et le refus est ici, dans le service, pas sur le
 * téléphone : un bouton grisé se contourne, et la synchronisation hors ligne
 * emprunte exactement le même chemin.
 */
it('laisse signaler hors zone tant que le blocage n’est pas activé, et constate l’écart', function () {
    Sanctum::actingAs($this->operateur->user);

    // À plus de 10 km du site.
    $reponse = $this->postJson('/api/v1/presence/signaler', [
        'uuid_client' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'arrivee',
        'latitude' => 12.0456,
        'longitude' => -3.0021,
        'horodatage_telephone' => now()->toIso8601String(),
    ])->assertCreated();

    expect($reponse->json('data.dans_zone'))->toBeFalse()
        ->and($reponse->json('data.distance_site_metres'))->toBeGreaterThan(500)
        ->and($reponse->json('message'))->toContain('votre superviseur');

    expect(SignalArrivee::query()->count())->toBe(1);
});

it('refuse le signal hors zone quand le paramètre l’impose, à l’arrivée comme au départ', function () {
    Parametre::query()->where('cle', 'presence.bloquer_signal_hors_zone')
        ->first()->update(['valeur' => '1']);

    Sanctum::actingAs($this->operateur->user);

    foreach (['arrivee', 'depart'] as $type) {
        $this->postJson('/api/v1/presence/signaler', [
            'uuid_client' => (string) Illuminate\Support\Str::uuid(),
            'type' => $type,
            'latitude' => 12.0456,
            'longitude' => -3.0021,
            'horodatage_telephone' => now()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'de sa zone')
                && str_contains($message, 'prévenez votre superviseur'));
    }

    // Rien n'est enregistré : un signal refusé ne laisse pas de trace partielle.
    expect(SignalArrivee::query()->count())->toBe(0);

    // Dans la zone, le même paramètre ne gêne personne.
    $this->postJson('/api/v1/presence/signaler', [
        'uuid_client' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'arrivee',
        'latitude' => 11.9456,
        'longitude' => -3.0021,
        'horodatage_telephone' => now()->toIso8601String(),
    ])->assertCreated();
});

it('accepte toujours le signal sur un site SANS coordonnées, même blocage activé', function () {
    Parametre::query()->where('cle', 'presence.bloquer_signal_hors_zone')
        ->first()->update(['valeur' => '1']);

    // Le cas de TOUS les sites aujourd'hui : aucune position connue.
    $this->site->update(['latitude' => null, 'longitude' => null]);

    Sanctum::actingAs($this->operateur->user);

    $reponse = $this->postJson('/api/v1/presence/signaler', [
        'uuid_client' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'arrivee',
        'latitude' => 12.0456,
        'longitude' => -3.0021,
        'horodatage_telephone' => now()->toIso8601String(),
    ])->assertCreated();

    // Sans coordonnées, la distance n'est pas mesurable : refuser sur une mesure
    // inexistante mettrait le module de présence hors service partout.
    expect($reponse->json('data.distance_site_metres'))->toBeNull();
});

function comptePresence(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'TEST', 'prenoms' => ucfirst($role),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);
    $user->consentements()->create(['version_charte' => '2026.1', 'accepte_le' => now()]);

    return $user;
}

function volontairePresence(
    string $telephone,
    CategorieVolontaire $categorie,
    ?int $localiteId = null
): Volontaire {
    $user = comptePresence($telephone, $categorie->role()->value);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
        'categorie' => $categorie->value, 'statut' => 'operationnel',
        'localite_id' => $localiteId,
    ]);
}

// ---------------------------------------------------------------------------
// 8.1 Le signal d'arrivée
// ---------------------------------------------------------------------------

it('enregistre un signal d\'arrivée et calcule la distance côté serveur', function () {
    Sanctum::actingAs($this->operateur->user);

    $reponse = $this->postJson('/api/v1/presence/signaler', [
        'uuid_client' => (string) Str::uuid(),
        'type' => 'arrivee',
        'latitude' => 11.9458, 'longitude' => -3.0023,   // à quelques dizaines de mètres
        'horodatage_telephone' => now()->format('Y-m-d H:i:s'),
    ])->assertStatus(201);

    expect($reponse->json('data.dans_zone'))->toBeTrue();
    expect($reponse->json('data.distance_site_metres'))->toBeLessThan(500);
    expect($reponse->json('data.site_id'))->toBe($this->site->id);

    // Le signal n'est pas un pointage : le message le rappelle à l'agent.
    expect($reponse->json('message'))->toContain('superviseur validera la feuille');
});

it('marque hors zone un signal trop éloigné du site', function () {
    Sanctum::actingAs($this->assistant->user);

    $reponse = $this->postJson('/api/v1/presence/signaler', [
        'uuid_client' => (string) Str::uuid(),
        'type' => 'arrivee',
        'latitude' => 12.0500, 'longitude' => -3.1000,   // plus de 10 km
        'horodatage_telephone' => now()->format('Y-m-d H:i:s'),
    ])->assertStatus(201);

    expect($reponse->json('data.dans_zone'))->toBeFalse();
    expect($reponse->json('data.distance_site_metres'))->toBeGreaterThan(500);
    expect($reponse->json('message'))->toContain('votre superviseur le verra');
});

it('ne crée jamais de doublon pour un même uuid client', function () {
    Sanctum::actingAs($this->operateur->user);
    $uuid = (string) Str::uuid();

    $charge = [
        'uuid_client' => $uuid, 'type' => 'arrivee',
        'latitude' => 11.9456, 'longitude' => -3.0021,
        'horodatage_telephone' => now()->format('Y-m-d H:i:s'),
    ];

    $this->postJson('/api/v1/presence/signaler', $charge)->assertStatus(201);
    $this->postJson('/api/v1/presence/signaler', $charge)->assertStatus(201);

    expect(SignalArrivee::query()->where('uuid_client', $uuid)->count())->toBe(1);
});

it('refuse un signal quand l\'agent n\'a aucune mission en cours', function () {
    $sansMission = volontairePresence('+22670000020', CategorieVolontaire::Operateur);
    Sanctum::actingAs($sansMission->user);

    $reponse = $this->postJson('/api/v1/presence/signaler', [
        'uuid_client' => (string) Str::uuid(), 'type' => 'arrivee',
        'latitude' => 11.9456, 'longitude' => -3.0021,
        'horodatage_telephone' => now()->format('Y-m-d H:i:s'),
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain("pas de mission en cours");
});

// ---------------------------------------------------------------------------
// 8.2 La carte temps réel
// ---------------------------------------------------------------------------

it('colore la carte du superviseur en vert, orange et gris', function () {
    // L'opérateur signale dans la zone, l'A-OPK hors zone, personne d'autre.
    SignalArrivee::query()->create([
        'uuid_client' => (string) Str::uuid(), 'volontaire_id' => $this->operateur->id,
        'affectation_id' => $this->affectationOpk->id, 'site_id' => $this->site->id,
        'vague_id' => $this->vague->id, 'type' => 'arrivee',
        'horodatage_telephone' => now(), 'latitude' => 11.9456, 'longitude' => -3.0021,
        'distance_site_metres' => 40, 'dans_zone' => true,
    ]);
    SignalArrivee::query()->create([
        'uuid_client' => (string) Str::uuid(), 'volontaire_id' => $this->assistant->id,
        'site_id' => $this->site->id, 'vague_id' => $this->vague->id, 'type' => 'arrivee',
        'horodatage_telephone' => now(), 'latitude' => 12.05, 'longitude' => -3.10,
        'distance_site_metres' => 12000, 'dans_zone' => false,
    ]);

    Sanctum::actingAs($this->superviseur->user);
    $reponse = $this->getJson('/api/v1/presence/carte')->assertOk();

    $agents = collect($reponse->json('data.agents'))->keyBy('matricule');

    expect($agents[$this->operateur->matricule]['couleur'])->toBe('vert');
    expect($agents[$this->assistant->matricule]['couleur'])->toBe('orange');
    expect($agents[$this->assistant->matricule]['distance_metres'])->toBe(12000);

    expect($reponse->json('data.resume.vert'))->toBe(1);
    expect($reponse->json('data.resume.orange'))->toBe(1);
});

it('affiche en gris un agent qui n\'a rien signalé', function () {
    Sanctum::actingAs($this->superviseur->user);

    $reponse = $this->getJson('/api/v1/presence/carte')->assertOk();

    // Personne n'a signalé : l'absence de signal n'est pas une absence.
    expect($reponse->json('data.resume.gris'))->toBe(2);
    expect($reponse->json('data.resume.vert'))->toBe(0);
});

// ---------------------------------------------------------------------------
// 8.3 La feuille de présence
// ---------------------------------------------------------------------------

it('ouvre une seule feuille par site et par jour, pré-remplie', function () {
    Sanctum::actingAs($this->superviseur->user);

    $premiere = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->assertOk();
    $id = $premiere->json('data.id');

    expect($premiere->json('message'))->toContain('pré-remplie');
    // Les deux agents attendus sur le site : l'opérateur et l'A-OPK.
    expect($premiere->json('data.lignes'))->toHaveCount(2);

    // Un second appel le même jour rend LA MÊME feuille.
    $seconde = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->assertOk();
    expect($seconde->json('data.id'))->toBe($id);
    expect($seconde->json('message'))->toContain('déjà ouverte');

    expect(FeuillePresence::query()->count())->toBe(1);
});

it('reprend l\'état du signal d\'arrivée dans la feuille', function () {
    SignalArrivee::query()->create([
        'uuid_client' => (string) Str::uuid(), 'volontaire_id' => $this->operateur->id,
        'site_id' => $this->site->id, 'vague_id' => $this->vague->id, 'type' => 'arrivee',
        'horodatage_telephone' => now()->setTime(7, 45), 'latitude' => 11.9456, 'longitude' => -3.0021,
        'distance_site_metres' => 40, 'dans_zone' => true,
    ]);

    Sanctum::actingAs($this->superviseur->user);
    $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->assertOk();

    $ligne = \App\Models\LignePresence::query()
        ->where('volontaire_id', $this->operateur->id)->first();

    expect($ligne->signal_arrivee_id)->not->toBeNull();
    expect($ligne->distance_signalee)->toBe(40);
    expect($ligne->couleurSignal())->toBe('vert');

    // Rien n'est présumé : le superviseur décide, la ligne part à « absent ».
    expect($ligne->statut->value)->toBe('absent');
});

it('valide la feuille en enregistrant la position du superviseur', function () {
    Sanctum::actingAs($this->superviseur->user);
    $feuille = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->json('data');

    $reponse = $this->postJson("/api/v1/feuilles/{$feuille['id']}/valider", [
        'lignes' => [
            ['volontaire_id' => $this->operateur->id, 'statut' => 'present'],
            ['volontaire_id' => $this->assistant->id, 'statut' => 'absent_justifie',
                'motif_absence' => 'Convoqué au dispensaire.'],
        ],
        'latitude' => 11.9460, 'longitude' => -3.0025,
    ])->assertOk();

    expect($reponse->json('message'))->toContain('1 présents sur 2 agents');
    expect($reponse->json('message'))->toContain('ne peut plus être modifiée');

    $feuille = FeuillePresence::query()->first();
    expect($feuille->statut)->toBe('validee');
    expect($feuille->valide_le)->not->toBeNull();
    // C'est la position du superviseur qui rend le pointage à distance opposable.
    expect((float) $feuille->latitude_superviseur)->toBe(11.946);
    expect($feuille->distance_site_metres)->not->toBeNull();
});

it('exige un motif pour toute absence justifiée', function () {
    Sanctum::actingAs($this->superviseur->user);
    $feuille = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->json('data');

    $reponse = $this->postJson("/api/v1/feuilles/{$feuille['id']}/valider", [
        'lignes' => [
            ['volontaire_id' => $this->operateur->id, 'statut' => 'absent_justifie'],
            ['volontaire_id' => $this->assistant->id, 'statut' => 'present'],
        ],
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('absence justifiée exige un motif');
    expect(FeuillePresence::query()->first()->statut)->toBe('brouillon');
});

it('interdit au superviseur de revenir sur une feuille qu\'il a validée', function () {
    Sanctum::actingAs($this->superviseur->user);
    $feuille = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->json('data');

    $lignes = ['lignes' => [
        ['volontaire_id' => $this->operateur->id, 'statut' => 'present'],
        ['volontaire_id' => $this->assistant->id, 'statut' => 'present'],
    ]];

    $this->postJson("/api/v1/feuilles/{$feuille['id']}/valider", $lignes)->assertOk();

    // Une feuille validée n'est plus modifiable par lui.
    $this->postJson("/api/v1/feuilles/{$feuille['id']}/valider", $lignes)->assertStatus(403);

    // Et il n'a pas le droit de corriger : c'est l'affaire du chef d'antenne.
    $this->postJson("/api/v1/feuilles/{$feuille['id']}/corriger", [
        ...$lignes, 'motif_correction' => 'Erreur de saisie.',
    ])->assertStatus(403);
});

it('laisse le chef d\'antenne corriger une feuille validée, avec motif et journalisation', function () {
    Sanctum::actingAs($this->superviseur->user);
    $feuille = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->json('data');

    $this->postJson("/api/v1/feuilles/{$feuille['id']}/valider", [
        'lignes' => [
            ['volontaire_id' => $this->operateur->id, 'statut' => 'absent'],
            ['volontaire_id' => $this->assistant->id, 'statut' => 'present'],
        ],
    ])->assertOk();

    $chef = comptePresence('+22670000030', 'chef_antenne_regional');
    $chef->update(['region_id' => $this->region->id]);
    Sanctum::actingAs($chef);

    // Sans motif : refusé.
    $this->postJson("/api/v1/feuilles/{$feuille['id']}/corriger", [
        'lignes' => [['volontaire_id' => $this->operateur->id, 'statut' => 'present']],
    ])->assertStatus(422);

    $this->postJson("/api/v1/feuilles/{$feuille['id']}/corriger", [
        'lignes' => [['volontaire_id' => $this->operateur->id, 'statut' => 'present']],
        'motif_correction' => 'Le superviseur avait omis une arrivée tardive.',
    ])->assertOk();

    $feuilleFinale = FeuillePresence::query()->first();
    expect($feuilleFinale->statut)->toBe('corrigee');
    expect($feuilleFinale->corrige_par)->toBe($chef->id);
    expect($feuilleFinale->motif_correction)->toContain('arrivée tardive');

    // La correction est journalisée, avec l'avant et l'après.
    $activite = \Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'feuille_presence')
        ->where('description', 'like', '%corrigée%')
        ->first();
    expect($activite)->not->toBeNull();
    expect($activite->properties['motif'])->toContain('arrivée tardive');
});

// ---------------------------------------------------------------------------
// Exports
// ---------------------------------------------------------------------------

it('exporte la liste des présents en PDF, avec en-tête et signataire', function () {
    Sanctum::actingAs($this->superviseur->user);
    $feuille = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->json('data');

    $this->postJson("/api/v1/feuilles/{$feuille['id']}/valider", [
        'lignes' => [
            ['volontaire_id' => $this->operateur->id, 'statut' => 'present'],
            ['volontaire_id' => $this->assistant->id, 'statut' => 'absent'],
        ],
    ])->assertOk();

    $reponse = $this->get("/api/v1/feuilles/export/pdf?feuille_id={$feuille['id']}")->assertOk();

    // DomPDF rend une réponse ordinaire, pas une réponse streamée.
    expect($reponse->headers->get('content-type'))->toContain('application/pdf');
    expect($reponse->getContent())->toStartWith('%PDF');

    // La génération d'une pièce opposable est journalisée.
    expect(\Spatie\Activitylog\Models\Activity::query()->where('log_name', 'export')->count())->toBe(1);
});

it('exporte la liste des présents en tableur', function () {
    Sanctum::actingAs($this->superviseur->user);
    $feuille = $this->postJson("/api/v1/sites/{$this->site->id}/feuille")->json('data');

    $this->postJson("/api/v1/feuilles/{$feuille['id']}/valider", [
        'lignes' => [
            ['volontaire_id' => $this->operateur->id, 'statut' => 'present'],
            ['volontaire_id' => $this->assistant->id, 'statut' => 'absent_justifie',
                'motif_absence' => 'Maladie'],
        ],
    ])->assertOk();

    $contenu = $this->get("/api/v1/feuilles/export/csv?centre_id={$this->centre->id}")
        ->assertOk()
        ->streamedContent();

    expect($contenu)->toContain('GIP-PNVB');
    expect($contenu)->toContain('Liste des présents');
    expect($contenu)->toContain($this->operateur->matricule);
    expect($contenu)->toContain('Maladie');
});

// ---------------------------------------------------------------------------
// 8.4 Rapprochement — l'encadrement
// ---------------------------------------------------------------------------

it('refuse un relevé de position hors des heures de service', function () {
    $service = app(ServiceRelevesPosition::class);

    // Un mardi à 22 h : hors service.
    $soir = Carbon::parse('2026-09-15 22:00:00');
    expect($service->dansLesHeuresDeService($soir))->toBeFalse();

    // Un samedi en pleine journée : week-end.
    $weekend = Carbon::parse('2026-09-19 10:00:00');
    expect($service->dansLesHeuresDeService($weekend))->toBeFalse();

    // Un mardi à 9 h : dans le service.
    expect($service->dansLesHeuresDeService(Carbon::parse('2026-09-15 09:00:00')))->toBeTrue();

    $resultat = $service->enregistrer($this->operateur, [
        'horodatage' => $soir->toDateTimeString(),
        'latitude' => 11.9456, 'longitude' => -3.0021,
    ]);

    expect($resultat['enregistre'])->toBeFalse();
    expect($resultat['motif'])->toBe('hors_heures_de_service');
    expect(RelevePosition::query()->count())->toBe(0);
});

it('refuse un relevé sans consentement à la charte', function () {
    $sansCharte = volontairePresence('+22670000040', CategorieVolontaire::Operateur);
    $sansCharte->user->consentements()->delete();

    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $sansCharte->id,
        'role_terrain' => 'operateur', 'centre_id' => $this->centre->id,
        'date_debut' => now()->toDateString(), 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $resultat = app(ServiceRelevesPosition::class)->enregistrer($sansCharte, [
        'horodatage' => Carbon::parse('2026-09-15 09:00:00')->toDateTimeString(),
        'latitude' => 11.9456, 'longitude' => -3.0021,
    ]);

    expect($resultat['enregistre'])->toBeFalse();
    expect($resultat['motif'])->toBe('charte_non_acceptee');
    expect(RelevePosition::query()->count())->toBe(0);
});

it('ignore les relevés trop rapprochés', function () {
    $service = app(ServiceRelevesPosition::class);
    $base = Carbon::parse('2026-09-15 09:00:00');

    $premier = $service->enregistrer($this->operateur, [
        'horodatage' => $base->toDateTimeString(),
        'latitude' => 11.9456, 'longitude' => -3.0021,
    ]);
    expect($premier['enregistre'])->toBeTrue();

    // 10 minutes plus tard : en deçà des 30 minutes paramétrées.
    $trop = $service->enregistrer($this->operateur, [
        'horodatage' => $base->copy()->addMinutes(10)->toDateTimeString(),
        'latitude' => 11.9456, 'longitude' => -3.0021,
    ]);
    expect($trop['enregistre'])->toBeFalse();
    expect($trop['motif'])->toBe('frequence_trop_elevee');

    expect(RelevePosition::query()->count())->toBe(1);
});

it('pose la date de purge à l\'écriture du relevé', function () {
    app(ServiceRelevesPosition::class)->enregistrer($this->operateur, [
        'horodatage' => Carbon::parse('2026-09-15 09:00:00')->toDateTimeString(),
        'latitude' => 11.9456, 'longitude' => -3.0021,
    ]);

    $releve = RelevePosition::query()->first();
    $retention = Parametre::entier('retention.releves_jours', 90);

    expect($releve->purge_prevue_le->toDateString())
        ->toBe(Carbon::parse('2026-09-15')->addDays($retention)->toDateString());
});

it('constate un écart quand un agent déclaré présent n\'a aucun relevé dans la zone', function () {
    $date = now()->subDay()->toDateString();

    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(), 'site_id' => $this->site->id,
        'centre_id' => $this->centre->id, 'vague_id' => $this->vague->id,
        'date_presence' => $date, 'statut' => 'validee',
        'superviseur_id' => $this->superviseur->id, 'valide_le' => now(),
    ]);
    \App\Models\LignePresence::query()->create([
        'feuille_presence_id' => $feuille->id, 'volontaire_id' => $this->operateur->id,
        'categorie' => 'operateur', 'statut' => 'present',
    ]);

    RapprocherPresencesJob::dispatchSync($date);

    $ecart = EcartPresence::query()->first();
    expect($ecart)->not->toBeNull();
    expect($ecart->type_ecart)->toBe('present_sans_releve');
    expect($ecart->nb_releves_zone)->toBe(0);
    expect($ecart->region_id)->toBe($this->region->id);

    // L'alerte part vers la région, et ne porte QUE l'écart.
    $alerte = Alerte::query()->where('type', 'ecart_presence')->first();
    expect($alerte)->not->toBeNull();
    expect($alerte->portee)->toBe('regionale');
    expect($alerte->region_id)->toBe($this->region->id);
    expect($alerte->message)->toContain('Déclaré présent');
    // Aucune coordonnée dans le message : pas de trace de déplacement.
    expect($alerte->message)->not->toContain('11.94');
});

it('constate un écart quand un agent déclaré absent a été relevé dans la zone', function () {
    $date = now()->subDay()->toDateString();

    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(), 'site_id' => $this->site->id,
        'centre_id' => $this->centre->id, 'vague_id' => $this->vague->id,
        'date_presence' => $date, 'statut' => 'validee',
        'superviseur_id' => $this->superviseur->id, 'valide_le' => now(),
    ]);
    \App\Models\LignePresence::query()->create([
        'feuille_presence_id' => $feuille->id, 'volontaire_id' => $this->assistant->id,
        'categorie' => 'assistant', 'statut' => 'absent',
    ]);

    RelevePosition::query()->create([
        'volontaire_id' => $this->assistant->id, 'site_id_attendu' => $this->site->id,
        'horodatage' => Carbon::parse($date.' 09:00:00'),
        'latitude' => 11.9456, 'longitude' => -3.0021,
        'distance_metres' => 20, 'dans_zone' => true,
        'purge_prevue_le' => now()->addDays(90)->toDateString(),
    ]);

    RapprocherPresencesJob::dispatchSync($date);

    $ecart = EcartPresence::query()->first();
    expect($ecart->type_ecart)->toBe('releve_zone_declare_absent');
    expect($ecart->nb_releves_zone)->toBe(1);
});

it('ne redouble pas un écart déjà constaté', function () {
    $date = now()->subDay()->toDateString();

    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(), 'site_id' => $this->site->id,
        'centre_id' => $this->centre->id, 'vague_id' => $this->vague->id,
        'date_presence' => $date, 'statut' => 'validee',
        'superviseur_id' => $this->superviseur->id, 'valide_le' => now(),
    ]);
    \App\Models\LignePresence::query()->create([
        'feuille_presence_id' => $feuille->id, 'volontaire_id' => $this->operateur->id,
        'categorie' => 'operateur', 'statut' => 'present',
    ]);

    RapprocherPresencesJob::dispatchSync($date);
    RapprocherPresencesJob::dispatchSync($date);

    expect(EcartPresence::query()->count())->toBe(1);
});

it('ne rapproche pas une feuille restée au brouillon', function () {
    $date = now()->subDay()->toDateString();

    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(), 'site_id' => $this->site->id,
        'centre_id' => $this->centre->id, 'vague_id' => $this->vague->id,
        'date_presence' => $date, 'statut' => 'brouillon',
        'superviseur_id' => $this->superviseur->id,
    ]);
    \App\Models\LignePresence::query()->create([
        'feuille_presence_id' => $feuille->id, 'volontaire_id' => $this->operateur->id,
        'categorie' => 'operateur', 'statut' => 'present',
    ]);

    RapprocherPresencesJob::dispatchSync($date);

    // Seule la feuille validée fait foi : un brouillon ne se compare à rien.
    expect(EcartPresence::query()->count())->toBe(0);
});

it('n\'expose les écarts qu\'au chef d\'antenne, jamais au superviseur', function () {
    $date = now()->subDay()->toDateString();

    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(), 'site_id' => $this->site->id,
        'centre_id' => $this->centre->id, 'vague_id' => $this->vague->id,
        'date_presence' => $date, 'statut' => 'validee',
        'superviseur_id' => $this->superviseur->id, 'valide_le' => now(),
    ]);
    \App\Models\LignePresence::query()->create([
        'feuille_presence_id' => $feuille->id, 'volontaire_id' => $this->operateur->id,
        'categorie' => 'operateur', 'statut' => 'present',
    ]);
    RapprocherPresencesJob::dispatchSync($date);

    // Le superviseur a validé la feuille : il est partie prenante du constat.
    Sanctum::actingAs($this->superviseur->user);
    $this->getJson('/api/v1/presence/ecarts')->assertStatus(403);

    // L'agent noté encore moins.
    Sanctum::actingAs($this->operateur->user);
    $this->getJson('/api/v1/presence/ecarts')->assertStatus(403);

    // Le chef d'antenne régional, oui.
    $chef = comptePresence('+22670000050', 'chef_antenne_regional');
    $chef->update(['region_id' => $this->region->id]);
    Sanctum::actingAs($chef);

    $reponse = $this->getJson('/api/v1/presence/ecarts')->assertOk();
    expect($reponse->json('data.total'))->toBe(1);

    // Et cette consultation est journalisée.
    expect(\Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'rapprochement')->count())->toBeGreaterThan(0);
});

it('n\'expose aucune route rendant les relevés de position', function () {
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());

    // Aucune trace de déplacement n'est restituable, par construction.
    expect($routes->filter(fn ($u) => str_contains($u, 'releves')
        && ! str_contains($u, 'releve')))->toBeEmpty();

    $chef = comptePresence('+22670000060', 'chef_antenne_regional');
    expect($chef->can('presence.consulter_carte'))->toBeTrue();
    // Aucune permission ne donne accès aux relevés bruts.
    expect(collect($chef->getAllPermissions()->pluck('name'))
        ->filter(fn ($p) => str_contains($p, 'releve')))->toBeEmpty();
});
