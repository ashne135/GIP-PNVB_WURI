<?php

use App\Enums\CategorieVolontaire;
use App\Enums\NiveauEtude;
use App\Enums\StatutCompte;
use App\Enums\StatutVolontaire;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/**
 * LE NIVEAU D'ÉTUDE COMMANDE LE PROFIL (décision du client, 17/09/2026) :
 * 4ème pour un A-OPK, BAC+1 pour un opérateur de kit, Licence pour un
 * superviseur de centre.
 *
 * Ce qu'on protège :
 *   - le refus est la règle, et il nomme le niveau atteint comme le niveau exigé ;
 *   - la DÉROGATION est possible, mais motivée, et elle reste sur la fiche ;
 *   - un niveau ABSENT n'est pas une dérogation tacite ;
 *   - l'échelle se compare par son rang, jamais par son libellé.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $region = Region::query()->create(['code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 10]);
    $province = Province::query()->create(['region_id' => $region->id, 'code' => 'BALE', 'nom' => 'Bale']);
    $commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);
    $this->localite = Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'nom' => 'Assio', 'type_localite' => 'village',
    ]);

    $this->admin = compteNiveau('+22670088001', 'administrateur_national');
    Sanctum::actingAs($this->admin);
});

function compteNiveau(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'NIV', 'prenoms' => 'Test',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function ficheNiveau(string $telephone, ?string $niveau, ?int $localiteId = null): Volontaire
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'AGENT', 'prenoms' => 'Test',
        'password' => 'Provisoire#123', 'statut_compte' => StatutCompte::Inactif->value,
    ]);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-AQU'.substr($telephone, -6),
        'categorie' => null,
        'statut' => 'operationnel',
        'niveau_etude' => $niveau,
        'localite_id' => $localiteId,
    ]);
}

it('classe les niveaux par rang, et non par libellé', function () {
    expect(NiveauEtude::Licence->atteint(NiveauEtude::BacPlus2))->toBeTrue();
    expect(NiveauEtude::Bac->atteint(NiveauEtude::BacPlus1))->toBeFalse();
    expect(NiveauEtude::Quatrieme->atteint(NiveauEtude::Quatrieme))->toBeTrue();

    expect(NiveauEtude::minimumPour(CategorieVolontaire::Superviseur))->toBe(NiveauEtude::Licence);
    expect(NiveauEtude::minimumPour(CategorieVolontaire::Operateur))->toBe(NiveauEtude::BacPlus1);
    expect(NiveauEtude::minimumPour(CategorieVolontaire::Assistant))->toBe(NiveauEtude::Quatrieme);
});

it('refuse un profil que le niveau ne permet pas, en nommant les deux niveaux', function () {
    $fiche = ficheNiveau('+22670088010', 'bac');

    $reponse = $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [['volontaire_id' => $fiche->id, 'categorie' => 'superviseur']],
    ])->assertOk();

    $motif = $reponse->json('data.refusees.0.motif');
    expect($motif)->toContain('BAC')->toContain('Licence (BAC+3)');
    expect($reponse->json('data.qualifiees'))->toBe([]);
    expect($fiche->fresh()->categorie)->toBeNull();
});

it('refuse aussi quand le niveau n\'est pas renseigné', function () {
    $fiche = ficheNiveau('+22670088011', null);

    $reponse = $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [['volontaire_id' => $fiche->id, 'categorie' => 'operateur']],
    ])->assertOk();

    expect($reponse->json('data.refusees.0.motif'))->toContain("n'est pas renseigné");
    expect($fiche->fresh()->categorie)->toBeNull();
});

it('accepte le profil dès que le niveau suffit', function () {
    $opk = ficheNiveau('+22670088012', 'bac_plus_1');
    $aopk = ficheNiveau('+22670088013', 'quatrieme', $this->localite->id);

    $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [
            ['volontaire_id' => $opk->id, 'categorie' => 'operateur'],
            ['volontaire_id' => $aopk->id, 'categorie' => 'assistant'],
        ],
    ])->assertOk()->assertJsonPath('data.refusees', []);

    expect($opk->fresh()->categorie)->toBe(CategorieVolontaire::Operateur);
    expect($aopk->fresh()->categorie)->toBe(CategorieVolontaire::Assistant);
    expect($opk->fresh()->derogation_niveau_motif)->toBeNull();
});

it('laisse passer par DÉROGATION motivée, et l\'inscrit sur la fiche', function () {
    $fiche = ficheNiveau('+22670088014', 'bac');

    // Une dérogation trop courte n'en est pas une.
    $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [[
            'volontaire_id' => $fiche->id, 'categorie' => 'superviseur', 'motif_derogation' => 'ok',
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors('qualifications.0.motif_derogation', 'data.erreurs');

    $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [[
            'volontaire_id' => $fiche->id,
            'categorie' => 'superviseur',
            'motif_derogation' => 'Dix ans d\'expérience en recensement, validé par la coordination.',
        ]],
    ])->assertOk()->assertJsonPath('data.refusees', []);

    $fiche->refresh();
    expect($fiche->categorie)->toBe(CategorieVolontaire::Superviseur);
    expect($fiche->derogation_niveau_motif)->toContain('Dix ans');
    expect($fiche->derogation_niveau_par)->toBe($this->admin->id);
    expect($fiche->derogation_niveau_le)->not->toBeNull();

    $journal = Activity::query()->where('description', 'like', '%DÉROGATION%')->sole();
    expect($journal->causer_id)->toBe($this->admin->id);
});

it('corrige une fiche, sans jamais toucher à la catégorie ni au matricule', function () {
    $fiche = ficheNiveau('+22670088015', 'bac');

    $this->putJson("/api/v1/volontaires/{$fiche->id}", [
        'niveau_etude' => 'licence',
        'diplome' => 'Licence en géographie',
        'telephone' => '70 88 00 16',
        'email' => 'Agent.Test@exemple.bf',
    ])->assertOk();

    $fiche->refresh();
    expect($fiche->niveau_etude)->toBe(NiveauEtude::Licence);
    expect($fiche->diplome)->toBe('Licence en géographie');
    expect($fiche->user->telephone)->toBe('+22670880016');
    expect($fiche->user->email)->toBe('agent.test@exemple.bf');

    $this->putJson("/api/v1/volontaires/{$fiche->id}", ['categorie' => 'superviseur'])
        ->assertStatus(422)
        ->assertJsonPath('data.erreurs.categorie.0', 'Les trois catégories sont étanches : la catégorie d\'un volontaire ne change jamais.');

    $this->putJson("/api/v1/volontaires/{$fiche->id}", ['matricule' => 'PNVB-SUP000001'])
        ->assertStatus(422)->assertJsonValidationErrors('matricule', 'data.erreurs');
});

it('retire une fiche avec motif, ferme son accès, et la réintègre en réserve', function () {
    $fiche = ficheNiveau('+22670088017', 'licence');
    $fiche->user->update(['statut_compte' => StatutCompte::Actif->value]);
    $fiche->user->createToken('mobile');

    $this->postJson("/api/v1/volontaires/{$fiche->id}/retirer", ['motif' => 'abc'])
        ->assertStatus(422)->assertJsonValidationErrors('motif', 'data.erreurs');

    $this->postJson("/api/v1/volontaires/{$fiche->id}/retirer", ['motif' => 'Parti à l\'étranger'])
        ->assertOk();

    $fiche->refresh();
    expect($fiche->statut)->toBe(StatutVolontaire::Retire);
    expect($fiche->motif_retrait)->toBe('Parti à l\'étranger');
    expect($fiche->retire_par)->toBe($this->admin->id);
    expect($fiche->user->fresh()->statut_compte)->toBe(StatutCompte::Ferme);
    expect($fiche->user->tokens()->count())->toBe(0);

    // La fiche existe toujours : rien n'a été effacé.
    expect(Volontaire::query()->whereKey($fiche->id)->exists())->toBeTrue();

    $this->postJson("/api/v1/volontaires/{$fiche->id}/reintegrer", ['motif' => 'Retour au pays'])
        ->assertOk();

    expect($fiche->fresh()->statut)->toBe(StatutVolontaire::Reserve);
    expect($fiche->fresh()->motif_retrait)->toBeNull();
});

it('retire un lot, et dit pourquoi une fiche engagée ne l\'est pas', function () {
    $libre = ficheNiveau('+22670088018', 'licence');
    $engage = ficheNiveau('+22670088019', 'licence');

    $vague = App\Models\VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->localite->region_id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);
    App\Models\Affectation::query()->create([
        'vague_id' => $vague->id, 'volontaire_id' => $engage->id, 'role_terrain' => 'assistant',
        'localite_id' => $this->localite->id, 'date_debut' => now()->toDateString(),
        'statut' => 'active', 'origine' => 'tirage_auto',
    ]);

    $reponse = $this->postJson('/api/v1/volontaires/retrait-en-lot', [
        'volontaire_ids' => [$libre->id, $engage->id],
        'motif' => 'Fin de collaboration',
    ])->assertOk();

    expect($reponse->json('data.retires'))->toHaveCount(1);
    expect($reponse->json('data.refusees.0.motif'))->toContain('engagée dans une vague');
    expect($libre->fresh()->statut)->toBe(StatutVolontaire::Retire);
    expect($engage->fresh()->statut)->toBe(StatutVolontaire::Operationnel);
});

it('interdit la correction et le retrait à qui n\'a pas le droit', function () {
    $fiche = ficheNiveau('+22670088020', 'licence');

    Sanctum::actingAs(compteNiveau('+22670088002', 'chef_antenne_regional'));

    $this->putJson("/api/v1/volontaires/{$fiche->id}", ['diplome' => 'Autre'])->assertForbidden();
    $this->postJson("/api/v1/volontaires/{$fiche->id}/retirer", ['motif' => 'Sans le droit'])->assertForbidden();
    $this->postJson('/api/v1/volontaires/retrait-en-lot', [
        'volontaire_ids' => [$fiche->id], 'motif' => 'Sans le droit',
    ])->assertForbidden();
});

/**
 * UNE FICHE « À QUALIFIER » NE DOIT RIEN FAIRE TOMBER.
 *
 * Elle a un volontaire, mais pas de catégorie. Trois chemins l'oubliaient et
 * échouaient en erreur 500 : le bordereau de remise, le profil rendu au
 * téléphone, et le recalcul nocturne des accès. Le premier a été constaté en
 * production le 18/09/2026.
 */
it('imprime un bordereau qui contient une fiche sans catégorie', function () {
    $fiche = ficheNiveau('+22670088030', 'licence');
    $fiche->user->update(['statut_compte' => StatutCompte::Actif->value]);

    $reponse = $this->postJson('/api/v1/comptes/remises/bordereau', [
        'user_ids' => [$fiche->user_id],
        'session' => 'Remise du jour',
    ])->assertOk();

    expect($reponse->json('data.lignes'))->toBe(1);
    expect($fiche->fresh()->categorie)->toBeNull();
});

it('rend le profil d\'une fiche sans catégorie au lieu d\'une erreur', function () {
    $fiche = ficheNiveau('+22670088031', 'licence');
    $fiche->user->update(['statut_compte' => StatutCompte::Actif->value, 'doit_changer_mot_de_passe' => false]);
    $fiche->user->consentements()->create(['version_charte' => '2026.1', 'accepte_le' => now()]);

    Sanctum::actingAs($fiche->user);

    $reponse = $this->getJson('/api/v1/moi')->assertOk();

    expect($reponse->json('data.volontaire.categorie'))->toBeNull();
    expect($reponse->json('data.volontaire.categorie_libelle'))->toBe('À qualifier');
    expect($reponse->json('data.volontaire.affectation_tournante'))->toBeFalse();
});

it('recalcule l\'accès d\'une fiche sans catégorie sans s\'interrompre', function () {
    $fiche = ficheNiveau('+22670088032', 'licence');

    app(App\Services\Comptes\ServiceCycleDeVieCompte::class)->recalculer($fiche);

    expect($fiche->user->fresh()->statut_compte)->toBe(StatutCompte::Inactif);
});
