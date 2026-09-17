<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\AppreciationReponse;
use App\Models\RapportSuiviAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appréciations individuelles et DROIT DE RÉPONSE (cadrage, section 9).
 *
 * « L'agent noté PEUT CONSULTER les appréciations le concernant. Pas de
 * notation invisible. Une anomalie signalée doit lui permettre d'ajouter une
 * OBSERVATION en réponse, horodatée, non modifiable par le supérieur. »
 *
 * Sans cela, on construirait un outil de notation unilatéral et non
 * contestable sur 2 415 personnes.
 */
class AppreciationsController extends Controller
{
    /** Les appréciations qui me concernent — toutes, y compris les anciennes. */
    public function mesAppreciations(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('appreciations.consulter_les_miennes'), 403);

        $volontaire = $requete->user()->volontaire;

        abort_unless($volontaire !== null, 403);

        $appreciations = RapportSuiviAgent::query()
            ->where('volontaire_id', $volontaire->id)
            ->with([
                'rapport:id,type,date_rapport,statut,auteur_volontaire_id',
                'rapport.auteur:id,user_id,matricule', 'rapport.auteur.user:id,nom,prenoms',
                'reponses',
            ])
            ->orderByDesc('created_at')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes(
            $appreciations->total() === 0
                ? 'Aucune appréciation ne vous concerne pour le moment.'
                : "{$appreciations->total()} appréciations vous concernent.",
            $appreciations
        );
    }

    /** Les appréciations de mon équipe, dans mon périmètre. */
    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', RapportSuiviAgent::class);

        $appreciations = RapportSuiviAgent::query()
            ->perimetre($requete->user())
            ->with([
                'volontaire:id,user_id,matricule,categorie', 'volontaire.user:id,nom,prenoms',
                'rapport:id,type,date_rapport,centre_id', 'reponses',
            ])
            ->when($requete->filled('volontaire_id'),
                fn ($q) => $q->where('volontaire_id', $requete->integer('volontaire_id')))
            ->when($requete->boolean('avec_anomalie'),
                fn ($q) => $q->whereNotNull('anomalies'))
            ->orderByDesc('created_at')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Appréciations récupérées.', $appreciations);
    }

    /**
     * L'agent répond à une appréciation le concernant.
     * Sa réponse est horodatée et définitive : personne ne peut la réécrire,
     * pas même lui — c'est ce qui lui donne sa valeur dans un litige.
     */
    public function repondre(Request $requete, RapportSuiviAgent $suivi): JsonResponse
    {
        $this->authorize('repondre', $suivi);

        $valide = $requete->validate([
            'reponse' => ['required', 'string', 'max:3000'],
        ], [
            'reponse.required' => 'Écrivez votre observation.',
        ]);

        $reponse = AppreciationReponse::query()->create([
            'suivi_agent_id' => $suivi->id,
            'volontaire_id' => $requete->user()->volontaire->id,
            'reponse' => $valide['reponse'],
            'repondu_le' => now(),
        ]);

        activity('appreciation')
            ->causedBy($requete->user())
            ->performedOn($suivi)
            ->log("Réponse de l'agent à une appréciation");

        return ReponseApi::succes(
            'Votre observation est enregistrée. Elle est horodatée et ne peut plus être modifiée, '
            .'ni par vous ni par votre supérieur.',
            $reponse,
            201
        );
    }

    /** Le supérieur accuse réception de la réponse de son agent. */
    public function marquerLue(Request $requete, AppreciationReponse $reponse): JsonResponse
    {
        $this->authorize('view', $reponse);
        abort_unless($requete->user()->can('appreciations.consulter_equipe'), 403);

        $reponse->update(['lu_par_superieur_le' => now()]);

        return ReponseApi::succes('Réponse marquée comme lue.', $reponse->fresh());
    }
}
