<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Enums\StatutPresence;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\FeuillePresence;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Province;
use App\Models\RapportJournalier;
use App\Models\Region;
use App\Models\RelevePosition;
use App\Models\SignalArrivee;
use App\Models\Site;
use App\Models\SyncLot;
use App\Models\TourneeSite;
use App\Models\UniteSupervision;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Cadrage, section 11 — POST /api/sync, la remontée du terrain.
 *
 * Ces tests protègent les quatre propriétés du mécanisme : idempotence du lot
 * ET de l'élément, succès partiel, règles identiques à celles d'en ligne, et
 * refus motivé qui dit au téléphone s'il doit réessayer.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->aujourdhui = now()->toDateString();

    $region = Region::query()->create(['code' => 'MOU', 'nom' => 'Mouhoun', 'nombre_sites_alloues' => 300]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'BALE', 'nom' => 'Bale']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'BORO', 'nom' => 'Boromo', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'Ouahabou', 'type_localite' => 'village',
        'population_totale' => 3400, 'quota_sites' => 1,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => 'MOU-BORO-C001', 'nom' => 'Centre Boromo',
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $this->site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $region->id,
        'code' => 'MOU-BORO-C001-S01', 'nom' => 'Site Ouahabou', 'statut' => 'ouvert',
        'latitude' => 11.7470, 'longitude' => -2.9310, 'rayon_zone_metres' => 500,
    ]);

    $this->admin = compteSync('+22670001900', 'administrateur_national');

    $vague = VagueDeploiement::query()->create([
        'code' => 'MOU-2026-V1', 'libelle' => 'Vague Mouhoun 1', 'region_id' => $region->id,
        'date_debut_prevue' => $this->aujourdhui, 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
        'objectif_enregistrements_par_kit_jour' => 100,
    ]);

    $this->superviseur = compteSync('+22670001901', 'volontaire_superviseur', CategorieVolontaire::Superviseur);
    UniteSupervision::query()->create([
        'vague_id' => $vague->id,
        'volontaire_superviseur_id' => $this->superviseur->volontaire->id,
        'centre_principal_id' => $centre->id,
        'meme_commune' => true, 'contrainte_respectee' => true,
    ]);
    Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $this->superviseur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Superviseur->value, 'centre_id' => $centre->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $this->operateur = compteSync('+22670001902', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $affectationOpk = Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $this->operateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $centre->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
    TourneeSite::query()->create([
        'vague_id' => $vague->id, 'centre_id' => $centre->id, 'site_id' => $this->site->id,
        'affectation_operateur_id' => $affectationOpk->id, 'ordre' => 1,
        'date_debut' => $this->aujourdhui, 'date_fin' => now()->addDays(6)->toDateString(),
        'statut' => 'en_cours',
    ]);

    $this->assistant = compteSync('+22670001903', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $this->assistant->volontaire->update(['localite_id' => $localite->id]);
    Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $this->assistant->volontaire->id,
        'role_terrain' => CategorieVolontaire::Assistant->value, 'centre_id' => $centre->id,
        'localite_id' => $localite->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $this->centre = $centre;
    $this->vague = $vague;
});

function compteSync(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Sync', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
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

/** Un signal d'arrivée tel que le téléphone le met en file. */
function elementSignal(array $remplacements = []): array
{
    return array_merge([
        'type' => 'signal_arrivee',
        'uuid_client' => (string) Str::uuid(),
        'type_signal' => 'arrivee',
        'latitude' => 11.7471,
        'longitude' => -2.9311,
        'horodatage_telephone' => now()->setTime(7, 42)->toIso8601String(),
        'precision_gps' => 12,
    ], $remplacements);
}

function envoyerLot(array $elements, ?string $uuidLot = null): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/sync', [
        'uuid_lot' => $uuidLot ?? (string) Str::uuid(),
        'elements' => $elements,
    ]);
}

// ---------------------------------------------------------------------------
// Idempotence, aux deux niveaux
// ---------------------------------------------------------------------------

it('accepte un lot et rend le détail élément par élément', function () {
    Sanctum::actingAs($this->operateur);

    $reponse = envoyerLot([elementSignal(), elementSignal(['type_signal' => 'depart'])])
        ->assertOk();

    $donnees = $reponse->json('data');

    expect($donnees['nb_elements'])->toBe(2)
        ->and($donnees['nb_acceptes'])->toBe(2)
        ->and($donnees['nb_rejetes'])->toBe(0)
        ->and($donnees['rejoue'])->toBeFalse()
        ->and($donnees['acceptes'][0]['action'])->toBe('cree')
        ->and($donnees['acceptes'][0]['type'])->toBe('signal_arrivee')
        // L'heure du serveur, pour que le téléphone mesure sa dérive.
        ->and($donnees['serveur_le'])->not->toBeNull();

    expect(SignalArrivee::query()->count())->toBe(2);
});

it("rejoue un lot sans rien réappliquer : la réponse d'origine est rendue", function () {
    Sanctum::actingAs($this->operateur);

    $uuidLot = (string) Str::uuid();
    $elements = [elementSignal()];

    $premiere = envoyerLot($elements, $uuidLot)->assertOk()->json('data');

    // Le réseau tombe au moment de la réponse : le téléphone renvoie le lot.
    $seconde = envoyerLot($elements, $uuidLot)->assertOk()->json('data');

    expect($seconde['rejoue'])->toBeTrue()
        ->and($seconde['acceptes'])->toBe($premiere['acceptes'])
        ->and(SignalArrivee::query()->count())->toBe(1)
        ->and(SyncLot::query()->count())->toBe(1);
});

it("reconnaît un élément déjà intégré dans un nouveau lot, sans le dupliquer", function () {
    Sanctum::actingAs($this->operateur);

    $element = elementSignal();

    envoyerLot([$element])->assertOk();

    // Nouveau lot, même élément : le téléphone n'avait pas vu l'accusé.
    $donnees = envoyerLot([$element])->assertOk()->json('data');

    expect($donnees['nb_acceptes'])->toBe(1)
        ->and($donnees['acceptes'][0]['action'])->toBe('existant')
        ->and(SignalArrivee::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Succès partiel et qualification des refus
// ---------------------------------------------------------------------------

it("n'abandonne jamais le reste du lot à cause d'un élément fautif", function () {
    Sanctum::actingAs($this->operateur);

    $donnees = envoyerLot([
        elementSignal(),
        // Position GPS manquante : définitivement invalide.
        ['type' => 'signal_arrivee', 'uuid_client' => (string) Str::uuid(),
         'type_signal' => 'arrivee', 'horodatage_telephone' => now()->toIso8601String()],
        elementSignal(['type_signal' => 'depart']),
    ])->assertOk()->json('data');

    expect($donnees['nb_acceptes'])->toBe(2)
        ->and($donnees['nb_rejetes'])->toBe(1)
        ->and($donnees['rejetes'][0]['code'])->toBe('donnees_invalides')
        // Le rang permet au téléphone de retrouver la ligne même sans uuid.
        ->and($donnees['rejetes'][0]['rang'])->toBe(1)
        ->and($donnees['rejetes'][0]['details'])->toHaveKey('latitude')
        ->and(SignalArrivee::query()->count())->toBe(2);
});

it("dit au téléphone s'il doit réessayer, refus par refus", function () {
    Sanctum::actingAs($this->operateur);

    $donnees = envoyerLot([
        // Un type qu'aucune version du serveur ne connaitra jamais : c'est le
        // cas d'un mobile plus recent que le serveur, qui envoie ce que celui-ci
        // ne sait pas encore recevoir. Nommer ici un type reel rendrait ce test
        // caduc le jour ou ce type serait implemente.
        ['type' => 'type_futur_inconnu_du_serveur', 'uuid_client' => (string) Str::uuid()],
        ['type' => 'visa_rapport', 'rapport_uuid' => (string) Str::uuid(), 'acte' => 'visa'],
    ])->assertOk()->json('data');

    $parType = collect($donnees['rejetes'])->keyBy('type');

    // Type absent du registre : réessayer ne servira jamais à rien.
    expect($parType['type_futur_inconnu_du_serveur']['code'])->toBe('type_inconnu')
        ->and($parType['type_futur_inconnu_du_serveur']['reessayer'])->toBeFalse()
        ->and($parType['type_futur_inconnu_du_serveur']['details']['types_connus'])->toContain('signal_arrivee');

    // Cible absente : elle arrivera peut-être d'un autre appareil.
    expect($parType['visa_rapport']['code'])->toBe('introuvable_serveur')
        ->and($parType['visa_rapport']['reessayer'])->toBeTrue();
});

it('rend un message métier en français quand une règle du terrain bloque', function () {
    // Un agent sans affectation active ne peut pas signaler son arrivée.
    $orphelin = compteSync('+22670001904', 'volontaire_operateur', CategorieVolontaire::Operateur);

    Sanctum::actingAs($orphelin);

    $donnees = envoyerLot([elementSignal()])->assertOk()->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('regle_metier')
        ->and($donnees['rejetes'][0]['motif'])
        ->toBe("Vous n'avez pas de mission en cours : votre arrivée ne peut pas être signalée.")
        ->and($donnees['rejetes'][0]['reessayer'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Les règles restent celles d'en ligne
// ---------------------------------------------------------------------------

it("refuse une action que le compte n'a pas le droit de faire", function () {
    // L'assistant n'a pas rapports.viser.
    Sanctum::actingAs($this->assistant);

    $donnees = envoyerLot([
        ['type' => 'visa_rapport', 'rapport_uuid' => (string) Str::uuid(), 'acte' => 'visa'],
    ])->assertOk()->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('droit_refuse')
        ->and($donnees['rejetes'][0]['reessayer'])->toBeFalse();
});

it("ne laisse pas un superviseur valider la feuille d'un centre qui n'est pas le sien", function () {
    $autre = compteSync('+22670001905', 'volontaire_superviseur', CategorieVolontaire::Superviseur);

    Sanctum::actingAs($autre);

    $donnees = envoyerLot([[
        'type' => 'feuille_presence',
        'uuid_client' => (string) Str::uuid(),
        'site_id' => $this->site->id,
        'date_presence' => $this->aujourdhui,
        'lignes' => [['volontaire_id' => $this->operateur->volontaire->id, 'statut' => 'present']],
    ]])->assertOk()->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('droit_refuse');
    expect(FeuillePresence::query()->where('statut', 'validee')->count())->toBe(0);
});

it("calcule la distance côté serveur, sans jamais la recevoir du téléphone", function () {
    Sanctum::actingAs($this->operateur);

    envoyerLot([elementSignal([
        // Loin du site, et le téléphone prétend le contraire.
        'latitude' => 12.3700, 'longitude' => -1.5200,
        'distance_site_metres' => 0, 'dans_zone' => true,
    ])])->assertOk();

    $signal = SignalArrivee::query()->first();

    expect($signal->dans_zone)->toBeFalse()
        ->and($signal->distance_site_metres)->toBeGreaterThan(100000);
});

it("retient l'heure du téléphone, pas celle de l'arrivée sur le serveur", function () {
    Sanctum::actingAs($this->operateur);

    // Le geste du jour même, à 7 h 15 : l'affectation commence aujourd'hui, et
    // « six heures plus tôt » tomberait la veille pour un test lancé avant 6 h.
    $heureDuGeste = now()->setTime(7, 15)->startOfMinute();

    envoyerLot([elementSignal(['horodatage_telephone' => $heureDuGeste->toIso8601String()])])
        ->assertOk();

    $signal = SignalArrivee::query()->first();

    expect($signal->horodatage_telephone->format('Y-m-d H:i'))
        ->toBe($heureDuGeste->format('Y-m-d H:i'))
        ->and($signal->recu_le)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Le rapport journalier hors ligne, saisi et signé d'un seul geste
// ---------------------------------------------------------------------------

it("ouvre, saisit et signe un rapport en un seul élément", function () {
    Sanctum::actingAs($this->operateur);

    $uuid = (string) Str::uuid();

    $donnees = envoyerLot([[
        'type' => 'rapport_journalier',
        'uuid_client' => $uuid,
        'date_rapport' => $this->aujourdhui,
        'production' => ['enregistrements_realises' => 118, 'recepisses_transmis' => 110],
        'difficultes' => [['difficulte' => 'Coupure réseau toute la matinée']],
        'soumettre' => true,
    ]])->assertOk()->json('data');

    expect($donnees['acceptes'][0]['action'])->toBe('soumis');

    $rapport = RapportJournalier::query()->where('uuid_client', $uuid)->first();

    expect($rapport)->not->toBeNull()
        ->and($rapport->statut->value)->toBe('soumis')
        // Le bloc d'identification vient du serveur, pas du téléphone.
        ->and($rapport->site_id)->toBe($this->site->id)
        ->and($rapport->superieur_volontaire_id)->toBe($this->superviseur->volontaire->id)
        // L'objectif est figé depuis la vague, jamais envoyé par le mobile.
        ->and($rapport->productionOpk->objectif_enregistrements)->toBe(100)
        ->and((int) $rapport->productionOpk->ecart_enregistrements)->toBe(18)
        ->and($rapport->difficultes)->toHaveCount(1);
});

it("ne rouvre pas un rapport déjà signé quand le téléphone rejoue son élément", function () {
    Sanctum::actingAs($this->operateur);

    $element = [
        'type' => 'rapport_journalier',
        'uuid_client' => (string) Str::uuid(),
        'date_rapport' => $this->aujourdhui,
        'production' => ['enregistrements_realises' => 118],
        'soumettre' => true,
    ];

    envoyerLot([$element])->assertOk();

    $donnees = envoyerLot([$element])->assertOk()->json('data');

    expect($donnees['acceptes'][0]['action'])->toBe('existant')
        ->and(RapportJournalier::query()->count())->toBe(1);

    // Et la production n'a pas été réécrite.
    expect(RapportJournalier::query()->first()->productionOpk->enregistrements_realises)->toBe(118);
});

it("garde l'uuid du serveur quand le rapport du jour avait déjà été ouvert en ligne", function () {
    Sanctum::actingAs($this->operateur);

    $enLigne = $this->postJson('/api/v1/rapports/ouvrir')->assertOk()->json('data');

    $donnees = envoyerLot([[
        'type' => 'rapport_journalier',
        'uuid_client' => (string) Str::uuid(),
        'date_rapport' => $this->aujourdhui,
        'production' => ['enregistrements_realises' => 95],
    ]])->assertOk()->json('data');

    // Un seul rapport, et c'est celui du serveur : l'unicité (auteur, type,
    // jour) prime, et le téléphone reçoit l'identifiant serveur pour se recaler.
    expect(RapportJournalier::query()->count())->toBe(1)
        ->and($donnees['acceptes'][0]['id'])->toBe($enLigne['id'])
        ->and(RapportJournalier::query()->first()->uuid_client)->toBe($enLigne['uuid_client']);
});

it("vise hors ligne un rapport remonté plus tôt dans le même lot", function () {
    // L'A-OPK saisit et signe, l'opérateur vise : les deux dans un seul lot,
    // le visa placé AVANT le rapport qu'il vise.
    $uuidRapport = (string) Str::uuid();

    Sanctum::actingAs($this->assistant);
    envoyerLot([[
        'type' => 'rapport_journalier',
        'uuid_client' => $uuidRapport,
        'date_rapport' => $this->aujourdhui,
        'activites' => ['justificatifs_recus_realise' => 40],
        'soumettre' => true,
    ]])->assertOk();

    Sanctum::actingAs($this->operateur);
    $donnees = envoyerLot([
        ['type' => 'visa_rapport', 'rapport_uuid' => $uuidRapport, 'acte' => 'visa',
         'commentaire' => 'Journée conforme.'],
    ])->assertOk()->json('data');

    expect($donnees['acceptes'][0]['action'])->toBe('vise')
        ->and(RapportJournalier::query()->where('uuid_client', $uuidRapport)->first()->statut->value)
        ->toBe('vise');
});

it("rattrape en seconde passe un visa placé avant son rapport dans le même lot", function () {
    $uuidRapport = (string) Str::uuid();

    Sanctum::actingAs($this->assistant);
    envoyerLot([[
        'type' => 'rapport_journalier', 'uuid_client' => $uuidRapport,
        'date_rapport' => $this->aujourdhui, 'soumettre' => true,
    ]])->assertOk();

    Sanctum::actingAs($this->operateur);

    // Le visa d'un rapport encore absent, puis un élément quelconque : la
    // seconde passe ne doit pas tout rejouer, seulement ce qui manquait.
    $uuidAutre = (string) Str::uuid();

    $donnees = envoyerLot([
        ['type' => 'visa_rapport', 'rapport_uuid' => $uuidAutre, 'acte' => 'visa'],
        ['type' => 'visa_rapport', 'rapport_uuid' => $uuidRapport, 'acte' => 'visa'],
    ])->assertOk()->json('data');

    expect($donnees['nb_acceptes'])->toBe(1)
        ->and($donnees['nb_rejetes'])->toBe(1)
        ->and($donnees['rejetes'][0]['code'])->toBe('introuvable_serveur');
});

it("ne vise pas deux fois un rapport déjà visé", function () {
    $uuidRapport = (string) Str::uuid();

    Sanctum::actingAs($this->assistant);
    envoyerLot([[
        'type' => 'rapport_journalier', 'uuid_client' => $uuidRapport,
        'date_rapport' => $this->aujourdhui, 'soumettre' => true,
    ]])->assertOk();

    Sanctum::actingAs($this->operateur);
    $visa = ['type' => 'visa_rapport', 'rapport_uuid' => $uuidRapport, 'acte' => 'visa'];

    envoyerLot([$visa])->assertOk();
    $donnees = envoyerLot([$visa])->assertOk()->json('data');

    expect($donnees['acceptes'][0]['action'])->toBe('existant')
        ->and(RapportJournalier::query()->where('uuid_client', $uuidRapport)
            ->first()->visas()->count())->toBe(2); // signature + visa, pas deux visas
});

it("exige un motif pour un renvoi remonté hors ligne, comme en ligne", function () {
    $uuidRapport = (string) Str::uuid();

    Sanctum::actingAs($this->assistant);
    envoyerLot([[
        'type' => 'rapport_journalier', 'uuid_client' => $uuidRapport,
        'date_rapport' => $this->aujourdhui, 'soumettre' => true,
    ]])->assertOk();

    Sanctum::actingAs($this->operateur);

    $donnees = envoyerLot([
        ['type' => 'visa_rapport', 'rapport_uuid' => $uuidRapport, 'acte' => 'rejet'],
    ])->assertOk()->json('data');

    expect($donnees['rejetes'][0]['code'])->toBe('regle_metier')
        ->and($donnees['rejetes'][0]['motif'])->toContain('un rejet sans motif est inexploitable');
});

// ---------------------------------------------------------------------------
// La feuille de présence validée hors ligne
// ---------------------------------------------------------------------------

it("valide hors ligne une feuille que le serveur ne connaissait pas encore", function () {
    Sanctum::actingAs($this->superviseur);

    $uuid = (string) Str::uuid();

    $donnees = envoyerLot([[
        'type' => 'feuille_presence',
        'uuid_client' => $uuid,
        'site_id' => $this->site->id,
        'date_presence' => $this->aujourdhui,
        'lignes' => [
            ['volontaire_id' => $this->operateur->volontaire->id, 'statut' => 'present'],
            ['volontaire_id' => $this->assistant->volontaire->id, 'statut' => 'absent_justifie',
             'motif_absence' => 'Convocation administrative'],
        ],
        'latitude' => 11.7472, 'longitude' => -2.9312,
    ]])->assertOk()->json('data');

    expect($donnees['nb_acceptes'])->toBe(1);

    $feuille = FeuillePresence::query()->where('uuid_client', $uuid)->first();

    expect($feuille)->not->toBeNull()
        ->and($feuille->statut)->toBe('validee')
        ->and($feuille->superviseur_id)->toBe($this->superviseur->volontaire->id)
        // La position du superviseur au moment de valider rend le pointage opposable.
        ->and($feuille->distance_site_metres)->not->toBeNull();
});

it("ne rejoue jamais la validation d'une feuille déjà signée", function () {
    Sanctum::actingAs($this->superviseur);

    $element = [
        'type' => 'feuille_presence',
        'uuid_client' => (string) Str::uuid(),
        'site_id' => $this->site->id,
        'date_presence' => $this->aujourdhui,
        'lignes' => [['volontaire_id' => $this->operateur->volontaire->id, 'statut' => 'present']],
    ];

    envoyerLot([$element])->assertOk();

    // Le téléphone rejoue, avec un marquage différent : la signature tient.
    $element['lignes'] = [['volontaire_id' => $this->operateur->volontaire->id, 'statut' => 'absent']];

    $donnees = envoyerLot([$element])->assertOk()->json('data');

    expect($donnees['acceptes'][0]['action'])->toBe('existant');

    $feuille = FeuillePresence::query()->first();
    expect($feuille->lignes()->where('volontaire_id', $this->operateur->volontaire->id)
        ->value('statut'))->toBe(StatutPresence::Present);
});

// ---------------------------------------------------------------------------
// Les relevés de position
// ---------------------------------------------------------------------------

it("compte comme traité un relevé que le serveur écarte délibérément", function () {
    Sanctum::actingAs($this->operateur);

    $donnees = envoyerLot([[
        'type' => 'releve_position',
        // 23 h : hors heures de service. Le serveur n'en veut pas, et c'est
        // la règle — le téléphone ne doit pas le réessayer indéfiniment.
        'horodatage' => now()->setTime(23, 10)->toIso8601String(),
        'latitude' => 11.7471, 'longitude' => -2.9311,
    ]])->assertOk()->json('data');

    expect($donnees['nb_acceptes'])->toBe(1)
        ->and($donnees['nb_rejetes'])->toBe(0)
        ->and($donnees['acceptes'][0]['action'])->toBe('ignore:hors_heures_de_service')
        ->and(RelevePosition::query()->count())->toBe(0);
});

it("accepte un relevé pris pendant les heures de service", function () {
    Sanctum::actingAs($this->operateur);

    $moment = now()->startOfWeek()->setTime(9, 30);

    // Un relevé n'est accepté que les jours d'affectation (section 8.4) : la
    // mission couvre ce lundi-là, quel que soit le jour où le test tourne.
    Affectation::query()
        ->where('volontaire_id', $this->operateur->volontaire->id)
        ->update(['date_debut' => $moment->toDateString()]);

    $donnees = envoyerLot([[
        'type' => 'releve_position',
        'horodatage' => $moment->toIso8601String(),
        'latitude' => 11.7471, 'longitude' => -2.9311,
    ]])->assertOk()->json('data');

    expect($donnees['acceptes'][0]['action'])->toBe('cree')
        ->and(RelevePosition::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Garde-fous du lot lui-même
// ---------------------------------------------------------------------------

it("refuse un lot plus gros que le paramètre, sans coder le seuil en dur", function () {
    Sanctum::actingAs($this->operateur);

    // Par le modele, pas par le constructeur de requetes : c'est l'evenement
    // « saved » qui vide le cache du parametre.
    Parametre::query()->where('cle', 'sync.max_elements_par_lot')->first()->update(['valeur' => 3]);

    envoyerLot([elementSignal(), elementSignal(), elementSignal(), elementSignal()])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    envoyerLot([elementSignal(), elementSignal(), elementSignal()])->assertOk();
});

it('annonce au mobile ce que ce serveur sait recevoir', function () {
    Sanctum::actingAs($this->operateur);

    $donnees = $this->getJson('/api/v1/sync/types')->assertOk()->json('data');

    expect($donnees['types'])->toContain('signal_arrivee', 'rapport_journalier',
        'feuille_presence', 'visa_rapport', 'releve_position')
        ->and($donnees['max_elements_par_lot'])->toBe(200);
});

it("garde la trace de chaque envoi, pour qu'un agent puisse être cru ou contredit", function () {
    Sanctum::actingAs($this->operateur);

    envoyerLot([elementSignal()])->assertOk();

    $lots = $this->getJson('/api/v1/sync/lots')->assertOk()->json('data.data');

    expect($lots)->toHaveCount(1)
        ->and($lots[0]['nb_acceptes'])->toBe(1)
        ->and($lots[0]['duree_ms'])->not->toBeNull();
});

it("n'expose à un agent que ses propres lots", function () {
    Sanctum::actingAs($this->operateur);
    envoyerLot([elementSignal()])->assertOk();

    Sanctum::actingAs($this->superviseur);

    expect($this->getJson('/api/v1/sync/lots')->assertOk()->json('data.data'))->toHaveCount(0);
});

it('exige une authentification', function () {
    $this->postJson('/api/v1/sync', [
        'uuid_lot' => (string) Str::uuid(),
        'elements' => [elementSignal()],
    ])->assertUnauthorized();
});
