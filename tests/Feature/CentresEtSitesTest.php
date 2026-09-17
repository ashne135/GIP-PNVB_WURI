<?php

use App\Enums\StatutCompte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\FeuillePresence;
use App\Models\Import;
use App\Models\Kit;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Referentiel\PurgeurCentresEtSites;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Tâche 4 : centres et sites — CRUD, codification automatique, import de
 * remplacement.
 *
 * POINT DE VIGILANCE : purger le fictif sans casser les clés étrangères.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->bankui = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 661]);
    $this->kadiogo = Region::query()->create(['code' => 'KAD', 'nom' => 'Kadiogo', 'nombre_sites_alloues' => 2247]);

    $province = Province::query()->create(['region_id' => $this->bankui->id, 'code' => 'BALE', 'nom' => 'Bale']);
    $this->commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->bankui->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $this->localite = Localite::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1549, 'quota_sites' => 2,
    ]);

    $provinceKad = Province::query()->create([
        'region_id' => $this->kadiogo->id, 'code' => 'KADIOGO', 'nom' => 'Kadiogo',
    ]);
    $this->communeKad = Commune::query()->create([
        'province_id' => $provinceKad->id, 'region_id' => $this->kadiogo->id,
        'code' => 'OUAG', 'nom' => 'Ouagadougou', 'type' => 'urbaine',
    ]);

    $this->admin = compteAvecRole('+22670000002', 'administrateur_national');
    Sanctum::actingAs($this->admin);
});

function compteAvecRole(string $telephone, string $role, ?int $regionId = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'TEST', 'prenoms' => ucfirst($role),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false, 'region_id' => $regionId,
    ]);
    $user->assignRole($role);

    return $user;
}

function fichierCentres(array $lignes, ?array $entetes = null): UploadedFile
{
    $entetes ??= App\Services\Import\CanevasCentresSites::entetesDuModele();

    $contenu = implode(';', $entetes)."\n";

    foreach ($lignes as $ligne) {
        $contenu .= implode(';', $ligne)."\n";
    }

    $chemin = tempnam(sys_get_temp_dir(), 'centres').'.csv';
    file_put_contents($chemin, $contenu);

    return new UploadedFile($chemin, 'centres.csv', 'text/csv', null, true);
}

// ---------------------------------------------------------------------------
// Recherche et localisation
// ---------------------------------------------------------------------------

it('situe les sites par région et par commune', function () {
    $centreBankui = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre de Bagassi', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    Site::query()->create([
        'centre_id' => $centreBankui->id, 'localite_id' => $this->localite->id,
        'region_id' => $this->bankui->id, 'code' => 'BAN-BAGA-C001-S01', 'nom' => 'Site Assio',
        'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $localiteKadiogo = Localite::query()->create([
        'commune_id' => $this->communeKad->id, 'region_id' => $this->kadiogo->id,
        'nom' => 'Secteur 12', 'type_localite' => 'secteur', 'population_totale' => 8400, 'quota_sites' => 1,
    ]);
    $centreKadiogo = Centre::query()->create([
        'commune_id' => $this->communeKad->id, 'region_id' => $this->kadiogo->id,
        'code' => 'KAD-OUAG-C001', 'nom' => 'Centre de Ouagadougou', 'nombre_kits' => 2, 'statut' => 'ouvert',
    ]);
    Site::query()->create([
        'centre_id' => $centreKadiogo->id, 'localite_id' => $localiteKadiogo->id,
        'region_id' => $this->kadiogo->id, 'code' => 'KAD-OUAG-C001-S01', 'nom' => 'Site Secteur 12',
        'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $codes = fn (string $filtre) => collect(
        $this->getJson('/api/v1/referentiel/sites?'.$filtre)->assertOk()->json('data.data')
    )->pluck('code')->all();

    expect($codes('region_id='.$this->bankui->id))->toBe(['BAN-BAGA-C001-S01'])
        ->and($codes('region_id='.$this->kadiogo->id))->toBe(['KAD-OUAG-C001-S01']);

    // La commune d'un site est celle de sa LOCALITÉ, pas celle de son centre.
    expect($codes('commune_id='.$this->communeKad->id))->toBe(['KAD-OUAG-C001-S01'])
        ->and($codes('commune_id='.$this->commune->id))->toBe(['BAN-BAGA-C001-S01']);

    // Les deux filtres se combinent, et une combinaison impossible ne rend rien.
    expect($codes('region_id='.$this->bankui->id.'&commune_id='.$this->communeKad->id))->toBe([]);
});

// ---------------------------------------------------------------------------
// Codification
// ---------------------------------------------------------------------------

it('génère le code du centre selon la règle du cadrage', function () {
    $reponse = $this->postJson('/api/v1/referentiel/centres', [
        'commune_id' => $this->commune->id,
        'nom' => 'Centre de Bagassi',
        'nombre_kits' => 1,
    ])->assertStatus(201);

    // <CODE_RÉGION>-<CODE_COMMUNE>-C<numéro sur 3 chiffres>
    expect($reponse->json('data.code'))->toBe('BAN-BAGA-C001');
    expect($reponse->json('message'))->toContain('définitif');

    // Le numéro suit, il ne repart pas de 1.
    $second = $this->postJson('/api/v1/referentiel/centres', [
        'commune_id' => $this->commune->id, 'nom' => 'Second centre',
    ])->assertStatus(201);

    expect($second->json('data.code'))->toBe('BAN-BAGA-C002');
});

it('génère le code du site à partir de celui du centre', function () {
    $centre = $this->postJson('/api/v1/referentiel/centres', [
        'commune_id' => $this->commune->id, 'nom' => 'Centre de Bagassi',
    ])->json('data.id');

    $premier = $this->postJson('/api/v1/referentiel/sites', [
        'centre_id' => $centre,
        'localite_id' => $this->localite->id,
        'nom' => 'Site Assio 1',
    ])->assertStatus(201);

    expect($premier->json('data.code'))->toBe('BAN-BAGA-C001-S01');
    expect($premier->json('data.ordre_tournee'))->toBe(1);

    $second = $this->postJson('/api/v1/referentiel/sites', [
        'centre_id' => $centre, 'localite_id' => $this->localite->id, 'nom' => 'Site Assio 2',
    ])->assertStatus(201);

    expect($second->json('data.code'))->toBe('BAN-BAGA-C001-S02');
});

it('refuse de changer la commune d\'un centre, car le code en dépend', function () {
    $centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    $this->putJson("/api/v1/referentiel/centres/{$centre->id}", [
        'commune_id' => $this->communeKad->id,
    ])->assertStatus(422);

    expect($centre->fresh()->commune_id)->toBe($this->commune->id);
    expect($centre->fresh()->code)->toBe('BAN-BAGA-C001');
});

it('ferme un centre et ses sites, sans jamais les supprimer', function () {
    $centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
    Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $this->localite->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001-S01', 'nom' => 'Site 1', 'ordre_tournee' => 1, 'statut' => 'ouvert',
    ]);

    $this->postJson("/api/v1/referentiel/centres/{$centre->id}/fermer", [
        'motif' => 'Enregistrement achevé sur la commune.',
    ])->assertOk();

    expect($centre->fresh()->statut)->toBe('ferme');
    expect(Site::query()->where('centre_id', $centre->id)->value('statut'))->toBe('ferme');

    // Rien n'a disparu : l'historique reste.
    expect(Centre::query()->count())->toBe(1);
    expect(Site::query()->count())->toBe(1);
});

it('refuse une fermeture sans motif', function () {
    $centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    $this->postJson("/api/v1/referentiel/centres/{$centre->id}/fermer", [])->assertStatus(422);

    expect($centre->fresh()->statut)->toBe('ouvert');
});

// ---------------------------------------------------------------------------
// Périmètre
// ---------------------------------------------------------------------------

it('un chef d\'antenne ne crée pas de centre hors de sa région', function () {
    $chef = compteAvecRole('+22670000010', 'chef_antenne_regional', $this->bankui->id);
    Sanctum::actingAs($chef);

    // Dans sa région : autorisé.
    $this->postJson('/api/v1/referentiel/centres', [
        'commune_id' => $this->commune->id, 'nom' => 'Centre de Bagassi',
    ])->assertStatus(201);

    // Dans la région d'à côté : refusé, malgré la permission.
    $this->postJson('/api/v1/referentiel/centres', [
        'commune_id' => $this->communeKad->id, 'nom' => 'Centre de Ouaga',
    ])->assertStatus(403);

    expect(Centre::query()->where('region_id', $this->kadiogo->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------

it('analyse le fichier de centres sans rien écrire', function () {
    $reponse = $this->postJson('/api/v1/imports/centres-sites', [
        'fichier' => fichierCentres([
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '1', 'Site Assio 1', '1', '', ''],
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '1', 'Site Assio 2', '2', '', ''],
        ]),
        'mode' => 'completer',
    ])->assertStatus(201);

    expect($reponse->json('data.import.lignes_valides'))->toBe(2);
    // Deux sites, un seul centre.
    expect($reponse->json('data.import.resume.centres_distincts'))->toBe(1);

    expect(Centre::query()->count())->toBe(0);
    expect(Site::query()->count())->toBe(0);
});

it('crée un centre unique pour ses deux sites une fois confirmé', function () {
    $this->postJson('/api/v1/imports/centres-sites', [
        'fichier' => fichierCentres([
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '2', 'Site Assio 1', '1', '11.94', '-3.00'],
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '2', 'Site Assio 2', '2', '', ''],
        ]),
        'mode' => 'completer',
    ])->assertStatus(201);

    $import = Import::query()->latest()->first();
    $reponse = $this->postJson("/api/v1/imports/centres-sites/{$import->id}/confirmer")->assertOk();

    expect($reponse->json('message'))->toContain('1 centres et 2 sites créés');

    $centre = Centre::query()->first();
    expect($centre->code)->toBe('BAN-BAGA-C001');
    expect($centre->nombre_kits)->toBe(2);
    expect($centre->est_fictif)->toBeFalse();

    $sites = Site::query()->orderBy('code')->get();
    expect($sites->pluck('code')->all())->toBe(['BAN-BAGA-C001-S01', 'BAN-BAGA-C001-S02']);
    expect((float) $sites[0]->latitude)->toBe(11.94);
});

it('signale les lignes dont le territoire est introuvable', function () {
    $reponse = $this->postJson('/api/v1/imports/centres-sites', [
        'fichier' => fichierCentres([
            ['Bankui', 'Bale', 'Commune Inconnue', '', 'Assio', '', 'Centre X', '1', 'Site X', '1', '', ''],
            ['Bankui', 'Bale', 'Bagassi', '', 'Localite Inconnue', '', 'Centre Y', '1', 'Site Y', '1', '', ''],
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', '', '1', 'Site Z', '1', '', ''],
        ]),
        'mode' => 'completer',
    ])->assertStatus(201);

    expect($reponse->json('data.import.lignes_erreur'))->toBe(3);

    $motifs = Import::query()->latest()->first()
        ->lignes()->where('valide', false)->pluck('motif_erreur')->implode(' | ');

    expect($motifs)->toContain('commune « Commune Inconnue » est introuvable');
    expect($motifs)->toContain("localité « Localite Inconnue » n'existe pas dans cette commune");
    expect($motifs)->toContain('nom du centre est vide');
});

it('refuse un centre à plus de deux kits', function () {
    $this->postJson('/api/v1/imports/centres-sites', [
        'fichier' => fichierCentres([
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '3', 'Site 1', '1', '', ''],
        ]),
        'mode' => 'completer',
    ])->assertStatus(201);

    $motif = Import::query()->latest()->first()->lignes()->where('valide', false)->value('motif_erreur');
    expect($motif)->toContain('1 ou 2 kits');
});

it('repère un site en double dans le fichier', function () {
    $this->postJson('/api/v1/imports/centres-sites', [
        'fichier' => fichierCentres([
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '1', 'Site Assio 1', '1', '', ''],
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre de Bagassi', '1', 'Site Assio 1', '2', '', ''],
        ]),
        'mode' => 'completer',
    ])->assertStatus(201);

    $motif = Import::query()->latest()->first()->lignes()->where('valide', false)->value('motif_erreur');
    expect($motif)->toContain('apparaît déjà à la ligne 2');
});

// ---------------------------------------------------------------------------
// Purge du fictif — le point de vigilance
// ---------------------------------------------------------------------------

it('purge le jeu fictif et détache les kits sans les supprimer', function () {
    $centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre fictif', 'nombre_kits' => 1,
        'statut' => 'ouvert', 'est_fictif' => true,
    ]);
    $site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $this->localite->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001-S01', 'nom' => 'Site fictif', 'ordre_tournee' => 1, 'est_fictif' => true,
    ]);

    // LE KIT APPARTIENT À L'AGENT : il survit au référentiel.
    $kit = Kit::query()->create([
        'reference' => 'KIT-BAN-BAGA-C001', 'etat' => 'fonctionnel',
        'centre_courant_id' => $centre->id, 'site_courant_id' => $site->id, 'est_fictif' => true,
    ]);

    $compte = (new PurgeurCentresEtSites)->purger();

    expect($compte['centres'])->toBe(1);
    expect($compte['sites'])->toBe(1);
    expect($compte['kits_detaches'])->toBe(1);

    expect(Centre::query()->count())->toBe(0);
    expect(Site::query()->count())->toBe(0);

    // Le kit est toujours là, simplement détaché.
    $kit->refresh();
    expect($kit->exists)->toBeTrue();
    expect($kit->centre_courant_id)->toBeNull();
    expect($kit->site_courant_id)->toBeNull();
});

it('refuse la purge quand une feuille de présence réelle en dépend', function () {
    $centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre fictif', 'nombre_kits' => 1, 'est_fictif' => true,
    ]);
    $site = Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $this->localite->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001-S01', 'nom' => 'Site fictif', 'ordre_tournee' => 1, 'est_fictif' => true,
    ]);

    $superviseur = compteAvecRole('+22670000020', 'volontaire_superviseur');
    $volontaire = Volontaire::query()->create([
        'user_id' => $superviseur->id, 'matricule' => 'PNVB-SUP000001',
        'categorie' => 'superviseur', 'statut' => 'operationnel',
    ]);
    $vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->bankui->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    // Une feuille de présence RÉELLE : elle fait foi, elle ne s'efface pas.
    FeuillePresence::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'site_id' => $site->id, 'centre_id' => $centre->id, 'vague_id' => $vague->id,
        'date_presence' => now()->toDateString(), 'statut' => 'validee',
        'superviseur_id' => $volontaire->id, 'valide_le' => now(), 'est_fictif' => false,
    ]);

    $purgeur = new PurgeurCentresEtSites;

    expect($purgeur->obstacles())->not->toBeEmpty();
    expect($purgeur->obstacles()[0])->toContain('feuilles de présence réels');

    expect(fn () => $purgeur->purger())->toThrow(DomainException::class);

    // Rien n'a bougé.
    expect(Centre::query()->count())->toBe(1);
    expect(FeuillePresence::query()->count())->toBe(1);
});

it('annonce dans l\'aperçu ce que le mode remplacer va emporter', function () {
    $centre = Centre::query()->create([
        'commune_id' => $this->commune->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre fictif', 'nombre_kits' => 1, 'est_fictif' => true,
    ]);
    Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $this->localite->id, 'region_id' => $this->bankui->id,
        'code' => 'BAN-BAGA-C001-S01', 'nom' => 'Site fictif', 'ordre_tournee' => 1, 'est_fictif' => true,
    ]);

    $reponse = $this->postJson('/api/v1/imports/centres-sites', [
        'fichier' => fichierCentres([
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre réel', '1', 'Site réel', '1', '', ''],
        ]),
        'mode' => 'remplacer',
    ])->assertStatus(201);

    expect($reponse->json('data.import.resume.purge_prevue.centres'))->toBe(1);
    expect($reponse->json('data.import.resume.purge_prevue.sites'))->toBe(1);
    expect($reponse->json('message'))->toContain('supprimera 1 centres et 1 sites de démonstration');

    // Confirmation : le fictif part, le réel arrive.
    $import = Import::query()->latest()->first();
    $this->postJson("/api/v1/imports/centres-sites/{$import->id}/confirmer")->assertOk();

    expect(Centre::query()->count())->toBe(1);
    expect(Centre::query()->first()->nom)->toBe('Centre réel');
    expect(Centre::query()->first()->est_fictif)->toBeFalse();
});

it('interdit l\'import à qui n\'a pas le droit referentiel.importer', function () {
    $chef = compteAvecRole('+22670000030', 'chef_antenne_regional', $this->bankui->id);
    Sanctum::actingAs($chef);

    $this->postJson('/api/v1/imports/centres-sites', [
        'fichier' => fichierCentres([
            ['Bankui', 'Bale', 'Bagassi', '', 'Assio', '', 'Centre', '1', 'Site', '1', '', ''],
        ]),
        'mode' => 'completer',
    ])->assertStatus(403);

    expect(Import::query()->count())->toBe(0);
});
