<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * LE JOURNAL D'ACTIVITÉ (cadrage, section 5).
 *
 * Il répond à une seule question : QUI a fait QUOI, et quand. Les écritures
 * existaient depuis la première tâche — chaque service journalise ses actes —
 * mais rien ne permettait de les relire. Un journal qu'on n'ouvre jamais ne
 * protège personne.
 *
 * RÉSERVÉ AU SUPER ADMINISTRATEUR. Seul ce rôle porte `journal.consulter`, et
 * c'est voulu : le journal traverse les douze régions et nomme des personnes.
 * Aucun périmètre ne s'y applique donc — il n'y a qu'un seul niveau de lecture,
 * le niveau national, et pas de version régionale de cet écran.
 *
 * CE QUE LE JOURNAL N'EST PAS : l'historique des déplacements d'un agent. On y
 * lit des ACTES posés dans la plateforme — une feuille validée, un centre
 * fermé, un tirage lancé — jamais une trace de position.
 *
 * L'AUTEUR PEUT ÊTRE ABSENT, et c'est une information, pas un trou : quand le
 * planificateur nocturne recalcule un accès, personne n'a rien décidé. L'écran
 * l'affiche « acteur système » plutôt que d'inventer un responsable.
 */
class JournalController extends Controller
{
    public function index(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('journal.consulter'), 403);

        $activites = Activity::query()
            ->with('causer')
            ->when($requete->filled('log'), fn ($q) => $q->where('log_name', $requete->string('log')))
            ->when($requete->filled('causer_id'),
                fn ($q) => $q->where('causer_id', $requete->integer('causer_id')))
            // « Ce qui s'est fait tout seul » est une question qu'on se pose :
            // elle mérite un filtre, pas un tri à l'œil.
            ->when($requete->boolean('sans_auteur'), fn ($q) => $q->whereNull('causer_id'))
            ->when($requete->filled('sujet'),
                fn ($q) => $q->where('subject_type', $requete->string('sujet')))
            ->when($requete->filled('du'),
                fn ($q) => $q->whereDate('created_at', '>=', $requete->string('du')->toString()))
            ->when($requete->filled('au'),
                fn ($q) => $q->whereDate('created_at', '<=', $requete->string('au')->toString()))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->where('description', 'like', "%{$recherche}%");
            })
            // Le plus récent d'abord : on ouvre un journal pour savoir ce qui
            // vient de se passer, pas ce qui s'est passé au premier jour.
            ->latest('id')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes(
            $activites->total() === 0 ? 'Aucun acte ne correspond.' : 'Journal récupéré.',
            $activites
        );
    }

    /**
     * Les journaux réellement présents, avec leur volume.
     *
     * Lus en base plutôt que codés en dur : la liste s'allonge à chaque service
     * qui journalise, et une liste figée finirait par masquer les nouveaux.
     */
    public function journaux(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('journal.consulter'), 403);

        $journaux = Activity::query()
            ->selectRaw('log_name, count(*) as total')
            ->groupBy('log_name')
            ->orderBy('log_name')
            ->get();

        return ReponseApi::succes('Journaux disponibles.', $journaux);
    }
}
