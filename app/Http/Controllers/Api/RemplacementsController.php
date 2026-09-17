<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Remplacements\RemplacerRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Affectation;
use App\Models\Remplacement;
use App\Models\Volontaire;
use App\Services\Affectation\ServiceRemplacements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remplacements et gestion de la réserve (cadrage, section 6).
 *
 *   GET   /remplacements                          l'historique
 *   GET   /affectations/{a}/remplacants           qui peut prendre la relève
 *   POST  /remplacements                          déclenche le remplacement
 *   GET   /reserve                                le vivier mobilisable
 *   GET   /volontaires/{v}/passages               tous ses passages
 *
 * Le remplacement n'est pas un simple changement de titulaire : il ferme une
 * affectation, en ouvre une autre, transfère un kit, bascule un agent en
 * réserve et recalcule deux accès. Tout se fait dans une seule transaction.
 */
class RemplacementsController extends Controller
{
    public function __construct(private readonly ServiceRemplacements $service)
    {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->autoriserConsultation($requete);

        $remplacements = Remplacement::query()
            ->with([
                'sortant:id,matricule,categorie', 'sortant.user:id,nom,prenoms',
                'entrant:id,matricule,categorie', 'entrant.user:id,nom,prenoms',
                'vague:id,code,libelle', 'decidePar:id,nom,prenoms',
                'kitMouvement:id,kit_id,etat_constate,photo_source_chemin,photo_destination_chemin',
            ])
            ->when($requete->filled('vague_id'),
                fn ($q) => $q->where('vague_id', $requete->integer('vague_id')))
            ->when($requete->filled('motif'),
                fn ($q) => $q->where('motif', $requete->string('motif')))
            ->latest('decide_le')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Remplacements récupérés.', $remplacements);
    }

    /**
     * Les réservistes qui peuvent prendre CETTE affectation : même catégorie,
     * disponibles, et sur la même localité s'il s'agit d'un A-OPK.
     */
    public function remplacantsPossibles(Request $requete, Affectation $affectation): JsonResponse
    {
        $this->autoriserConsultation($requete);
        $this->authorize('view', $affectation);

        $candidats = $this->service->remplacantsPossibles($affectation)
            ->orderBy('matricule')
            ->paginate(min($requete->integer('par_page', 50), 200));

        $sortant = $affectation->volontaire;

        return ReponseApi::succes(
            $candidats->total() === 0
                ? 'Aucun réserviste disponible dans cette catégorie.'
                : "{$candidats->total()} réservistes peuvent prendre cette affectation.",
            [
                'affectation' => $affectation->only(['id', 'role_terrain', 'centre_id', 'localite_id']),
                'titulaire' => $sortant?->only(['id', 'matricule', 'categorie']),
                'detient_un_kit' => $sortant?->kit()->exists() ?? false,
                'candidats' => $candidats,
            ]
        );
    }

    public function store(RemplacerRequest $requete): JsonResponse
    {
        $affectation = Affectation::query()
            ->with(['volontaire', 'vague'])
            ->findOrFail($requete->validated('affectation_id'));

        // Le DROIT et le PÉRIMÈTRE, comme partout : remplacer un agent est un
        // acte national, mais il porte sur une affectation précise.
        $this->authorize('remplacer', $affectation->volontaire);

        try {
            $remplacement = $this->service->remplacer(
                $affectation,
                Volontaire::query()->findOrFail($requete->validated('volontaire_entrant_id')),
                $requete->validated(),
                $requete->user()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $remplacement->load(['sortant:id,matricule', 'entrant:id,matricule', 'kitMouvement']);

        $message = "{$remplacement->sortant->matricule} est remplacé par "
            ."{$remplacement->entrant->matricule}. Le sortant passe en réserve.";

        if ($remplacement->kitMouvement) {
            $message .= ' Le kit lui a été transféré.';
        }

        return ReponseApi::succes($message, $remplacement, 201);
    }

    /** Le vivier de réserve, mobilisable en cas de désistement ou d'abandon. */
    public function reserve(Request $requete): JsonResponse
    {
        $this->autoriserConsultation($requete);

        $vivier = $this->service->vivierDeReserve(
            $requete->string('categorie')->toString() ?: null,
            $requete->filled('region_id') ? $requete->integer('region_id') : null
        );

        $reservistes = $vivier
            ->orderBy('matricule')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Vivier de réserve récupéré.', [
            'reservistes' => $reservistes,
            'repartition' => Volontaire::query()
                ->where('statut', 'reserve')
                ->selectRaw('categorie, motif_reserve, COUNT(*) as nombre')
                ->groupBy('categorie', 'motif_reserve')
                ->get(),
        ]);
    }

    /**
     * Tous les passages d'un volontaire.
     * « Un agent passé en réserve peut être remobilisé plus tard : l'historique
     * conserve tous ses passages. »
     */
    public function passages(Request $requete, Volontaire $volontaire): JsonResponse
    {
        $this->authorize('view', $volontaire);

        $passages = $volontaire->affectations()
            ->with(['vague:id,code,libelle,region_id', 'vague.region:id,code,nom', 'centre:id,code,nom'])
            ->orderByDesc('date_debut')
            ->get();

        return ReponseApi::succes(
            $passages->isEmpty()
                ? "Ce volontaire n'a encore aucun passage."
                : "{$passages->count()} passages enregistrés.",
            [
                'volontaire' => $volontaire->only(['id', 'matricule', 'categorie', 'statut', 'motif_reserve']),
                'passages' => $passages,
                'remplacements_subis' => Remplacement::query()
                    ->where('volontaire_sortant_id', $volontaire->id)
                    ->with('entrant:id,matricule')
                    ->get(['id', 'motif', 'decide_le', 'volontaire_entrant_id']),
                'remplacements_assures' => Remplacement::query()
                    ->where('volontaire_entrant_id', $volontaire->id)
                    ->with('sortant:id,matricule')
                    ->get(['id', 'motif', 'decide_le', 'volontaire_sortant_id']),
            ]
        );
    }

    private function autoriserConsultation(Request $requete): void
    {
        abort_unless($requete->user()->can('remplacements.consulter'), 403);
    }
}
