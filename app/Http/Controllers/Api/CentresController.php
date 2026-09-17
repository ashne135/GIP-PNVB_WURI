<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Referentiel\CentreRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Centre;
use App\Models\Commune;
use App\Services\Referentiel\ServiceCentresEtSites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centres d'enregistrement : consultation et gestion.
 *
 * Comme partout, DEUX mécanismes sur chaque requête (cadrage, section 5) :
 * la Policy pour le DROIT, le scope perimetre() pour les DONNÉES. Un chef
 * d'antenne peut modifier un centre — mais seulement dans sa région.
 *
 * Il n'y a pas de destroy : UN CENTRE NE SE SUPPRIME PAS. Il porte l'historique
 * des rapports, des présences et des affectations. Il se ferme.
 */
class CentresController extends Controller
{
    public function __construct(private readonly ServiceCentresEtSites $service)
    {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Centre::class);

        $centres = Centre::query()
            ->perimetre($requete->user())
            ->with(['commune:id,nom,code', 'region:id,code,nom'])
            ->withCount('sites')
            ->when($requete->filled('region_id'),
                fn ($q) => $q->where('region_id', $requete->integer('region_id')))
            ->when($requete->filled('commune_id'),
                fn ($q) => $q->where('commune_id', $requete->integer('commune_id')))
            ->when($requete->filled('statut'),
                fn ($q) => $q->where('statut', $requete->string('statut')))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));
                $q->where(fn ($r) => $r->where('code', 'like', "%{$recherche}%")
                    ->orWhere('nom', 'like', "%{$recherche}%"));
            })
            ->orderBy('code')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Centres récupérés.', $centres);
    }

    public function show(Request $requete, Centre $centre): JsonResponse
    {
        $this->authorize('view', $centre);

        return ReponseApi::succes('Centre récupéré.', $centre->load([
            'commune:id,nom,code', 'region:id,code,nom',
            'sites:id,centre_id,localite_id,code,nom,ordre_tournee,statut',
            'sites.localite:id,nom,population_totale',
        ]));
    }

    /** Le code est généré par le serveur : il n'est jamais fourni par le client. */
    public function store(CentreRequest $requete): JsonResponse
    {
        $this->authorize('create', Centre::class);

        $commune = Commune::query()->findOrFail($requete->validated('commune_id'));

        // Le périmètre vaut aussi pour la création : un chef d'antenne ne crée
        // pas un centre dans la région d'à côté.
        abort_unless(
            Commune::query()->perimetre($requete->user())->whereKey($commune->id)->exists(),
            403
        );

        $centre = $this->service->creerCentre($commune, $requete->validated(), $requete->user());

        return ReponseApi::succes(
            "Centre créé sous le code {$centre->code}. Ce code est définitif : il apparaîtra sur les documents.",
            $centre->load('commune:id,nom,code'),
            201
        );
    }

    public function update(CentreRequest $requete, Centre $centre): JsonResponse
    {
        $this->authorize('update', $centre);

        // Ni le code ni la commune ne bougent : validated() les exclut déjà,
        // mais on le dit explicitement pour qui relit.
        $centre->update($requete->safe()->except(['code', 'commune_id']));

        return ReponseApi::succes('Centre mis à jour.', $centre->fresh('commune'));
    }

    /**
     * Fermeture, et non suppression. Ferme aussi les sites du centre : un site
     * ouvert dans un centre fermé n'aurait plus personne pour le superviser.
     */
    public function fermer(Request $requete, Centre $centre): JsonResponse
    {
        $this->authorize('update', $centre);

        $valide = $requete->validate(
            ['motif' => ['required', 'string', 'max:255']],
            ['motif.required' => 'Indiquez pourquoi ce centre est fermé.']
        );

        $centre = $this->service->fermerCentre($centre, $valide['motif'], $requete->user());

        return ReponseApi::succes(
            "Centre {$centre->code} fermé, ainsi que ses sites. "
            .'Son historique reste consultable.',
            $centre
        );
    }
}
