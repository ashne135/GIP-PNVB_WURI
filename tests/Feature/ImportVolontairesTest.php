<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Enums\StatutVolontaire;
use App\Models\Commune;
use App\Models\Import;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use App\Models\Volontaire;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Import des RETENUS et de la LISTE D'ATTENTE, sur le canevas RÉEL du client
 * (cadrage v2, section 6).
 *
 * Ces tests protègent trois choses :
 *   - « Rien n'est écrit tant que l'aperçu n'est pas confirmé » ;
 *   - les normalisations imposées, parce que « le fichier réel est irrégulier » ;
 *   - le dédoublonnage sur trois clés, dans l'ordre : CNIB, téléphone, courriel.
 */
beforeEach(function () {
    Storage::fake('local');

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

    $this->admin = User::query()->create([
        'telephone' => '+22670000002', 'nom' => 'NATIONAL', 'prenoms' => 'Administrateur',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $this->admin->assignRole('administrateur_national');

    Sanctum::actingAs($this->admin);
});

/**
 * Fabrique un CSV au canevas réel du client.
 * Colonnes : N° · Email Address · numéro · Nom · Prénom(s) · Date de naissance ·
 * Lieu de naissance · Sexe · N° CNIB · Date d'établissement · Profil ·
 * Region · Province · commune · arrondissement · secteur · quartier · village · site
 */
function fichierRetenus(array $lignes, ?array $entetes = null): UploadedFile
{
    $entetes ??= App\Services\Import\CanevasVolontaires::entetesDuModele();

    $contenu = implode(';', $entetes)."\n";

    foreach ($lignes as $ligne) {
        $contenu .= implode(';', $ligne)."\n";
    }

    $chemin = tempnam(sys_get_temp_dir(), 'retenus').'.csv';
    file_put_contents($chemin, $contenu);

    return new UploadedFile($chemin, 'retenus.csv', 'text/csv', null, true);
}

/** Une ligne complète du canevas, avec surcharges ponctuelles. */
function ligneRetenu(array $surcharges = []): array
{
    $defaut = [
        'n' => '1',
        'email' => 'aminata.ouedraogo@exemple.bf',
        'telephone' => '70123456',
        'nom' => 'OUEDRAOGO',
        'prenoms' => 'Aminata',
        'date_naissance' => '15/04/1992',
        'lieu_naissance' => 'Ouagadougou',
        'sexe' => 'Féminin',
        'cnib' => 'B1234567',
        'date_cnib' => '12/03/2018',
        'profil' => 'Superviseur de centre',
        // Le niveau commande le profil : un superviseur exige la licence.
        'niveau' => 'Licence',
        'diplome' => 'Licence en sociologie',
        'region' => '', 'province' => '', 'commune' => '', 'arrondissement' => '',
        'secteur' => '', 'quartier' => '', 'village' => '', 'site' => '',
    ];

    return array_values(array_merge($defaut, $surcharges));
}

function televerser(array $lignes, string $type = 'volontaires_retenus', string $mode = 'completer')
{
    return test()->postJson('/api/v1/imports/volontaires', [
        'fichier' => fichierRetenus($lignes),
        'type' => $type,
        'mode' => $mode,
    ]);
}

it('analyse le fichier SANS rien écrire dans le registre', function () {
    $reponse = televerser([
        ligneRetenu(),
        ligneRetenu(['n' => '2', 'email' => 'issa@exemple.bf', 'telephone' => '76234567',
            'nom' => 'KABORE', 'prenoms' => 'Issa', 'cnib' => 'B2345678',
            'profil' => 'Opérateur de kit']),
    ])->assertStatus(201);

    expect($reponse->json('data.import.statut'))->toBe('apercu_pret');
    expect($reponse->json('data.import.lignes_valides'))->toBe(2);
    expect($reponse->json('data.import.lignes_erreur'))->toBe(0);

    // LE POINT CENTRAL : aucun compte n'existe encore.
    expect(Volontaire::count())->toBe(0);
    expect(User::whereHas('volontaire')->count())->toBe(0);
});

it('lit le numéro de téléphone en texte, même écrit comme un nombre', function () {
    // Excel stocke « 70123456 » en nombre ; un CSV réexporté peut même porter
    // une notation scientifique. Les deux doivent donner le même identifiant.
    $reponse = televerser([
        ligneRetenu(['telephone' => '70123456']),
        ligneRetenu(['n' => '2', 'telephone' => '7.6234567E+7', 'nom' => 'KABORE',
            'prenoms' => 'Issa', 'cnib' => 'B2345678', 'email' => 'issa@exemple.bf']),
    ])->assertStatus(201);

    expect($reponse->json('data.import.lignes_valides'))->toBe(2);

    $import = Import::query()->latest()->first();
    $telephones = $import->lignes->pluck('donnees')->pluck('telephone');

    expect($telephones)->toContain('+22670123456');
    expect($telephones)->toContain('+22676234567');
});

it('convertit le sexe écrit en toutes lettres', function () {
    televerser([
        ligneRetenu(['sexe' => 'Féminin']),
        ligneRetenu(['n' => '2', 'sexe' => 'Masculin', 'telephone' => '76234567',
            'nom' => 'KABORE', 'prenoms' => 'Issa', 'cnib' => 'B2345678', 'email' => 'issa@exemple.bf']),
    ])->assertStatus(201);

    $donnees = Import::query()->latest()->first()->lignes->pluck('donnees');

    expect($donnees[0]['sexe'])->toBe('F');
    expect($donnees[1]['sexe'])->toBe('M');
});

it('normalise la casse incohérente des noms et prénoms', function () {
    televerser([
        ligneRetenu(['nom' => 'ouedraogo', 'prenoms' => 'aminata']),
        ligneRetenu(['n' => '2', 'nom' => '  kabore  ', 'prenoms' => 'jean-baptiste',
            'telephone' => '76234567', 'cnib' => 'B2345678', 'email' => 'issa@exemple.bf']),
    ])->assertStatus(201);

    $donnees = Import::query()->latest()->first()->lignes->pluck('donnees');

    expect($donnees[0]['nom'])->toBe('OUEDRAOGO');
    expect($donnees[0]['prenoms'])->toBe('Aminata');
    expect($donnees[1]['nom'])->toBe('KABORE');
    expect($donnees[1]['prenoms'])->toBe('Jean-Baptiste');
});

it('refuse une date d\'établissement de pièce d\'identité dans le futur', function () {
    $reponse = televerser([
        ligneRetenu(['date_cnib' => now()->addYear()->format('d/m/Y')]),
    ])->assertStatus(201);

    expect($reponse->json('data.import.lignes_erreur'))->toBe(1);

    $motif = Import::query()->latest()->first()->lignes()->where('valide', false)->value('motif_erreur');
    expect($motif)->toContain("est dans le futur");
});

it('dédoublonne sur le CNIB en premier, puis le téléphone, puis le courriel', function () {
    televerser([
        ligneRetenu(),
        // Même CNIB, tout le reste différent : rejet sur la première clé.
        ligneRetenu(['n' => '2', 'telephone' => '76234567', 'email' => 'autre@exemple.bf',
            'nom' => 'KABORE', 'prenoms' => 'Issa', 'cnib' => 'B1234567']),
        // CNIB différent, même téléphone.
        ligneRetenu(['n' => '3', 'telephone' => '70123456', 'email' => 'troisieme@exemple.bf',
            'nom' => 'ZONGO', 'prenoms' => 'Moussa', 'cnib' => 'B3333333']),
        // CNIB et téléphone différents, même courriel.
        ligneRetenu(['n' => '4', 'telephone' => '65999999', 'email' => 'aminata.ouedraogo@exemple.bf',
            'nom' => 'SANA', 'prenoms' => 'Bintou', 'cnib' => 'B4444444']),
    ])->assertStatus(201);

    $import = Import::query()->latest()->first();
    expect($import->lignes_valides)->toBe(1);
    expect($import->lignes_erreur)->toBe(3);

    $motifs = $import->lignes()->where('valide', false)->pluck('motif_erreur')->implode(' | ');

    expect($motifs)->toContain("pièce d'identité apparaît déjà à la ligne 2");
    expect($motifs)->toContain('téléphone apparaît déjà à la ligne 2');
    expect($motifs)->toContain('courriel apparaît déjà à la ligne 2');
});

it('accepte une ligne sans profil et la place « à qualifier »', function () {
    $reponse = televerser([
        ligneRetenu(['profil' => '']),
    ])->assertStatus(201);

    expect($reponse->json('data.import.lignes_valides'))->toBe(1);
    expect($reponse->json('data.import.resume.a_qualifier'))->toBe(1);

    $import = Import::query()->latest()->first();
    $this->postJson("/api/v1/imports/volontaires/{$import->id}/confirmer")->assertOk();

    $volontaire = Volontaire::first();
    expect($volontaire->categorie)->toBeNull();
    expect($volontaire->estAQualifier())->toBeTrue();
    expect($volontaire->matricule)->toStartWith('PNVB-AQU');
});

it('retient la localité d\'une ligne sans profil, pour une qualification en A-OPK', function () {
    televerser([
        // Sans profil, avec son village : la localité est gardée.
        ligneRetenu(['profil' => '', 'commune' => 'Bagassi', 'village' => 'Assio']),
        // Sans profil, village inconnu : ce n'est PAS une erreur — le profil
        // n'est pas encore connu, rien n'oblige à avoir une localité.
        ligneRetenu(['n' => '2', 'profil' => '', 'telephone' => '76234567',
            'cnib' => 'B2345678', 'email' => 'x@exemple.bf', 'nom' => 'ZONGO',
            'village' => 'Village inexistant']),
    ])->assertStatus(201);

    $import = Import::query()->latest()->first();
    expect($import->lignes_valides)->toBe(2);

    $this->postJson("/api/v1/imports/volontaires/{$import->id}/confirmer")->assertOk();

    $avecVillage = Volontaire::query()->whereHas('user', fn ($q) => $q->where('nom', 'OUEDRAOGO'))->first();
    $sansVillage = Volontaire::query()->whereHas('user', fn ($q) => $q->where('nom', 'ZONGO'))->first();

    expect($avecVillage->localite_id)->toBe($this->localite->id);
    expect($sansVillage->localite_id)->toBeNull();

    // La qualification en A-OPK passe sans ressaisir la localité.
    $this->postJson('/api/v1/volontaires/a-qualifier', [
        'qualifications' => [
            ['volontaire_id' => $avecVillage->id, 'categorie' => 'assistant'],
            ['volontaire_id' => $sansVillage->id, 'categorie' => 'assistant'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.qualifiees.0.volontaire_id', $avecVillage->id)
        ->assertJsonPath('data.refusees.0.volontaire_id', $sansVillage->id);

    expect($avecVillage->fresh()->categorie)->toBe(CategorieVolontaire::Assistant);
});

it('exige le territoire pour un A-OPK, pas pour les autres profils', function () {
    televerser([
        // A-OPK sans village : refusé, il est rattaché en permanence à sa localité.
        ligneRetenu(['profil' => 'A-OPK']),
        // A-OPK avec son village : accepté.
        ligneRetenu(['n' => '2', 'profil' => 'A-OPK', 'telephone' => '76234567',
            'cnib' => 'B2345678', 'email' => 'aopk@exemple.bf', 'nom' => 'SAWADOGO',
            'prenoms' => 'Fatimata', 'commune' => 'Bagassi', 'village' => 'Assio']),
        // Superviseur sans territoire : accepté, il le reçoit à l'affectation.
        ligneRetenu(['n' => '3', 'telephone' => '65345678', 'cnib' => 'B3456789',
            'email' => 'sup@exemple.bf', 'nom' => 'TRAORE', 'prenoms' => 'Awa']),
    ])->assertStatus(201);

    $import = Import::query()->latest()->first();
    expect($import->lignes_valides)->toBe(2);
    expect($import->lignes_erreur)->toBe(1);

    $motif = $import->lignes()->where('valide', false)->value('motif_erreur');
    expect($motif)->toContain('localité est vide');
    expect($motif)->toContain('A-OPK');
});

it('crée les comptes en statut INACTIF avec leur identité, une fois confirmé', function () {
    televerser([
        ligneRetenu(['profil' => 'A-OPK', 'commune' => 'Bagassi', 'village' => 'Assio']),
    ])->assertStatus(201);

    $import = Import::query()->latest()->first();
    $reponse = $this->postJson("/api/v1/imports/volontaires/{$import->id}/confirmer")->assertOk();

    expect($reponse->json('message'))->toContain('1 comptes créés, en statut inactif');

    $volontaire = Volontaire::first();
    expect($volontaire->categorie)->toBe(CategorieVolontaire::Assistant);
    expect($volontaire->localite_id)->toBe($this->localite->id);
    expect($volontaire->numero_cnib)->toBe('B1234567');
    expect($volontaire->lieu_naissance)->toBe('Ouagadougou');
    expect($volontaire->date_etablissement_cnib->format('Y-m-d'))->toBe('2018-03-12');

    // C'est l'AFFECTATION qui ouvrira l'accès, jamais l'import.
    expect($volontaire->user->statut_compte)->toBe(StatutCompte::Inactif);
    expect($volontaire->user->hasRole('volontaire_assistant'))->toBeTrue();
});

it('place la liste d\'attente en réserve', function () {
    televerser([ligneRetenu(['profil' => 'Opérateur de kit'])], 'volontaires_reserve')
        ->assertStatus(201);

    $import = Import::query()->latest()->first();
    $this->postJson("/api/v1/imports/volontaires/{$import->id}/confirmer")->assertOk();

    expect(Volontaire::first()->statut)->toBe(StatutVolontaire::Reserve);
});

it('signale une ligne sans courriel et annonce le canal de secours', function () {
    $reponse = televerser([ligneRetenu(['email' => ''])])->assertStatus(201);

    expect($reponse->json('data.import.resume.sans_courriel'))->toBe(1);
    expect($reponse->json('message'))->toContain('SMS ou par bordereau en formation');
});

it('reconnaît des intitulés de colonnes différents du modèle', function () {
    // Un export Google Forms n'aura pas exactement nos intitulés.
    $reponse = televerser(
        [ligneRetenu()],
    )->assertStatus(201);

    expect($reponse->json('data.import.lignes_valides'))->toBe(1);

    $autre = $this->postJson('/api/v1/imports/volontaires', [
        'fichier' => fichierRetenus(
            [['1', 'x@exemple.bf', '76234567', 'KABORE', 'Issa', '', '', 'Masculin', 'B9999999', '',
                'OPK', 'BAC+2', 'BTS informatique', '', '', '', '', '', '', '', '']],
            ['No', 'Adresse email', 'Téléphone', 'NOM', 'Prenom(s)', 'Né le', 'Lieu naissance',
                'Genre', 'CNIB', 'Date de délivrance', 'Fonction', 'Niveau', 'Dernier diplôme',
                'Region', 'Province', 'commune', 'arrondissement', 'secteur', 'quartier', 'village', 'site']
        ),
        'type' => 'volontaires_retenus',
        'mode' => 'completer',
    ])->assertStatus(201);

    expect($autre->json('data.import.lignes_valides'))->toBe(1);
});

it('enregistre le niveau d\'étude et l\'intitulé du diplôme', function () {
    televerser([ligneRetenu(['niveau' => 'bac + 2', 'diplome' => 'BTS en réseaux',
        'profil' => 'Opérateur de kit'])])->assertStatus(201);

    $import = Import::query()->latest()->first();
    $this->postJson("/api/v1/imports/volontaires/{$import->id}/confirmer")->assertOk();

    $volontaire = Volontaire::query()->firstOrFail();
    expect($volontaire->niveau_etude)->toBe(App\Enums\NiveauEtude::BacPlus2);
    expect($volontaire->diplome)->toBe('BTS en réseaux');
    expect($volontaire->categorie)->toBe(CategorieVolontaire::Operateur);
});

it('écarte le profil que le niveau ne permet pas, SANS perdre la ligne', function () {
    // Un superviseur exige la licence : avec le BAC, le profil n'est pas
    // appliqué, mais le retenu entre bien au registre, « à qualifier ».
    $reponse = televerser([
        ligneRetenu(['profil' => 'Superviseur de centre', 'niveau' => 'BAC']),
        // Un opérateur exige le BAC : sans niveau du tout, même traitement.
        ligneRetenu(['n' => '2', 'profil' => 'Opérateur de kit', 'niveau' => '', 'diplome' => '',
            'telephone' => '76234567', 'cnib' => 'B2345678', 'email' => 'opk@exemple.bf', 'nom' => 'KABORE']),
        // Un A-OPK n'exige que la 4ème : la 3ème suffit, le profil tient.
        ligneRetenu(['n' => '3', 'profil' => 'A-OPK', 'niveau' => '3ème',
            'telephone' => '65345678', 'cnib' => 'B3456789', 'email' => 'aopk@exemple.bf',
            'nom' => 'SAWADOGO', 'commune' => 'Bagassi', 'village' => 'Assio']),
    ])->assertStatus(201);

    expect($reponse->json('data.import.lignes_valides'))->toBe(3);
    expect($reponse->json('data.import.lignes_erreur'))->toBe(0);
    expect($reponse->json('data.import.resume.profils_ecartes'))->toBe(2);
    expect($reponse->json('data.import.resume.a_qualifier'))->toBe(2);
    expect($reponse->json('message'))->toContain('faute du niveau d\'étude exigé');

    $import = Import::query()->latest()->first();
    $this->postJson("/api/v1/imports/volontaires/{$import->id}/confirmer")->assertOk();

    $superviseurEcarte = Volontaire::query()->whereHas('user', fn ($q) => $q->where('nom', 'OUEDRAOGO'))->sole();
    $assistant = Volontaire::query()->whereHas('user', fn ($q) => $q->where('nom', 'SAWADOGO'))->sole();

    expect($superviseurEcarte->categorie)->toBeNull();
    expect($superviseurEcarte->niveau_etude)->toBe(App\Enums\NiveauEtude::Bac);
    expect($assistant->categorie)->toBe(CategorieVolontaire::Assistant);

    // Le compte rendu dit pourquoi le profil n'a pas été appliqué.
    $contenu = $this->get("/api/v1/imports/volontaires/{$import->id}/compte-rendu")->assertOk()->streamedContent();
    expect($contenu)->toContain('non appliqué : niveau « BAC »');
    expect($contenu)->toContain('Licence (BAC+3)');
});

it('refuse un niveau d\'étude qui n\'est pas dans l\'échelle', function () {
    televerser([ligneRetenu(['niveau' => 'Licence ou équivalent peut-être'])])->assertStatus(201);

    $import = Import::query()->latest()->first();
    expect($import->lignes_erreur)->toBe(1);
    expect($import->lignes()->where('valide', false)->value('motif_erreur'))
        ->toContain("niveau d'étude")->toContain("n'est pas reconnu");
});

it('refuse un fichier dont les colonnes obligatoires sont absentes', function () {
    $reponse = $this->postJson('/api/v1/imports/volontaires', [
        'fichier' => fichierRetenus([['OUEDRAOGO', 'Aminata']], ['Nom', 'Prénom(s)']),
        'type' => 'volontaires_retenus',
        'mode' => 'completer',
    ])->assertStatus(422);

    expect($reponse->json('message'))->toContain('Colonnes obligatoires introuvables');
    expect($reponse->json('message'))->toContain('telephone');
});

it('interdit l\'import à qui n\'a pas le droit volontaires.importer', function () {
    $chef = User::query()->create([
        'telephone' => '+22670000030', 'nom' => 'ANTENNE', 'prenoms' => 'Chef',
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $chef->assignRole('chef_antenne_regional');

    Sanctum::actingAs($chef);

    televerser([ligneRetenu()])->assertStatus(403);

    expect(Import::count())->toBe(0);
});

it('nomme la localité d\'un A-OPK dans le compte rendu', function () {
    // Le canevas n'a pas de colonne « localité » : village, secteur ou
    // quartier. La colonne du compte rendu restait donc toujours vide.
    televerser([
        ligneRetenu(['profil' => 'A-OPK', 'commune' => 'Bagassi', 'village' => 'Assio']),
    ])->assertStatus(201);

    $import = Import::query()->latest()->first();

    $contenu = $this->get("/api/v1/imports/volontaires/{$import->id}/compte-rendu")
        ->assertOk()
        ->streamedContent();

    expect($contenu)->toContain('Assio (Bagassi)');
});

it('produit un compte rendu téléchargeable, motifs compris', function () {
    televerser([
        ligneRetenu(),
        ligneRetenu(['n' => '2', 'nom' => '', 'telephone' => 'pas-un-numero',
            'cnib' => 'B2345678', 'email' => 'issa@exemple.bf']),
    ])->assertStatus(201);

    $import = Import::query()->latest()->first();

    $contenu = $this->get("/api/v1/imports/volontaires/{$import->id}/compte-rendu")
        ->assertOk()
        ->streamedContent();

    expect($contenu)->toContain('Ligne;Statut;Motif');
    expect($contenu)->toContain('En erreur');
    expect($contenu)->toContain('nom est vide');
});
