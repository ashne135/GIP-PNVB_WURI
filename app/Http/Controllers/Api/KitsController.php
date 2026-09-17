<?php

namespace App\Http\Controllers\Api;

use App\Enums\TypeMouvementKit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kits\DeclarerMouvementRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Kit;
use App\Services\Kits\ServiceAlertesKits;
use App\Services\Kits\ServiceMouvementsKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le parc de kits et ses mouvements (cadrage, section 13).
 *
 *   GET  /kits                    le parc, dans mon périmètre
 *   GET  /kits/synthese           combien, où, dans quel état
 *   GET  /kits/non-restitues      ceux qu'il faut aller réclamer
 *   GET  /kits/{k}                fiche et historique complet
 *   POST /kits                    entrée d'un kit au parc
 *   PUT  /kits/{k}                composition, état, marquage zone à défis
 *   POST /kits/{k}/mouvements     remise, transfert, restitution, panne, perte
 *
 * Il n'existe AUCUNE route pour écrire directement le détenteur d'un kit : le
 * seul chemin est un mouvement, qui laisse une trace. Sans cela, le parc
 * pourrait être corrigé en silence, et le journal cesserait de faire foi.
 */
class KitsController extends Controller
{
    public function __construct(
        private readonly ServiceMouvementsKit $mouvements,
        private readonly ServiceAlertesKits $alertes,
    ) {}

    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Kit::class);

        $kits = Kit::query()
            ->perimetre($requete->user())
            ->with([
                'detenteur:id,user_id,matricule,categorie', 'detenteur.user:id,nom,prenoms',
                'centreCourant:id,code,nom', 'siteCourant:id,code,nom',
            ])
            ->when($requete->filled('etat'), fn ($q) => $q->where('etat', $requete->string('etat')))
            ->when($requete->filled('centre_id'),
                fn ($q) => $q->where('centre_courant_id', $requete->integer('centre_id')))
            ->when($requete->boolean('disponibles'), fn ($q) => $q->disponibles())
            ->when($requete->boolean('zone_defis'), fn ($q) => $q->where('est_permanent_zone_defis', true))
            ->when($requete->filled('reference'),
                fn ($q) => $q->where('reference', 'like', '%'.$requete->string('reference').'%'))
            ->orderBy('reference')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Parc récupéré.', $kits);
    }

    /** Combien de kits, où, et dans quel état — la vue de gestion du parc. */
    public function synthese(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Kit::class);

        $base = fn () => Kit::query()->perimetre($requete->user());

        return ReponseApi::succes('Synthèse du parc.', [
            'total' => $base()->count(),
            'par_etat' => $base()->selectRaw('etat, count(*) as nombre')
                ->groupBy('etat')->pluck('nombre', 'etat'),
            'attribues' => $base()->whereNotNull('volontaire_detenteur_id')->count(),
            'disponibles' => $base()->disponibles()->count(),
            'permanents_zone_defis' => $base()->where('est_permanent_zone_defis', true)->count(),
            // Jamais additionné entre régions : c'est un décompte de matériel,
            // pas un effectif déployé.
            'non_restitues' => $this->alertes->kitsNonRestitues()->count(),
        ]);
    }

    /**
     * Les kits qu'il faut aller réclamer — nominatifs, avec la date de fin de
     * mission. Une liste qui dirait seulement « 14 kits non restitués » ne
     * servirait à personne.
     */
    public function nonRestitues(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Kit::class);

        $kits = $this->alertes->kitsNonRestitues()
            ->filter(fn (Kit $kit) => Kit::query()
                ->perimetre($requete->user())
                ->whereKey($kit->id)
                ->exists())
            ->values();

        return ReponseApi::succes(
            $kits->isEmpty()
                ? 'Aucun kit non restitué dans votre périmètre.'
                : "{$kits->count()} kits restent à récupérer.",
            $kits
        );
    }

    public function show(Request $requete, Kit $kit): JsonResponse
    {
        $this->authorize('view', $kit);

        return ReponseApi::succes('Fiche du kit.', $kit->load([
            'detenteur:id,user_id,matricule,categorie', 'detenteur.user:id,nom,prenoms',
            'centreCourant:id,code,nom', 'siteCourant:id,code,nom',
            'mouvements.source:id,user_id,matricule', 'mouvements.source.user:id,nom,prenoms',
            'mouvements.destination:id,user_id,matricule', 'mouvements.destination.user:id,nom,prenoms',
            'mouvements.effectuePar:id,nom,prenoms', 'mouvements.site:id,code,nom',
            'mouvements.piecesJointes:id,uuid_fichier,kit_mouvement_id,role,type_mime,taille_octets,deposee_le',
        ]));
    }

    public function store(Request $requete): JsonResponse
    {
        $this->authorize('create', Kit::class);

        $valide = $requete->validate([
            'reference' => ['required', 'string', 'max:30', 'unique:kits,reference'],
            'composition' => ['nullable', 'array'],
            'composition.*' => ['string', 'max:120'],
            'centre_courant_id' => ['nullable', 'integer', 'exists:centres,id'],
            'est_permanent_zone_defis' => ['nullable', 'boolean'],
        ], [
            'reference.required' => 'Donnez une référence à ce kit.',
            'reference.unique' => 'Cette référence est déjà utilisée par un autre kit.',
        ]);

        $kit = Kit::query()->create($valide + ['etat' => 'fonctionnel']);

        // fresh() : le modele tout juste cree ne porte que ce qu'on lui a passe.
        // Le client doit recevoir la fiche complete, valeurs par defaut de la
        // base comprises — sans quoi il croirait ces colonnes absentes.
        return ReponseApi::succes("Kit {$kit->reference} ajouté au parc.", $kit->fresh(), 201);
    }

    /**
     * Composition, état matériel, marquage « zone à défis ».
     * Le détenteur ne se modifie PAS ici : il change par un mouvement, jamais
     * par une correction silencieuse.
     */
    public function update(Request $requete, Kit $kit): JsonResponse
    {
        $this->authorize('update', $kit);

        $valide = $requete->validate([
            'composition' => ['nullable', 'array'],
            'composition.*' => ['string', 'max:120'],
            'etat' => ['nullable', Rule::in(['fonctionnel', 'panne', 'reforme'])],
            'est_permanent_zone_defis' => ['nullable', 'boolean'],
        ], [
            'etat.in' => 'Une perte ou un vol se déclare par un mouvement, pas par une correction.',
        ]);

        $kit->update(array_filter($valide, fn ($valeur) => $valeur !== null));

        return ReponseApi::succes('Kit mis à jour.', $kit->fresh());
    }

    public function declarerMouvement(DeclarerMouvementRequest $requete, Kit $kit): JsonResponse
    {
        $this->authorize('declarerMouvement', $kit);

        $type = TypeMouvementKit::from($requete->validated()['type']);

        try {
            $mouvement = $this->mouvements->declarer(
                $kit, $type, $requete->user(), $requete->validated()
            );
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            $this->message($type, $kit->fresh()),
            $mouvement,
            201
        );
    }

    private function message(TypeMouvementKit $type, Kit $kit): string
    {
        return match ($type) {
            TypeMouvementKit::Remise => "Kit {$kit->reference} remis à "
                .($kit->detenteur?->matricule ?? "l'agent").'.',
            TypeMouvementKit::Transfert => "Kit {$kit->reference} transféré à "
                .($kit->detenteur?->matricule ?? "l'agent").'.',
            TypeMouvementKit::Restitution => "Kit {$kit->reference} restitué : il retourne au parc.",
            TypeMouvementKit::ChangementSite => "Changement de site enregistré pour le kit {$kit->reference}.",
            TypeMouvementKit::Panne => "Panne enregistrée : le kit {$kit->reference} est hors service.",
            TypeMouvementKit::PerteVol => "Déclaration enregistrée. Le kit {$kit->reference} sort du parc "
                .'et une alerte est partie vers la hiérarchie.',
        };
    }
}
