<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Referentiel\SiteRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Centre;
use App\Models\Localite;
use App\Models\Site;
use App\Services\Referentiel\ServiceCentresEtSites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sites d'enregistrement.
 *
 * Un site porte DEUX rattachements (cadrage v2, section 2) : sa LOCALITÉ, dont
 * la population sert de dénominateur au taux de couverture, et son CENTRE, qui
 * donne la chaîne de supervision. Ni l'un ni l'autre ne change après création —
 * le code du site dérive du centre, et un code ne change jamais.
 */
class SitesController extends Controller
{
    public function __construct(private readonly ServiceCentresEtSites $service)
    {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Site::class);

        $sites = Site::query()
            ->perimetre($requete->user())
            ->with(['centre:id,code,nom', 'localite:id,nom,population_totale,type_localite'])
            ->when($requete->filled('centre_id'),
                fn ($q) => $q->where('centre_id', $requete->integer('centre_id')))
            ->when($requete->filled('localite_id'),
                fn ($q) => $q->where('localite_id', $requete->integer('localite_id')))
            ->when($requete->filled('region_id'),
                fn ($q) => $q->where('region_id', $requete->integer('region_id')))
            /*
             * LA COMMUNE D'UN SITE EST CELLE DE SA LOCALITÉ, pas celle de son
             * centre. La localité porte son rattachement réel — et la population
             * qui sert de dénominateur au taux de couverture ; le centre porte la
             * chaîne de supervision. Les deux coïncident aujourd'hui, mais filtrer
             * par le mauvais rendrait des résultats faux et silencieux le jour où
             * ils divergeraient.
             */
            ->when($requete->filled('commune_id'), fn ($q) => $q->whereHas(
                'localite',
                fn ($localite) => $localite->where('commune_id', $requete->integer('commune_id'))
            ))
            ->when($requete->filled('statut'),
                fn ($q) => $q->where('statut', $requete->string('statut')))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));
                $q->where(fn ($r) => $r->where('code', 'like', "%{$recherche}%")
                    ->orWhere('nom', 'like', "%{$recherche}%"));
            })
            ->orderBy('code')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Sites récupérés.', $sites);
    }

    public function show(Request $requete, Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        return ReponseApi::succes('Site récupéré.', $site->load([
            'centre:id,code,nom,commune_id', 'centre.commune:id,nom',
            'localite:id,nom,population_totale,type_localite',
        ]));
    }

    public function store(SiteRequest $requete): JsonResponse
    {
        $this->authorize('create', Site::class);

        $centre = Centre::query()->findOrFail($requete->validated('centre_id'));

        abort_unless(
            Centre::query()->perimetre($requete->user())->whereKey($centre->id)->exists(),
            403
        );

        $localite = Localite::query()->findOrFail($requete->validated('localite_id'));

        $site = $this->service->creerSite($centre, $localite, $requete->validated(), $requete->user());

        return ReponseApi::succes(
            "Site créé sous le code {$site->code}. Ce code est définitif.",
            $site->load(['centre:id,code,nom', 'localite:id,nom']),
            201
        );
    }

    public function update(SiteRequest $requete, Site $site): JsonResponse
    {
        $this->authorize('update', $site);

        $site->update($requete->safe()->except(['code', 'centre_id']));

        return ReponseApi::succes('Site mis à jour.', $site->fresh(['centre', 'localite']));
    }
}
