<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\FeuillePresence;
use App\Models\Site;
use App\Services\Presence\ExportPresences;
use App\Services\Presence\ServiceFeuillePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Feuilles de présence : la seule pièce qui fait foi (cadrage, section 8.3).
 *
 *   POST /sites/{site}/feuille        ouvre — ou récupère — la feuille du jour
 *   POST /feuilles/{f}/valider        le superviseur marque et valide
 *   POST /feuilles/{f}/corriger       le chef d'antenne corrige, avec motif
 *   GET  /feuilles/export/pdf|csv     la pièce justificative
 *
 * UNE feuille par site et par jour : l'ouverture est idempotente, deux appels
 * le même jour rendent la même feuille.
 */
class FeuillesPresenceController extends Controller
{
    public function __construct(
        private readonly ServiceFeuillePresence $service,
        private readonly ExportPresences $exports,
    ) {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', FeuillePresence::class);

        $feuilles = FeuillePresence::query()
            ->perimetre($requete->user())
            ->with(['site:id,code,nom', 'centre:id,code,nom', 'superviseur.user:id,nom,prenoms'])
            ->withCount('lignes')
            ->when($requete->filled('date'), fn ($q) => $q->whereDate('date_presence', $requete->string('date')))
            ->when($requete->filled('centre_id'),
                fn ($q) => $q->where('centre_id', $requete->integer('centre_id')))
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->orderByDesc('date_presence')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Feuilles récupérées.', $feuilles);
    }

    public function show(Request $requete, FeuillePresence $feuille): JsonResponse
    {
        $this->authorize('view', $feuille);

        return ReponseApi::succes('Feuille récupérée.', $feuille->load([
            'site:id,code,nom,latitude,longitude,rayon_zone_metres',
            'centre:id,code,nom',
            'superviseur:id,user_id,matricule', 'superviseur.user:id,nom,prenoms',
            'lignes.volontaire:id,user_id,matricule,categorie',
            'lignes.volontaire.user:id,nom,prenoms',
        ]));
    }

    /** Ouvre la feuille du jour pour un site, pré-remplie. */
    public function ouvrir(Request $requete, Site $site): JsonResponse
    {
        $this->authorize('create', FeuillePresence::class);
        $this->authorize('view', $site);

        $date = $requete->string('date')->toString() ?: now()->toDateString();
        $superviseur = $requete->user()->volontaire;

        abort_unless($superviseur !== null, 403);

        try {
            $feuille = $this->service->preparer($site, $date, $superviseur);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            $feuille->wasRecentlyCreated
                ? "Feuille du {$date} ouverte, pré-remplie avec les agents attendus."
                : "Feuille du {$date} déjà ouverte : voici son état.",
            $feuille
        );
    }

    public function valider(Request $requete, FeuillePresence $feuille): JsonResponse
    {
        $this->authorize('valider', $feuille);

        $valide = $this->validerLignes($requete);
        $superviseur = $requete->user()->volontaire;

        abort_unless($superviseur !== null, 403);

        try {
            $feuille = $this->service->valider(
                $feuille,
                $valide['lignes'],
                ['latitude' => $valide['latitude'] ?? null, 'longitude' => $valide['longitude'] ?? null],
                $superviseur
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $presents = $feuille->effectifPresent();
        $total = $feuille->lignes->count();

        return ReponseApi::succes(
            "Feuille validée : {$presents} présents sur {$total} agents attendus. "
            .'Elle fait foi et ne peut plus être modifiée.',
            $feuille
        );
    }

    /** Correction d'une feuille validée — chef d'antenne régional uniquement. */
    public function corriger(Request $requete, FeuillePresence $feuille): JsonResponse
    {
        $this->authorize('corriger', $feuille);

        $valide = $this->validerLignes($requete, avecMotif: true);

        try {
            $feuille = $this->service->corriger(
                $feuille,
                $valide['lignes'],
                $valide['motif_correction'],
                $requete->user()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Feuille corrigée. La correction est journalisée.', $feuille);
    }

    /** Export PDF : pièce justificative traçable et opposable. */
    public function exporterPdf(Request $requete)
    {
        [$feuilles, $intitule] = $this->selectionner($requete);

        if ($feuilles->isEmpty()) {
            return ReponseApi::echec('Aucune feuille à exporter pour cette sélection.', null, 404);
        }

        $nom = 'presents-'.now()->format('Ymd-His').'.pdf';

        return $this->exports->pdf($feuilles, $requete->user(), $intitule)->download($nom);
    }

    public function exporterCsv(Request $requete): StreamedResponse
    {
        [$feuilles, $intitule] = $this->selectionner($requete);

        abort_if($feuilles->isEmpty(), 404);

        return response()->streamDownload(
            $this->exports->csv($feuilles, $requete->user(), $intitule),
            'presents-'.now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    // ------------------------------------------------------------------

    /**
     * L'export porte sur UNE feuille, UN centre ou UNE PÉRIODE (cadrage, 8.3).
     * Le périmètre s'applique à l'export comme au reste : on n'exporte jamais
     * au-delà de ce qu'on a le droit de voir.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: string}
     */
    private function selectionner(Request $requete): array
    {
        $this->authorize('viewAny', FeuillePresence::class);
        abort_unless($requete->user()->can('presence.exporter'), 403);

        $requete->validate([
            'feuille_id' => ['nullable', 'integer', 'exists:feuilles_presence,id'],
            'centre_id' => ['nullable', 'integer', 'exists:centres,id'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date', 'after_or_equal:du'],
        ]);

        $requeteFeuilles = FeuillePresence::query()
            ->perimetre($requete->user())
            ->with([
                'site:id,code,nom', 'centre:id,code,nom',
                'superviseur.user:id,nom,prenoms',
                'lignes.volontaire:id,user_id,matricule',
                'lignes.volontaire.user:id,nom,prenoms',
            ]);

        if ($requete->filled('feuille_id')) {
            $requeteFeuilles->where('id', $requete->integer('feuille_id'));
            $intitule = 'Feuille du site sélectionné';
        } elseif ($requete->filled('centre_id')) {
            $requeteFeuilles->where('centre_id', $requete->integer('centre_id'));
            $intitule = 'Centre sélectionné';
        } else {
            $intitule = 'Période';
        }

        if ($requete->filled('du')) {
            $requeteFeuilles->whereDate('date_presence', '>=', $requete->string('du'));
            $intitule .= ' — du '.$requete->string('du');
        }

        if ($requete->filled('au')) {
            $requeteFeuilles->whereDate('date_presence', '<=', $requete->string('au'));
            $intitule .= ' au '.$requete->string('au');
        }

        return [$requeteFeuilles->orderBy('date_presence')->limit(500)->get(), $intitule];
    }

    private function validerLignes(Request $requete, bool $avecMotif = false): array
    {
        $regles = [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.volontaire_id' => ['required', 'integer', 'exists:volontaires,id'],
            'lignes.*.statut' => ['required', Rule::in(['present', 'absent', 'absent_justifie'])],
            'lignes.*.motif_absence' => ['nullable', 'string', 'max:255'],
            'lignes.*.commentaire' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];

        if ($avecMotif) {
            $regles['motif_correction'] = ['required', 'string', 'max:500'];
        }

        return $requete->validate($regles, [
            'lignes.required' => 'Marquez la présence de chaque agent.',
            'lignes.*.statut.required' => 'Chaque agent doit être marqué présent, absent ou absent justifié.',
            'motif_correction.required' => 'Indiquez pourquoi cette feuille validée est corrigée.',
        ]);
    }
}
