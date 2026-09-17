<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\ExportPlanifie;
use App\Services\Exports\ServiceExportsPlanifies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les exports déposés, et leur téléchargement.
 *
 *   GET  /exports                       ce qui est prêt, dans mon périmètre
 *   GET  /exports/{export}/telecharger  le fichier, jamais par une URL directe
 *   POST /exports/produire              rejouer une journée, à la demande
 *
 * Le fichier vit hors du dossier public : le contrôleur revérifie le droit ET
 * le périmètre à chaque téléchargement. Un chemin exposé serait une adresse à
 * deviner, et le périmètre ne tiendrait plus.
 */
class ExportsPlanifiesController extends Controller
{
    public function __construct(private readonly ServiceExportsPlanifies $service) {}

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', ExportPlanifie::class);

        $exports = ExportPlanifie::query()
            ->perimetre($requete->user())
            ->with('region:id,code,nom')
            ->when($requete->filled('type'), fn ($q) => $q->where('type', $requete->string('type')))
            ->when($requete->filled('date'), fn ($q) => $q->whereDate('date_debut', $requete->string('date')))
            ->orderByDesc('date_debut')
            ->orderBy('type')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes(
            $exports->total() === 0
                ? 'Aucun export disponible pour le moment.'
                : "{$exports->total()} exports disponibles.",
            $exports
        );
    }

    public function telecharger(Request $requete, ExportPlanifie $export): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $export);

        if (! $export->estTelechargeable()) {
            // Une journée sans donnée n'est pas une panne : le dire vaut mieux
            // que rendre un fichier vide qu'on croira tronqué.
            return ReponseApi::echec(
                $export->message ?? "Cet export n'a pas de fichier : la période ne contenait aucune donnée.",
                null,
                404
            );
        }

        return Storage::disk(ServiceExportsPlanifies::DISQUE)->response(
            $export->chemin,
            $export->nom_fichier,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    /**
     * Rejouer une journée : le même fichier est réécrit, jamais empilé.
     *
     * Utile quand un rapport a été visé en retard — les chiffres de la veille
     * changent alors après la production de nuit.
     */
    public function produire(Request $requete): JsonResponse
    {
        $this->authorize('produire', ExportPlanifie::class);

        $valide = $requete->validate([
            // « before », pas « before_or_equal » : la journée en cours est
            // incomplète par construction, et son fichier ne le dirait pas.
            'date' => ['nullable', 'date', 'before:today'],
        ], [
            'date.before' => "Un export ne se produit que sur une journée écoulée : celle d'aujourd'hui serait incomplète.",
        ]);

        $date = $valide['date'] ?? now()->subDay()->toDateString();
        $bilan = $this->service->genererJournee($date, $requete->user());

        return ReponseApi::succes(
            "Journée du {$date} : {$bilan['fichiers']} fichiers produits, {$bilan['vides']} périmètres sans donnée.",
            $bilan
        );
    }
}
