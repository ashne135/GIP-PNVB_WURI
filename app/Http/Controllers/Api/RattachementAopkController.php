<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategorieVolontaire;
use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\Affectation;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\UniteSupervision;
use App\Models\Volontaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RATTACHER LES A-OPK À UN SITE, par région ou par commune.
 *
 * L'A-OPK n'est pas rattaché à un opérateur : il l'est à sa LOCALITÉ, et c'est
 * de là que tout découle. Le jour où le kit passe sur le site de sa localité,
 * l'opérateur de ce kit devient son supérieur ; la semaine suivante, le kit est
 * ailleurs et ce n'est plus le même. Figer ce lien à la main le rendrait faux
 * au premier déplacement du kit.
 *
 * Ce que cet écran permet, c'est de poser le seul maillon qui se décide : DANS
 * QUELLE LOCALITÉ un A-OPK travaille. On le désigne par le SITE — c'est ce que
 * l'administration a sous les yeux — et le serveur en tire la localité.
 *
 * Il rend aussi la chaîne visible, site par site : son centre, le superviseur
 * de l'unité qui le couvre, et l'opérateur dont le kit s'y trouve aujourd'hui.
 * Sans cela, on rattacherait à l'aveugle.
 */
class RattachementAopkController extends Controller
{
    /** Les A-OPK d'un périmètre, et les sites où les rattacher. */
    public function index(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('volontaires.modifier'), 403);

        $valide = $requete->validate([
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'commune_id' => ['nullable', 'integer', 'exists:communes,id'],
        ]);

        $auteur = $requete->user();
        $aujourdhui = now()->toDateString();

        $sites = Site::query()
            ->perimetre($auteur)
            ->with(['centre:id,code,nom,region_id', 'localite:id,nom,commune_id'])
            ->when($valide['region_id'] ?? null, fn ($q, $id) => $q->where('region_id', $id))
            ->when(
                $valide['commune_id'] ?? null,
                fn ($q, $id) => $q->whereHas('localite', fn ($l) => $l->where('commune_id', $id))
            )
            ->orderBy('code')
            ->limit(500)
            ->get();

        $assistants = Volontaire::query()
            ->perimetre($auteur)
            ->where('categorie', CategorieVolontaire::Assistant->value)
            ->with(['user:id,nom,prenoms,telephone', 'localite:id,nom,commune_id,region_id'])
            ->when(
                $valide['region_id'] ?? null,
                fn ($q, $id) => $q->whereHas('localite', fn ($l) => $l->where('region_id', $id))
            )
            ->when(
                $valide['commune_id'] ?? null,
                fn ($q, $id) => $q->whereHas('localite', fn ($l) => $l->where('commune_id', $id))
            )
            ->orderBy('matricule')
            ->limit(500)
            ->get();

        return ReponseApi::succes(
            count($assistants).' A-OPK et '.count($sites).' sites dans ce périmètre.',
            [
                'assistants' => $assistants->map(fn (Volontaire $v) => $this->ligneAssistant($v, $aujourdhui))->all(),
                'sites' => $sites->map(fn (Site $s) => $this->ligneSite($s, $aujourdhui))->all(),
            ]
        );
    }

    /**
     * Rattache les A-OPK choisis au site choisi.
     *
     * DEUX ÉCRITURES, et la seconde n'a lieu que si l'agent est déjà déployé :
     * sa FICHE change de localité, et son AFFECTATION ACTIVE change de site et
     * de centre. Déplacer quelqu'un qui travaille déjà exige un motif — c'est
     * un déplacement sur le terrain, pas une correction de saisie.
     */
    public function rattacher(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('volontaires.modifier'), 403);

        $valide = $requete->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'volontaire_ids' => ['required', 'array', 'min:1', 'max:500'],
            'volontaire_ids.*' => ['integer'],
            'motif' => ['nullable', 'string', 'min:5', 'max:500'],
        ], [
            'site_id.required' => 'Choisissez le site de rattachement.',
            'volontaire_ids.required' => 'Choisissez au moins un A-OPK.',
            'motif.min' => 'Le motif doit être compréhensible par celui qui le lira plus tard.',
        ]);

        $auteur = $requete->user();
        $site = Site::query()->perimetre($auteur)->with('localite')->findOrFail($valide['site_id']);

        $assistants = Volontaire::query()
            ->perimetre($auteur)
            ->whereIn('id', $valide['volontaire_ids'])
            ->with('user')
            ->get();

        $rattaches = [];
        $refuses = [];

        foreach ($assistants as $assistant) {
            $refus = $this->refuser($assistant, $site, $valide['motif'] ?? null);

            if ($refus !== null) {
                $refuses[] = ['id' => $assistant->id, 'matricule' => $assistant->matricule, 'motif' => $refus];

                continue;
            }

            $deplace = $this->appliquer($assistant, $site, $valide['motif'] ?? null, $auteur);

            $rattaches[] = [
                'id' => $assistant->id,
                'matricule' => $assistant->matricule,
                'deplace' => $deplace,
            ];
        }

        $message = count($rattaches).' A-OPK rattachés à '.$site->nom.'.';

        if ($refuses !== []) {
            $message .= ' '.count($refuses).' ne l\'ont pas été : le détail dit pourquoi.';
        }

        return ReponseApi::succes($message, ['rattaches' => $rattaches, 'refuses' => $refuses]);
    }

    /** Ce qui interdit le rattachement, en clair. Null quand il est possible. */
    private function refuser(Volontaire $assistant, Site $site, ?string $motif): ?string
    {
        if ($assistant->categorie !== CategorieVolontaire::Assistant) {
            // Un opérateur suit son kit, un superviseur son unité : ni l'un ni
            // l'autre ne se rattache à une localité.
            return 'Seuls les A-OPK se rattachent à un site : cette fiche est '
                .($assistant->categorie?->libelle() ?? 'sans catégorie').'.';
        }

        if ($assistant->localite_id === $site->localite_id) {
            return 'Déjà rattaché à la localité de ce site : rien à changer.';
        }

        if ($this->affectationActive($assistant) !== null && ($motif === null || trim($motif) === '')) {
            return 'Cet agent est déployé : indiquez un motif de déplacement, il restera sur sa fiche.';
        }

        return null;
    }

    private function appliquer(Volontaire $assistant, Site $site, ?string $motif, $auteur): bool
    {
        $affectation = $this->affectationActive($assistant);

        DB::transaction(function () use ($assistant, $site, $affectation, $motif, $auteur) {
            $assistant->update(['localite_id' => $site->localite_id]);

            if ($affectation !== null) {
                $affectation->update([
                    'localite_id' => $site->localite_id,
                    'centre_id' => $site->centre_id,
                ]);
            }

            activity('affectation')
                ->causedBy($auteur)
                ->performedOn($assistant)
                ->withProperties([
                    'site_id' => $site->id,
                    'site' => $site->code,
                    'localite_id' => $site->localite_id,
                    'deplacement' => $affectation !== null,
                    'motif' => $motif,
                ])
                ->log($affectation !== null
                    ? "A-OPK déplacé vers le site {$site->code} : {$motif}"
                    : "A-OPK rattaché au site {$site->code}");
        });

        return $affectation !== null;
    }

    private function affectationActive(Volontaire $assistant): ?Affectation
    {
        return Affectation::query()
            ->where('volontaire_id', $assistant->id)
            ->actives()
            ->first();
    }

    /** @return array<string, mixed> */
    private function ligneAssistant(Volontaire $assistant, string $date): array
    {
        $affectation = $this->affectationActive($assistant);

        // Le site où il travaille aujourd'hui : celui de sa localité, dans le
        // centre de son affectation quand il en a une.
        $site = $assistant->localite_id === null ? null : Site::query()
            ->where('localite_id', $assistant->localite_id)
            ->when($affectation?->centre_id, fn ($q, $id) => $q->where('centre_id', $id))
            ->with('centre:id,code,nom')
            ->first();

        return [
            'id' => $assistant->id,
            'matricule' => $assistant->matricule,
            'nom' => $assistant->user?->nom,
            'prenoms' => $assistant->user?->prenoms,
            'telephone' => $assistant->user?->telephone,
            'localite' => $assistant->localite?->nom,
            'localite_id' => $assistant->localite_id,
            'site' => $site ? ['id' => $site->id, 'code' => $site->code, 'nom' => $site->nom] : null,
            'centre' => $site?->centre ? ['code' => $site->centre->code, 'nom' => $site->centre->nom] : null,
            'deploye' => $affectation !== null,
            // SANS SITE DANS SA LOCALITÉ, la chaîne est rompue : aucun kit n'y
            // passera, donc aucun opérateur ne sera son supérieur. C'est
            // l'information qui explique pourquoi un A-OPK n'entre dans aucun
            // tirage, et elle doit se voir dans la liste.
            'rattachement_rompu' => $assistant->localite_id === null || $site === null,
        ];
    }

    /** @return array<string, mixed> */
    private function ligneSite(Site $site, string $date): array
    {
        $unite = UniteSupervision::query()
            ->with('superviseur.user:id,nom,prenoms')
            ->where(fn ($q) => $q->where('centre_principal_id', $site->centre_id)
                ->orWhere('centre_secondaire_id', $site->centre_id))
            ->latest('id')
            ->first();

        $tournee = TourneeSite::query()
            ->with('affectationOperateur.volontaire.user:id,nom,prenoms')
            ->where('site_id', $site->id)
            ->couvrant($date)
            ->first();

        $operateur = $tournee?->affectationOperateur?->volontaire;
        $superviseur = $unite?->superviseur;

        return [
            'id' => $site->id,
            'code' => $site->code,
            'nom' => $site->nom,
            'localite' => $site->localite?->nom,
            'localite_id' => $site->localite_id,
            'centre' => $site->centre ? ['code' => $site->centre->code, 'nom' => $site->centre->nom] : null,
            // LA CHAÎNE, telle qu'elle est AUJOURD'HUI. L'opérateur change avec
            // le passage du kit : ce n'est pas une affectation, c'est un état.
            'superviseur' => $superviseur ? [
                'matricule' => $superviseur->matricule,
                'nom' => trim(($superviseur->user?->prenoms ?? '').' '.($superviseur->user?->nom ?? '')),
            ] : null,
            'operateur_du_jour' => $operateur ? [
                'matricule' => $operateur->matricule,
                'nom' => trim(($operateur->user?->prenoms ?? '').' '.($operateur->user?->nom ?? '')),
            ] : null,
            'assistants_rattaches' => Volontaire::query()
                ->where('categorie', CategorieVolontaire::Assistant->value)
                ->where('localite_id', $site->localite_id)
                ->count(),
        ];
    }
}
