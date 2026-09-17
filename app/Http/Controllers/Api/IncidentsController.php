<?php

namespace App\Http\Controllers\Api;

use App\Enums\GraviteIncident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\DeclarerIncidentRequest;
use App\Http\Responses\ReponseApi;
use App\Models\DestinataireIncident;
use App\Models\ImpactIncident;
use App\Models\Incident;
use App\Models\MesureIncident;
use App\Models\NatureIncident;
use App\Services\Incidents\ServiceDeclarationIncident;
use App\Services\Incidents\ServiceTraitementIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module incidents — canevas client, sections A à J (cadrage, section 10).
 *
 *   GET  /incidents/canevas          les listes à cases multiples, pour le formulaire
 *   POST /incidents                  déclaration
 *   GET  /incidents                  liste, dans mon périmètre
 *   GET  /incidents/en-retard        ceux que personne n'a pris en charge à temps
 *   GET  /incidents/{i}              fiche complète avec son historique
 *   POST /incidents/{i}/prendre-en-charge
 *   POST /incidents/{i}/avancer      changement de statut, avec mesures correctives
 *   POST /incidents/{i}/commenter
 *   POST /incidents/{i}/preuves
 *   POST /incidents/{i}/cloturer     rapport de clôture obligatoire
 */
class IncidentsController extends Controller
{
    public function __construct(
        private readonly ServiceDeclarationIncident $declaration,
        private readonly ServiceTraitementIncident $traitement,
    ) {}

    /**
     * Les quatre listes du canevas et l'échelle de gravité, servies au
     * formulaire. Le mobile les met en cache : hors ligne, il doit pouvoir
     * afficher les libellés sans réseau.
     */
    public function canevas(): JsonResponse
    {
        $actives = fn ($modele) => $modele::query()
            ->where('actif', true)
            ->orderBy('ordre')
            ->orderBy('libelle')
            ->get(['id', 'code', 'libelle', 'libelle_moore', 'libelle_dioula']);

        return ReponseApi::succes("Canevas de déclaration d'incident.", [
            'natures' => $actives(NatureIncident::class),
            'impacts' => $actives(ImpactIncident::class),
            'mesures' => $actives(MesureIncident::class),
            'destinataires' => $actives(DestinataireIncident::class),
            'gravites' => array_map(fn (GraviteIncident $g) => [
                'valeur' => $g->value,
                'libelle' => $g->libelle(),
                'description' => $g->description(),
                'couleur' => $g->couleur(),
            ], GraviteIncident::cases()),
            'preuves' => ['photo', 'video', 'document', 'capture', 'aucun'],
            'types_lieu' => ['site', 'trajet_aller', 'trajet_retour', 'autre'],
        ]);
    }

    public function store(DeclarerIncidentRequest $requete): JsonResponse
    {
        $this->authorize('create', Incident::class);

        $incident = $this->declaration->declarer($requete->user(), $requete->validated());

        return ReponseApi::succes(
            "Incident enregistré sous le numéro {$incident->numero}. "
            .'Les responsables concernés viennent d\'être prévenus.',
            $incident,
            201
        );
    }

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Incident::class);

        $incidents = Incident::query()
            ->perimetre($requete->user())
            ->with([
                'declarant:id,nom,prenoms', 'responsable:id,nom,prenoms',
                'site:id,code,nom', 'centre:id,code,nom', 'region:id,code,nom',
                'natures:id,code,libelle',
            ])
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->when($requete->filled('gravite'),
                fn ($q) => $q->where('gravite', $requete->integer('gravite')))
            ->when($requete->boolean('ouverts'), fn ($q) => $q->ouverts())
            ->when($requete->filled('centre_id'),
                fn ($q) => $q->where('centre_id', $requete->integer('centre_id')))
            ->when($requete->filled('nature'), fn ($q) => $q->whereHas(
                'natures', fn ($n) => $n->where('code', $requete->string('nature'))
            ))
            ->when($requete->filled('du'), fn ($q) => $q->whereDate('declare_le', '>=', $requete->string('du')))
            ->when($requete->filled('au'), fn ($q) => $q->whereDate('declare_le', '<=', $requete->string('au')))
            // Le plus grave et le plus récent d'abord : c'est l'ordre dans
            // lequel on veut les lire quand il y en a beaucoup.
            ->orderByDesc('gravite')
            ->orderByDesc('declare_le')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Incidents récupérés.', $incidents);
    }

    /**
     * CE QUE PERSONNE N'A PRIS EN CHARGE À TEMPS — la liste qui compte pour un
     * responsable qui arrive le matin.
     */
    public function enRetard(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Incident::class);

        $incidents = Incident::query()
            ->perimetre($requete->user())
            ->where('statut', 'nouveau')
            ->where(fn ($q) => $q->where('echeance_escalade', '<=', now())
                ->orWhere('niveau_escalade', '>', 0))
            ->with([
                'declarant:id,nom,prenoms', 'site:id,code,nom', 'centre:id,code,nom',
                'natures:id,code,libelle',
            ])
            ->orderByDesc('niveau_escalade')
            ->orderByDesc('gravite')
            ->orderBy('declare_le')
            ->get();

        return ReponseApi::succes(
            $incidents->isEmpty()
                ? 'Aucun incident en attente de prise en charge dans votre périmètre.'
                : "{$incidents->count()} incidents attendent une prise en charge.",
            $incidents
        );
    }

    public function show(Request $requete, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        return ReponseApi::succes('Fiche d\'incident.', $incident->load([
            'declarant:id,nom,prenoms,telephone', 'responsable:id,nom,prenoms',
            'site:id,code,nom', 'centre:id,code,nom', 'region:id,code,nom',
            'natures', 'impacts', 'mesures', 'personnesInformees',
            'actions.user:id,nom,prenoms', 'alertes:id,code,incident_id,titre,niveau,publiee_le',
            'piecesJointes:id,uuid_fichier,incident_id,role,type_mime,taille_octets,largeur,hauteur,deposee_le,horodatage_telephone',
        ]));
    }

    public function prendreEnCharge(Request $requete, Incident $incident): JsonResponse
    {
        $this->authorize('traiter', $incident);

        $valide = $requete->validate(['commentaire' => ['nullable', 'string', 'max:2000']]);

        try {
            $incident = $this->traitement->prendreEnCharge(
                $incident, $requete->user(), $valide['commentaire'] ?? null
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            'Incident pris en charge. Il ne remontera plus automatiquement.',
            $incident
        );
    }

    public function avancer(Request $requete, Incident $incident): JsonResponse
    {
        $this->authorize('traiter', $incident);

        $valide = $requete->validate([
            'statut' => ['required', Rule::in(['en_cours', 'resolu'])],
            'mesures_correctives' => ['nullable', 'string', 'max:5000'],
            'commentaire' => ['nullable', 'string', 'max:2000'],
        ], [
            'statut.required' => 'Indiquez le nouvel état du traitement.',
        ]);

        try {
            $incident = $this->traitement->avancer(
                $incident, $requete->user(), $valide['statut'], $valide
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Traitement mis à jour.', $incident);
    }

    public function commenter(Request $requete, Incident $incident): JsonResponse
    {
        $this->authorize('traiter', $incident);

        $valide = $requete->validate([
            'commentaire' => ['required', 'string', 'max:2000'],
        ], ['commentaire.required' => 'Écrivez votre commentaire.']);

        $action = $this->traitement->commenter($incident, $requete->user(), $valide['commentaire']);

        return ReponseApi::succes('Commentaire ajouté à la fiche.', $action, 201);
    }

    /** Ajout de preuves après coup : le réseau manquait au moment des faits. */
    public function ajouterPreuves(Request $requete, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $valide = $requete->validate([
            'preuves' => ['required', 'array', 'min:1'],
            'preuves.*' => [Rule::in(['photo', 'video', 'document', 'capture'])],
        ]);

        $incident = $this->traitement->ajouterPreuves(
            $incident, $requete->user(), $valide['preuves']
        );

        return ReponseApi::succes('Preuves ajoutées à la fiche.', $incident);
    }

    public function cloturer(Request $requete, Incident $incident): JsonResponse
    {
        $this->authorize('cloturer', $incident);

        $valide = $requete->validate([
            'rapport_cloture' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'rapport_cloture.required' => 'Écrivez ce qui a été fait avant de clore la fiche.',
            'rapport_cloture.min' => 'Le rapport de clôture est trop court pour être utile plus tard.',
        ]);

        try {
            $incident = $this->traitement->cloturer(
                $incident, $requete->user(), $valide['rapport_cloture']
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Incident clos.', $incident);
    }
}
