<?php

use App\Http\Controllers\Api\AppreciationsController;
use App\Http\Controllers\Api\AlertesController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CentresController;
use App\Http\Controllers\Api\CharteController;
use App\Http\Controllers\Api\ComptesAdministrationController;
use App\Http\Controllers\Api\EquipesController;
use App\Http\Controllers\Api\ExportsPlanifiesController;
use App\Http\Controllers\Api\FeuillesPresenceController;
use App\Http\Controllers\Api\ImportCentresSitesController;
use App\Http\Controllers\Api\ImportVolontairesController;
use App\Http\Controllers\Api\IncidentsController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\KitsController;
use App\Http\Controllers\Api\NomenclaturesIncidentController;
use App\Http\Controllers\Api\ParametresController;
use App\Http\Controllers\Api\PiecesJointesController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\QualificationVolontairesController;
use App\Http\Controllers\Api\RapportsController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\RemiseIdentifiantsController;
use App\Http\Controllers\Api\RemplacementsController;
use App\Http\Controllers\Api\SitesController;
use App\Http\Controllers\Api\SuppressionsController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TableauBordController;
use App\Http\Controllers\Api\TerritoireController;
use App\Http\Controllers\Api\TourneesController;
use App\Http\Controllers\Api\VaguesController;
use App\Http\Controllers\Api\VolontairesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API PNVB — volontaires WURI
|--------------------------------------------------------------------------
| Authentification Sanctum, jetons Bearer (cadrage, section 14).
| L'identifiant de connexion est le NUMÉRO DE TÉLÉPHONE (section 6).
|
| Toutes les routes authentifiées passent par quatre barrières serveur, dans
| cet ordre :
|   1. observateur.lecture_seule — le serveur refuse toute écriture de ce rôle
|   2. jeton.rattrapage          — le jeton d'un accès fermé n'envoie plus que sa file
|   3. mot_de_passe.change       — mot de passe initial à usage unique
|   4. charte.acceptee           — consentement tracé avant toute collecte
*/

Route::prefix('v1')->name('api.')->group(function () {

    // ---------------- Public ----------------
    Route::post('connexion', [AuthController::class, 'connexion'])
        ->middleware('throttle:20,1')
        ->name('connexion');

    // ---------------- Authentifié ----------------
    Route::middleware(['auth:sanctum', 'observateur.lecture_seule', 'jeton.rattrapage'])->group(function () {

        // Accessibles même quand une action est requise (changement de mot de
        // passe, acceptation de la charte) : ce sont elles qui la lèvent.
        Route::get('moi', [AuthController::class, 'moi'])->name('moi');
        Route::post('deconnexion', [AuthController::class, 'deconnexion'])->name('deconnexion');
        Route::post('mot-de-passe/changer', [AuthController::class, 'changerMotDePasse'])
            ->name('mot-de-passe.changer');
        // Le texte se lit AVANT d'être accepté : même client, web ou mobile.
        Route::get('charte', [CharteController::class, 'courante'])->name('charte');
        Route::post('charte/accepter', [AuthController::class, 'accepterCharte'])
            ->name('charte.accepter');

        // Le reste de l'API n'est atteignable qu'une fois ces deux obligations levées.
        Route::middleware(['mot_de_passe.change', 'charte.acceptee'])->group(function () {

            Route::prefix('referentiel')->name('referentiel.')->group(function () {
                Route::get('regions', [ReferentielController::class, 'regions'])->name('regions');
                Route::get('communes', [ReferentielController::class, 'communes'])->name('communes');
                Route::get('localites', [ReferentielController::class, 'localites'])->name('localites');

                /*
                 * Le référentiel territorial : consultation et correction.
                 * Correction nationale (referentiel.modifier_territoire),
                 * dérogation au cadrage décidée par le client ; aperçu des
                 * quotas avec simulation=1 avant tout enregistrement.
                 */
                Route::prefix('territoire')->name('territoire.')->group(function () {
                    Route::get('regions', [TerritoireController::class, 'regions'])->name('regions');
                    Route::get('provinces', [TerritoireController::class, 'provinces'])->name('provinces');
                    Route::get('communes', [TerritoireController::class, 'communes'])->name('communes');
                    Route::get('localites', [TerritoireController::class, 'localites'])->name('localites');
                    Route::get('arrondissements', [TerritoireController::class, 'arrondissements'])
                        ->name('arrondissements');
                    Route::put('regions/{region}', [TerritoireController::class, 'modifierRegion'])
                        ->name('regions.modifier');
                    Route::put('provinces/{province}', [TerritoireController::class, 'modifierProvince'])
                        ->name('provinces.modifier');
                    Route::put('communes/{commune}', [TerritoireController::class, 'modifierCommune'])
                        ->name('communes.modifier');
                    Route::put('localites/{localite}', [TerritoireController::class, 'modifierLocalite'])
                        ->name('localites.modifier');
                    Route::post('localites', [TerritoireController::class, 'ajouterLocalite'])
                        ->name('localites.ajouter');
                });

                /*
                 * Centres et sites : consultation et gestion. Le geste normal
                 * reste la FERMETURE — un centre porte l'historique des rapports,
                 * des présences et des affectations, il ne disparaît pas.
                 *
                 * La suppression définitive existe depuis le 18/09/2026, mais
                 * ailleurs (POST suppressions), derrière son propre droit, et
                 * refusée dès qu'une donnée qui fait foi en dépend. Elle sert au
                 * jeu d'essai, pas à la gestion courante.
                 */
                Route::get('centres', [CentresController::class, 'index'])->name('centres');
                Route::post('centres', [CentresController::class, 'store'])->name('centres.creer');
                Route::get('centres/{centre}', [CentresController::class, 'show'])->name('centres.voir');
                Route::put('centres/{centre}', [CentresController::class, 'update'])->name('centres.modifier');
                Route::post('centres/{centre}/fermer', [CentresController::class, 'fermer'])
                    ->name('centres.fermer');

                Route::get('sites', [SitesController::class, 'index'])->name('sites');
                Route::post('sites', [SitesController::class, 'store'])->name('sites.creer');
                Route::get('sites/{site}', [SitesController::class, 'show'])->name('sites.voir');
                Route::put('sites/{site}', [SitesController::class, 'update'])->name('sites.modifier');
            });

            Route::get('volontaires', [ReferentielController::class, 'volontaires'])->name('volontaires');

            /*
            |--------------------------------------------------------------
            | Qualification des fiches importées sans profil
            |--------------------------------------------------------------
            | La colonne « Profil » est vide dans le fichier des retenus : ces
            | fiches attendent un profil avant toute affectation.
            */
            Route::prefix('volontaires/a-qualifier')->name('volontaires.qualification.')->group(function () {
                Route::get('/', [QualificationVolontairesController::class, 'index'])->name('index');
                Route::post('/', [QualificationVolontairesController::class, 'qualifier'])->name('qualifier');
            });

            /*
            |--------------------------------------------------------------
            | Import des retenus et de la liste d'attente
            |--------------------------------------------------------------
            | Trois temps SÉPARÉS, conformément au cadrage (section 2) :
            | téléverser analyse sans rien écrire, apercu montre le résultat,
            | confirmer applique. Rien n'est écrit avant la confirmation.
            */
            /*
            |--------------------------------------------------------------
            | La fiche d'un volontaire : corriger, retirer, réintégrer
            |--------------------------------------------------------------
            | Ni la catégorie ni le matricule ne se modifient. Retirer n'efface
            | rien : la fiche sort des listes, ses pièces restent.
            */
            Route::post('volontaires/retrait-en-lot', [VolontairesController::class, 'retirerEnLot'])
                ->name('volontaires.retrait-en-lot');

            // SUPPRIMER POUR DE BON : un seul point d'entrée pour les quatre
            // familles, protégé par son propre droit. Voir le contrôleur.
            Route::post('suppressions', [SuppressionsController::class, 'supprimer'])
                ->name('suppressions.supprimer');
            Route::get('volontaires/{volontaire}', [VolontairesController::class, 'show'])
                ->whereNumber('volontaire')->name('volontaires.voir');
            Route::put('volontaires/{volontaire}', [VolontairesController::class, 'update'])
                ->whereNumber('volontaire')->name('volontaires.modifier');
            Route::post('volontaires/{volontaire}/retirer', [VolontairesController::class, 'retirer'])
                ->whereNumber('volontaire')->name('volontaires.retirer');
            Route::post('volontaires/{volontaire}/reintegrer', [VolontairesController::class, 'reintegrer'])
                ->whereNumber('volontaire')->name('volontaires.reintegrer');

            Route::prefix('imports/volontaires')->name('imports.volontaires.')->group(function () {
                Route::get('/', [ImportVolontairesController::class, 'index'])->name('index');
                Route::get('modele', [ImportVolontairesController::class, 'modele'])->name('modele');
                Route::post('/', [ImportVolontairesController::class, 'televerser'])->name('televerser');
                Route::get('{import}/apercu', [ImportVolontairesController::class, 'apercu'])->name('apercu');
                Route::get('{import}/compte-rendu', [ImportVolontairesController::class, 'compteRendu'])
                    ->name('compte-rendu');
                Route::post('{import}/confirmer', [ImportVolontairesController::class, 'confirmer'])
                    ->name('confirmer');
                Route::delete('{import}', [ImportVolontairesController::class, 'annuler'])->name('annuler');
            });

            /*
            |--------------------------------------------------------------
            | Tableau de bord et agrégats (cadrage, section 15)
            |--------------------------------------------------------------
            | Ces routes lisent les AGRÉGATS PRÉ-CALCULÉS, jamais les tables de
            | détail : 12 294 sites et des rapports quotidiens interdisent toute
            | agrégation à la volée.
            |
            | LES EFFECTIFS NE S'ADDITIONNENT JAMAIS ENTRE RÉGIONS. Ce sont les
            | mêmes équipes qui tournent ; l'indicateur national est un PIC
            | SIMULTANÉ, accompagné de la région qui l'atteint.
            */
            /*
            |--------------------------------------------------------------
            | Paramètres du dispositif (cadrage, sections 5 et 15)
            |--------------------------------------------------------------
            | L'administrateur national consulte, seul le super administrateur
            | modifie. Aucune création ni suppression : la liste est celle que
            | le code lit. La clé contient des points, d'où la contrainte.
            */
            Route::get('parametres', [ParametresController::class, 'index'])->name('parametres.index');
            Route::put('parametres/{parametre:cle}', [ParametresController::class, 'update'])
                ->where('parametre', '[A-Za-z0-9_.]+')
                ->name('parametres.modifier');

            Route::prefix('tableau-bord')->name('tableau-bord.')->group(function () {
                Route::get('/', [TableauBordController::class, 'synthese'])->name('synthese');
                Route::get('pilotage', [TableauBordController::class, 'pilotage'])->name('pilotage');
                Route::get('evolution', [TableauBordController::class, 'evolution'])->name('evolution');
                Route::get('couverture', [TableauBordController::class, 'couverture'])->name('couverture');
                Route::get('retards', [TableauBordController::class, 'retards'])->name('retards');
                Route::get('centres', [TableauBordController::class, 'centres'])->name('centres');
                Route::get('sites-carte', [TableauBordController::class, 'sitesCarte'])->name('sites-carte');
            });

            /*
            |--------------------------------------------------------------
            | Parc de kits et mouvements (cadrage, section 13)
            |--------------------------------------------------------------
            | LE KIT EST RATTACHÉ À UN AGENT, PAS À UN SITE : il suit la
            | personne, y compris d'une région à l'autre.
            |
            | Aucune route n'écrit directement le détenteur d'un kit. Le seul
            | chemin est un mouvement, qui laisse une trace : sans cela, le parc
            | pourrait être corrigé en silence et le journal cesserait de faire foi.
            */
            Route::prefix('kits')->name('kits.')->group(function () {
                Route::get('synthese', [KitsController::class, 'synthese'])->name('synthese');
                Route::get('non-restitues', [KitsController::class, 'nonRestitues'])->name('non-restitues');
                Route::get('/', [KitsController::class, 'index'])->name('index');
                Route::post('/', [KitsController::class, 'store'])->name('creer');
                Route::get('{kit}', [KitsController::class, 'show'])->name('voir');
                Route::put('{kit}', [KitsController::class, 'update'])->name('modifier');
                Route::post('{kit}/mouvements', [KitsController::class, 'declarerMouvement'])
                    ->name('mouvements');
            });

            /*
            |--------------------------------------------------------------
            | Incidents et moteur d'escalade (cadrage, section 10)
            |--------------------------------------------------------------
            | Le canevas client, sections A à J. La section J — prise en charge,
            | mesures correctives, clôture — est réservée aux responsables
            | habilités : le déclarant ne traite pas son propre incident.
            |
            | L'escalade n'est pas une route : c'est l'absence de prise en
            | charge dans le délai qui la déclenche, et le planificateur
            | l'exécute.
            */
            Route::prefix('incidents')->name('incidents.')->group(function () {
                Route::get('canevas', [IncidentsController::class, 'canevas'])->name('canevas');
                Route::get('en-retard', [IncidentsController::class, 'enRetard'])->name('en-retard');
                Route::get('/', [IncidentsController::class, 'index'])->name('index');
                Route::post('/', [IncidentsController::class, 'store'])->name('declarer');
                Route::get('{incident}', [IncidentsController::class, 'show'])->name('voir');
                Route::post('{incident}/prendre-en-charge', [IncidentsController::class, 'prendreEnCharge'])
                    ->name('prendre-en-charge');
                Route::post('{incident}/avancer', [IncidentsController::class, 'avancer'])->name('avancer');
                Route::post('{incident}/commenter', [IncidentsController::class, 'commenter'])
                    ->name('commenter');
                Route::post('{incident}/preuves', [IncidentsController::class, 'ajouterPreuves'])
                    ->name('preuves');
                Route::post('{incident}/cloturer', [IncidentsController::class, 'cloturer'])
                    ->name('cloturer');
            });

            /*
            |--------------------------------------------------------------
            | Alertes : ce qui rend l'escalade visible
            |--------------------------------------------------------------
            | La portée de chaque alerte décide seule de qui la voit. Aucune
            | route ne permet de lire une alerte hors de sa portée.
            */
            Route::prefix('alertes')->name('alertes.')->group(function () {
                Route::get('/', [AlertesController::class, 'index'])->name('index');
                // Une consigne descendante, publiée par un responsable dans son
                // périmètre : le pendant humain des alertes du planificateur.
                Route::post('/', [AlertesController::class, 'publier'])->name('publier');
                Route::get('non-lues', [AlertesController::class, 'nonLues'])->name('non-lues');
                Route::post('{alerte}/lue', [AlertesController::class, 'marquerLue'])->name('lue');
            });

            /*
            |--------------------------------------------------------------
            | Synchronisation hors ligne (cadrage, section 11)
            |--------------------------------------------------------------
            | Le lot est idempotent par son uuid, chaque élément l'est par le
            | sien. Un élément refusé n'invalide jamais le lot : la réponse dit,
            | élément par élément, ce qui est passé et ce qui doit être repris.
            */
            Route::prefix('sync')->name('sync.')->group(function () {
                Route::get('types', [SyncController::class, 'types'])->name('types');
                Route::get('lots', [SyncController::class, 'lots'])->name('lots');
                Route::get('supervision', [SyncController::class, 'supervision'])->name('supervision');
                // Les photos partent APRÈS les données, une par une (section 11.8).
                Route::post('fichiers', [PiecesJointesController::class, 'deposer'])->name('fichiers');
            });
            Route::post('sync', [SyncController::class, 'synchroniser'])->name('sync');

            // Une photo du terrain, pour qui peut voir sa fiche.
            Route::get('pieces-jointes/{piece}', [PiecesJointesController::class, 'telecharger'])
                ->name('pieces-jointes.voir');

            /*
            |--------------------------------------------------------------
            | Rapports journaliers à trois niveaux (cadrage, section 9)
            |--------------------------------------------------------------
            | A-OPK → visa OPK → visa Superviseur → visa Contrôleur terrain.
            | Les chiffres d'un niveau viennent du niveau inférieur DÉJÀ VISÉ :
            | ils sont pré-remplis, jamais ressaisis.
            */
            Route::prefix('rapports')->name('rapports.')->group(function () {
                Route::get('/', [RapportsController::class, 'index'])->name('index');
                Route::post('ouvrir', [RapportsController::class, 'ouvrir'])->name('ouvrir');
                Route::get('a-viser', [RapportsController::class, 'aViser'])->name('a-viser');
                Route::get('export/csv', [RapportsController::class, 'exporterCsv'])->name('export.csv');
                Route::get('{rapport}/export/pdf', [RapportsController::class, 'exporterPdf'])
                    ->name('export.pdf');
                Route::get('{rapport}', [RapportsController::class, 'show'])->name('voir');
                Route::put('{rapport}', [RapportsController::class, 'update'])->name('saisir');
                Route::post('{rapport}/rafraichir', [RapportsController::class, 'rafraichir'])
                    ->name('rafraichir');
                Route::post('{rapport}/soumettre', [RapportsController::class, 'soumettre'])
                    ->name('soumettre');
                Route::post('{rapport}/viser', [RapportsController::class, 'viser'])->name('viser');
                Route::post('{rapport}/rejeter', [RapportsController::class, 'rejeter'])->name('rejeter');
                Route::post('{rapport}/cloturer', [RapportsController::class, 'cloturer'])->name('cloturer');
                Route::post('{rapport}/corriger', [RapportsController::class, 'corriger'])->name('corriger');
            });

            /*
            |--------------------------------------------------------------
            | Appréciations individuelles et droit de réponse
            |--------------------------------------------------------------
            | L'agent noté consulte ce qui le concerne et peut y répondre.
            | Sa réponse n'est modifiable par personne.
            */
            /*
            |--------------------------------------------------------------
            | Exports planifiés
            |--------------------------------------------------------------
            | Produits chaque nuit et DÉPOSÉS : un fichier national, un par
            | région. Le fichier vit hors du dossier public et n'est servi que
            | par le contrôleur, qui revérifie droit et périmètre.
            */
            Route::prefix('exports')->name('exports.')->group(function () {
                Route::get('/', [ExportsPlanifiesController::class, 'index'])->name('index');
                Route::post('produire', [ExportsPlanifiesController::class, 'produire'])->name('produire');
                Route::get('{export}/telecharger', [ExportsPlanifiesController::class, 'telecharger'])
                    ->name('telecharger');
            });

            Route::prefix('appreciations')->name('appreciations.')->group(function () {
                Route::get('/', [AppreciationsController::class, 'index'])->name('index');
                Route::get('mes-appreciations', [AppreciationsController::class, 'mesAppreciations'])
                    ->name('miennes');
                Route::post('{suivi}/repondre', [AppreciationsController::class, 'repondre'])
                    ->name('repondre');
                Route::post('reponses/{reponse}/lue', [AppreciationsController::class, 'marquerLue'])
                    ->name('lue');
            });

            /*
            |--------------------------------------------------------------
            | Présence : quatre mécanismes distincts (cadrage, section 8)
            |--------------------------------------------------------------
            | L'agent SIGNALE, le superviseur VALIDE, et seule la feuille
            | validée fait foi. Les relevés de rapprochement ne sont jamais
            | restitués : seul l'écart constaté est exposé.
            */
            Route::prefix('presence')->name('presence.')->group(function () {
                Route::post('signaler', [PresenceController::class, 'signaler'])->name('signaler');
                Route::get('carte', [PresenceController::class, 'carte'])->name('carte');
                Route::post('releve', [PresenceController::class, 'deposerReleve'])->name('releve');
                Route::get('ecarts', [PresenceController::class, 'ecarts'])->name('ecarts');
                Route::post('ecarts/{ecart}/traiter', [PresenceController::class, 'traiterEcart'])
                    ->name('ecarts.traiter');
            });

            Route::prefix('feuilles')->name('feuilles.')->group(function () {
                Route::get('/', [FeuillesPresenceController::class, 'index'])->name('index');
                Route::get('export/pdf', [FeuillesPresenceController::class, 'exporterPdf'])
                    ->name('export.pdf');
                Route::get('export/csv', [FeuillesPresenceController::class, 'exporterCsv'])
                    ->name('export.csv');
                Route::get('{feuille}', [FeuillesPresenceController::class, 'show'])->name('voir');
                Route::post('{feuille}/valider', [FeuillesPresenceController::class, 'valider'])
                    ->name('valider');
                Route::post('{feuille}/corriger', [FeuillesPresenceController::class, 'corriger'])
                    ->name('corriger');
            });

            Route::post('sites/{site}/feuille', [FeuillesPresenceController::class, 'ouvrir'])
                ->name('sites.feuille');

            /*
            |--------------------------------------------------------------
            | Vagues de déploiement et affectation automatique
            |--------------------------------------------------------------
            | Quatre temps séparés : planifier, tirer une PROPOSITION, valider,
            | clôturer. Rien n'est notifié et aucun accès n'est ouvert tant que
            | la proposition n'est pas validée.
            */
            Route::prefix('vagues')->name('vagues.')->group(function () {
                Route::get('/', [VaguesController::class, 'index'])->name('index');
                Route::post('/', [VaguesController::class, 'store'])->name('planifier');
                Route::get('{vague}', [VaguesController::class, 'show'])->name('voir');
                Route::post('{vague}/tirer', [VaguesController::class, 'tirer'])->name('tirer');
                Route::get('{vague}/proposition', [VaguesController::class, 'proposition'])
                    ->name('proposition');
                Route::put('{vague}/affectations/{affectation}', [VaguesController::class, 'ajuster'])
                    ->name('ajuster');
                Route::post('{vague}/valider', [VaguesController::class, 'valider'])->name('valider');
                Route::post('{vague}/cloturer', [VaguesController::class, 'cloturer'])->name('cloturer');
                Route::get('{vague}/verifier-tirage', [VaguesController::class, 'verifierReproductibilite'])
                    ->name('verifier-tirage');
            });

            /*
            |--------------------------------------------------------------
            | Équipes déployées
            |--------------------------------------------------------------
            | Qui travaille où, aujourd'hui. Les affectations ACTIVES, avec le
            | site du jour : celui de la tournée du kit pour un opérateur,
            | celui de sa localité pour un A-OPK. Le superviseur couvre deux
            | centres et n'a donc aucun site unique.
            */
            Route::prefix('equipes')->name('equipes.')->group(function () {
                Route::get('/', [EquipesController::class, 'index'])->name('index');

                /*
                 | Déplacer un agent DÉJÀ DÉPLOYÉ, ou l'équipe d'un centre.
                 | Le pendant de l'ajustement, qui lui ne vaut qu'avant
                 | validation : ici l'affectation est active, un kit circule et
                 | des passages sont planifiés. Motif obligatoire.
                 */
                Route::put('affectations/{affectation}/centre', [EquipesController::class, 'deplacer'])
                    ->name('deplacer');
                Route::put('centres/{centre}/deplacer', [EquipesController::class, 'deplacerEquipe'])
                    ->name('deplacer-equipe');
            });

            /*
            |--------------------------------------------------------------
            | Passages du kit sur les sites
            |--------------------------------------------------------------
            | Un kit couvre les sites de son centre EN SÉQUENCE : c'est le
            | passage qui dit où un opérateur travaille un jour donné, et donc
            | à quel site se rattachent la feuille de présence et le rapport.
            |
            | Deux actes distincts : le passage bouge, ou l'agent change. Aucun
            | des deux n'est possible sur une journée déjà attestée par une
            | feuille validée — seule la feuille validée fait foi.
            */
            Route::prefix('tournees')->name('tournees.')->group(function () {
                Route::get('/', [TourneesController::class, 'index'])->name('index');
                Route::get('{tournee}/operateurs', [TourneesController::class, 'operateurs'])
                    ->name('operateurs');
                Route::post('/', [TourneesController::class, 'programmer'])->name('programmer');
                Route::put('{tournee}', [TourneesController::class, 'corriger'])->name('corriger');
                Route::put('{tournee}/operateur', [TourneesController::class, 'reaffecter'])
                    ->name('reaffecter');
            });

            /*
            |--------------------------------------------------------------
            | Journal d'activité
            |--------------------------------------------------------------
            | Qui a fait quoi, et quand. Réservé au super administrateur :
            | le journal traverse les douze régions et nomme des personnes,
            | donc aucun périmètre ne s'y applique — il n'existe pas de
            | version régionale de cet écran.
            */
            Route::prefix('journal')->name('journal.')->group(function () {
                Route::get('/', [JournalController::class, 'index'])->name('index');
                Route::get('journaux', [JournalController::class, 'journaux'])->name('journaux');
            });

            /*
            |--------------------------------------------------------------
            | Comptes d'administration — super administrateur seul
            |--------------------------------------------------------------
            | Créer un compte, c'est attribuer des droits : roles.attribuer.
            | Pas de suppression : un compte se ferme, et se rouvre.
            */
            Route::prefix('administration/comptes')->name('administration.comptes.')->group(function () {
                Route::get('/', [ComptesAdministrationController::class, 'index'])->name('index');
                Route::post('/', [ComptesAdministrationController::class, 'store'])->name('creer');
                Route::put('{compte}', [ComptesAdministrationController::class, 'update'])->name('modifier');
                Route::post('{compte}/fermer', [ComptesAdministrationController::class, 'fermer'])->name('fermer');
                Route::post('{compte}/rouvrir', [ComptesAdministrationController::class, 'rouvrir'])->name('rouvrir');
                Route::post('{compte}/mot-de-passe', [ComptesAdministrationController::class, 'reinitialiser'])
                    ->name('mot-de-passe');
            });

            /*
            |--------------------------------------------------------------
            | Listes du canevas d'incident — administration nationale
            |--------------------------------------------------------------
            | Pas de suppression : une entrée qui ne sert plus se désactive.
            */
            Route::prefix('incidents-nomenclatures')->name('incidents.nomenclatures.')->group(function () {
                Route::get('/', [NomenclaturesIncidentController::class, 'index'])->name('index');
                Route::post('{liste}', [NomenclaturesIncidentController::class, 'store'])->name('ajouter');
                Route::put('{liste}/{id}', [NomenclaturesIncidentController::class, 'update'])
                    ->whereNumber('id')
                    ->name('modifier');
            });

            /*
            |--------------------------------------------------------------
            | Remplacements et gestion de la réserve
            |--------------------------------------------------------------
            | Un remplacement ferme une affectation, en ouvre une autre,
            | transfère le kit et bascule le sortant en réserve — avec motif
            | obligatoire, en une seule transaction.
            */
            Route::get('reserve', [RemplacementsController::class, 'reserve'])->name('reserve');
            Route::get('remplacements', [RemplacementsController::class, 'index'])
                ->name('remplacements.index');
            Route::post('remplacements', [RemplacementsController::class, 'store'])
                ->name('remplacements.creer');
            Route::get('affectations/{affectation}/remplacants',
                [RemplacementsController::class, 'remplacantsPossibles'])->name('remplacants');
            Route::get('volontaires/{volontaire}/passages',
                [RemplacementsController::class, 'passages'])->name('volontaires.passages');

            /*
            |--------------------------------------------------------------
            | Import des centres et sites
            |--------------------------------------------------------------
            | Deux modes : compléter le référentiel, ou remplacer le jeu de
            | démonstration. L'aperçu annonce ce que la purge emportera.
            */
            Route::prefix('imports/centres-sites')->name('imports.centres.')->group(function () {
                Route::get('/', [ImportCentresSitesController::class, 'index'])->name('index');
                Route::get('modele', [ImportCentresSitesController::class, 'modele'])->name('modele');
                Route::post('/', [ImportCentresSitesController::class, 'televerser'])->name('televerser');
                Route::get('{import}/apercu', [ImportCentresSitesController::class, 'apercu'])->name('apercu');
                Route::get('{import}/compte-rendu', [ImportCentresSitesController::class, 'compteRendu'])
                    ->name('compte-rendu');
                Route::post('{import}/confirmer', [ImportCentresSitesController::class, 'confirmer'])
                    ->name('confirmer');
                Route::delete('{import}', [ImportCentresSitesController::class, 'annuler'])->name('annuler');
            });

            /*
            |--------------------------------------------------------------
            | Remise des identifiants : suivi, renvoi, bordereau
            |--------------------------------------------------------------
            | Cascade courriel > SMS > bordereau signé en formation.
            */
            Route::prefix('comptes/remises')->name('comptes.')->group(function () {
                Route::get('/', [RemiseIdentifiantsController::class, 'index'])->name('remises');
                // L'état des accès : un PDF de suivi, SANS mot de passe.
                Route::get('etat-acces', [RemiseIdentifiantsController::class, 'etatAcces'])
                    ->name('etat-acces');
                Route::post('renvoyer', [RemiseIdentifiantsController::class, 'renvoyer'])->name('renvoyer');
                Route::post('bordereau', [RemiseIdentifiantsController::class, 'bordereau'])->name('bordereau');
                Route::get('bordereau/{fichier}', [RemiseIdentifiantsController::class, 'telechargerBordereau'])
                    ->name('bordereau.telecharger');
            });
        });
    });
});
