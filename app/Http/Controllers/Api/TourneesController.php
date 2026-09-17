<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutAffectation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tournees\CorrigerTourneeRequest;
use App\Http\Requests\Tournees\ReaffecterOperateurRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Affectation;
use App\Models\TourneeSite;
use App\Services\Affectation\ServiceTournees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LES PASSAGES DU KIT SUR LES SITES (cadrage, sections 4 et 7).
 *
 * C'est le passage qui dit sur quel site un opérateur travaille un jour donné.
 * Jusqu'ici la table existait et six services la lisaient, mais RIEN ne
 * permettait de la corriger : un kit arrivé en retard sur un site, ou un
 * opérateur à déplacer, n'avait aucun chemin dans la plateforme.
 *
 * Deux actes séparés, parce qu'ils ne répondent pas à la même question :
 *
 *   PUT  /tournees/{t}            le passage bouge — site, dates, ordre
 *   PUT  /tournees/{t}/operateur  le passage reste, l'agent change
 */
class TourneesController extends Controller
{
    public function __construct(private readonly ServiceTournees $tournees)
    {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', TourneeSite::class);

        $passages = TourneeSite::query()
            ->perimetre($requete->user())
            ->with([
                'site:id,code,nom,centre_id',
                'centre:id,code,nom,region_id',
                'kit:id,reference',
                'vague:id,code,libelle,statut',
                'affectationOperateur:id,volontaire_id,centre_id,statut',
                'affectationOperateur.volontaire:id,user_id,matricule',
                'affectationOperateur.volontaire.user:id,nom,prenoms',
            ])
            ->when($requete->filled('vague_id'), fn ($q) => $q->where('vague_id', $requete->integer('vague_id')))
            ->when($requete->filled('centre_id'), fn ($q) => $q->where('centre_id', $requete->integer('centre_id')))
            ->when($requete->filled('site_id'), fn ($q) => $q->where('site_id', $requete->integer('site_id')))
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            // « Qui travaille où AUJOURD'HUI » est la question la plus posée :
            // elle mérite un filtre, pas un tri à l'œil sur une longue liste.
            ->when($requete->filled('date'), fn ($q) => $q->couvrant($requete->string('date')->toString()))
            ->orderBy('centre_id')
            ->orderBy('ordre')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes(
            $passages->total() === 0 ? 'Aucun passage ne correspond.' : 'Passages récupérés.',
            $passages
        );
    }

    /** Le passage bouge : site, dates, rang dans la tournée, statut. */
    public function corriger(CorrigerTourneeRequest $requete, TourneeSite $tournee): JsonResponse
    {
        $this->authorize('ajuster', $tournee);

        try {
            $passage = $this->tournees->corriger($tournee, $requete->validated(), $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $du = $passage->date_debut?->format('d/m/Y');
        $au = $passage->date_fin?->format('d/m/Y');

        return ReponseApi::succes(
            "Passage corrigé : le kit couvre {$passage->site?->nom} du {$du}"
            .($au ? " au {$au}." : ', sans date de fin.'),
            $passage
        );
    }

    /** Le passage reste où il est : seul l'agent qui le tient change. */
    public function reaffecter(ReaffecterOperateurRequest $requete, TourneeSite $tournee): JsonResponse
    {
        $this->authorize('ajuster', $tournee);

        $id = $requete->validated()['affectation_operateur_id'] ?? null;
        $affectation = $id !== null ? Affectation::query()->findOrFail($id) : null;

        try {
            $passage = $this->tournees->reaffecterOperateur($tournee, $affectation, $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $agent = $passage->affectationOperateur?->volontaire?->matricule;

        return ReponseApi::succes(
            $agent
                ? "{$agent} tient désormais le passage sur {$passage->site?->nom}."
                : "Le passage sur {$passage->site?->nom} n'a plus d'opérateur : le kit est sans porteur.",
            $passage
        );
    }

    /**
     * Les opérateurs mobilisables sur ce passage.
     *
     * Ceux du CENTRE du passage, et eux seuls : un kit ne sort pas de son
     * centre, donc proposer les opérateurs des autres centres reviendrait à
     * proposer des choix que le serveur refusera.
     */
    public function operateurs(Request $requete, TourneeSite $tournee): JsonResponse
    {
        $this->authorize('view', $tournee);

        $candidats = Affectation::query()
            ->perimetre($requete->user())
            ->where('centre_id', $tournee->centre_id)
            ->where('role_terrain', CategorieVolontaire::Operateur->value)
            ->where('statut', StatutAffectation::Active->value)
            ->with(['volontaire:id,user_id,matricule', 'volontaire.user:id,nom,prenoms'])
            ->get(['id', 'volontaire_id', 'centre_id', 'kit_id', 'statut', 'role_terrain']);

        return ReponseApi::succes(
            $candidats->isEmpty() ? 'Aucun opérateur actif dans ce centre.' : 'Opérateurs mobilisables.',
            $candidats
        );
    }
}
