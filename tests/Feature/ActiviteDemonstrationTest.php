<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Affectation;
use App\Models\AgregatJourRegion;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\FeuillePresence;
use App\Models\Kit;
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
use App\Services\Demonstration\GenerateurActiviteDemonstration;
use App\Services\Import\PurgeurVolontairesFictifs;
use Database\Seeders\NomenclaturesIncidentSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * L'activité fictive de démonstration, et sa purge.
 *
 * Le point que ces tests protègent avant tout : une fois l'activité générée,
 * la purge des volontaires fictifs doit TOUJOURS passer. Rapports et feuilles
 * tiennent leurs auteurs par des clés restrictives ; sans purge dédiée, le jeu
 * de démonstration ne pourrait plus jamais être remplacé par les vrais retenus.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);
    $this->seed(NomenclaturesIncidentSeeder::class);

    $this->admin = User::query()->create([
        'telephone' => '+22670006900', 'nom' => 'National', 'prenoms' => 'Admin',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $this->admin->assignRole('administrateur_national');

    $region = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'population_totale' => 900000, 'nombre_sites_alloues' => 661]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'BALE', 'nom' => 'Balé']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id, 'nom' => 'Assio',
        'type_localite' => 'village', 'population_totale' => 4000, 'quota_sites' => 2,
    ]);
    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre Bagassi', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    $centre->forceFill(['est_fictif' => true])->save();

    $this->sites = collect([1, 2])->map(fn ($rang) => Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $region->id,
        'code' => "BAN-BAGA-C001-S0{$rang}", 'nom' => "Site Assio {$rang}",
        'statut' => 'ouvert', 'ordre_tournee' => $rang, 'est_fictif' => true,
    ]));

    // Une vague fictive qui commence AUJOURD'HUI, comme celle du seeder.
    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-DEMO', 'libelle' => 'Vague de démonstration', 'region_id' => $region->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addWeeks(8)->toDateString(),
        'date_ouverture_reelle' => now(), 'statut' => 'active', 'cree_par' => $this->admin->id,
        'est_fictif' => true,
    ]);
    DB::table('vague_centres')->insert([
        'vague_id' => $this->vague->id, 'centre_id' => $centre->id, 'statut' => 'ouvert',
        'date_ouverture' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $superviseur = volontaireFictif('+22670006901', CategorieVolontaire::Superviseur);
    UniteSupervision::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_superviseur_id' => $superviseur->id,
        'centre_principal_id' => $centre->id, 'meme_commune' => true, 'contrainte_respectee' => true,
    ]);

    $operateur = volontaireFictif('+22670006902', CategorieVolontaire::Operateur);
    $kit = Kit::query()->create(['reference' => 'KIT-DEMO-001', 'etat' => 'fonctionnel', 'est_fictif' => true]);
    $affectation = Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $operateur->id, 'role_terrain' => 'operateur',
        'centre_id' => $centre->id, 'kit_id' => $kit->id, 'date_debut' => now()->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto', 'est_fictif' => true,
    ]);

    // Deux passages de trois jours, l'un après l'autre.
    foreach ($this->sites as $rang => $site) {
        TourneeSite::query()->create([
            'vague_id' => $this->vague->id, 'centre_id' => $centre->id, 'site_id' => $site->id,
            'kit_id' => $kit->id, 'affectation_operateur_id' => $affectation->id, 'ordre' => $rang + 1,
            'date_debut' => now()->addDays($rang * 3)->toDateString(),
            'date_fin' => now()->addDays($rang * 3 + 2)->toDateString(),
            'statut' => $rang === 0 ? 'en_cours' : 'planifiee', 'est_fictif' => true,
        ]);
    }

    $assistant = volontaireFictif('+22670006903', CategorieVolontaire::Assistant);
    Affectation::query()->create([
        'vague_id' => $this->vague->id, 'volontaire_id' => $assistant->id, 'role_terrain' => 'assistant',
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'date_debut' => now()->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto', 'est_fictif' => true,
    ]);
});

function volontaireFictif(string $telephone, CategorieVolontaire $categorie): Volontaire
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'Fictif', 'prenoms' => ucfirst($categorie->value),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);

    $volontaire = Volontaire::query()->create([
        'user_id' => $user->id, 'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -5),
        'categorie' => $categorie->value, 'statut' => 'operationnel',
    ]);
    $volontaire->forceFill(['est_fictif' => true])->save();

    return $volontaire;
}

/** Les jours ouvrés couverts par les deux passages, une fois la vague reculée de 7 jours. */
function joursAttendus(): array
{
    $jours = [];

    for ($jour = now()->subDays(7)->startOfDay(); $jour->lessThanOrEqualTo(now()->subDays(2)->startOfDay()); $jour->addDay()) {
        if (! $jour->isWeekend()) {
            $jours[] = $jour->toDateString();
        }
    }

    return $jours;
}

it('recule la vague entière plutôt que d’antidater l’activité', function () {
    $resultat = app(GenerateurActiviteDemonstration::class)->generer(7);

    expect($resultat['decalage_jours'])->toBe(7)
        ->and($this->vague->fresh()->date_debut_prevue->toDateString())->toBe(now()->subDays(7)->toDateString());

    // L'enchaînement des passages est conservé : le second suit le premier.
    $tournees = TourneeSite::query()->where('vague_id', $this->vague->id)->orderBy('ordre')->get();
    expect($tournees[0]->date_debut->toDateString())->toBe(now()->subDays(7)->toDateString())
        ->and($tournees[1]->date_debut->toDateString())->toBe(now()->subDays(4)->toDateString())
        ->and($tournees[0]->statut)->toBe('terminee');
});

it('produit des rapports VISÉS et des feuilles VALIDÉES, les jours ouvrés seulement', function () {
    $resultat = app(GenerateurActiviteDemonstration::class)->generer(7);
    $attendus = joursAttendus();

    expect($resultat['rapports'])->toBe(count($attendus))
        ->and($resultat['feuilles'])->toBe(count($attendus));

    $rapports = RapportJournalier::query()->where('vague_id', $this->vague->id)->get();

    expect($rapports->pluck('statut')->map->value->unique()->all())->toBe(['vise'])
        ->and($rapports->every(fn ($r) => $r->est_fictif))->toBeTrue()
        ->and($rapports->pluck('date_rapport')->map->toDateString()->sort()->values()->all())->toBe($attendus);

    // Chaque rapport porte sa signature ET son visa : une chaîne vide sur un
    // rapport visé serait incohérente à l'écran.
    expect(DB::table('rapport_visas')->whereIn('rapport_id', $rapports->pluck('id'))->count())
        ->toBe(2 * count($attendus));

    $feuille = FeuillePresence::query()->with('lignes')->where('vague_id', $this->vague->id)->first();
    expect($feuille->statut)->toBe('validee')
        ->and($feuille->est_fictif)->toBeTrue()
        // L'opérateur a produit ce jour-là : il est présent.
        ->and($feuille->lignes->firstWhere('categorie.value', 'operateur')?->statut->value
            ?? $feuille->lignes->first(fn ($l) => $l->categorie?->value === 'operateur')?->statut->value)
        ->toBe('present');
});

it('remplit le tableau de bord : agrégats et couverture recalculés', function () {
    app(GenerateurActiviteDemonstration::class)->generer(7);

    expect(AgregatJourRegion::query()->count())->toBe(count(joursAttendus()))
        ->and((int) DB::table('agregats_couverture_localite')->sum('cumul_enregistres'))
        ->toBeGreaterThan(0);
});

it('donne toujours la même activité pour la même graine', function () {
    app(GenerateurActiviteDemonstration::class)->generer(7);
    $premier = DB::table('rapport_opk_production')->orderBy('id')->pluck('enregistrements_realises')->all();

    app(GenerateurActiviteDemonstration::class)->generer(7, refaire: true);
    $second = DB::table('rapport_opk_production')->orderBy('id')->pluck('enregistrements_realises')->all();

    expect($second)->toBe($premier);
});

it('refuse de générer deux fois sans l’option de régénération', function () {
    app(GenerateurActiviteDemonstration::class)->generer(7);

    expect(fn () => app(GenerateurActiviteDemonstration::class)->generer(7))
        ->toThrow(DomainException::class, 'déjà une activité de démonstration');
});

it('régénère sans doublon avec l’option', function () {
    app(GenerateurActiviteDemonstration::class)->generer(7);
    app(GenerateurActiviteDemonstration::class)->generer(7, refaire: true);

    expect(RapportJournalier::query()->count())->toBe(count(joursAttendus()))
        ->and(FeuillePresence::query()->count())->toBe(count(joursAttendus()));
});

it('laisse TOUJOURS passer la purge des volontaires fictifs après génération', function () {
    app(GenerateurActiviteDemonstration::class)->generer(7);

    $purgeur = new PurgeurVolontairesFictifs;

    // Plus aucune table supprimée par la v2 n'est interrogée.
    expect($purgeur->obstacles())->toBe([]);

    $compte = $purgeur->purger();

    expect($compte['volontaires'])->toBe(3)
        ->and($compte['activite_rapports'])->toBe(count(joursAttendus()))
        ->and(RapportJournalier::query()->count())->toBe(0)
        ->and(FeuillePresence::query()->count())->toBe(0)
        // Les agrégats ne gardent aucune trace de l'activité retirée.
        ->and(AgregatJourRegion::query()->count())->toBe(0)
        ->and(DB::table('agregats_couverture_localite')->count())->toBe(0);
});

it('rend les sites de la carte, en disant combien manquent de coordonnées', function () {
    Sanctum::actingAs($this->admin);

    $reponse = $this->getJson('/api/v1/tableau-bord/sites-carte')->assertOk();

    expect($reponse->json('data.total_sites'))->toBe(2)
        ->and($reponse->json('data.localises'))->toBe(0)
        ->and($reponse->json('message'))->toContain("Aucun site n'a encore de coordonnées");

    $this->sites[0]->update(['latitude' => 11.9, 'longitude' => -3.3]);
    app(GenerateurActiviteDemonstration::class)->generer(7);

    $donnees = $this->getJson('/api/v1/tableau-bord/sites-carte')->assertOk()->json('data');

    expect($donnees['localises'])->toBe(1)
        ->and($donnees['sites'][0])->toMatchArray(['code' => 'BAN-BAGA-C001-S01', 'lat' => 11.9, 'lng' => -3.3, 'couvert' => true]);
});
