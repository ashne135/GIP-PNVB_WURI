<?php

namespace App\Http\Controllers\Api;

use App\Enums\EtatRemise;
use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\User;
use App\Services\Comptes\GenerateurBordereauFormation;
use App\Services\Comptes\ServiceRemiseIdentifiants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Écran de suivi de la remise des identifiants, et bouton de RENVOI —
 * individuel ou EN LOT (cadrage, section 6).
 *
 * L'administrateur national doit pouvoir répondre à la question : « qui n'a pas
 * encore ses identifiants, et pourquoi ? » — puis agir dessus.
 */
class RemiseIdentifiantsController extends Controller
{
    public function __construct(
        private readonly ServiceRemiseIdentifiants $service,
        private readonly GenerateurBordereauFormation $bordereaux,
    ) {
    }

    /** Suivi : la liste des comptes, filtrable par état de remise. */
    public function index(Request $requete): JsonResponse
    {
        $this->autoriserSuivi($requete);

        $comptes = User::query()
            ->with(['volontaire:id,user_id,matricule,categorie,statut', 'remisesIdentifiants'])
            ->whereHas('volontaire')
            ->when($requete->filled('etat_remise'),
                fn ($q) => $q->where('etat_remise', $requete->string('etat_remise')))
            ->when($requete->filled('categorie'),
                fn ($q) => $q->whereHas('volontaire',
                    fn ($r) => $r->where('categorie', $requete->string('categorie'))))
            ->when($requete->boolean('sans_courriel'), fn ($q) => $q->whereNull('email'))
            ->orderBy('nom')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Suivi des remises récupéré.', [
            'comptes' => $comptes,
            'repartition' => $this->repartitionParEtat(),
        ]);
    }

    /**
     * Renvoi des identifiants, à l'unité ou en lot.
     *
     * Un renvoi régénère un mot de passe : l'ancien cesse de fonctionner. C'est
     * voulu — on renvoie précisément parce que le premier n'est pas arrivé.
     */
    public function renvoyer(Request $requete): JsonResponse
    {
        $this->autoriserRenvoi($requete);

        $valide = $requete->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'canal' => ['nullable', Rule::in(['courriel', 'sms', 'cascade'])],
        ], [
            'user_ids.required' => 'Choisissez au moins un compte.',
            'user_ids.max' => 'Vous ne pouvez pas renvoyer à plus de 500 comptes à la fois.',
        ]);

        $canal = $valide['canal'] ?? 'cascade';
        $resultats = ['envoyes' => 0, 'echecs' => 0, 'details' => []];

        // L'administrateur qui déclenche le renvoi est nommé dans le journal :
        // il faut qu'il soit capturé par la fermeture, sinon chaque ligne
        // repartirait sans auteur alors qu'un humain l'a bien demandé.
        $auteur = $requete->user();

        User::query()->whereIn('id', $valide['user_ids'])->with('volontaire')->each(
            function (User $utilisateur) use ($canal, $auteur, &$resultats) {
                $remise = match ($canal) {
                    'courriel' => $this->service->tenterCourriel($utilisateur, $auteur),
                    'sms' => $this->service->tenterSms($utilisateur, $auteur),
                    default => $this->service->amorcerCascade($utilisateur, $auteur),
                };

                $reussi = $remise->statut === 'envoye';
                $resultats[$reussi ? 'envoyes' : 'echecs']++;
                $resultats['details'][] = [
                    'user_id' => $utilisateur->id,
                    'nom_complet' => $utilisateur->nomComplet(),
                    'canal' => $remise->canal,
                    'statut' => $remise->statut,
                ];
            }
        );

        $message = "{$resultats['envoyes']} identifiants envoyés.";

        if ($resultats['echecs'] > 0) {
            $message .= " {$resultats['echecs']} envois n'ont pas abouti : "
                .'ces volontaires devront recevoir leurs identifiants en formation.';
        }

        return ReponseApi::succes($message, $resultats);
    }

    /**
     * Bordereau PDF d'une session de formation : une ligne par volontaire, à
     * découper et à remettre contre signature.
     */
    public function bordereau(Request $requete): JsonResponse
    {
        $this->autoriserRenvoi($requete);

        $valide = $requete->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:300'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'session' => ['required', 'string', 'max:80'],
        ], [
            'user_ids.required' => 'Choisissez les volontaires de cette session.',
            'session.required' => 'Donnez un nom à la session de formation.',
        ]);

        $utilisateurs = User::query()
            ->with('volontaire')
            ->whereIn('id', $valide['user_ids'])
            ->orderBy('nom')
            ->get();

        $resultat = $this->bordereaux->generer($utilisateurs, $valide['session'], $requete->user());

        return ReponseApi::succes(
            "Bordereau généré pour {$resultat['lignes']} volontaires. "
            .'Imprimez-le, distribuez les talons contre signature, puis détruisez le document.',
            [
                'session' => $resultat['session'],
                'lignes' => $resultat['lignes'],
                'url_telechargement' => route('api.comptes.bordereau.telecharger', [
                    'fichier' => basename($resultat['chemin']),
                ]),
            ]
        );
    }

    public function telechargerBordereau(Request $requete, string $fichier)
    {
        $this->autoriserRenvoi($requete);

        $chemin = 'bordereaux/'.basename($fichier);

        abort_unless(Storage::exists($chemin), 404);

        return Storage::download($chemin);
    }

    /** Combien de comptes dans chaque état : le chiffre clé de l'écran de suivi. */
    private function repartitionParEtat(): array
    {
        $repartition = User::query()
            ->whereHas('volontaire')
            ->selectRaw('etat_remise, COUNT(*) as nombre')
            ->groupBy('etat_remise')
            ->pluck('nombre', 'etat_remise');

        return collect(EtatRemise::cases())
            ->mapWithKeys(fn (EtatRemise $etat) => [
                $etat->value => [
                    'libelle' => $etat->libelle(),
                    'nombre' => (int) ($repartition[$etat->value] ?? 0),
                ],
            ])
            ->all();
    }

    private function autoriserSuivi(Request $requete): void
    {
        abort_unless($requete->user()->can('comptes.consulter'), 403);
    }

    private function autoriserRenvoi(Request $requete): void
    {
        abort_unless($requete->user()->can('comptes.renvoyer_identifiants'), 403);
    }
}
