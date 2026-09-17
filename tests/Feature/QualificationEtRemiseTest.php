<?php

use App\Enums\CategorieVolontaire;
use App\Enums\EtatRemise;
use App\Enums\StatutCompte;
use App\Mail\IdentifiantsVolontaireMail;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Province;
use App\Models\Region;
use App\Models\RemiseIdentifiants;
use App\Models\User;
use App\Models\Volontaire;
use App\Services\Comptes\ServiceRemiseIdentifiants;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Deux suites de la tâche 3 :
 *   - la QUALIFICATION en lot des fiches importées sans profil ;
 *   - la CASCADE de remise des identifiants : courriel, SMS, bordereau.
 */
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();

    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $region = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 661]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'BALE', 'nom' => 'Bale']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $this->localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1549,
    ]);

    $this->admin = User::query()->create([
        'telephone' => '+22670000002', 'nom' => 'NATIONAL', 'prenoms' => 'Administrateur',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $this->admin->assignRole('administrateur_national');

    Sanctum::actingAs($this->admin);
});

/** Une fiche importée sans profil : catégorie nulle, matricule provisoire. */
function ficheAQualifier(string $telephone, string $nom, ?string $email = null): Volontaire
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => $nom, 'prenoms' => 'Test',
        'email' => $email,
        'password' => 'Provisoire#123', 'statut_compte' => StatutCompte::Inactif->value,
    ]);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-AQU'.substr($telephone, -6),
        'categorie' => null,
        'statut' => 'operationnel',
    ]);
}

// ---------------------------------------------------------------------------
// Qualification en lot
// ---------------------------------------------------------------------------

it('liste les fiches en attente de profil', function () {
    ficheAQualifier('+22670000010', 'OUEDRAOGO');
    ficheAQualifier('+22670000011', 'KABORE');

    $reponse = $this->getJson('/api/v1/volontaires/a-qualifier')->assertOk();

    expect($reponse->json('data.fiches.total'))->toBe(2);
    expect($reponse->json('message'))->toContain('2 fiches attendent un profil');

    // L'écran dit à l'administrateur quel profil exige une localité.
    $profils = collect($reponse->json('data.profils_possibles'));
    expect($profils->firstWhere('valeur', 'assistant')['localite_obligatoire'])->toBeTrue();
    expect($profils->firstWhere('valeur', 'operateur')['localite_obligatoire'])->toBeFalse();
});

it('qualifie un lot, attribue les rôles et les matricules définitifs', function () {
    $a = ficheAQualifier('+22670000012', 'OUEDRAOGO');
    $b = ficheAQualifier('+22670000013', 'KABORE');

    $reponse = $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [
            ['volontaire_id' => $a->id, 'categorie' => 'superviseur'],
            ['volontaire_id' => $b->id, 'categorie' => 'assistant', 'localite_id' => $this->localite->id],
        ],
    ])->assertOk();

    expect($reponse->json('message'))->toContain('2 fiches qualifiées');
    expect($reponse->json('data.restantes'))->toBe(0);

    $a->refresh();
    $b->refresh();

    expect($a->categorie)->toBe(CategorieVolontaire::Superviseur);
    expect($a->matricule)->toStartWith('PNVB-SUP');
    expect($a->user->hasRole('volontaire_superviseur'))->toBeTrue();
    expect($a->qualifie_par)->toBe($this->admin->id);

    expect($b->categorie)->toBe(CategorieVolontaire::Assistant);
    expect($b->matricule)->toStartWith('PNVB-ASS');
    expect($b->localite_id)->toBe($this->localite->id);
    expect($b->user->hasRole('volontaire_assistant'))->toBeTrue();
});

it('refuse de qualifier un A-OPK sans localité, sans bloquer le reste du lot', function () {
    $bon = ficheAQualifier('+22670000014', 'OUEDRAOGO');
    $sansLocalite = ficheAQualifier('+22670000015', 'KABORE');

    $reponse = $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [
            ['volontaire_id' => $bon->id, 'categorie' => 'operateur'],
            ['volontaire_id' => $sansLocalite->id, 'categorie' => 'assistant'],
        ],
    ])->assertOk();

    // Une ligne refusée n'annule pas les autres.
    expect($reponse->json('data.qualifiees'))->toHaveCount(1);
    expect($reponse->json('data.refusees'))->toHaveCount(1);
    expect($reponse->json('data.refusees.0.motif'))->toContain('rattaché en permanence à sa localité');
    expect($reponse->json('data.restantes'))->toBe(1);

    expect($bon->fresh()->categorie)->toBe(CategorieVolontaire::Operateur);
    expect($sansLocalite->fresh()->estAQualifier())->toBeTrue();
});

it('refuse de requalifier une fiche qui porte déjà un profil', function () {
    $fiche = ficheAQualifier('+22670000016', 'OUEDRAOGO');
    $fiche->qualifier(CategorieVolontaire::Operateur, $this->admin);

    $reponse = $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [
            ['volontaire_id' => $fiche->id, 'categorie' => 'superviseur'],
        ],
    ])->assertOk();

    expect($reponse->json('data.qualifiees'))->toHaveCount(0);
    expect($reponse->json('data.refusees.0.motif'))->toContain('les catégories sont étanches');
    expect($fiche->fresh()->categorie)->toBe(CategorieVolontaire::Operateur);
});

it('interdit la qualification à qui n\'a pas le droit', function () {
    $fiche = ficheAQualifier('+22670000017', 'OUEDRAOGO');

    $chef = User::query()->create([
        'telephone' => '+22670000018', 'nom' => 'ANTENNE', 'prenoms' => 'Chef',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $chef->assignRole('chef_antenne_regional');

    Sanctum::actingAs($chef);

    $this->getJson('/api/v1/volontaires/a-qualifier')->assertStatus(403);
    $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [['volontaire_id' => $fiche->id, 'categorie' => 'operateur']],
    ])->assertStatus(403);

    expect($fiche->fresh()->estAQualifier())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Cascade de remise des identifiants
// ---------------------------------------------------------------------------

it('envoie les identifiants par courriel quand l\'adresse existe', function () {
    $fiche = ficheAQualifier('+22670000020', 'OUEDRAOGO', 'aminata@exemple.bf');

    $remise = app(ServiceRemiseIdentifiants::class)->amorcerCascade($fiche->user);

    expect($remise->canal)->toBe('courriel');
    expect($remise->statut)->toBe('envoye');
    expect($fiche->user->fresh()->etat_remise)->toBe(EtatRemise::Envoye);

    Mail::assertSent(IdentifiantsVolontaireMail::class, fn ($mail) => $mail->hasTo('aminata@exemple.bf'));
});

it('bascule sur le SMS quand le volontaire n\'a pas de courriel', function () {
    // Cas fréquent des A-OPK recrutés en milieu rural.
    $fiche = ficheAQualifier('+22670000021', 'SAWADOGO');

    $remise = app(ServiceRemiseIdentifiants::class)->amorcerCascade($fiche->user);

    expect($remise->canal)->toBe('sms');
    expect($remise->statut)->toBe('envoye');
    expect($remise->destinataire)->toBe('+22670000021');

    Mail::assertNothingSent();
});

it('tombe sur la remise en main propre quand le SMS est désactivé', function () {
    Parametre::query()->where('cle', 'comptes.canal_secours_sms')->update(['valeur' => '0']);
    cache()->flush();

    $fiche = ficheAQualifier('+22670000022', 'ZONGO');

    $remise = app(ServiceRemiseIdentifiants::class)->amorcerCascade($fiche->user);

    expect($remise->canal)->toBe('main_propre');
    expect($remise->statut)->toBe('en_attente');
});

it('le mot de passe change à chaque envoi et n\'est jamais stocké en clair', function () {
    $fiche = ficheAQualifier('+22670000023', 'TRAORE', 'awa@exemple.bf');
    $service = app(ServiceRemiseIdentifiants::class);

    $service->tenterCourriel($fiche->user);
    $empreinte1 = $fiche->user->fresh()->password;

    $service->tenterCourriel($fiche->user->fresh());
    $empreinte2 = $fiche->user->fresh()->password;

    // Un renvoi régénère le mot de passe : l'ancien cesse de fonctionner.
    expect($empreinte1)->not->toBe($empreinte2);

    // Seule la trace de l'envoi subsiste, jamais le secret.
    $colonnes = RemiseIdentifiants::query()->first()->getAttributes();
    expect(implode(' ', array_keys($colonnes)))->not->toContain('mot_de_passe');
    expect($fiche->user->fresh()->doit_changer_mot_de_passe)->toBeTrue();
});

it('suit l\'état de remise et permet le renvoi en lot', function () {
    $a = ficheAQualifier('+22670000024', 'OUEDRAOGO', 'a@exemple.bf');
    $b = ficheAQualifier('+22670000025', 'KABORE');

    $suivi = $this->getJson('/api/v1/comptes/remises')->assertOk();

    expect($suivi->json('data.comptes.total'))->toBe(2);
    expect($suivi->json('data.repartition.non_envoye.nombre'))->toBe(2);

    $renvoi = $this->postJson('/api/v1/comptes/remises/renvoyer', [
        'user_ids' => [$a->user_id, $b->user_id],
    ])->assertOk();

    expect($renvoi->json('data.envoyes'))->toBe(2);
    expect($renvoi->json('message'))->toContain('2 identifiants envoyés');

    // Chacun par le canal qui lui convient.
    $canaux = collect($renvoi->json('data.details'))->pluck('canal', 'user_id');
    expect($canaux[$a->user_id])->toBe('courriel');
    expect($canaux[$b->user_id])->toBe('sms');
});

it('génère un bordereau PDF pour une session de formation', function () {
    $a = ficheAQualifier('+22670000026', 'OUEDRAOGO');
    $b = ficheAQualifier('+22670000027', 'KABORE');
    $a->qualifier(CategorieVolontaire::Assistant, $this->admin, $this->localite->id);
    $b->qualifier(CategorieVolontaire::Operateur, $this->admin);

    $reponse = $this->postJson('/api/v1/comptes/remises/bordereau', [
        'user_ids' => [$a->user_id, $b->user_id],
        'session' => 'Formation Bagassi — mars 2026',
    ])->assertOk();

    expect($reponse->json('data.lignes'))->toBe(2);
    expect($reponse->json('message'))->toContain('détruisez le document');

    // L'état de remise bascule, et la trace porte la session.
    expect($a->user->fresh()->etat_remise)->toBe(EtatRemise::RemisMainPropre);

    $remise = RemiseIdentifiants::query()->where('user_id', $a->user_id)->first();
    expect($remise->canal)->toBe('main_propre');
    expect($remise->statut)->toBe('remis');
    expect($remise->session_formation)->toBe('Formation Bagassi — mars 2026');
    expect($remise->remis_par)->toBe($this->admin->id);

    // Le PDF est bien écrit sur le disque.
    $fichiers = Storage::allFiles('bordereaux');
    expect($fichiers)->toHaveCount(1);
    expect(Storage::get($fichiers[0]))->toStartWith('%PDF');
});

it('interdit le renvoi à qui n\'a pas le droit', function () {
    $fiche = ficheAQualifier('+22670000028', 'OUEDRAOGO', 'x@exemple.bf');

    $observateur = User::query()->create([
        'telephone' => '+22670000029', 'nom' => 'OBSERVATEUR', 'prenoms' => 'WURI',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $observateur->assignRole('observateur');

    Sanctum::actingAs($observateur);

    // L'observateur est bloqué en amont : le serveur refuse toute écriture.
    $this->postJson('/api/v1/comptes/remises/renvoyer', ['user_ids' => [$fiche->user_id]])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Votre profil est en consultation seule : vous ne pouvez pas modifier de données.');
});
