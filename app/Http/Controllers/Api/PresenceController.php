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
                'region:id,code,nom', 'examinePar:id,nom,prenoms',
            ])
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->when($requete->filled('type_ecart'),
                fn ($q) => $q->where('type_ecart', $requete->string('type_ecart')))
            ->when($requete->filled('du'), fn ($q) => $q->whereDate('date_constat', '>=', $requete->date('du')))
            ->when($requete->filled('au'), fn ($q) => $q->whereDate('date_constat', '<=', $requete->date('au')))
            ->orderByDesc('date_constat')
            ->orderByDesc('id')
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

    /**
     * Traiter un écart : l'examiner, puis le clore.
     *
     * Le commentaire est OBLIGATOIRE et S'AJOUTE aux précédents, signé et daté :
     * « examiné » puis « clos » laissent deux lignes, et personne ne réécrit ce
     * qu'un autre a constaté. Un écart clos ne se rouvre pas ici.
     *
     * Le traitement ne montre rien de plus que la consultation : toujours le
     * nombre de relevés, jamais une position.
     */
    public function traiterEcart(Request $requete, EcartPresence $ecart): JsonResponse
    {
        $this->authorize('traiter', $ecart);

        $valide = $requete->validate([
            'statut' => ['required', Rule::in(['examine', 'clos'])],
            'commentaire' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'statut.required' => 'Choisissez « examiné » ou « clos ».',
            'statut.in' => 'Un écart se marque « examiné » ou « clos ».',
            'commentaire.required' => 'Écrivez ce que vous avez constaté : le commentaire est obligatoire.',
            'commentaire.min' => 'Le commentaire est trop court pour expliquer le traitement.',
        ]);

        if ($ecart->statut === 'clos') {
            return ReponseApi::echec('Cet écart est déjà clos : il ne peut plus être modifié.', null, 422);
        }

        $auteur = $requete->user();
        $ligne = sprintf(
            '[%s · %s] %s',
            now()->format('d/m/Y H:i'),
            $auteur->nomComplet(),
            trim($valide['commentaire'])
        );

        $ecart->update([
            'statut' => $valide['statut'],
            'examine_par' => $auteur->id,
            'examine_le' => now(),
            'commentaire' => $ecart->commentaire ? $ecart->commentaire."\n".$ligne : $ligne,
        ]);

        activity('rapprochement')
            ->causedBy($auteur)
            ->performedOn($ecart)
            ->withProperties(['statut' => $valide['statut'], 'commentaire' => trim($valide['commentaire'])])
            ->log($valide['statut'] === 'clos' ? 'Écart de présence clos' : 'Écart de présence examiné');

        return ReponseApi::succes(
            $valide['statut'] === 'clos' ? 'Écart clos.' : 'Écart marqué comme examiné.',
            $ecart->fresh()->load(['examinePar:id,nom,prenoms'])
        );
    }
}
