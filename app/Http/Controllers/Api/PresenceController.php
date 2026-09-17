<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\EcartPresence;
use App\Services\Presence\ServiceRelevesPosition;
use App\Services\Presence\ServiceSignalArrivee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Signal d'arrivée, carte temps réel, relevés de rapprochement et écarts —
 * les quatre mécanismes de la section 8, chacun à sa place.
 *
 * Ce qui n'existe PAS ici, volontairement : aucune route ne rend les relevés de
 * position. « Aucune interface n'affiche la trace des déplacements d'une
 * personne. Seul l'écart constaté est exposé, et uniquement au chef d'antenne
 * régional. »
 */
class PresenceController extends Controller
{
    public function __construct(
        private readonly ServiceSignalArrivee $signaux,
        private readonly ServiceRelevesPosition $releves,
    ) {
    }

    /** Le gros bouton « JE SUIS ARRIVÉ ». */
    public function signaler(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('presence.signaler_arrivee'), 403);

        $valide = $requete->validate([
            'uuid_client' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['arrivee', 'depart'])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'horodatage_telephone' => ['required', 'date'],
            'precision_gps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ], [
            'uuid_client.required' => "L'identifiant de l'enregistrement est manquant.",
            'latitude.required' => 'La position GPS est manquante : activez la localisation.',
            'longitude.required' => 'La position GPS est manquante : activez la localisation.',
            'horodatage_telephone.required' => "L'heure de l'appareil est manquante.",
        ]);

        $volontaire = $requete->user()->volontaire;

        abort_unless($volontaire !== null, 403);

        try {
            $signal = $this->signaux->enregistrer($volontaire, $valide);
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        $message = $signal->type === 'arrivee'
            ? 'Votre arrivée est enregistrée.'
            : 'Votre départ est enregistré.';

        if (! $signal->dans_zone && $signal->distance_site_metres !== null) {
            $message .= " Vous êtes à {$signal->distance_site_metres} m du site : "
                .'votre superviseur le verra.';
        }

        // Le signal n'est pas un pointage : on le rappelle à l'agent lui-même.
        $message .= ' Votre superviseur validera la feuille de présence.';

        return ReponseApi::succes($message, $signal, 201);
    }

    /** La carte temps réel du superviseur : vert, orange, gris. */
    public function carte(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('presence.consulter_carte'), 403);

        $date = $requete->string('date')->toString() ?: now()->toDateString();
        $centres = $requete->user()->idsCentresAccessibles();

        if ($centres === []) {
            return ReponseApi::succes('Aucun centre ne vous est rattaché.', [
                'date' => $date, 'sites' => [], 'agents' => [],
                'resume' => ['vert' => 0, 'orange' => 0, 'gris' => 0],
            ]);
        }

        return ReponseApi::succes('Carte du jour récupérée.', $this->signaux->carteDuJour($centres, $date));
    }

    /**
     * Dépôt d'un relevé de rapprochement par le mobile.
     *
     * La réponse ne dit jamais « refusé » à l'agent : ces relevés ne sont pas
     * son affaire, et l'écran ne les lui présente pas. Le motif du refus sert
     * au diagnostic du client mobile, pas à l'utilisateur.
     */
    public function deposerReleve(Request $requete): JsonResponse
    {
        $valide = $requete->validate([
            'horodatage' => ['required', 'date'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $volontaire = $requete->user()->volontaire;

        abort_unless($volontaire !== null, 403);

        $resultat = $this->releves->enregistrer($volontaire, $valide);

        return ReponseApi::succes('Relevé traité.', $resultat);
    }

    /**
     * Les écarts constatés — SEUL objet exposé du rapprochement, et au seul
     * chef d'antenne régional. Le scope de périmètre du modèle l'impose déjà ;
     * la Policy y ajoute le droit, et la consultation est journalisée.
     */
    public function ecarts(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', EcartPresence::class);

        $ecarts = EcartPresence::query()
            ->perimetre($requete->user())
            ->with([
                'volontaire:id,user_id,matricule,categorie', 'volontaire.user:id,nom,prenoms',
                'feuille:id,site_id,date_presence', 'feuille.site:id,code,nom',
            ])
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->orderByDesc('date_constat')
            ->paginate(min($requete->integer('par_page', 50), 200));

        activity('rapprochement')
            ->causedBy($requete->user())
            ->withProperties(['nombre' => $ecarts->total()])
            ->log('Consultation de la liste des écarts de présence');

        return ReponseApi::succes(
            $ecarts->total() === 0
                ? 'Aucun écart constaté.'
                : "{$ecarts->total()} écarts constatés entre les feuilles et les relevés.",
            $ecarts
        );
    }
}
