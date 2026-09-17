<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Jobs\RecalculerAgregatsJob;
use App\Models\Affectation;
use App\Models\AgregatCouvertureLocalite;
use App\Models\AgregatJourCentre;
use App\Models\AgregatJourRegion;
use App\Models\AgregatJourSite;
use App\Models\AgregatMoisRegion;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\FeuillePresence;
use App\Models\Incident;
use App\Models\LignePresence;
use App\Models\Localite;
use App\Models\Province;
use App\Models\RapportJournalier;
use App\Models\RapportOpkProduction;
use App\Models\Region;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Agregats\CalculateurAgregats;
use App\Services\Agregats\CalculateurCouverture;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Cadrage, sections 4 et 15 — Agrégats, taux de couverture, tableau de bord.
 *
 * LA RÈGLE QUE CES TESTS PROTÈGENT AVANT TOUTES LES AUTRES : les effectifs
 * déployés ne s'additionnent JAMAIS entre régions. Ce sont les mêmes équipes
 * qui tournent ; sommer les douze régions annoncerait plusieurs milliers
 * d'agents là où il y en a 2 415, et ce chiffre finirait dans un rapport.
 *
 * Et la règle qui la suit de près : seuls les rapports VISÉS et les feuilles
 * VALIDÉES alimentent un agrégat. Un brouillon ne remonte nulle part.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->hier = now()->subDay()->toDateString();

    $this->admin = compteAgregat('+22670004900', 'administrateur_national');

    // ---- Région A : deux sites, de la production ----
    $this->regionA = territoire('EST', 'Est', 120000, $this->admin);
    // ---- Région B : un site, moins de production ----
    $this->regionB = territoire('SUD', 'Sud-Ouest', 80000, $this->admin);

    $this->chefA = compteAgregat('+22670004901', 'chef_antenne_regional');
    $this->chefA->update(['region_id' => $this->regionA['region']->id]);
});

function compteAgregat(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Agr', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
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

/** Une région complète : province, commune, localité, centre, deux sites, vague. */
function territoire(string $code, string $nom, int $population, User $admin): array
{
    $region = Region::query()->create([
        'code' => $code, 'nom' => $nom,
        'population_totale' => $population, 'nombre_sites_alloues' => 200,
    ]);
    $province = Province::query()->create([
        'region_id' => $region->id, 'code' => $code.'P', 'nom' => $nom.' Province',
    ]);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => $code.'C', 'nom' => $nom.' Ville', 'type' => 'urbaine',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => $nom.' Centre-ville', 'type_localite' => 'secteur',
        'population_totale' => (int) ($population / 4), 'quota_sites' => 2,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => $code.'-C001', 'nom' => 'Centre '.$nom,
        'nombre_kits' => 2, 'statut' => 'ouvert',
    ]);

    $sites = [];

    foreach ([1, 2] as $rang) {
        $sites[] = Site::query()->create([
            'centre_id' => $centre->id, 'localite_id' => $localite->id,
            'region_id' => $region->id,
            'code' => $code.'-C001-S0'.$rang, 'nom' => 'Site '.$nom.' '.$rang,
            'statut' => 'ouvert', 'ordre_tournee' => $rang,
        ]);
    }

    $vague = VagueDeploiement::query()->create([
        'code' => $code.'-2026-V1', 'libelle' => 'Vague '.$nom,
        'region_id' => $region->id,
        'date_debut_prevue' => now()->subWeek()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $admin->id,
        'objectif_enregistrements_par_kit_jour' => 100,
    ]);

    \Illuminate\Support\Facades\DB::table('vague_centres')->insert([
        'vague_id' => $vague->id, 'centre_id' => $centre->id,
        'statut' => 'ouvert', 'date_ouverture' => now()->subWeek()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('region', 'province', 'commune', 'localite', 'centre', 'sites', 'vague');
}

/**
 * Un rapport d'opérateur, avec sa production, à un statut donné.
 * Le statut est le point du test : seul « vise » ou « clos » doit compter.
 */
function rapportProduction(
    array $territoire,
    int $rang,
    string $date,
    int $enregistres,
    string $statut = 'vise',
    int $rejetes = 0
): RapportJournalier {
    $operateur = compteAgregat(
        '+2267000'.str_pad((string) random_int(10000, 99999), 5, '0'),
        'volontaire_operateur',
        CategorieVolontaire::Operateur
    );

    Affectation::query()->create([
        'vague_id' => $territoire['vague']->id,
        'volontaire_id' => $operateur->volontaire->id,
        'role_terrain' => CategorieVolontaire::Operateur->value,
        'centre_id' => $territoire['centre']->id,
        'date_debut' => now()->subWeek()->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $rapport = RapportJournalier::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'type' => 'opk',
        'date_rapport' => $date,
        'auteur_volontaire_id' => $operateur->volontaire->id,
        'vague_id' => $territoire['vague']->id,
        'site_id' => $territoire['sites'][$rang]->id,
        'centre_id' => $territoire['centre']->id,
        'region_id' => $territoire['region']->id,
        'statut' => $statut,
    ]);

    RapportOpkProduction::query()->create([
        'rapport_id' => $rapport->id,
        'objectif_enregistrements' => 100,
        'enregistrements_realises' => $enregistres,
        'enregistrements_non_valides' => $rejetes,
    ]);

    return $rapport;
}

/** Une feuille de présence, à un statut donné. */
function feuille(array $territoire, int $rang, string $date, int $presents, int $absents, string $statut = 'validee'): void
{
    $superviseur = compteAgregat(
        '+2267001'.str_pad((string) random_int(10000, 99999), 5, '0'),
        'volontaire_superviseur',
        CategorieVolontaire::Superviseur
    );

    $feuille = FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'site_id' => $territoire['sites'][$rang]->id,
        'centre_id' => $territoire['centre']->id,
        'vague_id' => $territoire['vague']->id,
        'date_presence' => $date,
        'statut' => $statut,
        'superviseur_id' => $superviseur->volontaire->id,
        'valide_le' => $statut === 'validee' ? now() : null,
    ]);

    foreach (range(1, $presents + $absents) as $index) {
        $agent = compteAgregat(
            '+2267002'.str_pad((string) random_int(10000, 99999), 5, '0'),
            'volontaire_assistant',
            CategorieVolontaire::Assistant
        );

        LignePresence::query()->create([
            'feuille_presence_id' => $feuille->id,
            'volontaire_id' => $agent->volontaire->id,
            'categorie' => CategorieVolontaire::Assistant->value,
            'statut' => $index <= $presents ? 'present' : 'absent',
            'motif_absence' => $index <= $presents ? null : 'Non signalé',
        ]);
    }
}

// ---------------------------------------------------------------------------
// Le calcul des agrégats
// ---------------------------------------------------------------------------

it('agrège la production des sites, des centres et des régions', function () {
    rapportProduction($this->regionA, 0, $this->hier, 120, 'vise', 5);
    rapportProduction($this->regionA, 1, $this->hier, 80);
    rapportProduction($this->regionB, 0, $this->hier, 60);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    expect(AgregatJourSite::query()->count())->toBe(3);

    $centreA = AgregatJourCentre::query()
        ->where('centre_id', $this->regionA['centre']->id)->first();

    expect((int) $centreA->nb_enregistres)->toBe(200)
        ->and((int) $centreA->nb_rejetes)->toBe(5)
        ->and((int) $centreA->nb_sites_actifs)->toBe(2);

    $regionA = AgregatJourRegion::query()
        ->where('region_id', $this->regionA['region']->id)->first();

    expect((int) $regionA->nb_enregistres)->toBe(200)
        ->and((int) $regionA->nb_centres_ouverts)->toBe(1)
        ->and((int) $regionA->nb_sites_couverts)->toBe(2);
});

it("ne compte QUE les rapports visés : un brouillon ne remonte nulle part", function () {
    rapportProduction($this->regionA, 0, $this->hier, 120, 'vise');
    rapportProduction($this->regionA, 1, $this->hier, 500, 'brouillon');

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    $region = AgregatJourRegion::query()
        ->where('region_id', $this->regionA['region']->id)->first();

    // 120, pas 620 : le brouillon n'a été contrôlé par personne.
    expect((int) $region->nb_enregistres)->toBe(120)
        ->and(AgregatJourSite::query()->count())->toBe(1);
});

it("ne compte QU'UNE feuille validée : un brouillon ne produit aucun effectif", function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    feuille($this->regionA, 0, $this->hier, presents: 8, absents: 2);

    rapportProduction($this->regionA, 1, $this->hier, 100);
    feuille($this->regionA, 1, $this->hier, presents: 9, absents: 1, statut: 'brouillon');

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    $centre = AgregatJourCentre::query()
        ->where('centre_id', $this->regionA['centre']->id)->first();

    expect((int) $centre->effectif_attendu)->toBe(10)
        ->and((int) $centre->effectif_present)->toBe(8)
        ->and((int) $centre->effectif_absent)->toBe(2)
        ->and((float) $centre->taux_presence)->toBe(80.0);
});

it('reprend un rapport visé en retard sans jamais cumuler', function () {
    $tardif = rapportProduction($this->regionA, 0, $this->hier, 120, 'brouillon');

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);
    expect(AgregatJourSite::query()->count())->toBe(0);

    // Le superviseur vise le lendemain matin.
    $tardif->update(['statut' => 'vise']);
    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    $region = AgregatJourRegion::query()->first();
    expect((int) $region->nb_enregistres)->toBe(120);

    // Rejouer une troisième fois n'ajoute rien : le recalcul écrase.
    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    expect((int) AgregatJourRegion::query()->first()->nb_enregistres)->toBe(120)
        ->and(AgregatJourRegion::query()->count())->toBe(1);
});

it("n'écrit aucune ligne pour un site sans activité", function () {
    rapportProduction($this->regionA, 0, $this->hier, 50);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    // Un seul site a produit : écrire 12 294 lignes à zéro chaque jour
    // ferait grossir la table sans rien apprendre à personne.
    expect(AgregatJourSite::query()->count())->toBe(1)
        ->and(AgregatJourRegion::query()->count())->toBe(1);
});

it('compte les incidents ouverts par gravité, dans la région concernée', function () {
    rapportProduction($this->regionA, 0, $this->hier, 50);

    foreach ([3, 3, 1] as $gravite) {
        Incident::query()->create([
            'uuid_client' => (string) Str::uuid(),
            'numero' => 'INC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'declarant_user_id' => $this->admin->id,
            'declare_le' => now()->subDay(),
            'site_id' => $this->regionA['sites'][0]->id,
            'centre_id' => $this->regionA['centre']->id,
            'region_id' => $this->regionA['region']->id,
            'recit' => 'Incident de démonstration pour le calcul des agrégats.',
            'gravite' => $gravite,
            'statut' => 'nouveau',
        ]);
    }

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    $region = AgregatJourRegion::query()
        ->where('region_id', $this->regionA['region']->id)->first();

    expect($region->nb_incidents_ouverts_par_gravite)
        ->toBe(['niveau_1' => 1, 'niveau_3' => 2]);

    expect((int) AgregatJourSite::query()
        ->where('site_id', $this->regionA['sites'][0]->id)->first()->nb_incidents)->toBe(3);
});

// ---------------------------------------------------------------------------
// Le taux de couverture
// ---------------------------------------------------------------------------

it('rapporte le cumul à la population réelle de la localité', function () {
    rapportProduction($this->regionA, 0, now()->subDays(3)->toDateString(), 1000);
    rapportProduction($this->regionA, 1, $this->hier, 2000);

    foreach ([now()->subDays(3)->toDateString(), $this->hier] as $jour) {
        app(CalculateurAgregats::class)->recalculerJournee($jour);
    }

    app(CalculateurCouverture::class)->recalculer();

    $couverture = AgregatCouvertureLocalite::query()
        ->where('localite_id', $this->regionA['localite']->id)->first();

    // Population de la localité : 120 000 / 4 = 30 000.
    expect((int) $couverture->population_cible)->toBe(30000)
        ->and((int) $couverture->cumul_enregistres)->toBe(3000)
        ->and((float) $couverture->taux_couverture)->toBe(10.0)
        ->and((int) $couverture->nb_sites)->toBe(2)
        ->and((int) $couverture->nb_sites_couverts)->toBe(2);
});

it('cumule les mois sans jamais garder une erreur passée', function () {
    rapportProduction($this->regionA, 0, $this->hier, 500, 'brouillon');
    rapportProduction($this->regionA, 1, $this->hier, 300);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);
    app(CalculateurCouverture::class)->recalculer();

    $mois = AgregatMoisRegion::query()
        ->where('region_id', $this->regionA['region']->id)->first();

    expect((int) $mois->nb_enregistres_mois)->toBe(300)
        ->and((int) $mois->cumul_depuis_debut)->toBe(300)
        ->and((int) $mois->nb_jours_actifs)->toBe(1);

    // Le rapport en retard est visé : le mois passé doit être corrigé, pas
    // complété par-dessus une valeur fausse.
    RapportJournalier::query()->where('statut', 'brouillon')->update(['statut' => 'vise']);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);
    app(CalculateurCouverture::class)->recalculer();

    expect((int) AgregatMoisRegion::query()->first()->cumul_depuis_debut)->toBe(800);
});

it("ne transforme pas une population inconnue en taux de zéro", function () {
    $this->regionA['localite']->update(['population_totale' => 0]);

    rapportProduction($this->regionA, 0, $this->hier, 500);
    app(CalculateurAgregats::class)->recalculerJournee($this->hier);
    app(CalculateurCouverture::class)->recalculer();

    $couverture = AgregatCouvertureLocalite::query()->first();

    expect((int) $couverture->cumul_enregistres)->toBe(500)
        ->and((float) $couverture->taux_couverture)->toBe(0.0)
        // La localité reste identifiable comme non mesurable.
        ->and((int) $couverture->population_cible)->toBe(0);
});

// ---------------------------------------------------------------------------
// LE PIÈGE DU CADRAGE : ne jamais additionner les effectifs entre régions
// ---------------------------------------------------------------------------

it("rend un PIC SIMULTANÉ au national, jamais la somme des régions", function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    feuille($this->regionA, 0, $this->hier, presents: 40, absents: 0);

    rapportProduction($this->regionB, 0, $this->hier, 50);
    feuille($this->regionB, 0, $this->hier, presents: 25, absents: 0);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    Sanctum::actingAs($this->admin);

    $synthese = $this->getJson("/api/v1/tableau-bord?date={$this->hier}")
        ->assertOk()->json('data');

    $effectif = $synthese['deploiement']['effectif_simultane'];

    // 40, pas 65 : ce sont les mêmes équipes qui tournent d'une région à l'autre.
    expect($effectif['valeur'])->toBe(40)
        ->and($effectif['nature'])->toBe('pic_regional')
        // Nommée, sinon le nombre n'est pas interprétable.
        ->and($effectif['region'])->toBe('Est');

    // Les enregistrements, EUX, s'additionnent : ce sont des personnes différentes.
    expect($synthese['enregistrements']['du_jour'])->toBe(150);
});

it('somme les sites à l\'intérieur d\'une région, où ce sont bien des personnes différentes', function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    feuille($this->regionA, 0, $this->hier, presents: 12, absents: 0);
    rapportProduction($this->regionA, 1, $this->hier, 100);
    feuille($this->regionA, 1, $this->hier, presents: 8, absents: 0);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    Sanctum::actingAs($this->chefA);

    $effectif = $this->getJson("/api/v1/tableau-bord?date={$this->hier}")
        ->assertOk()->json('data.deploiement.effectif_simultane');

    expect($effectif['valeur'])->toBe(20)
        ->and($effectif['nature'])->toBe('somme_des_sites');
});

it("rend le pic du jour dans la courbe, pas un cumul entre régions", function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    feuille($this->regionA, 0, $this->hier, presents: 40, absents: 0);
    rapportProduction($this->regionB, 0, $this->hier, 50);
    feuille($this->regionB, 0, $this->hier, presents: 25, absents: 0);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    Sanctum::actingAs($this->admin);

    $jours = $this->getJson("/api/v1/tableau-bord/evolution?du={$this->hier}&au={$this->hier}")
        ->assertOk()->json('data.jours');

    expect($jours)->toHaveCount(1)
        ->and($jours[0]['enregistrements'])->toBe(150)
        ->and($jours[0]['pic_effectif_regional'])->toBe(40);
});

// ---------------------------------------------------------------------------
// Le tableau de bord
// ---------------------------------------------------------------------------

it('cadre la synthèse sur le périmètre de qui la demande', function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    rapportProduction($this->regionB, 0, $this->hier, 50);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    Sanctum::actingAs($this->admin);
    $national = $this->getJson("/api/v1/tableau-bord?date={$this->hier}")->assertOk()->json('data');

    expect($national['portee'])->toBe('nationale')
        ->and($national['regions'])->toHaveCount(2)
        ->and($national['enregistrements']['du_jour'])->toBe(150);

    Sanctum::actingAs($this->chefA);
    $regional = $this->getJson("/api/v1/tableau-bord?date={$this->hier}")->assertOk()->json('data');

    expect($regional['portee'])->toBe('regionale')
        ->and($regional['regions'])->toHaveCount(1)
        ->and($regional['enregistrements']['du_jour'])->toBe(100);
});

it('pondère le taux de présence par les effectifs, sans moyenner les régions', function () {
    // Région A : 90 présents sur 100 attendus. Région B : 2 sur 10.
    rapportProduction($this->regionA, 0, $this->hier, 10);
    feuille($this->regionA, 0, $this->hier, presents: 18, absents: 2);
    rapportProduction($this->regionB, 0, $this->hier, 10);
    feuille($this->regionB, 0, $this->hier, presents: 1, absents: 4);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    Sanctum::actingAs($this->admin);

    $taux = $this->getJson("/api/v1/tableau-bord?date={$this->hier}")
        ->assertOk()->json('data.deploiement.taux_presence');

    // 19 présents sur 25 attendus = 76 %, et non la moyenne de 90 % et 20 %.
    expect($taux)->toEqual(76);
});

it('donne la couverture par région, en disant quand le contour manque', function () {
    rapportProduction($this->regionA, 0, $this->hier, 3000);
    app(CalculateurAgregats::class)->recalculerJournee($this->hier);
    app(CalculateurCouverture::class)->recalculer();

    Sanctum::actingAs($this->admin);

    $reponse = $this->getJson('/api/v1/tableau-bord/couverture')->assertOk();
    $regions = collect($reponse->json('data'))->keyBy('code');

    expect($regions['EST']['enregistres'])->toBe(3000)
        ->and($regions['EST']['taux_couverture'])->toBe(2.5)
        ->and($regions['EST']['contour_geojson'])->toBeNull();

    // Le message dit franchement ce qui manque pour dessiner la carte.
    expect($reponse->json('message'))->toContain("n'ont pas encore de contour");
});

it('classe les localités les moins couvertes, en écartant les non mesurables', function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    rapportProduction($this->regionB, 0, $this->hier, 5000);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);
    app(CalculateurCouverture::class)->recalculer();

    Sanctum::actingAs($this->admin);

    $retards = $this->getJson('/api/v1/tableau-bord/retards')->assertOk()->json('data');

    // La moins couverte d'abord : c'est là qu'il faut retourner.
    expect($retards[0]['region'])->toBe('Est')
        ->and($retards[0]['taux_couverture'])->toBeLessThan($retards[1]['taux_couverture']);
});

it('classe les centres du jour par production', function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    rapportProduction($this->regionB, 0, $this->hier, 300);

    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    Sanctum::actingAs($this->admin);

    $centres = $this->getJson("/api/v1/tableau-bord/centres?date={$this->hier}")
        ->assertOk()->json('data');

    expect($centres)->toHaveCount(2)
        ->and($centres[0]['region'])->toBe('Sud-Ouest')
        ->and($centres[0]['enregistrements'])->toBe(300);
});

it("n'expose pas le tableau de bord à qui n'a pas la permission", function () {
    $operateur = compteAgregat('+22670004999', 'volontaire_operateur', CategorieVolontaire::Operateur);

    Sanctum::actingAs($operateur);

    $this->getJson('/api/v1/tableau-bord')->assertForbidden();
    $this->getJson('/api/v1/tableau-bord/couverture')->assertForbidden();
});

it('refuse une période dont la fin précède le début', function () {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/tableau-bord/evolution?du=2026-09-30&au=2026-09-01')
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.au.0', 'La date de fin doit suivre la date de début.');
});

// ---------------------------------------------------------------------------
// Le job et la commande
// ---------------------------------------------------------------------------

it('recalcule deux journées, pour rattraper les visas du lendemain', function () {
    $avantHier = now()->subDays(2)->toDateString();

    rapportProduction($this->regionA, 0, $avantHier, 70);
    rapportProduction($this->regionA, 1, $this->hier, 90);

    (new RecalculerAgregatsJob)->handle(
        app(CalculateurAgregats::class),
        app(CalculateurCouverture::class)
    );

    expect(AgregatJourRegion::query()->count())->toBe(2)
        ->and((int) AgregatJourRegion::query()->whereDate('date_jour', $avantHier)
            ->first()->nb_enregistres)->toBe(70)
        // La couverture cumulée est recalculée dans la foulée.
        ->and((int) AgregatCouvertureLocalite::query()->first()->cumul_enregistres)->toBe(160);
});

it('rattrape une période entière en une commande', function () {
    rapportProduction($this->regionA, 0, now()->subDays(10)->toDateString(), 40);
    rapportProduction($this->regionA, 1, now()->subDays(5)->toDateString(), 60);

    $this->artisan('pnvb:recalculer-agregats', [
        '--du' => now()->subDays(12)->toDateString(),
        '--au' => now()->subDay()->toDateString(),
    ])->assertSuccessful();

    expect(AgregatJourRegion::query()->count())->toBe(2)
        ->and((int) AgregatCouvertureLocalite::query()->first()->cumul_enregistres)->toBe(100);
});

it('refuse une période à l\'envers', function () {
    $this->artisan('pnvb:recalculer-agregats', [
        '--du' => now()->toDateString(),
        '--au' => now()->subMonth()->toDateString(),
    ])->assertFailed();
});

it("rend les jours de l'évolution en AAAA-MM-JJ, réutilisables tels quels comme filtre", function () {
    rapportProduction($this->regionA, 0, $this->hier, 100);
    app(CalculateurAgregats::class)->recalculerJournee($this->hier);

    Sanctum::actingAs($this->admin);

    $jour = $this->getJson("/api/v1/tableau-bord/evolution?du={$this->hier}&au={$this->hier}")
        ->assertOk()
        ->json('data.jours.0.date');

    expect($jour)->toBe($this->hier);

    // Exactement ce que fait la page : elle renvoie ce jour comme filtre.
    $this->getJson("/api/v1/tableau-bord?date={$jour}")
        ->assertOk()
        ->assertJsonPath('data.enregistrements.du_jour', 100);
});
