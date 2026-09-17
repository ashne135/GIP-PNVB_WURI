<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Enums\StatutRapport;
use App\Enums\TypeRapport;
use App\Models\AppreciationReponse;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\RapportJournalier;
use App\Models\RapportSuiviAgent;
use App\Models\Region;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Rapports\ServiceCycleDeVieRapport;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;

/**
 * Cadrage v2, section 9 : TROIS rapports journaliers et une chaîne de visas.
 *
 * Ces tests protègent les règles que le cadrage pose comme non négociables :
 * un rapport visé n'est plus modifiable, un rejet exige un motif, le visa
 * appartient au supérieur désigné, et l'agent noté peut répondre sans que son
 * supérieur puisse réécrire sa réponse.
 */
beforeEach(function () {
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
        'nom' => 'Assio', 'type_localite' => 'village', 'population_totale' => 1549, 'quota_sites' => 1,
    ]);
    $this->centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $region->id,
        'code' => 'BAN-BAGA-C001', 'nom' => 'Centre Bagassi', 'nombre_kits' => 1, 'statut' => 'ouvert',
    ]);

    $this->admin = creerCompteRapport('+22670000002', 'administrateur_national');

    $this->vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague test', 'region_id' => $region->id,
        'date_debut_prevue' => now()->toDateString(), 'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
        'objectif_enregistrements_par_kit_jour' => 120,
    ]);

    $this->region = $region;
});

function creerCompteRapport(string $telephone, string $role, ?CategorieVolontaire $categorie = null): User
{
    $user = User::query()->create([
        'telephone' => $telephone,
        'nom' => 'Test', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1',
        'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    if ($categorie) {
        Volontaire::query()->create([
            'user_id' => $user->id,
            'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
            'categorie' => $categorie->value,
            'statut' => 'operationnel',
        ]);
        $user->refresh();
    }

    return $user;
}

/** Crée un rapport dans l'état voulu, avec son supérieur désigné. */
function creerRapport(
    TypeRapport $type,
    Volontaire $auteur,
    ?Volontaire $superieur,
    $contexte,
    StatutRapport $statut = StatutRapport::Brouillon
): RapportJournalier {
    return RapportJournalier::query()->create([
        'uuid_client' => (string) Str::uuid(),
        'type' => $type->value,
        'date_rapport' => now()->toDateString(),
        'auteur_volontaire_id' => $auteur->id,
        'vague_id' => $contexte->vague->id,
        'centre_id' => $contexte->centre->id,
        'region_id' => $contexte->region->id,
        'superieur_volontaire_id' => $superieur?->id,
        'statut' => $statut->value,
    ]);
}

it('la chaîne de visas suit bien les trois niveaux', function () {
    expect(TypeRapport::Aopk->roleViseur()->value)->toBe('volontaire_operateur');
    expect(TypeRapport::Opk->roleViseur()->value)->toBe('volontaire_superviseur');
    expect(TypeRapport::Superviseur->roleViseur()->value)->toBe('controleur_terrain');

    // Le A-OPK est au bas de la chaîne : il ne vise personne.
    expect(TypeRapport::Aopk->niveauInferieur())->toBeNull();
    expect(TypeRapport::Superviseur->niveauInferieur())->toBe(TypeRapport::Opk);
});

it('l\'A-OPK n\'enregistre personne, seul l\'opérateur produit un chiffre', function () {
    expect(CategorieVolontaire::Assistant->enregistreLaPopulation())->toBeFalse();
    expect(CategorieVolontaire::Operateur->enregistreLaPopulation())->toBeTrue();
    expect(CategorieVolontaire::Superviseur->enregistreLaPopulation())->toBeFalse();
});

it('le supérieur désigné vise, un autre porteur de la permission ne peut pas', function () {
    $aopk = creerCompteRapport('+22670000011', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $sonOpk = creerCompteRapport('+22670000012', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $autreOpk = creerCompteRapport('+22670000013', 'volontaire_operateur', CategorieVolontaire::Operateur);

    $rapport = creerRapport(
        TypeRapport::Aopk, $aopk->volontaire, $sonOpk->volontaire, $this, StatutRapport::Soumis
    );

    // Les deux opérateurs portent « rapports.viser »…
    expect($sonOpk->can('rapports.viser'))->toBeTrue();
    expect($autreOpk->can('rapports.viser'))->toBeTrue();

    // …mais seul le supérieur DÉSIGNÉ peut viser CE rapport.
    expect($sonOpk->can('viser', $rapport))->toBeTrue();
    expect($autreOpk->can('viser', $rapport))->toBeFalse();
});

it('un rapport visé n\'est plus modifiable par son auteur', function () {
    $aopk = creerCompteRapport('+22670000014', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $opk = creerCompteRapport('+22670000015', 'volontaire_operateur', CategorieVolontaire::Operateur);

    $rapport = creerRapport(TypeRapport::Aopk, $aopk->volontaire, $opk->volontaire, $this);

    // Au brouillon, l'auteur modifie.
    expect($aopk->can('update', $rapport))->toBeTrue();

    $service = app(ServiceCycleDeVieRapport::class);
    $service->soumettre($rapport, $aopk);
    $rapport->refresh();

    // Soumis : l'auteur ne touche plus.
    expect($aopk->can('update', $rapport))->toBeFalse();

    $service->viser($rapport, $opk);
    $rapport->refresh();

    expect($rapport->statut)->toBe(StatutRapport::Vise);
    expect($aopk->can('update', $rapport))->toBeFalse();
    expect($rapport->estModifiableParAuteur())->toBeFalse();
});

it('un rejet exige un motif et rouvre le rapport à son auteur', function () {
    $aopk = creerCompteRapport('+22670000016', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $opk = creerCompteRapport('+22670000017', 'volontaire_operateur', CategorieVolontaire::Operateur);

    $rapport = creerRapport(
        TypeRapport::Aopk, $aopk->volontaire, $opk->volontaire, $this, StatutRapport::Soumis
    );

    $service = app(ServiceCycleDeVieRapport::class);

    // Un rejet sans motif est inexploitable pour celui qui doit corriger.
    expect(fn () => $service->rejeter($rapport, $opk, '   '))
        ->toThrow(DomainException::class);

    $service->rejeter($rapport, $opk, 'Le nombre de justificatifs transmis est incohérent.');
    $rapport->refresh();

    expect($rapport->statut)->toBe(StatutRapport::Rejete);
    expect($rapport->motif_rejet)->toContain('incohérent');

    // Rejeté : l'auteur peut de nouveau corriger, puis resoumettre.
    expect($aopk->can('update', $rapport))->toBeTrue();
    expect($rapport->statut->peutAllerVers(StatutRapport::Soumis))->toBeTrue();
});

it('refuse une transition que le cycle de vie n\'autorise pas', function () {
    $aopk = creerCompteRapport('+22670000018', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $opk = creerCompteRapport('+22670000019', 'volontaire_operateur', CategorieVolontaire::Operateur);

    $rapport = creerRapport(TypeRapport::Aopk, $aopk->volontaire, $opk->volontaire, $this);

    // On ne vise pas un brouillon : il n'a pas encore été soumis.
    expect(fn () => app(ServiceCycleDeVieRapport::class)->viser($rapport, $opk))
        ->toThrow(DomainException::class);
});

it('chaque transition laisse une trace signée et horodatée', function () {
    $aopk = creerCompteRapport('+22670000020', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $opk = creerCompteRapport('+22670000021', 'volontaire_operateur', CategorieVolontaire::Operateur);

    $rapport = creerRapport(TypeRapport::Aopk, $aopk->volontaire, $opk->volontaire, $this);
    $service = app(ServiceCycleDeVieRapport::class);

    $service->soumettre($rapport, $aopk, [
        'latitude' => 11.9456, 'longitude' => -3.0021,
    ]);
    $service->viser($rapport->fresh(), $opk, 'Conforme.');

    $visas = $rapport->fresh()->visas;

    expect($visas)->toHaveCount(2);
    expect($visas[0]->acte->value)->toBe('signature');
    expect($visas[0]->user_id)->toBe($aopk->id);
    expect((float) $visas[0]->latitude)->toBe(11.9456);
    expect($visas[1]->acte->value)->toBe('visa');
    expect($visas[1]->user_id)->toBe($opk->id);
    expect($visas[1]->role_tenu)->toBe('volontaire_operateur');
});

it('seuls les rapports visés alimentent le niveau supérieur', function () {
    expect(StatutRapport::Brouillon->alimenteLeNiveauSuperieur())->toBeFalse();
    expect(StatutRapport::Soumis->alimenteLeNiveauSuperieur())->toBeFalse();
    expect(StatutRapport::Rejete->alimenteLeNiveauSuperieur())->toBeFalse();
    expect(StatutRapport::Vise->alimenteLeNiveauSuperieur())->toBeTrue();
    expect(StatutRapport::Clos->alimenteLeNiveauSuperieur())->toBeTrue();
});

it('une correction de valeur pré-remplie exige un motif et reste tracée', function () {
    $superviseur = creerCompteRapport('+22670000022', 'volontaire_superviseur', CategorieVolontaire::Superviseur);
    $rapport = creerRapport(TypeRapport::Superviseur, $superviseur->volontaire, null, $this);

    $service = app(ServiceCycleDeVieRapport::class);

    expect(fn () => $service->corriger($rapport, 'evolution.personnes_enregistrees', 340, 352, '', $superviseur))
        ->toThrow(DomainException::class);

    $correction = $service->corriger(
        $rapport, 'evolution.personnes_enregistrees', 340, 352,
        "Un rapport OPK est arrivé après la consolidation.", $superviseur
    );

    expect($correction->valeur_origine)->toBe('340');
    expect($correction->valeur_corrigee)->toBe('352');
    expect($rapport->fresh()->corrections)->toHaveCount(1);
});

it('l\'agent noté voit son appréciation et peut y répondre', function () {
    $aopk = creerCompteRapport('+22670000023', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $opk = creerCompteRapport('+22670000024', 'volontaire_operateur', CategorieVolontaire::Operateur);

    $rapport = creerRapport(TypeRapport::Opk, $opk->volontaire, null, $this);

    $suivi = RapportSuiviAgent::query()->create([
        'rapport_id' => $rapport->id,
        'volontaire_id' => $aopk->volontaire->id,
        'categorie_agent' => 'aopk',
        'production' => 'peu_satisfaisant',
        'anomalies' => ['retard'],
        'observation' => 'Arrivé après 9h à deux reprises.',
    ]);

    // Pas de notation invisible : l'agent noté voit son appréciation.
    expect($aopk->can('view', $suivi))->toBeTrue();
    expect(RapportSuiviAgent::query()->perimetre($aopk)->count())->toBe(1);

    // Il peut répondre, le supérieur non.
    expect($aopk->can('repondre', $suivi))->toBeTrue();
    expect($opk->can('repondre', $suivi))->toBeFalse();

    // Il ne réécrit pas l'appréciation : il y répond.
    expect($aopk->can('update', $suivi))->toBeFalse();
});

it('la réponse d\'un agent n\'est modifiable par personne', function () {
    $aopk = creerCompteRapport('+22670000025', 'volontaire_assistant', CategorieVolontaire::Assistant);
    $opk = creerCompteRapport('+22670000026', 'volontaire_operateur', CategorieVolontaire::Operateur);

    $rapport = creerRapport(TypeRapport::Opk, $opk->volontaire, null, $this);
    $suivi = RapportSuiviAgent::query()->create([
        'rapport_id' => $rapport->id,
        'volontaire_id' => $aopk->volontaire->id,
        'categorie_agent' => 'aopk',
        'anomalies' => ['retard'],
    ]);

    $reponse = AppreciationReponse::query()->create([
        'suivi_agent_id' => $suivi->id,
        'volontaire_id' => $aopk->volontaire->id,
        'reponse' => "J'étais au dispensaire, le justificatif a été remis au superviseur.",
        'repondu_le' => now(),
    ]);

    // Ni le supérieur, ni l'agent lui-même : une réponse est définitive.
    expect($opk->can('update', $reponse))->toBeFalse();
    expect($aopk->can('update', $reponse))->toBeFalse();

    expect(fn () => $reponse->update(['reponse' => 'Texte réécrit']))
        ->toThrow(DomainException::class);

    expect($reponse->fresh()->reponse)->toContain('dispensaire');

    // Seul l'accusé de lecture par le supérieur peut évoluer. On repart d'une
    // instance fraîche : celle ci-dessus porte encore la modification refusée,
    // et le verrou la refuserait de nouveau — à raison.
    $reponseFraiche = $reponse->fresh();
    $reponseFraiche->update(['lu_par_superieur_le' => now()]);

    expect($reponseFraiche->fresh()->lu_par_superieur_le)->not->toBeNull();
});

it('l\'écart et le taux sont calculés par la base, jamais saisis', function () {
    $opk = creerCompteRapport('+22670000027', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $rapport = creerRapport(TypeRapport::Opk, $opk->volontaire, null, $this);

    $production = $rapport->productionOpk()->create([
        'objectif_enregistrements' => 120,
        'enregistrements_realises' => 96,
        'recepisses_transmis' => 90,
        'enregistrements_non_valides' => 6,
        'motif_non_valides' => 'Pièces illisibles.',
    ]);

    $production->refresh();

    expect($production->ecart_enregistrements)->toBe(-24);
    expect((float) $production->taux_realisation)->toBe(80.0);

    // Sans objectif fixé, le taux n'a pas de sens : il reste nul.
    $autre = creerCompteRapport('+22670000028', 'volontaire_operateur', CategorieVolontaire::Operateur);
    $rapport2 = creerRapport(TypeRapport::Opk, $autre->volontaire, null, $this);
    $sansObjectif = $rapport2->productionOpk()->create(['enregistrements_realises' => 50]);

    expect($sansObjectif->refresh()->taux_realisation)->toBeNull();
});

it('une fiche sans profil peut être qualifiée une fois, puis jamais rechangée', function () {
    // Le fichier des retenus a une colonne Profil vide : la fiche arrive « à qualifier ».
    $user = User::query()->create([
        'telephone' => '+22670000029', 'nom' => 'Sawadogo', 'prenoms' => 'Fatimata',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Inactif->value,
    ]);

    $volontaire = Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-AQU000001',
        'categorie' => null,
        'statut' => 'operationnel',
    ]);

    $volontaire->update(['niveau_etude' => 'troisieme_bepc']);

    expect($volontaire->estAQualifier())->toBeTrue();
    expect(Volontaire::query()->aQualifier()->count())->toBe(1);

    // Un A-OPK est rattaché en permanence à sa localité : la qualification
    // l'exige, puisque la fiche importée sans profil n'en portait pas non plus.
    $volontaire->qualifier(CategorieVolontaire::Assistant, $this->admin, $this->localite->id);
    $volontaire->refresh();

    expect($volontaire->categorie)->toBe(CategorieVolontaire::Assistant);
    expect($volontaire->qualifie_par)->toBe($this->admin->id);
    expect($volontaire->user->hasRole('volontaire_assistant'))->toBeTrue();

    // Les catégories restent étanches : aucune requalification.
    expect(fn () => $volontaire->qualifier(CategorieVolontaire::Operateur, $this->admin))
        ->toThrow(DomainException::class);
    expect(fn () => $volontaire->update(['categorie' => CategorieVolontaire::Operateur->value]))
        ->toThrow(DomainException::class);
});

it('le numéro CNIB est masqué sauf pour l\'administration nationale', function () {
    $superviseur = creerCompteRapport('+22670000030', 'volontaire_superviseur', CategorieVolontaire::Superviseur);
    $volontaire = $superviseur->volontaire;
    $volontaire->update(['numero_cnib' => 'B1234567']);

    expect($volontaire->numeroCnibMasque())->toBe('B1******');

    // Un superviseur ne voit que la forme masquée.
    expect($volontaire->numeroCnibPour($superviseur))->toBe('B1******');

    // L'administrateur national voit le numéro en clair.
    expect($volontaire->numeroCnibPour($this->admin))->toBe('B1234567');

    // Le numéro ne sort jamais dans une sérialisation par défaut.
    expect($volontaire->toArray())->not->toHaveKey('numero_cnib');
});
