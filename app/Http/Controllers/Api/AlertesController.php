<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alertes\PublierAlerteRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Alerte;
use App\Services\Alertes\ServicePublicationAlerte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les alertes qui me concernent.
 *
 * Une escalade qui n'aboutit à rien de lisible ne sert à rien : c'est cet
 * écran qui fait exister le moteur d'escalade pour un responsable. La portée
 * de chaque alerte — nationale, régionale, centre, volontaire ou rôle — décide
 * seule de qui la voit ; le scope du modèle l'applique, et rien ici ne le
 * reformule.
 */
class AlertesController extends Controller
{
    public function index(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('alertes.consulter'), 403);

        $alertes = Alerte::query()
            ->pour($requete->user())
            ->when(! $requete->boolean('toutes'), fn ($q) => $q->enCours())
            ->when($requete->filled('type'), fn ($q) => $q->where('type', $requete->string('type')))
            ->when($requete->filled('niveau'), fn ($q) => $q->where('niveau', $requete->string('niveau')))
            ->with([
                'incident:id,numero,gravite,statut',
                'emetteur:id,nom,prenoms',
            ])
            ->withExists(['lecteurs as lue' => fn ($q) => $q->whereKey($requete->user()->id)])
            ->orderByDesc('publiee_le')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes(
            $alertes->total() === 0 ? 'Aucune alerte en cours.' : 'Alertes récupérées.',
            $alertes
        );
    }

    /**
     * PUBLIER UNE ALERTE DESCENDANTE.
     *
     * Jusqu'ici seul le planificateur émettait des alertes : une consigne d'un
     * responsable à ses équipes n'avait aucun chemin, alors que la permission
     * `alertes.publier` existait depuis la tâche 2.
     *
     * Le DROIT est vérifié par la Policy ; le PÉRIMÈTRE par le service, qui
     * refuse à un régional de publier au national — sans quoi une consigne
     * locale partirait aux douze régions.
     */
    public function publier(PublierAlerteRequest $requete, ServicePublicationAlerte $service): JsonResponse
    {
        $this->authorize('create', Alerte::class);

        try {
            $alerte = $service->publier($requete->user(), $requete->validated());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $alerte->load(['region:id,nom', 'centre:id,code', 'emetteur:id,nom,prenoms']);

        // Le message NOMME les destinataires : « publiée » sans dire à qui
        // laisserait croire qu'elle est partie plus loin qu'elle ne l'est.
        $cible = match ($alerte->portee) {
            'nationale' => 'toutes les régions',
            'regionale' => 'la région '.($alerte->region?->nom ?? ''),
            'centre' => 'le centre '.($alerte->centre?->code ?? ''),
            'volontaire' => 'un agent',
            default => 'les comptes « '.$alerte->role_cible.' »',
        };

        return ReponseApi::succes("Alerte {$alerte->code} publiée pour {$cible}.", $alerte, 201);
    }

    /** Le compteur du bandeau : combien d'alertes je n'ai pas encore lues. */
    public function nonLues(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('alertes.consulter'), 403);

        $requetes = Alerte::query()
            ->pour($requete->user())
            ->enCours()
            ->whereDoesntHave('lecteurs', fn ($q) => $q->whereKey($requete->user()->id));

        return ReponseApi::succes('Alertes non lues.', [
            'total' => (clone $requetes)->count(),
            'critiques' => (clone $requetes)->where('niveau', 'critique')->count(),
        ]);
    }

    /**
     * Marquer comme lue. L'accusé est nominatif et horodaté : savoir QUI a lu
     * une alerte critique, et quand, fait partie de ce qu'on doit pouvoir
     * établir après coup.
     */
    public function marquerLue(Request $requete, Alerte $alerte): JsonResponse
    {
        $this->authorize('view', $alerte);

        $alerte->lecteurs()->syncWithoutDetaching([
            $requete->user()->id => ['lu_le' => now()],
        ]);

        return ReponseApi::succes('Alerte marquée comme lue.', null);
    }
}
