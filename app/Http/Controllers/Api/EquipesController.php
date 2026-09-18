<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutAffectation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affectations\DeplacerAffectationRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Services\Affectation\ServiceDeplacementAffectation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * LES ÉQUIPES DÉPLOYÉES — qui travaille où, aujourd'hui.
 *
 * Jusqu'ici, les affectations n'étaient visibles qu'à l'état de PROPOSITION,
 * avant validation. Une fois la vague validée elles passaient « active » et
 * disparaissaient de tous les écrans : on ne pouvait plus répondre à la
 * question la plus simple du dispositif — qui est où.
 *
 * DEUX SUBTILITÉS DU MODÈLE, qu'un tableau naïf afficherait de travers :
 *
 *  1. LE SUPERVISEUR N'A PAS DE CENTRE. Son affectation porte une unité de
 *     supervision — deux centres — et `centre_id` reste nul. Ses centres se
 *     lisent donc sur l'unité, jamais sur l'affectation.
 *
 *  2. LE SITE DÉPEND DU JOUR. L'affectation rattache à un CENTRE ; le site où
 *     un opérateur travaille vient de la tournée de son kit ce jour-là, et
 *     celui d'un A-OPK de sa localité. Le superviseur, lui, couvre deux
 *     centres : aucun site unique ne lui correspond.
 */
class EquipesController extends Controller
{
    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Affectation::class);

        $date = $requete->filled('date')
            ? $requete->string('date')->toString()
            : now()->toDateString();

        $equipes = Affectation::query()
            ->perimetre($requete->user())
            ->where('statut', StatutAffectation::Active->value)
            ->with([
                'volontaire:id,user_id,matricule,categorie,localite_id',
                'volontaire.user:id,nom,prenoms,telephone',
                'volontaire.localite:id,nom',
                'centre:id,code,nom,commune_id,region_id',
                'centre.commune:id,nom',
                'centre.region:id,code,nom',
                'uniteSupervision:id,centre_principal_id,centre_secondaire_id',
                'uniteSupervision.centrePrincipal:id,code,nom,commune_id',
                'uniteSupervision.centrePrincipal.commune:id,nom',
                'uniteSupervision.centreSecondaire:id,code,nom',
                'vague:id,code,libelle,region_id',
            ])
            // La RÉGION se lit sur la vague : une vague est régionale par
            // nature, et c'est le seul rattachement que TOUS les rôles
            // partagent — le superviseur n'ayant pas de centre.
            ->when($requete->filled('region_id'), fn ($q) => $q->whereHas(
                'vague',
                fn ($v) => $v->where('region_id', $requete->integer('region_id'))
            ))
            ->when($requete->filled('centre_id'), fn ($q) => $q->where('centre_id', $requete->integer('centre_id')))
            ->when($requete->filled('role'), fn ($q) => $q->where('role_terrain', $requete->string('role')))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->whereHas('volontaire', fn ($v) => $v
                    ->where('matricule', 'like', "%{$recherche}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('prenoms', 'like', "%{$recherche}%")
                        ->orWhere('telephone', 'like', "%{$recherche}%")));
            })
            ->orderBy('role_terrain')
            ->paginate(min($requete->integer('par_page', 50), 200));

        $this->attacherSiteDuJour($equipes, $date);

        return ReponseApi::succes(
            $equipes->total() === 0
                ? 'Aucun agent déployé ne correspond.'
                : "Équipes déployées au {$date}.",
            $equipes
        );
    }

    /** Déplacer UN agent déployé vers un autre centre. */
    public function deplacer(
        DeplacerAffectationRequest $requete,
        Affectation $affectation,
        ServiceDeplacementAffectation $service
    ): JsonResponse {
        $this->authorize('deplacer', $affectation);

        $destination = Centre::query()->findOrFail($requete->validated('centre_destination_id'));

        try {
            $deplacee = $service->deplacer(
                $affectation,
                $destination,
                $requete->validated('motif'),
                $requete->user()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $agent = $deplacee->volontaire?->matricule;

        return ReponseApi::succes(
            "{$agent} travaille désormais au centre {$destination->code}. Son kit l'a suivi.",
            $deplacee
        );
    }

    /**
     * Déplacer L'ÉQUIPE d'un centre — c'est-à-dire ses OPÉRATEURS.
     *
     * Le message de retour dit combien d'A-OPK sont restés sur place. Annoncer
     * « équipe déplacée » en laissant des agents derrière sans le dire serait
     * une demi-vérité, et c'est sur le terrain qu'elle se paierait.
     */
    public function deplacerEquipe(
        DeplacerAffectationRequest $requete,
        Centre $centre,
        ServiceDeplacementAffectation $service
    ): JsonResponse {
        $destination = Centre::query()->findOrFail($requete->validated('centre_destination_id'));

        // Le DROIT et le PÉRIMÈTRE sont vérifiés agent par agent : déplacer une
        // équipe n'est rien d'autre que déplacer chacun de ses opérateurs.
        $operateurs = $service->operateursDe($centre);

        foreach ($operateurs as $operateur) {
            $this->authorize('deplacer', $operateur);
        }

        try {
            $resultat = $service->deplacerEquipe(
                $centre,
                $destination,
                $requete->validated('motif'),
                $requete->user()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $nombre = count($resultat['deplaces']);
        $restes = $resultat['assistants_restes'];

        $message = "{$nombre} opérateur(s) déplacé(s) vers le centre {$destination->code}, avec leurs kits.";

        if ($restes > 0) {
            $message .= " {$restes} assistant(s) restent sur place : un A-OPK est rattaché à sa localité "
                .'et ne se redéploie pas.';
        }

        return ReponseApi::succes($message, $resultat);
    }

    /**
     * Le site de CHAQUE agent pour la journée demandée, en deux requêtes.
     *
     * Résoudre le site agent par agent produirait une requête par ligne : sur
     * une région entière, l'écran deviendrait inutilisable. On charge donc les
     * tournées et les sites d'un coup, puis on rapproche en mémoire.
     */
    private function attacherSiteDuJour(LengthAwarePaginator $equipes, string $date): void
    {
        $lignes = collect($equipes->items());

        // L'OPÉRATEUR vient avec son kit : c'est la tournée qui dit où.
        $tournees = TourneeSite::query()
            ->whereIn('affectation_operateur_id', $lignes->pluck('id')->all())
            ->couvrant($date)
            ->with('site:id,code,nom,latitude,longitude')
            ->get()
            ->keyBy('affectation_operateur_id');

        // L'A-OPK est rattaché en permanence à la localité de son site.
        $assistants = $lignes->where('role_terrain', CategorieVolontaire::Assistant);

        $sitesAssistants = $assistants->isEmpty()
            ? collect()
            : Site::query()
                ->whereIn('localite_id', $assistants->pluck('volontaire.localite_id')->filter()->all())
                ->whereIn('centre_id', $assistants->pluck('centre_id')->filter()->all())
                ->get(['id', 'code', 'nom', 'localite_id', 'centre_id', 'latitude', 'longitude']);

        foreach ($lignes as $affectation) {
            $site = match ($affectation->role_terrain) {
                CategorieVolontaire::Operateur => $tournees->get($affectation->id)?->site,
                CategorieVolontaire::Assistant => $sitesAssistants->first(
                    fn (Site $s) => (int) $s->localite_id === (int) ($affectation->volontaire?->localite_id)
                        && (int) $s->centre_id === (int) $affectation->centre_id
                ),
                // Le superviseur couvre DEUX centres : lui inventer un site
                // unique serait faux. L'écran montre ses centres à la place.
                default => null,
            };

            // Les coordonnées accompagnent le site : c'est avec elles, et
            // elles seules, qu'on trace un itinéraire vers un village sans
            // adresse (demande du client, 18/09/2026).
            $affectation->setAttribute(
                'site_du_jour',
                $site?->only(['id', 'code', 'nom', 'latitude', 'longitude'])
            );
        }
    }
}
