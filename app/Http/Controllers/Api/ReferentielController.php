<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Region;
use App\Models\Volontaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consultation du référentiel, filtrée CÔTÉ SERVEUR.
 *
 * Ces routes sont le socle de lecture des tâches suivantes, et elles
 * démontrent la règle non négociable du cadrage : sur CHAQUE requête, les deux
 * mécanismes sont appliqués, l'un après l'autre —
 *
 *   1. le DROIT     : $this->authorize('viewAny', Modele::class) → la Policy
 *   2. le PÉRIMÈTRE : ->perimetre($requete->user())              → le scope
 *
 * Retirer l'un des deux suffit à ouvrir une fuite : un superviseur a bien le
 * droit de consulter des centres, mais pas TOUS les centres.
 */
class ReferentielController extends Controller
{
    public function regions(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Region::class);

        $regions = Region::query()
            ->perimetre($requete->user())
            ->orderBy('nom')
            ->get(['id', 'code', 'nom', 'population_totale', 'nombre_sites_alloues']);

        return ReponseApi::succes('Régions récupérées.', $regions);
    }

    /**
     * Les communes, dans le périmètre de l'utilisateur — pour les formulaires.
     *
     * Sans cette liste, créer un centre obligerait à saisir l'identifiant
     * numérique d'une commune : c'est l'erreur qu'on ne veut pas voir sur un
     * code de centre définitif.
     */
    public function communes(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Commune::class);

        $communes =Commune::query()
            ->perimetre($requete->user())
            ->when($requete->filled('region_id'), fn ($q) => $q->where('region_id', $requete->integer('region_id')))
            ->orderBy('nom')
            ->get(['id', 'code', 'nom', 'region_id', 'type']);

        return ReponseApi::succes('Communes récupérées.', $communes);
    }

    /**
     * Les localités d'UNE commune. La commune est obligatoire : la liste
     * complète compte plus de 7 000 localités, inutilisable dans un formulaire.
     */
    public function localites(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Localite::class);

        $valide =$requete->validate(
            ['commune_id' => ['required', 'integer', 'exists:communes,id']],
            ['commune_id.required' => 'Précisez la commune : la liste complète compte plus de 7 000 localités.']
        );

        // Le périmètre vaut aussi pour la lecture d'une liste de formulaire.
        abort_unless(
            Commune::query()->perimetre($requete->user())->whereKey($valide['commune_id'])->exists(),
            403
        );

        $localites = Localite::query()
            ->where('commune_id', $valide['commune_id'])
            ->orderBy('nom')
            ->get(['id', 'nom', 'type_localite', 'population_totale', 'quota_sites']);

        return ReponseApi::succes('Localités récupérées.', $localites);
    }

    public function volontaires(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Volontaire::class);

        $volontaires = Volontaire::query()
            ->perimetre($requete->user())
            ->with(['user:id,nom,prenoms,telephone,statut_compte', 'localite:id,nom'])
            ->when($requete->filled('categorie'), fn ($q) => $q->where('categorie', $requete->string('categorie')))
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            // Sans recherche, ajuster une affectation obligerait à parcourir des
            // pages entières pour retrouver UN agent. On cherche par matricule,
            // par nom et par téléphone : c'est par l'un des trois qu'on le
            // désigne sur le terrain.
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->where(fn ($r) => $r
                    ->where('matricule', 'like', "%{$recherche}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('prenoms', 'like', "%{$recherche}%")
                        ->orWhere('telephone', 'like', "%{$recherche}%")));
            })
            ->orderBy('matricule')
            ->paginate(perPage: min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Volontaires récupérés.', $volontaires);
    }
}
