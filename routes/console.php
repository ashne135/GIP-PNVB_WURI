<?php

use App\Jobs\AlerterKitsNonRestituesJob;
use App\Jobs\AlerterPhotosKitsManquantesJob;
use App\Jobs\EscaladerIncidentsJob;
use App\Jobs\GenererExportsPlanifiesJob;
use App\Models\Parametre;
use App\Jobs\PurgerRelevesPositionJob;
use App\Jobs\RecalculerAgregatsJob;
use App\Jobs\RapprocherPresencesJob;
use App\Jobs\RecalculerAccesComptesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planificateur — filets de sécurité quotidiens
|--------------------------------------------------------------------------
| Le cadrage (section 6) est explicite : l'ouverture et la fermeture des accès
| sont pilotées par le job planifié, déclenché à l'ouverture et à la clôture
| de chaque vague, ET quotidiennement en filet de sécurité. Cette exécution
| de nuit rattrape tout écart — une vague dont la date de fin est dépassée
| sans clôture explicite, par exemple.
|
| La purge des relevés de position (section 8.4) applique la rétention
| paramétrable : aucune trace de déplacement ne doit survivre au-delà du
| délai fixé dans les paramètres.
*/
Schedule::job(new RecalculerAccesComptesJob)
    ->dailyAt('02:00')
    ->name('pnvb:recalculer-acces-comptes')
    ->withoutOverlapping();

Schedule::job(new PurgerRelevesPositionJob)
    ->dailyAt('03:00')
    ->name('pnvb:purger-releves-position')
    ->withoutOverlapping();

/*
| Rapprochement quotidien des feuilles de présence et des relevés de position
| (cadrage, section 8.4). Tourne AVANT la purge : les relevés de la veille
| doivent encore exister au moment de la comparaison.
*/
Schedule::job(new RapprocherPresencesJob)
    ->dailyAt('02:30')
    ->name('pnvb:rapprocher-presences')
    ->withoutOverlapping();

/*
| Escalade des incidents non pris en charge (cadrage, section 10).
|
| Passage toutes les cinq minutes, et non toutes les heures : le délai le plus
| court du dispositif est de 30 minutes pour un incident critique. Un balayage
| horaire y ajouterait jusqu'a une heure de retard — sur un danger grave, c'est
| la difference entre une alerte utile et une alerte qui arrive trop tard.
|
| Les DELAIS eux-memes restent des parametres ; seule la frequence du balayage
| est ici, et elle doit simplement rester plus fine que le plus court d'entre eux.
*/
Schedule::job(new EscaladerIncidentsJob)
    ->everyFiveMinutes()
    ->name('pnvb:escalader-incidents')
    ->withoutOverlapping();

/*
| Kits non restitues (cadrage, section 13).
|
| La cloture d'une vague publie deja ses alertes. Ce balayage quotidien est le
| FILET : il rattrape les agents dont l'affectation s'est terminee hors cloture
| — un remplacement, une fin de mission isolee — et les kits dont le delai de
| grace expire des jours apres la cloture.
|
| Passage a 04h00, apres le recalcul des acces et le rapprochement : les
| affectations de la nuit sont deja a jour quand on regarde qui detient quoi.
*/
Schedule::job(new AlerterKitsNonRestituesJob)
    ->dailyAt('04:00')
    ->name('pnvb:alerter-kits-non-restitues')
    ->withoutOverlapping();

/*
| Photos de constat annoncées et jamais arrivées (cadrage, sections 11.8 et 13).
|
| Passage horaire : le délai est un paramètre exprimé en heures, et une photo
| manquante sur une remise de kit doit être réclamée tant que les agents sont
| encore joignables sur le site.
*/
Schedule::job(new AlerterPhotosKitsManquantesJob)
    ->hourly()
    ->name('pnvb:alerter-photos-kits-manquantes')
    ->withoutOverlapping();

/*
| Recalcul nocturne des agregats (cadrage, section 15).
|
| Passage a 01h00, AVANT le recalcul des acces et le rapprochement : le tableau
| de bord doit etre a jour des la premiere connexion du matin.
|
| Le job reprend DEUX journees, pas une : un rapport peut etre vise avec un jour
| de retard — c'est meme le cas normal quand un superviseur vise le matin les
| rapports de la veille — et seuls les rapports vises alimentent les agregats.
*/
Schedule::job(new RecalculerAgregatsJob)
    ->dailyAt('01:00')
    ->name('pnvb:recalculer-agregats')
    ->withoutOverlapping();

/*
| Exports planifiés (tâche 18).
|
| 05h00 par défaut, c'est-à-dire APRÈS le recalcul des agrégats (01h00), le
| recalcul des accès (02h00) et le rapprochement des présences (02h30) : un
| export produit avant eux porterait des chiffres incomplets tout en ayant
| l'apparence d'un document définitif.
|
| L'heure est un paramètre, mais lue ici avec un repli et sans laisser passer
| une exception : ce fichier est chargé par TOUTE commande artisan, y compris
| sur une base encore vide. Un paramètre illisible ne doit pas empêcher
| `migrate` de tourner.
*/
try {
    $heureDesExports = (string) Parametre::valeur('exports.heure_generation', '05:00');
} catch (\Throwable) {
    $heureDesExports = '05:00';
}

Schedule::job(new GenererExportsPlanifiesJob)
    ->dailyAt(preg_match('/^\d{2}:\d{2}$/', $heureDesExports) ? $heureDesExports : '05:00')
    ->name('pnvb:generer-exports-planifies')
    ->withoutOverlapping();
