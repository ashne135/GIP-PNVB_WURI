<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Enums\StatutPresence;
use App\Enums\StatutRapport;
use App\Enums\TypeRapport;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\FeuillePresence;
use App\Models\LignePresence;
use App\Models\Localite;
use App\Models\Province;
use App\Models\RapportJournalier;
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

/**
 * Cadrage v2, section 9 — L'API des rapports journaliers.
 *
 * Le point de vigilance de la tâche tient en une phrase : « les chiffres sont
 * PRÉ-REMPLIS, JAMAIS RESSAISIS ». Ces tests vérifient que l'API le tient
 * bout en bout : l'identification vient de l'affectation, la production du
 * superviseur vient des rapports OPK déjà visés, la présence vient de la
 * feuille validée, et les colonnes calculées restent hors d'atteinte du client.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->aujourdhui = now()->toDateString();

    $region = Region::query()->create(['code' => 'NAZ', 'nom' => 'Nazinon', 'nombre_sites_alloues' => 400]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'BAZE', 'nom' => 'Bazega']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'KOMB', 'nom' => 'Kombissiri', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'Sondogo', 'type_localite' => 'village',
        'population_totale' => 2100, 'quota_sites' => 1,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => 'NAZ-KOMB-C001', 'nom' => 'Centre Kombissiri',
        'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $region->id,
        'code' => 'NAZ-KOMB-C001-S01', 'nom' => 'Site Sondogo', 'statut' => 'ouvert',
    ]);

    $this->admin = compteApi('+22670000900', 'administrateur_national');

    $vague = VagueDeploiement::query()->create([
        'code' => 'NAZ-2026-V1', 'libelle' => 'Vague Nazinon 1', 'region_id' => $region->id,
        'date_debut_prevue' => $this->aujourdhui, 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
        'objectif_enregistrements_par_kit_jour' => 120,
    ]);

    // Le superviseur et son unité de supervision : c'est elle qui désigne le
    // viseur du rapport de l'opérateur.
    $this->superviseur = compteApi('+22670000901', 'volontaire_superviseur', CategorieVolontaire::Superviseur);
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

    // L'opérateur, son affectation et la tournée du kit sur le site du jour.
    $this->operateur = compteApi('+22670000902', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $affectationOpk = Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $this->operateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value, 'centre_id' => $centre->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
    TourneeSite::query()->create([
        'vague_id' => $vague->id, 'centre_id' => $centre->id, 'site_id' => $site->id,
        'affectation_operateur_id' => $affectationOpk->id, 'ordre' => 1,
        'date_debut' => $this->aujourdhui, 'date_fin' => now()->addDays(6)->toDateString(),
        'statut' => 'en_cours',
    ]);

    // L'assistant, rattaché à la LOCALITÉ du site où le kit se trouve.
    $this->assistant = compteApi('+22670000903', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $this->assistant->volontaire->update(['localite_id' => $localite->id]);
    Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $this->assistant->volontaire->id,
        'role_terrain' => CategorieVolontaire::Assistant->value, 'centre_id' => $centre->id,
        'localite_id' => $localite->id,
        'date_debut' => $this->aujourdhui, 'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $this->region = $region;
    $this->centre = $centre;
    $this->site = $site;
    $this->vague = $vague;
});

function compteApi(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Api', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    // La charte du volontaire est acceptee a la premiere connexion : sans sa
    // trace, le middleware ferme toute l'API metier.
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

// ---------------------------------------------------------------------------
// Ouverture : tout ce qui peut être pré-rempli l'est
// ---------------------------------------------------------------------------

it("pré-remplit le bloc d'identification depuis l'affectation, sans rien demander à l'agent",
    function () {
        Sanctum::actingAs($this->operateur);

        $reponse = $this->postJson('/api/v1/rapports/ouvrir')->assertOk();

        $donnees = $reponse->json('data');

        expect($donnees['type'])->toBe('opk')
            ->and($donnees['centre_id'])->toBe($this->centre->id)
            ->and($donnees['site_id'])->toBe($this->site->id)
            ->and($donnees['region_id'])->toBe($this->region->id)
            ->and($donnees['vague_id'])->toBe($this->vague->id)
            // Le viseur est désigné par l'unité de supervision, pas choisi.
            ->and($donnees['superieur_volontaire_id'])->toBe($this->superviseur->volontaire->id)
            ->and($donnees['statut'])->toBe('brouillon');
    });

it("déduit le type du rapport de la catégorie de l'auteur, le client ne le choisit pas",
    function () {
        Sanctum::actingAs($this->assistant);
        $this->postJson('/api/v1/rapports/ouvrir', ['type' => 'superviseur'])
            ->assertOk()
            ->assertJsonPath('data.type', 'aopk');

        Sanctum::actingAs($this->superviseur);
        $this->postJson('/api/v1/rapports/ouvrir')
            ->assertOk()
            ->assertJsonPath('data.type', 'superviseur');
    });

it("ouvre le rapport du jour une seule fois : un second appel rend le même", function () {
    Sanctum::actingAs($this->operateur);

    $premier = $this->postJson('/api/v1/rapports/ouvrir')->json('data.id');
    $second = $this->postJson('/api/v1/rapports/ouvrir')->json('data.id');

    expect($second)->toBe($premier)
        ->and(RapportJournalier::query()->count())->toBe(1);
});

it("refuse d'ouvrir un rapport à qui n'a pas d'affectation active", function () {
    $orphelin = compteApi('+22670000904', 'volontaire_operateur', CategorieVolontaire::Operateur);

    Sanctum::actingAs($orphelin);

    $this->postJson('/api/v1/rapports/ouvrir')
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', "Vous n'avez pas d'affectation active : aucun rapport ne peut être ouvert.");
});

it("fige l'objectif de production à l'ouverture : changer la vague ne réécrit pas l'écart",
    function () {
        Sanctum::actingAs($this->operateur);

        $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
        expect($rapport['production_opk']['objectif_enregistrements'])->toBe(120);

        // La coordination revoit l'objectif de la vague à la baisse.
        $this->vague->update(['objectif_enregistrements_par_kit_jour' => 80]);

        $this->putJson("/api/v1/rapports/{$rapport['id']}", [
            'production' => ['enregistrements_realises' => 100],
        ])->assertOk();

        $production = RapportJournalier::query()->find($rapport['id'])->productionOpk;

        expect($production->objectif_enregistrements)->toBe(120)
            // L'écart reste calculé sur l'objectif du jour, pas sur le nouveau.
            ->and((int) $production->ecart_enregistrements)->toBe(-20);
    });

it("reprend la présence des A-OPK depuis la feuille validée, jamais d'une saisie", function () {
    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'site_id' => $this->site->id, 'centre_id' => $this->centre->id, 'vague_id' => $this->vague->id,
        'date_presence' => $this->aujourdhui, 'statut' => 'validee',
        'superviseur_id' => $this->superviseur->volontaire->id, 'valide_le' => now(),
    ]);
    LignePresence::query()->create([
        'feuille_presence_id' => $feuille->id,
        'volontaire_id' => $this->assistant->volontaire->id,
        'categorie' => CategorieVolontaire::Assistant->value,
        'statut' => StatutPresence::AbsentJustifie->value,
        'motif_absence' => 'Convocation administrative',
    ]);

    Sanctum::actingAs($this->operateur);

    $donnees = $this->postJson('/api/v1/rapports/ouvrir')->assertOk()->json('data');

    expect($donnees['suivi_agents'])->toHaveCount(1)
        ->and($donnees['suivi_agents'][0]['volontaire_id'])->toBe($this->assistant->volontaire->id)
        ->and($donnees['suivi_agents'][0]['presence'])->toBe('absent_justifie');

    // Et la saisie ne peut pas la contredire : le champ n'est pas accepté.
    $rapport = RapportJournalier::query()->find($donnees['id']);

    $this->putJson("/api/v1/rapports/{$rapport->id}", [
        'suivi_agents' => [[
            'volontaire_id' => $this->assistant->volontaire->id,
            'presence' => 'present',
            'production' => 'satisfaisant',
        ]],
    ])->assertOk();

    expect($rapport->suiviAgents()->first()->presence)->toBe(StatutPresence::AbsentJustifie)
        ->and($rapport->suiviAgents()->first()->production)->toBe('satisfaisant');
});

// ---------------------------------------------------------------------------
// Saisie : les colonnes calculées restent hors d'atteinte
// ---------------------------------------------------------------------------

it("ignore les colonnes calculées envoyées par le client", function () {
    Sanctum::actingAs($this->operateur);

    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'production' => [
            'enregistrements_realises' => 90,
            // Un client mal intentionné — ou une vieille version de l'appli.
            'ecart_enregistrements' => 9999,
            'taux_realisation' => 100,
        ],
    ])->assertOk();

    $production = RapportJournalier::query()->find($rapport['id'])->productionOpk;

    expect((int) $production->ecart_enregistrements)->toBe(-30)
        ->and((float) $production->taux_realisation)->toBe(75.0);
});

it("exige un motif quand des enregistrements ne sont pas validés", function () {
    Sanctum::actingAs($this->operateur);

    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'production' => ['enregistrements_realises' => 90, 'enregistrements_non_valides' => 4],
    ])->assertStatus(422)->assertJsonPath('success', false);

    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'production' => [
            'enregistrements_realises' => 90, 'enregistrements_non_valides' => 4,
            'motif_non_valides' => 'Photos illisibles, dossiers repris le lendemain.',
        ],
    ])->assertOk();
});

it("accepte un nombre libre de difficultés, et remplace la liste à chaque envoi", function () {
    Sanctum::actingAs($this->assistant);

    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'difficultes' => [
            ['difficulte' => 'Rupture de connexion', 'solution' => 'Bascule sur le réseau mobile'],
            ['difficulte' => "Affluence au-delà de la capacité d'accueil"],
            ['difficulte' => 'Absence de sièges pour les personnes âgées', 'solution' => null],
        ],
        'points_amelioration' => ['Prévoir un abri', 'Renforcer la sensibilisation'],
    ])->assertOk();

    $modele = RapportJournalier::query()->find($rapport['id']);
    expect($modele->difficultes)->toHaveCount(3)
        ->and($modele->pointsAmelioration)->toHaveCount(2);

    // Deuxième envoi : la liste précédente ne se cumule pas.
    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'difficultes' => [['difficulte' => 'Rupture de connexion', 'solution' => 'Résolu']],
    ])->assertOk();

    expect($modele->fresh()->difficultes)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// La chaîne de visas par l'API
// ---------------------------------------------------------------------------

it("ne fait remonter au superviseur que les chiffres des rapports OPK VISÉS", function () {
    // L'opérateur saisit et signe.
    Sanctum::actingAs($this->operateur);
    $rapportOpk = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    $this->putJson("/api/v1/rapports/{$rapportOpk['id']}", [
        'production' => ['enregistrements_realises' => 137, 'recepisses_transmis' => 130],
    ])->assertOk();
    $this->postJson("/api/v1/rapports/{$rapportOpk['id']}/soumettre")->assertOk();

    // Le superviseur ouvre le sien : le rapport OPK n'est que SOUMIS.
    Sanctum::actingAs($this->superviseur);
    $rapportSup = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    expect($rapportSup['evolution']['personnes_enregistrees'])->toBe(0);

    // Il le vise, puis rafraîchit : le chiffre remonte alors seulement.
    $this->postJson("/api/v1/rapports/{$rapportOpk['id']}/viser")->assertOk();

    $rafraichi = $this->postJson("/api/v1/rapports/{$rapportSup['id']}/rafraichir")
        ->assertOk()
        ->json('data');

    expect($rafraichi['evolution']['personnes_enregistrees'])->toBe(137);
});

it("liste ce que j'ai à viser, et rien de ce qui ne m'attend pas", function () {
    Sanctum::actingAs($this->assistant);
    $rapportAopk = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    $this->postJson("/api/v1/rapports/{$rapportAopk['id']}/soumettre")->assertOk();

    // L'opérateur est le supérieur désigné de cet A-OPK.
    Sanctum::actingAs($this->operateur);
    $this->getJson('/api/v1/rapports/a-viser')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $rapportAopk['id']);

    // Le superviseur, lui, n'a encore rien à viser.
    Sanctum::actingAs($this->superviseur);
    $this->getJson('/api/v1/rapports/a-viser')
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('message', 'Aucun rapport en attente de votre visa.');
});

it("refuse le visa à qui n'est pas le supérieur désigné, même avec la permission", function () {
    Sanctum::actingAs($this->assistant);
    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    $this->postJson("/api/v1/rapports/{$rapport['id']}/soumettre")->assertOk();

    // Le superviseur a rapports.viser, mais ce rapport attend l'opérateur.
    Sanctum::actingAs($this->superviseur);
    $this->postJson("/api/v1/rapports/{$rapport['id']}/viser")->assertForbidden();
});

it("ferme le rapport à la saisie dès qu'il est signé", function () {
    Sanctum::actingAs($this->operateur);

    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    $this->postJson("/api/v1/rapports/{$rapport['id']}/soumettre")->assertOk();

    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'production' => ['enregistrements_realises' => 500],
    ])->assertForbidden();
});

it("renvoie un rapport pour correction, avec motif obligatoire, et le rouvre à son auteur",
    function () {
        Sanctum::actingAs($this->assistant);
        $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
        $this->postJson("/api/v1/rapports/{$rapport['id']}/soumettre")->assertOk();

        Sanctum::actingAs($this->operateur);
        // Le format d'erreur du projet : message lisible, detail dans data.erreurs.
        $this->postJson("/api/v1/rapports/{$rapport['id']}/rejeter")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['data' => ['erreurs' => ['motif']]]);

        $this->postJson("/api/v1/rapports/{$rapport['id']}/rejeter", [
            'motif' => 'Le nombre de justificatifs transmis manque.',
        ])->assertOk()->assertJsonPath('data.statut', 'rejete');

        // L'auteur peut de nouveau saisir.
        Sanctum::actingAs($this->assistant);
        $this->putJson("/api/v1/rapports/{$rapport['id']}", [
            'activites' => ['justificatifs_transmis_realise' => 48],
        ])->assertOk();
    });

it("garde trace d'une correction de chiffre pré-rempli, avec son motif", function () {
    Sanctum::actingAs($this->superviseur);

    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    $this->postJson("/api/v1/rapports/{$rapport['id']}/corriger", [
        'champ' => 'personnes_enregistrees',
        'valeur_origine' => 137,
        'valeur_corrigee' => 140,
        'motif' => "Trois dossiers saisis hors ligne, synchronisés après le visa de l'opérateur.",
    ])->assertOk();

    $correction = RapportJournalier::query()->find($rapport['id'])->corrections()->first();

    expect($correction->valeur_origine)->toBe('137')
        ->and($correction->valeur_corrigee)->toBe('140')
        ->and($correction->corrige_par)->toBe($this->superviseur->id);
});

// ---------------------------------------------------------------------------
// Périmètre
// ---------------------------------------------------------------------------

it("n'expose à un agent que ses rapports et ceux qu'il doit viser", function () {
    Sanctum::actingAs($this->assistant);
    $rapportAopk = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    Sanctum::actingAs($this->operateur);
    $rapportOpk = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    // L'opérateur voit le sien et celui de son A-OPK : il en est le viseur.
    $ids = collect($this->getJson('/api/v1/rapports')->assertOk()->json('data.data'))->pluck('id');
    expect($ids)->toContain($rapportOpk['id'])->toContain($rapportAopk['id']);

    // L'assistant ne voit pas celui de son opérateur.
    Sanctum::actingAs($this->assistant);
    $ids = collect($this->getJson('/api/v1/rapports')->assertOk()->json('data.data'))->pluck('id');
    expect($ids)->toContain($rapportAopk['id'])->not->toContain($rapportOpk['id']);

    $this->getJson("/api/v1/rapports/{$rapportOpk['id']}")->assertForbidden();
});

// ---------------------------------------------------------------------------
// Appréciations : consultation et droit de réponse
// ---------------------------------------------------------------------------

it("montre à l'agent noté ce qui le concerne, et lui laisse répondre", function () {
    Sanctum::actingAs($this->operateur);
    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'suivi_agents' => [[
            'volontaire_id' => $this->assistant->volontaire->id,
            'production' => 'peu_satisfaisant',
            'anomalies' => ['retard'],
            'observation' => 'Arrivé à 9h15 pour une ouverture à 8h.',
        ]],
    ])->assertOk();

    Sanctum::actingAs($this->assistant);

    $miennes = $this->getJson('/api/v1/appreciations/mes-appreciations')->assertOk()->json('data.data');

    expect($miennes)->toHaveCount(1)
        ->and($miennes[0]['production'])->toBe('peu_satisfaisant')
        ->and($miennes[0]['anomalies'])->toBe(['retard']);

    $this->postJson("/api/v1/appreciations/{$miennes[0]['id']}/repondre", [
        'reponse' => "Panne de moto signalée par message au chef d'équipe le matin même.",
    ])->assertStatus(201);

    expect($this->getJson('/api/v1/appreciations/mes-appreciations')->json('data.data.0.reponses'))
        ->toHaveCount(1);
});

it("interdit à un agent de répondre à l'appréciation d'un autre", function () {
    Sanctum::actingAs($this->operateur);
    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'suivi_agents' => [[
            'volontaire_id' => $this->assistant->volontaire->id,
            'production' => 'satisfaisant',
        ]],
    ])->assertOk();

    $suivi = RapportJournalier::query()->find($rapport['id'])->suiviAgents()->first();

    // L'opérateur est l'AUTEUR de l'appréciation : il ne parle pas à la place
    // de son agent.
    $this->postJson("/api/v1/appreciations/{$suivi->id}/repondre", ['reponse' => 'RAS'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Exports
// ---------------------------------------------------------------------------

it("exporte un rapport en PDF, en disant s'il n'est pas encore visé", function () {
    Sanctum::actingAs($this->operateur);
    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'production' => ['enregistrements_realises' => 118, 'recepisses_transmis' => 110],
        'difficultes' => [['difficulte' => 'Coupure électrique de 11h à 13h']],
    ])->assertOk();

    Sanctum::actingAs($this->superviseur);

    $reponse = $this->get("/api/v1/rapports/{$rapport['id']}/export/pdf")->assertOk();

    expect($reponse->headers->get('content-type'))->toContain('application/pdf')
        ->and(strlen($reponse->getContent()))->toBeGreaterThan(2000);
});

it("exporte une sélection de rapports en tableur, avec les chiffres calculés", function () {
    Sanctum::actingAs($this->operateur);
    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'production' => ['enregistrements_realises' => 90],
    ])->assertOk();

    Sanctum::actingAs($this->superviseur);

    $reponse = $this->get('/api/v1/rapports/export/csv?du='.$this->aujourdhui)->assertOk();

    ob_start();
    $reponse->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Rapports journaliers')
        ->toContain($this->operateur->volontaire->matricule)
        // L'écart et le taux figurent dans l'export, calculés par la base.
        ->toContain('-30')
        ->toContain('75');
});

it("refuse l'export à qui n'a pas la permission", function () {
    Sanctum::actingAs($this->assistant);

    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');

    $this->get("/api/v1/rapports/{$rapport['id']}/export/pdf")->assertForbidden();
    $this->get('/api/v1/rapports/export/csv')->assertForbidden();
});

/**
 * LE RAPPORT D'UN SUPERVISEUR, QUI N'A PAS DE CENTRE.
 *
 * Le tirage ne donne PAS de centre_id à un superviseur : il en couvre deux,
 * réunis dans une unité de supervision. Le pré-remplissage ne consultait que
 * l'affectation et le site du jour — ni l'un ni l'autre pour lui — et laissait
 * donc le centre vide, sur une colonne que la base refuse de laisser vide.
 *
 * L'ouverture partait en erreur 500. Sur le téléphone, cette erreur devenait
 * « ces informations ne sont pas encore sur le téléphone », et l'agent
 * actualisait en boucle sans que rien ne change.
 *
 * Le cas d'avant ne voyait rien : son affectation de superviseur portait un
 * centre_id que la réalité ne lui donne jamais.
 */
it('ouvre le rapport d\'un superviseur rattaché à une unité, sans centre sur son affectation', function () {
    $unite = UniteSupervision::query()
        ->where('volontaire_superviseur_id', $this->superviseur->volontaire->id)
        ->sole();

    // L'affectation telle que le TIRAGE la crée : aucune colonne centre_id,
    // le rattachement passe par l'unité.
    Affectation::query()->where('volontaire_id', $this->superviseur->volontaire->id)->update([
        'centre_id' => null,
        'unite_supervision_id' => $unite->id,
    ]);

    Sanctum::actingAs($this->superviseur);

    $reponse = $this->postJson('/api/v1/rapports/ouvrir', ['date' => $this->aujourdhui])
        ->assertOk();

    // Le rapport existe, et il est rattaché au centre PRINCIPAL de l'unité :
    // sans centre, il ne remonterait dans aucun agrégat.
    expect($reponse->json('data.centre_id'))->toBe($unite->centre_principal_id)
        ->and($reponse->json('data.type'))->toBe('superviseur')
        ->and($reponse->json('data.statut'))->toBe('brouillon');
});

it('refuse en le disant quand aucun centre ne peut être trouvé', function () {
    // Ni centre sur l'affectation, ni unité : le cas ne devrait pas exister,
    // mais s'il survient l'agent doit lire POURQUOI. Une erreur 500 se
    // traduisait sur le téléphone par un écran vide et inexplicable.
    UniteSupervision::query()->where('volontaire_superviseur_id', $this->superviseur->volontaire->id)->delete();
    Affectation::query()->where('volontaire_id', $this->superviseur->volontaire->id)->update([
        'centre_id' => null,
        'unite_supervision_id' => null,
    ]);

    Sanctum::actingAs($this->superviseur);

    $this->postJson('/api/v1/rapports/ouvrir', ['date' => $this->aujourdhui])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'aucun centre'));
});

/**
 * UN OBJECTIF DE 1, ET UNE JOURNÉE NORMALE.
 *
 * Le taux de réalisation est une colonne CALCULÉE : réalisés × 100 / objectif.
 * Elle tenait dans DECIMAL(6,2), donc s'arrêtait à 9 999,99 %. Un objectif de 1
 * — valeur légitime, celle des vagues d'essai — et 200 enregistrements dans la
 * journée donnent 20 000 %, que la base refusait d'écrire.
 *
 * Le rapport ENTIER était alors perdu : rejeté à la synchronisation avec la
 * promesse qu'il passerait « à la prochaine tentative », ce qui ne pouvait pas
 * arriver. Le téléphone réessayait sans fin.
 */
it('enregistre une journée normale même quand l\'objectif de la vague vaut 1', function () {
    $this->vague->update(['objectif_enregistrements_par_kit_jour' => 1]);

    Sanctum::actingAs($this->operateur);

    $rapport = $this->postJson('/api/v1/rapports/ouvrir')->json('data');
    expect($rapport['production_opk']['objectif_enregistrements'])->toBe(1);

    $this->putJson("/api/v1/rapports/{$rapport['id']}", [
        'production' => ['enregistrements_realises' => 200, 'recepisses_transmis' => 250],
    ])->assertOk();

    $production = RapportJournalier::query()->find($rapport['id'])->productionOpk;

    // Le taux est JUSTE, pas plafonné : un chiffre tronqué dans un rapport
    // signé serait un chiffre faux.
    expect((int) $production->enregistrements_realises)->toBe(200)
        ->and((float) $production->taux_realisation)->toBe(20000.0)
        ->and((int) $production->ecart_enregistrements)->toBe(199);
});
