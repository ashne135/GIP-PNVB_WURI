<?php

use App\Enums\CategorieVolontaire;
use App\Enums\StatutCompte;
use App\Models\Centre;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Affectation\ServiceVagues;
use App\Services\Affectation\TirageAffectations;
use App\Services\Comptes\ServiceCycleDeVieCompte;
use App\Services\Comptes\ServiceRemiseIdentifiants;
use App\Services\Presence\ServiceFeuillePresence;
use App\Services\Referentiel\ServiceCentresEtSites;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\RolesEtPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * QUI A FAIT QUOI — l'auteur des écritures du journal.
 *
 * Le journal d'activité répond à une seule question : qui a fait quoi, et
 * quand. Plusieurs écritures l'enregistraient sans jamais nommer l'auteur —
 * dont la VALIDATION D'UNE FEUILLE DE PRÉSENCE, alors que seule la feuille
 * validée fait foi et que c'est ce superviseur qui en répond.
 *
 * LE CAS INVERSE COMPTE AUTANT : quand c'est le job planifié qui recalcule un
 * accès, l'auteur reste NUL. Personne n'a rien décidé, et inscrire le dernier
 * connecté serait une information fausse. Un test le fige, pour qu'une
 * « amélioration » ne vienne pas le remplir plus tard.
 */
beforeEach(function () {
    $this->seed(RolesEtPermissionsSeeder::class);
    $this->seed(ParametresSeeder::class);

    $this->region = Region::query()->create([
        'code' => 'BAN', 'nom' => 'Bankui', 'nombre_sites_alloues' => 100,
    ]);
    $province = Province::query()->create([
        'region_id' => $this->region->id, 'code' => 'BALE', 'nom' => 'Bale',
    ]);
    $this->commune = Commune::query()->create([
        'province_id' => $province->id, 'region_id' => $this->region->id,
        'code' => 'BAGA', 'nom' => 'Bagassi', 'type' => 'rurale',
    ]);

    $this->admin = compteJournal('+22670009001', 'administrateur_national');
});

function compteJournal(string $telephone, string $role): User
{
    $user = User::query()->create([
        'telephone' => $telephone, 'nom' => 'JRN', 'prenoms' => ucfirst(str_replace('_', ' ', $role)),
        'password' => 'MotDePasse#1', 'statut_compte' => StatutCompte::Actif->value,
        'doit_changer_mot_de_passe' => false,
    ]);
    $user->assignRole($role);

    return $user->fresh();
}

function volontaireJournal(string $telephone, CategorieVolontaire $categorie): Volontaire
{
    $user = compteJournal($telephone, $categorie->role()->value);

    return Volontaire::query()->create([
        'user_id' => $user->id,
        'matricule' => 'PNVB-'.$categorie->prefixeMatricule().substr($telephone, -6),
        'categorie' => $categorie->value, 'statut' => 'operationnel',
    ]);
}

function localiteJournal(Commune $commune, string $nom): Localite
{
    return Localite::query()->create([
        'commune_id' => $commune->id, 'region_id' => $commune->region_id,
        'nom' => $nom, 'type_localite' => 'village', 'population_totale' => 1500,
    ]);
}

/** Un centre avec son site, tel que le tirage l'attend. */
function centreJournal(Commune $commune, int $numero): Centre
{
    $localite = localiteJournal($commune, "{$commune->nom} localite {$numero}");

    $centre = Centre::query()->create([
        'commune_id' => $commune->id, 'region_id' => $commune->region_id,
        'code' => 'BAN-'.$commune->code.'-C'.str_pad((string) $numero, 3, '0', STR_PAD_LEFT),
        'nom' => "Centre {$numero}", 'nombre_kits' => 1, 'statut' => 'planifie',
    ]);

    Site::query()->create([
        'centre_id' => $centre->id, 'localite_id' => $localite->id, 'region_id' => $commune->region_id,
        'code' => $centre->code.'-S01', 'nom' => "Site {$numero}", 'ordre_tournee' => 1,
    ]);

    return $centre;
}

function vivierJournal(int $superviseurs, int $operateurs): void
{
    foreach (range(1, $superviseurs) as $i) {
        volontaireJournal('+2267'.str_pad((string) (4000000 + $i), 7, '0', STR_PAD_LEFT),
            CategorieVolontaire::Superviseur);
    }

    foreach (range(1, $operateurs) as $i) {
        volontaireJournal('+2267'.str_pad((string) (5000000 + $i), 7, '0', STR_PAD_LEFT),
            CategorieVolontaire::Operateur);
    }
}

/** La dernière écriture d'un journal donné. */
function derniereActivite(string $log, ?string $descriptionCommencantPar = null): ?Activity
{
    return Activity::query()
        ->where('log_name', $log)
        ->when($descriptionCommencantPar,
            fn ($q) => $q->where('description', 'like', $descriptionCommencantPar.'%'))
        ->latest('id')
        ->first();
}

it('nomme l’auteur de la création d’un centre et d’un site', function () {
    $service = app(ServiceCentresEtSites::class);

    $centre = $service->creerCentre($this->commune, ['nom' => 'Centre du test'], $this->admin);

    $activite = derniereActivite('referentiel', 'Centre créé');
    expect($activite?->causer_id)->toBe($this->admin->id);

    $service->creerSite($centre, localiteJournal($this->commune, 'Assio'), ['nom' => 'Site du test'], $this->admin);

    expect(derniereActivite('referentiel', 'Site créé')?->causer_id)->toBe($this->admin->id);
});

it('nomme l’auteur de la fermeture d’un centre', function () {
    $service = app(ServiceCentresEtSites::class);
    $centre = $service->creerCentre($this->commune, ['nom' => 'Centre à fermer'], $this->admin);

    $service->fermerCentre($centre, 'fin de campagne', $this->admin);

    $activite = derniereActivite('referentiel', 'Centre fermé');

    expect($activite?->causer_id)->toBe($this->admin->id)
        ->and($activite?->properties['motif'])->toBe('fin de campagne');
});

it('NOMME LE SUPERVISEUR qui valide une feuille de présence', function () {
    $centre = centreJournal($this->commune, 1);
    $site = Site::query()->where('centre_id', $centre->id)->first();

    $vague = VagueDeploiement::query()->create([
        'code' => 'BAN-2026-V1', 'libelle' => 'Vague', 'region_id' => $this->region->id,
        'date_debut_prevue' => now()->subDays(5)->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
        'statut' => 'active', 'cree_par' => $this->admin->id,
    ]);

    TourneeSite::query()->create([
        'vague_id' => $vague->id, 'centre_id' => $centre->id, 'site_id' => $site->id,
        'ordre' => 1, 'date_debut' => now()->subDays(5)->toDateString(),
        'date_fin' => now()->addDays(5)->toDateString(), 'statut' => 'en_cours',
    ]);

    $superviseur = volontaireJournal('+22670009020', CategorieVolontaire::Superviseur);
    $service = app(ServiceFeuillePresence::class);

    $feuille = $service->preparer($site, now()->toDateString(), $superviseur);
    $service->valider($feuille, [], [], $superviseur);

    $activite = derniereActivite('feuille_presence', 'Feuille de présence validée');

    // C'est ce superviseur qui répond de cette feuille : le journal le nomme.
    expect($activite?->causer_id)->toBe($superviseur->user_id);
});

it('nomme l’auteur d’une remise d’identifiants', function () {
    $agent = compteJournal('+22670009030', 'volontaire_operateur');

    // La remise en main propre journalise sans envoyer ni courriel ni SMS :
    // c'est l'auteur qu'on éprouve ici, pas la passerelle.
    app(ServiceRemiseIdentifiants::class)->marquerEnAttenteMainPropre($agent, $this->admin);

    expect(derniereActivite('compte')?->causer_id)->toBe($this->admin->id);
});

it('n’invente AUCUN auteur quand c’est le job planifié qui recalcule un accès', function () {
    $operateur = volontaireJournal('+22670009040', CategorieVolontaire::Operateur);
    $operateur->user->update(['statut_compte' => StatutCompte::Actif->value]);

    // Aucun auteur passé : c'est le filet de sécurité qui tourne, la nuit.
    app(ServiceCycleDeVieCompte::class)->recalculer($operateur->fresh());

    $activite = derniereActivite('compte');

    expect($activite?->causer_id)->toBeNull()
        ->and($activite?->properties['evenement'])->toBe('recalcul_planifie');
});

it('nomme celui qui a lancé le tirage', function () {
    $centres = collect(range(1, 2))->map(fn ($i) => centreJournal($this->commune, $i));
    vivierJournal(superviseurs: 2, operateurs: 4);

    $vague = app(ServiceVagues::class)->planifier([
        'libelle' => 'Vague de test',
        'region_id' => $this->region->id,
        'date_debut_prevue' => now()->toDateString(),
        'date_fin_prevue' => now()->addMonth()->toDateString(),
    ], $centres->pluck('id')->all(), $this->admin);

    app(TirageAffectations::class)->tirer($vague, 777, $this->admin);

    $activite = derniereActivite('vague', 'Tirage');

    // Un tirage est reproductible ET imputable : la graine dit ce qui a été
    // tiré, l'auteur dit qui l'a lancé.
    expect($activite?->causer_id)->toBe($this->admin->id)
        ->and($activite?->properties['graine'])->toBe(777);
});
