<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\SyncLot;
use App\Services\Comptes\ServiceAccesRattrapage;
use App\Services\Sync\CodeRejet;
use App\Services\Sync\RegistreSync;
use Carbon\CarbonInterface;
use App\Services\Sync\ServiceSynchronisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La remontée groupée du terrain (cadrage, section 11).
 *
 *   POST /sync            un lot d'éléments, réponse détaillée élément par élément
 *   GET  /sync/types      ce que ce serveur sait recevoir
 *   GET  /sync/lots       l'historique des envois de l'agent, pour le diagnostic
 *
 * Le lot est accepté MÊME SI TOUS SES ÉLÉMENTS SONT REJETÉS : la réponse reste
 * un 200, parce que le lot, lui, a bien été traité. Un code d'erreur HTTP ferait
 * croire au téléphone qu'il doit tout renvoyer, alors que le détail lui dit
 * précisément quoi reprendre et quoi abandonner.
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly ServiceSynchronisation $service,
        private readonly RegistreSync $registre,
    ) {}

    public function synchroniser(Request $requete): JsonResponse
    {
        $maximum = $this->service->tailleMaximale();

        $valide = $requete->validate([
            'uuid_lot' => ['required', 'uuid'],
            'elements' => ['required', 'array', 'min:1', "max:{$maximum}"],
            'elements.*.type' => ['required', 'string', 'max:60'],
        ], [
            'uuid_lot.required' => "L'identifiant du lot est manquant.",
            'elements.required' => 'Le lot ne contient aucun élément.',
            'elements.max' => "Un lot ne peut pas dépasser {$maximum} éléments : "
                .'découpez votre envoi.',
            'elements.*.type.required' => 'Chaque élément doit indiquer son type.',
        ]);

        // Les elements sont repris BRUTS de la requete, jamais de validated() :
        // la validation du lot ne declare que « type », et Laravel ne rend que
        // les cles declarees — le contenu metier de chaque element serait perdu.
        // Ce contenu est valide ensuite, regle par regle, par son propre type.
        $resultat = $this->service->traiterLot(
            $requete->user(),
            $valide['uuid_lot'],
            $requete->input('elements', []),
            $this->fermetureSiRattrapage($requete)
        );

        return ReponseApi::succes($this->message($resultat), $resultat);
    }

    /**
     * Le jeton d'un accès fermé ne sert plus qu'au rattrapage : la date de
     * fermeture borne alors ce que le lot peut encore faire passer.
     */
    private function fermetureSiRattrapage(Request $requete): ?CarbonInterface
    {
        if (! ServiceAccesRattrapage::estRestreint($requete->user()->currentAccessToken())) {
            return null;
        }

        return $requete->user()->acces_ferme_le ?? now();
    }

    /**
     * Ce que ce serveur sait recevoir. Le mobile s'en sert au démarrage pour
     * savoir s'il parle à un serveur plus ancien que lui, et mettre de côté ce
     * qu'il ne pourrait pas faire passer.
     */
    public function types(): JsonResponse
    {
        return ReponseApi::succes('Types synchronisables de ce serveur.', [
            'types' => $this->registre->cles(),
            'max_elements_par_lot' => $this->service->tailleMaximale(),
        ]);
    }

    /** L'historique des lots de l'agent : sa trace, et son diagnostic. */
    public function lots(Request $requete): JsonResponse
    {
        $lots = SyncLot::query()
            ->where('user_id', $requete->user()->id)
            ->orderByDesc('recu_le')
            ->paginate(min($requete->integer('par_page', 20), 100));

        return ReponseApi::succes('Historique de vos synchronisations.', $lots);
    }

    /**
     * SUPERVISION DES SYNCHRONISATIONS — pour le super administrateur (DSI).
     *
     * Répond à « quel téléphone n'arrive pas à envoyer, et pourquoi ? ». Réservé
     * au droit journal.consulter : c'est un outil de diagnostic technique,
     * national, comme le journal d'activité.
     *
     * CE QUI N'EST PAS RENDU : le contenu des éléments. Un rejet est décrit par
     * son type, son code et son motif, jamais par ses données — un relevé de
     * position refusé ne livre donc aucune coordonnée (cadrage, section 8).
     */
    public function supervision(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('journal.consulter'), 403);

        $lots = SyncLot::query()
            ->with(['user:id,nom,prenoms,telephone', 'user.volontaire:id,user_id,matricule,categorie'])
            ->when($requete->boolean('avec_rejets'), fn ($q) => $q->where('nb_rejetes', '>', 0))
            ->when($requete->filled('du'), fn ($q) => $q->whereDate('recu_le', '>=', $requete->date('du')))
            ->when($requete->filled('au'), fn ($q) => $q->whereDate('recu_le', '<=', $requete->date('au')))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->whereHas('user', fn ($u) => $u
                    ->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenoms', 'like', "%{$recherche}%")
                    ->orWhere('telephone', 'like', "%{$recherche}%")
                    ->orWhereHas('volontaire', fn ($v) => $v->where('matricule', 'like', "%{$recherche}%")));
            })
            ->orderByDesc('recu_le')
            ->orderByDesc('id')
            ->paginate(min($requete->integer('par_page', 50), 200));

        $lots->getCollection()->transform(fn (SyncLot $lot) => [
            'id' => $lot->id,
            'recu_le' => $lot->recu_le,
            'nb_elements' => $lot->nb_elements,
            'nb_acceptes' => $lot->nb_acceptes,
            'nb_rejetes' => $lot->nb_rejetes,
            'duree_ms' => $lot->duree_ms,
            'user' => $lot->user ? [
                'id' => $lot->user->id,
                'nom' => $lot->user->nom,
                'prenoms' => $lot->user->prenoms,
                'telephone' => $lot->user->telephone,
                'matricule' => $lot->user->volontaire?->matricule,
            ] : null,
            'rejets' => array_map(function (array $rejet) {
                $code = CodeRejet::tryFrom((string) ($rejet['code'] ?? ''));

                return [
                    'rang' => $rejet['rang'] ?? null,
                    'type' => $rejet['type'] ?? null,
                    'code' => $rejet['code'] ?? null,
                    'code_libelle' => $code?->libelle(),
                    'motif' => $rejet['motif'] ?? null,
                    'reessayer' => (bool) ($rejet['reessayer'] ?? false),
                ];
            }, $lot->detail['rejetes'] ?? []),
        ]);

        return ReponseApi::succes('Synchronisations récupérées.', $lots);
    }

    private function message(array $resultat): string
    {
        if ($resultat['rejoue']) {
            return 'Ce lot avait déjà été reçu : voici la réponse rendue à ce moment-là. '
                .'Rien n\'a été enregistré deux fois.';
        }

        if ($resultat['nb_rejetes'] === 0) {
            return "Synchronisation terminée : {$resultat['nb_acceptes']} éléments enregistrés.";
        }

        if ($resultat['nb_acceptes'] === 0) {
            return "Aucun élément n'a pu être enregistré. Le détail indique pourquoi, "
                .'élément par élément.';
        }

        return "{$resultat['nb_acceptes']} éléments enregistrés, "
            ."{$resultat['nb_rejetes']} refusés. Le détail indique pourquoi.";
    }
}
