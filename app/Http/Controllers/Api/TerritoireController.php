<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\Arrondissement;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Services\Referentiel\ServiceTerritoire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * LE RÉFÉRENTIEL TERRITORIAL : consultation et correction.
 *
 * CONSULTER : régions › provinces › communes › localités, dans le périmètre
 * de chacun — un chef d'antenne parcourt sa région.
 *
 * CORRIGER : administration nationale, droit referentiel.modifier_territoire.
 * Dérogation au cadrage décidée par le client (voir ServiceTerritoire).
 * Toute modification qui touche une population ou un nombre de sites accepte
 * `simulation=1` : rien n'est enregistré, la réponse dit quels quotas
 * changeraient. L'écran s'en sert pour montrer les effets AVANT de valider.
 *
 * Les CODES ne sont jamais modifiables : ils figurent sur des documents.
 */
class TerritoireController extends Controller
{
    public function __construct(private readonly ServiceTerritoire $service)
    {
    }

    // ------------------------------------------------------------------
    // Consultation
    // ------------------------------------------------------------------

    public function regions(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Region::class);

        $regions = Region::query()
            ->perimetre($requete->user())
            // select() et non get([...]) : withCount pose déjà ses colonnes, et
            // get() ignorerait la liste — le contour GeoJSON partirait avec.
            ->select(['id', 'code', 'nom', 'population_hommes', 'population_femmes', 'population_totale', 'nombre_sites_alloues'])
            ->withCount(['provinces', 'communes', 'localites', 'centres', 'sites'])
            ->withSum('localites as somme_quotas', 'quota_sites')
            ->orderBy('nom')
            ->get();

        return ReponseApi::succes('Régions récupérées.', $regions);
    }

    public function provinces(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Province::class);

        $provinces = Province::query()
            ->perimetre($requete->user())
            ->select(['id', 'region_id', 'code', 'nom', 'population_hommes', 'population_femmes', 'population_totale'])
            ->withCount('communes')
            ->when($requete->filled('region_id'),
                fn ($q) => $q->where('region_id', $requete->integer('region_id')))
            ->orderBy('nom')
            ->get();

        return ReponseApi::succes('Provinces récupérées.', $provinces);
    }

    public function communes(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Commune::class);

        $communes = Commune::query()
            ->perimetre($requete->user())
            ->with(['province:id,nom', 'region:id,code,nom'])
            ->withCount(['localites', 'centres'])
            ->when($requete->filled('region_id'),
                fn ($q) => $q->where('region_id', $requete->integer('region_id')))
            ->when($requete->filled('province_id'),
                fn ($q) => $q->where('province_id', $requete->integer('province_id')))
            ->when($requete->boolean('avec_ecart'),
                fn ($q) => $q->whereColumn('population_localites', '!=', 'population_totale'))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));
                $q->where(fn ($r) => $r->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('code', 'like', "%{$recherche}%"));
            })
            ->orderBy('nom')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Communes récupérées.', $communes);
    }

    public function localites(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Localite::class);

        $localites = Localite::query()
            ->perimetre($requete->user())
            ->with(['commune:id,nom,province_id', 'arrondissement:id,nom'])
            ->withCount('sites')
            ->when($requete->filled('region_id'),
                fn ($q) => $q->where('region_id', $requete->integer('region_id')))
            ->when($requete->filled('commune_id'),
                fn ($q) => $q->where('commune_id', $requete->integer('commune_id')))
            ->when($requete->filled('type_localite'),
                fn ($q) => $q->where('type_localite', $requete->string('type_localite')))
            ->when($requete->boolean('sans_quota'), fn ($q) => $q->where('quota_sites', 0))
            // Moins de sites créés que le quota : ce qui reste à ouvrir.
            ->when($requete->boolean('quota_non_atteint'),
                fn ($q) => $q->whereRaw('(select count(*) from sites where sites.localite_id = localites.id) < localites.quota_sites'))
            ->when($requete->filled('recherche'),
                fn ($q) => $q->where('nom', 'like', '%'.trim((string) $requete->string('recherche')).'%'))
            ->orderBy('nom')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Localités récupérées.', $localites);
    }

    /** Lecture seule : aucun arrondissement ne se crée ici (décision du client). */
    public function arrondissements(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Arrondissement::class);

        $arrondissements = Arrondissement::query()
            ->perimetre($requete->user())
            ->withCount('localites')
            ->when($requete->filled('commune_id'),
                fn ($q) => $q->where('commune_id', $requete->integer('commune_id')))
            ->orderBy('numero')
            ->get();

        return ReponseApi::succes('Arrondissements récupérés.', $arrondissements);
    }

    // ------------------------------------------------------------------
    // Correction
    // ------------------------------------------------------------------

    public function modifierRegion(Request $requete, Region $region): JsonResponse
    {
        $this->authorize('update', $region);

        $valide = $requete->validate([
            'nom' => ['sometimes', 'string', 'max:80', Rule::unique('regions', 'nom')->ignore($region->id)],
            'nombre_sites_alloues' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'code' => ['prohibited'],
            'simulation' => ['sometimes', 'boolean'],
        ], $this->messages());

        $resultat = $this->service->modifierRegion(
            $region,
            collect($valide)->except('simulation')->all(),
            $requete->user(),
            $requete->boolean('simulation')
        );

        return $this->repondre($resultat, "Région {$resultat['objet']->nom} enregistrée.");
    }

    public function modifierProvince(Request $requete, Province $province): JsonResponse
    {
        $this->authorize('update', $province);

        $valide = $requete->validate([
            'nom' => [
                'required', 'string', 'max:80',
                Rule::unique('provinces', 'nom')->where('region_id', $province->region_id)->ignore($province->id),
            ],
            'code' => ['prohibited'],
        ], $this->messages());

        $province = $this->service->modifierProvince($province, $valide, $requete->user());

        return ReponseApi::succes("Province {$province->nom} enregistrée.", $province);
    }

    public function modifierCommune(Request $requete, Commune $commune): JsonResponse
    {
        $this->authorize('update', $commune);

        $valide = $requete->validate([
            'nom' => [
                'sometimes', 'string', 'max:80',
                Rule::unique('communes', 'nom')->where('province_id', $commune->province_id)->ignore($commune->id),
            ],
            'type' => ['sometimes', Rule::in(['rurale', 'urbaine'])],
            'population_hommes' => ['sometimes', 'integer', 'min:0'],
            'population_femmes' => ['sometimes', 'integer', 'min:0'],
            'est_zone_defis_securitaires' => ['sometimes', 'boolean'],
            'code' => ['prohibited'],
        ], $this->messages());

        $commune = $this->service->modifierCommune($commune, $valide, $requete->user());

        return ReponseApi::succes("Commune {$commune->nom} enregistrée.", $commune);
    }

    public function modifierLocalite(Request $requete, Localite $localite): JsonResponse
    {
        $this->authorize('update', $localite);

        $valide = $requete->validate([
            ...$this->reglesLocalite($localite->commune_id, $localite->id, creation: false),
            'commune_id' => ['prohibited'],
            'simulation' => ['sometimes', 'boolean'],
        ], $this->messages());

        $resultat = $this->service->modifierLocalite(
            $localite,
            collect($valide)->except('simulation')->all(),
            $requete->user(),
            $requete->boolean('simulation')
        );

        return $this->repondre($resultat, "Localité {$resultat['objet']->nom} enregistrée.");
    }

    public function ajouterLocalite(Request $requete): JsonResponse
    {
        $this->authorize('create', Localite::class);

        $requete->validate(['commune_id' => ['required', 'integer', 'exists:communes,id']], [
            'commune_id.required' => 'Choisissez la commune de la nouvelle localité.',
        ]);

        $commune = Commune::query()->findOrFail($requete->integer('commune_id'));

        abort_unless(Commune::query()->perimetre($requete->user())->whereKey($commune->id)->exists(), 403);

        $valide = $requete->validate([
            ...$this->reglesLocalite($commune->id, null, creation: true),
            'simulation' => ['sometimes', 'boolean'],
        ], $this->messages());

        $resultat = $this->service->ajouterLocalite(
            $commune,
            collect($valide)->except('simulation')->all(),
            $requete->user(),
            $requete->boolean('simulation')
        );

        return $this->repondre(
            $resultat,
            "Localité {$resultat['objet']->nom} ajoutée à {$commune->nom}.",
            $resultat['simulation'] ? 200 : 201
        );
    }

    private function reglesLocalite(int $communeId, ?int $ignorer, bool $creation): array
    {
        $exige = $creation ? 'required' : 'sometimes';

        return [
            'nom' => [
                $exige, 'string', 'max:120',
                Rule::unique('localites', 'nom')->where('commune_id', $communeId)->ignore($ignorer),
            ],
            'type_localite' => [$exige, Rule::in(['village', 'secteur', 'quartier'])],
            'population_hommes' => [$exige, 'integer', 'min:0'],
            'population_femmes' => [$exige, 'integer', 'min:0'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    private function repondre(array $resultat, string $message, int $statut = 200): JsonResponse
    {
        $nombre = count($resultat['changements']);
        $alertes = count($resultat['alertes']);

        if ($resultat['simulation']) {
            $message = 'Aperçu : rien n\'est encore enregistré. '
                .($nombre === 0
                    ? 'Aucun quota de site ne changerait.'
                    : "{$nombre} localités verraient leur quota de sites changer.");
        } elseif ($nombre > 0) {
            $message .= " Les quotas de {$nombre} localités ont été recalculés.";
        }

        if ($alertes > 0) {
            $message .= " Attention : {$alertes} localités auraient plus de sites ouverts que leur nouveau quota.";
        }

        return ReponseApi::succes($message, [
            'objet' => $resultat['objet'],
            'simulation' => $resultat['simulation'],
            'changements' => $resultat['changements'],
            'alertes' => $resultat['alertes'],
        ], $statut);
    }

    private function messages(): array
    {
        return [
            'code.prohibited' => 'Un code ne change jamais : il figure sur des documents imprimés.',
            'commune_id.prohibited' => 'Une localité ne change pas de commune : ses sites et ses volontaires y sont rattachés.',
            'nom.required' => 'Indiquez le nom.',
            'nom.unique' => 'Ce nom existe déjà à cet endroit du référentiel.',
            'type_localite.required' => 'Précisez s\'il s\'agit d\'un village, d\'un secteur ou d\'un quartier.',
            'type_localite.in' => 'Le type doit être village, secteur ou quartier.',
            'population_hommes.required' => 'Indiquez la population masculine (0 si inconnue).',
            'population_femmes.required' => 'Indiquez la population féminine (0 si inconnue).',
            'population_hommes.min' => 'Une population ne peut pas être négative.',
            'population_femmes.min' => 'Une population ne peut pas être négative.',
            'nombre_sites_alloues.min' => 'Une région dispose d\'au moins un site.',
        ];
    }
}
