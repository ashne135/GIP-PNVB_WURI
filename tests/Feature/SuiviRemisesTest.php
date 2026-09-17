<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * LE SUIVI DE LA REMISE DES IDENTIFIANTS — ce que l'écran du back-office exige.
 *
 *  - LE PÉRIMÈTRE. Le chef d'antenne détient le droit de consulter ce suivi.
 *    Le serveur ne filtrait rien : il lisait les téléphones et les courriels de
 *    tout le pays. Il ne voit plus que les agents affectés dans sa région.
 *
 *  - LA RECHERCHE. Préparer un test mobile, c'est retrouver trois agents
 *    parmi des milliers, par nom, téléphone ou matricule.
 *
 *  - LE NOM DU BORDEREAU. Sous un sous-chemin (/pnvbwuri), l'adresse absolue
 *    que construit route() ignore le préfixe : l'écran télécharge par le nom.
 *
 *  - LES CANAUX SIMULÉS. Avec le pilote « log », un envoi est compté comme
 *    réussi alors que rien ne part. L'écran doit pouvoir le dire.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->bankui = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 10]);
    $this->nando = Region::query()->create(['code' => 'NAN', 'nom' => 'Nando', 'nombre_sites_alloues' => 10]);

    $this->admin = compteSuivi('+22670022001', 'NATIONAL', 'administrateur_national');
    $this->chef = compteSuivi('+22670022002', 'CHEF', 'chef_antenne_regional');
    $this->chef->update(['region_id' => $this->bankui->id]);

    $this->agentBankui = volontaireSuivi('+22670022010', 'KABORE', 'PNVB-OPK000010');
    $this->agentNando = volontaireSuivi('+22670022011', 'ZONGO', 'PNVB-OPK000011');
    $this->agentNonAffecte = volontaireSuivi('+22670022012', 'SAWADOGO', 'PNVB-OPK000012');

    affecterSuivi($this->agentBankui, centreSuivi($this->bankui, 'BAN-BAGA-C001'), $this->admin);
    affecterSuivi($this->agentNando, centreSuivi($this->nando, 'NAN-KOUD-C001'), $this->admin);
});

function compteSuivi(string $telephone, string $nom, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => $nom, 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function volontaireSuivi(string $telephone, string $nom, string $matricule): Volontaire
{
    $user = compteSuivi($telephone, $nom, 'volontaire_operateur');
    $user->update(['statut_compte' => StatutCompte::Inactif->value]);

    return Volontaire::query()->create([
        'user_id' => $user->id, 'matricule' => $matricule,
        'categorie' => CategorieVolontaire::Operateur->value, 'statut' => 'operationnel',
    ]);
}

function centreSuivi(Region $region, string $code): Centre
{
    $province = Province::query()->create([
        'region_id' => $region->id, 'code' => 'P'.$region->code, 'nom' => 'Province '.$region->nom,
    ]);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'C'.$region->code, 'nom' => 'Commune '.$region->nom, 'type' => 'rurale',
    ]);

    return Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => $code, 'nom' => 'Centre '.$region->nom, 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);
}

function affecterSuivi(Volontaire $volontaire, Centre $centre, User $admin): void
{
    $vague = VagueDeploiement::query()->create([
        'code' => $centre->code.'-V1', 'libelle' => 'Vague', 'region_id' => $centre->region_id,
        'date_debut_prevue' => now()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $admin->id,
    ]);

    Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $volontaire->id,
        'role_terrain' => 'operateur', 'centre_id' => $centre->id,
        'date_debut' => now()->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);
}

it('montre tous les comptes de volontaires à l\'administration nationale', function () {
    Sanctum::actingAs($this->admin);

    $reponse = $this->getJson('/api/v1/comptes/remises')->assertOk();

    expect($reponse->json('data.comptes.total'))->toBe(3);
    expect($reponse->json('data.repartition.non_envoye.nombre'))->toBe(3);
});

it('ne montre au chef d\'antenne que les agents affectés dans SA région', function () {
    Sanctum::actingAs($this->chef);

    $reponse = $this->getJson('/api/v1/comptes/remises')->assertOk();

    $telephones = collect($reponse->json('data.comptes.data'))->pluck('telephone')->all();

    // Ni l'agent de Nando, ni l'agent importé qui n'est encore affecté nulle part.
    expect($telephones)->toBe(['+22670022010']);

    // Le chiffre clé de l'écran suit le même périmètre que la liste.
    expect($reponse->json('data.repartition.non_envoye.nombre'))->toBe(1);
});

it('retrouve un agent par son nom, son téléphone ou son matricule', function () {
    Sanctum::actingAs($this->admin);

    $parNom = $this->getJson('/api/v1/comptes/remises?recherche=zongo')->assertOk();
    expect(collect($parNom->json('data.comptes.data'))->pluck('nom')->all())->toBe(['ZONGO']);

    $parTelephone = $this->getJson('/api/v1/comptes/remises?recherche=22012')->assertOk();
    expect(collect($parTelephone->json('data.comptes.data'))->pluck('nom')->all())->toBe(['SAWADOGO']);

    $parMatricule = $this->getJson('/api/v1/comptes/remises?recherche=OPK000010')->assertOk();
    expect(collect($parMatricule->json('data.comptes.data'))->pluck('nom')->all())->toBe(['KABORE']);
});

it('filtre sur l\'état de l\'accès', function () {
    Sanctum::actingAs($this->admin);
    $this->agentNando->user->update(['statut_compte' => StatutCompte::Actif->value]);

    $reponse = $this->getJson('/api/v1/comptes/remises?statut_compte=actif')->assertOk();

    expect(collect($reponse->json('data.comptes.data'))->pluck('nom')->all())->toBe(['ZONGO']);
});

it('dit quand les courriels et les SMS ne partent pas pour de vrai', function () {
    Sanctum::actingAs($this->admin);

    config(['mail.default' => 'log', 'pnvb.sms.pilote' => 'log']);
    $simule = $this->getJson('/api/v1/comptes/remises')->assertOk();
    expect($simule->json('data.canaux'))->toBe(['courriel_simule' => true, 'sms_simule' => true]);

    config(['mail.default' => 'smtp', 'pnvb.sms.pilote' => 'http']);
    $reel = $this->getJson('/api/v1/comptes/remises')->assertOk();
    expect($reel->json('data.canaux'))->toBe(['courriel_simule' => false, 'sms_simule' => false]);
});

it('imprime l\'état des accès, et ce document ne porte aucun mot de passe', function () {
    Sanctum::actingAs($this->admin);

    $reponse = $this->get('/api/v1/comptes/remises/etat-acces')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $pdf = $reponse->streamedContent();
    expect($pdf)->toStartWith('%PDF');

    // Le PDF est produit depuis une vue qui n'a reçu aucun mot de passe : la
    // seule remise qui en porte reste le bordereau nominatif.
    expect(App\Models\RemiseIdentifiants::query()->count())->toBe(0);
    // Aucun mot de passe n'a été régénéré au passage : imprimer un état n'est
    // pas une remise d'identifiants.
    expect($this->agentBankui->user->fresh()->password)->toBe($this->agentBankui->user->password);

    // Le chef d'antenne imprime son périmètre, comme il le consulte.
    Sanctum::actingAs($this->chef);
    $this->get('/api/v1/comptes/remises/etat-acces')->assertOk();

    Sanctum::actingAs($this->agentBankui->user);
    $this->get('/api/v1/comptes/remises/etat-acces')->assertForbidden();
});

it('rend le nom du bordereau, et ce nom suffit à le télécharger', function () {
    Sanctum::actingAs($this->admin);

    $reponse = $this->postJson('/api/v1/comptes/remises/bordereau', [
        'user_ids' => [$this->agentBankui->user_id],
        'session' => 'Test mobile',
    ])->assertOk();

    $fichier = $reponse->json('data.fichier');
    expect($fichier)->toStartWith('bordereau-test-mobile-')->toEndWith('.pdf');

    $this->get('/api/v1/comptes/remises/bordereau/'.$fichier)->assertOk();
});
