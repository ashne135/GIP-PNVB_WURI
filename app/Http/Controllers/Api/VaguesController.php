<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vagues\PlanifierVagueRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Affectation;
use App\Models\VagueDeploiement;
use App\Models\Volontaire;
use App\Services\Affectation\ServiceVagues;
use App\Services\Affectation\TirageAffectations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vagues de déploiement et affectation automatique (cadrage, section 7).
 *
 * Le parcours est en quatre temps, et l'API les sépare :
 *
 *   POST  /vagues                     planifie — région, période, centres
 *   POST  /vagues/{v}/tirer           produit une PROPOSITION, rien n'est notifié
 *   POST  /vagues/{v}/valider         la proposition devient la réalité
 *   POST  /vagues/{v}/cloturer        événement métier de fin de mission
 *
 * Entre le tirage et la validation, l'administrateur consulte la proposition,
 * lit les alertes sur les contraintes non satisfaites, et peut ajuster une
 * affectation. « Rien n'est écrit ni notifié tant que la proposition n'est pas
 * validée. »
 */
class VaguesController extends Controller
{
    public function __construct(
        private readonly ServiceVagues $vagues,
        private readonly TirageAffectations $tirage,
    ) {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', VagueDeploiement::class);

        $vagues = VagueDeploiement::query()
            ->perimetre($requete->user())
            ->with(['region:id,code,nom', 'creePar:id,nom,prenoms'])
            ->withCount(['vagueCentres as centres_count', 'affectations'])
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->latest('date_debut_prevue')
            ->paginate(min($requete->integer('par_page', 20), 100));

        return ReponseApi::succes('Vagues récupérées.', $vagues);
    }

    public function show(Request $requete, VagueDeploiement $vague): JsonResponse
    {
        $this->authorize('view', $vague);

        return ReponseApi::succes('Vague récupérée.', $vague->load([
            'region:id,code,nom',
            'centres:id,code,nom,commune_id,nombre_kits',
            'unitesSupervision.superviseur.user:id,nom,prenoms',
        ]));
    }

    public function store(PlanifierVagueRequest $requete): JsonResponse
    {
        $this->authorize('create', VagueDeploiement::class);

        try {
            $vague = $this->vagues->planifier(
                $requete->validated(),
                $requete->validated('centres'),
                $requete->user()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            "Vague {$vague->code} planifiée. Lancez le tirage pour obtenir une proposition d'affectation.",
            $vague->load('region:id,code,nom'),
            201
        );
    }

    /**
     * Lance le tirage. La graine peut être imposée — c'est ce qui permet de
     * REJOUER un tirage à l'identique et d'en expliquer le résultat.
     */
    public function tirer(Request $requete, VagueDeploiement $vague): JsonResponse
    {
        $this->authorize('tirer', $vague);

        $valide = $requete->validate([
            'graine' => ['nullable', 'integer', 'min:1'],
        ], [
            'graine.integer' => 'La graine doit être un nombre entier.',
        ]);

        try {
            $resultat = $this->tirage->tirer($vague, $valide['graine'] ?? null, $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $message = "Proposition établie : {$resultat->nombreAffectations()} affectations, "
            ."graine {$resultat->graine}.";

        if ($resultat->anomalies !== []) {
            $message .= ' '.count($resultat->anomalies).' points demandent votre attention avant validation.';
        }

        $message .= " Rien n'est encore notifié : aucun accès n'est ouvert.";

        return ReponseApi::succes($message, $resultat->enTableau());
    }

    /** La proposition, telle qu'elle doit être lue avant de valider. */
    public function proposition(Request $requete, VagueDeploiement $vague): JsonResponse
    {
        $this->authorize('view', $vague);

        $affectations = Affectation::query()
            ->where('vague_id', $vague->id)
            ->where('statut', 'proposee')
            ->with([
                'volontaire:id,user_id,matricule,categorie,localite_id',
                'volontaire.user:id,nom,prenoms,telephone',
                'centre:id,code,nom',
                'uniteSupervision',
                'kit:id,reference',
            ])
            ->orderBy('role_terrain')
            ->orderBy('rang_tirage')
            ->paginate(min($requete->integer('par_page', 100), 500));

        $unites = $vague->unitesSupervision()
            ->with(['superviseur:id,user_id,matricule', 'centrePrincipal:id,code', 'centreSecondaire:id,code'])
            ->get();

        return ReponseApi::succes('Proposition récupérée.', [
            'vague' => $vague->only(['id', 'code', 'libelle', 'statut', 'graine_tirage', 'contraintes_tirage']),
            'affectations' => $affectations,
            'unites_supervision' => $unites,
            'contraintes_non_satisfaites' => $unites->where('contrainte_respectee', false)->count(),
        ]);
    }

    /** Ajustement manuel d'une affectation, avant validation seulement. */
    public function ajuster(Request $requete, VagueDeploiement $vague, Affectation $affectation): JsonResponse
    {
        $this->authorize('ajuster', $affectation);

        $valide = $requete->validate([
            'volontaire_id' => ['required', 'integer', 'exists:volontaires,id'],
        ], [
            'volontaire_id.required' => 'Choisissez le volontaire qui prend cette affectation.',
        ]);

        try {
            $affectation = $this->vagues->ajusterAffectation(
                $affectation,
                Volontaire::query()->findOrFail($valide['volontaire_id']),
                $requete->user()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Affectation ajustée.', $affectation->load('volontaire:id,user_id,matricule'));
    }

    public function valider(Request $requete, VagueDeploiement $vague): JsonResponse
    {
        $this->authorize('valider', $vague);

        try {
            $vague = $this->vagues->valider($vague, $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            "Vague {$vague->code} validée et active. Les accès des agents affectés sont ouverts, "
            .'et leurs identifiants partent par courriel, SMS ou bordereau.',
            $vague
        );
    }

    public function cloturer(Request $requete, VagueDeploiement $vague): JsonResponse
    {
        $this->authorize('cloturer', $vague);

        try {
            $bilan = $this->vagues->cloturer($vague, $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $message = "Vague clôturée : {$bilan['affectations']} affectations terminées, "
            .'accès recalculés.';

        if ($bilan['kits_non_restitues'] > 0) {
            $message .= " Attention : {$bilan['kits_non_restitues']} kits ne sont ni restitués "
                .'ni transférés.';
        }

        return ReponseApi::succes($message, ['vague' => $vague->fresh(), 'bilan' => $bilan]);
    }

    /**
     * AUDIT : rejoue le tirage avec la graine enregistrée et compare.
     * « On doit pouvoir rejouer et expliquer une affectation. »
     */
    public function verifierReproductibilite(Request $requete, VagueDeploiement $vague): JsonResponse
    {
        $this->authorize('tirer', $vague);

        try {
            $verification = $this->tirage->verifierReproductibilite($vague);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            $verification['identique']
                ? "Le tirage rejoué avec la graine {$vague->graine_tirage} donne exactement le même résultat."
                : 'Le tirage rejoué diffère du tirage enregistré : '
                    .count($verification['ecarts']).' écarts constatés.',
            $verification
        );
    }
}
