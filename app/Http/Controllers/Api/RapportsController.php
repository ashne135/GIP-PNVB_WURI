<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rapports\SaisirRapportRequest;
use App\Http\Responses\ReponseApi;
use App\Models\RapportJournalier;
use App\Services\Rapports\ExportRapports;
use App\Services\Rapports\ServiceCycleDeVieRapport;
use App\Services\Rapports\ServicePreparationRapport;
use App\Services\Rapports\ServiceSaisieRapport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapports journaliers à trois niveaux et chaîne de visas (cadrage, section 9).
 *
 *   POST /rapports/ouvrir          ouvre le rapport du jour, PRÉ-REMPLI
 *   PUT  /rapports/{r}             saisie du contenu
 *   POST /rapports/{r}/rafraichir  recalcule les chiffres du niveau inférieur
 *   POST /rapports/{r}/soumettre   l'auteur signe
 *   POST /rapports/{r}/viser       le supérieur vise
 *   POST /rapports/{r}/rejeter     le supérieur renvoie, avec motif
 *   GET  /rapports/a-viser         ce que j'ai à viser
 *
 * Le type de rapport découle de la CATÉGORIE de l'auteur : un A-OPK produit un
 * rapport d'accueil, un opérateur un rapport de production, un superviseur un
 * rapport de centre. Le client ne le choisit pas.
 */
class RapportsController extends Controller
{
    public function __construct(
        private readonly ServicePreparationRapport $preparation,
        private readonly ServiceSaisieRapport $saisie,
        private readonly ServiceCycleDeVieRapport $cycleDeVie,
        private readonly ExportRapports $export,
    ) {}

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', RapportJournalier::class);

        $rapports = RapportJournalier::query()
            ->perimetre($requete->user())
            ->with([
                'auteur:id,user_id,matricule,categorie', 'auteur.user:id,nom,prenoms',
                'site:id,code,nom', 'centre:id,code,nom',
            ])
            ->when($requete->filled('type'), fn ($q) => $q->where('type', $requete->string('type')))
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->when($requete->filled('date'), fn ($q) => $q->whereDate('date_rapport', $requete->string('date')))
            ->when($requete->filled('centre_id'),
                fn ($q) => $q->where('centre_id', $requete->integer('centre_id')))
            ->orderByDesc('date_rapport')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Rapports récupérés.', $rapports);
    }

    public function show(Request $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('view', $rapport);

        return ReponseApi::succes('Rapport récupéré.', $rapport->load([
            'auteur:id,user_id,matricule,categorie', 'auteur.user:id,nom,prenoms',
            'superieur:id,user_id,matricule', 'superieur.user:id,nom,prenoms',
            'site:id,code,nom', 'centre:id,code,nom', 'region:id,code,nom',
            'vague:id,code,libelle',
            'activitesAopk', 'productionOpk', 'evolution', 'qualite', 'logistique',
            'difficultes', 'pointsAmelioration',
            'suiviAgents.volontaire:id,user_id,matricule', 'suiviAgents.volontaire.user:id,nom,prenoms',
            'suiviAgents.reponses',
            'visas.user:id,nom,prenoms', 'corrections.corrigePar:id,nom,prenoms',
        ]));
    }

    /** Ouvre le rapport du jour, avec tout ce qui peut être pré-rempli. */
    public function ouvrir(Request $requete): JsonResponse
    {
        $this->authorize('create', RapportJournalier::class);

        $date = $requete->string('date')->toString() ?: now()->toDateString();
        $volontaire = $requete->user()->volontaire;

        abort_unless($volontaire !== null, 403);

        try {
            $rapport = $this->preparation->ouvrir($volontaire, $date);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $message = $rapport->wasRecentlyCreated
            ? "Rapport du {$date} ouvert. Vérifiez les informations pré-remplies, elles viennent "
                .'de votre affectation.'
            : "Rapport du {$date} déjà ouvert : voici son état.";

        return ReponseApi::succes($message, $rapport);
    }

    public function update(SaisirRapportRequest $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('update', $rapport);

        try {
            $rapport = $this->saisie->enregistrer($rapport, $requete->validated(), $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Rapport enregistré.', $rapport);
    }

    /**
     * Recalcule les chiffres remontés du niveau inférieur — utile quand un
     * rapport d'un agent est visé après l'ouverture du sien.
     */
    public function rafraichir(Request $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('update', $rapport);

        try {
            $rapport = $this->preparation->rafraichir($rapport);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            'Chiffres remis à jour depuis les rapports de vos agents déjà visés.',
            $rapport
        );
    }

    public function soumettre(Request $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('soumettre', $rapport);

        $position = $this->position($requete);

        try {
            $rapport = $this->cycleDeVie->soumettre($rapport, $requete->user(), $position);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $viseur = $rapport->superieur?->user?->nomComplet();

        return ReponseApi::succes(
            $viseur
                ? "Rapport signé et transmis à {$viseur} pour visa. Il n'est plus modifiable."
                : "Rapport signé et transmis pour visa. Il n'est plus modifiable.",
            $rapport
        );
    }

    /** Ce que l'utilisateur a à viser, en attente. */
    public function aViser(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', RapportJournalier::class);

        $rapports = RapportJournalier::query()
            ->aViserPar($requete->user())
            ->with([
                'auteur:id,user_id,matricule,categorie', 'auteur.user:id,nom,prenoms',
                'site:id,code,nom', 'centre:id,code,nom',
                'activitesAopk', 'productionOpk', 'evolution',
            ])
            ->orderBy('soumis_le')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes(
            $rapports->total() === 0
                ? 'Aucun rapport en attente de votre visa.'
                : "{$rapports->total()} rapports attendent votre visa.",
            $rapports
        );
    }

    public function viser(Request $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('viser', $rapport);

        $valide = $requete->validate(['commentaire' => ['nullable', 'string', 'max:2000']]);

        try {
            $rapport = $this->cycleDeVie->viser(
                $rapport,
                $requete->user(),
                $valide['commentaire'] ?? null,
                $this->position($requete)
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            'Rapport visé. Ses chiffres remontent maintenant au niveau supérieur.',
            $rapport
        );
    }

    public function rejeter(Request $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('rejeter', $rapport);

        $valide = $requete->validate(
            ['motif' => ['required', 'string', 'max:2000']],
            ['motif.required' => 'Indiquez ce qui doit être corrigé : '
                .'un rejet sans motif est inexploitable.']
        );

        try {
            $rapport = $this->cycleDeVie->rejeter(
                $rapport, $requete->user(), $valide['motif'], $this->position($requete)
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            'Rapport renvoyé à son auteur pour correction.',
            $rapport
        );
    }

    public function cloturer(Request $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('cloturer', $rapport);

        try {
            $rapport = $this->cycleDeVie->cloturer($rapport, $requete->user(), $this->position($requete));
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Rapport clos.', $rapport);
    }

    /** Correction d'une valeur pré-remplie, avec motif et trace. */
    public function corriger(Request $requete, RapportJournalier $rapport): JsonResponse
    {
        $this->authorize('corriger', $rapport);

        $valide = $requete->validate([
            'champ' => ['required', 'string', 'max:80'],
            'valeur_origine' => ['nullable'],
            'valeur_corrigee' => ['nullable'],
            'motif' => ['required', 'string', 'max:2000'],
        ], [
            'champ.required' => 'Précisez quel chiffre est corrigé.',
            'motif.required' => 'Indiquez pourquoi vous corrigez ce chiffre : '
                .'il vient des rapports de vos agents.',
        ]);

        try {
            $correction = $this->cycleDeVie->corriger(
                $rapport,
                $valide['champ'],
                $valide['valeur_origine'] ?? null,
                $valide['valeur_corrigee'] ?? null,
                $valide['motif'],
                $requete->user()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Correction enregistrée et tracée.', $correction);
    }

    /** Le rapport au format du canevas, visas et droits de réponse compris. */
    public function exporterPdf(Request $requete, RapportJournalier $rapport)
    {
        $this->authorize('exporter', $rapport);

        $rapport->load([
            'auteur:id,user_id,matricule', 'auteur.user:id,nom,prenoms',
            'superieur:id,user_id,matricule', 'superieur.user:id,nom,prenoms',
            'site', 'centre.commune.province', 'region',
            'activitesAopk', 'productionOpk', 'evolution', 'qualite', 'logistique',
            'difficultes', 'pointsAmelioration',
            'suiviAgents.volontaire:id,user_id,matricule', 'suiviAgents.volontaire.user:id,nom,prenoms',
            'suiviAgents.reponses', 'visas.user:id,nom,prenoms', 'corrections',
        ]);

        $nom = 'rapport-'.$rapport->type->value.'-'
            .$rapport->date_rapport->format('Y-m-d').'-'
            .($rapport->auteur?->matricule ?? $rapport->id).'.pdf';

        return $this->export->pdf($rapport, $requete->user())->download($nom);
    }

    /** Synthèse tableur d'une sélection : une ligne par rapport. */
    public function exporterCsv(Request $requete): StreamedResponse
    {
        $this->authorize('viewAny', RapportJournalier::class);
        abort_unless($requete->user()->can('rapports.exporter'), 403);

        $rapports = RapportJournalier::query()
            ->perimetre($requete->user())
            ->with([
                'auteur:id,user_id,matricule', 'auteur.user:id,nom,prenoms',
                'superieur:id,user_id,matricule', 'superieur.user:id,nom,prenoms',
                'site:id,code', 'centre:id,code', 'region:id,nom',
                'activitesAopk', 'productionOpk', 'qualite', 'difficultes', 'corrections',
            ])
            ->when($requete->filled('type'), fn ($q) => $q->where('type', $requete->string('type')))
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->when($requete->filled('centre_id'),
                fn ($q) => $q->where('centre_id', $requete->integer('centre_id')))
            ->when($requete->filled('du'), fn ($q) => $q->whereDate('date_rapport', '>=', $requete->string('du')))
            ->when($requete->filled('au'), fn ($q) => $q->whereDate('date_rapport', '<=', $requete->string('au')))
            ->orderBy('date_rapport')
            ->orderBy('centre_id')
            ->get();

        $intitule = $requete->filled('du') || $requete->filled('au')
            ? 'Période du '.($requete->string('du')->toString() ?: 'début')
                .' au '.($requete->string('au')->toString() ?: "aujourd'hui")
            : 'Ensemble des rapports de mon périmètre';

        return response()->streamDownload(
            $this->export->csv($rapports, $requete->user(), $intitule),
            'rapports-'.now()->format('Y-m-d-Hi').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    /** @return array{latitude?: float|null, longitude?: float|null, horodatage_telephone?: string|null} */
    private function position(Request $requete): array
    {
        return $requete->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'horodatage_telephone' => ['nullable', 'date'],
        ]);
    }
}
