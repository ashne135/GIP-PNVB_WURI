<?php

namespace App\Http\Controllers\Api;

use App\Enums\EtatRemise;
use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\User;
use App\Services\Comptes\GenerateurBordereauFormation;
use App\Services\Comptes\ServiceRemiseIdentifiants;
use App\Support\NormalisateurTelephone;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
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

        $utilisateur = $requete->user();

        $comptes = $this->comptesDuPerimetre($utilisateur)
            ->with(['volontaire:id,user_id,matricule,categorie,statut', 'remisesIdentifiants'])
            ->when($requete->filled('etat_remise'),
                fn ($q) => $q->where('etat_remise', $requete->string('etat_remise')))
            ->when($requete->filled('statut_compte'),
                fn ($q) => $q->where('statut_compte', $requete->string('statut_compte')))
            ->when($requete->filled('region_id'),
                fn ($q) => $this->filtrerParRegion($q, $requete->integer('region_id')))
            ->when($requete->filled('categorie'),
                fn ($q) => $q->whereHas('volontaire',
                    fn ($r) => $r->where('categorie', $requete->string('categorie'))))
            ->when($requete->boolean('sans_courriel'), fn ($q) => $q->whereNull('email'))
            // On désigne un agent par son nom, son téléphone ou son matricule :
            // sans recherche, retrouver les trois agents d'un test obligerait à
            // parcourir des pages entières.
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->where(fn ($r) => $r
                    ->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenoms', 'like', "%{$recherche}%")
                    ->orWhere('telephone', 'like', "%{$recherche}%")
                    ->orWhereHas('volontaire', fn ($v) => $v->where('matricule', 'like', "%{$recherche}%")));
            })
            ->orderBy('nom')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes('Suivi des remises récupéré.', [
            'comptes' => $comptes,
            'repartition' => $this->repartitionParEtat($utilisateur),
            'canaux' => $this->canauxSimules(),
        ]);
    }

    /**
     * L'ÉTAT DES ACCÈS EN PDF — un document de suivi, qui circule sans risque.
     *
     * IL NE PORTE AUCUN MOT DE PASSE (décision du client, 17/09/2026) : c'est
     * le bordereau nominatif qui les remet, contre signature, et qu'on détruit
     * après distribution. Ici on répond à « qui a son accès, et où en est-on ».
     *
     * Mêmes filtres et même périmètre que l'écran : on imprime ce qu'on voit.
     */
    public function etatAcces(Request $requete)
    {
        $this->autoriserSuivi($requete);

        $utilisateur = $requete->user();

        $comptes = $this->comptesDuPerimetre($utilisateur)
            ->with(['volontaire:id,user_id,matricule,categorie,statut'])
            ->when($requete->filled('etat_remise'),
                fn ($q) => $q->where('etat_remise', $requete->string('etat_remise')))
            ->when($requete->filled('statut_compte'),
                fn ($q) => $q->where('statut_compte', $requete->string('statut_compte')))
            ->when($requete->filled('region_id'),
                fn ($q) => $this->filtrerParRegion($q, $requete->integer('region_id')))
            ->when($requete->filled('categorie'),
                fn ($q) => $q->whereHas('volontaire',
                    fn ($r) => $r->where('categorie', $requete->string('categorie'))))
            ->when($requete->boolean('sans_courriel'), fn ($q) => $q->whereNull('email'))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->where(fn ($r) => $r
                    ->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenoms', 'like', "%{$recherche}%")
                    ->orWhere('telephone', 'like', "%{$recherche}%")
                    ->orWhereHas('volontaire', fn ($v) => $v->where('matricule', 'like', "%{$recherche}%")));
            })
            ->orderBy('nom')
            // Un PDF se lit : au-delà, c'est un export tableur qu'il faut.
            ->limit(2000)
            ->get();

        $lignes = $comptes->map(fn (User $compte) => [
            'matricule' => $compte->volontaire?->matricule ?? '—',
            'nom_complet' => $compte->nomComplet(),
            'categorie' => $compte->volontaire?->categorie?->libelle() ?? 'À qualifier',
            'telephone' => NormalisateurTelephone::pourAffichage($compte->telephone),
            'email' => $compte->email ?? '—',
            'acces' => $compte->statut_compte->libelle(),
            'remise' => $compte->etat_remise->libelle(),
            'premiere_connexion' => $compte->premiere_connexion_le?->format('d/m/Y') ?? '—',
        ]);

        $filtres = collect([
            // La région est nommée, jamais rendue par son identifiant : le PDF
            // se lit, il ne se déchiffre pas.
            'région' => $requete->filled('region_id')
                ? (string) \App\Models\Region::query()->whereKey($requete->integer('region_id'))->value('nom')
                : '',
            'remise' => $requete->string('etat_remise')->toString(),
            'accès' => $requete->string('statut_compte')->toString(),
            'catégorie' => $requete->string('categorie')->toString(),
            'recherche' => $requete->string('recherche')->toString(),
        ])->filter()->map(fn ($valeur, $cle) => "{$cle} = {$valeur}")->implode(', ');

        $pdf = Pdf::loadView('documents.etat-acces', [
            'lignes' => $lignes,
            'repartition' => $this->repartitionParEtat($utilisateur),
            'filtres' => $filtres,
            'genere_le' => now(),
            'genere_par' => $utilisateur->nomComplet(),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'etat-acces-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf']
        );
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
                // Le nom seul, en plus de l'adresse : servie sous un sous-chemin,
                // l'application ne peut pas se fier à l'adresse absolue que
                // construit route(), qui ignore ce préfixe.
                'fichier' => basename($resultat['chemin']),
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

    /**
     * FILTRER PAR RÉGION DE DÉPLOIEMENT.
     *
     * La région d'un agent, c'est celle où il est affecté — pas celle d'où il
     * vient. Elle se lit sur le centre de son affectation, et À DÉFAUT sur la
     * vague : un SUPERVISEUR n'a pas de centre, son affectation porte une unité
     * de supervision qui en couvre deux. Ne filtrer que sur le centre le ferait
     * disparaître de sa propre région.
     *
     * Un volontaire jamais affecté n'appartient à aucune région : il ne sort
     * dans aucun filtre régional, et c'est exact.
     */
    private function filtrerParRegion(Builder $requete, int $regionId): Builder
    {
        return $requete->whereHas('volontaire.affectations', fn (Builder $q) => $q
            ->where(fn (Builder $r) => $r
                ->whereHas('centre', fn (Builder $c) => $c->where('region_id', $regionId))
                ->orWhereHas('vague', fn (Builder $v) => $v->where('region_id', $regionId))));
    }

    /**
     * Les comptes de volontaires que l'utilisateur a le droit de voir.
     *
     * Le chef d'antenne régional détient le droit de consulter : sans ce
     * filtre, il lisait les téléphones et courriels de tout le pays. Un
     * volontaire importé mais pas encore affecté n'appartient à aucune région :
     * il reste du ressort de l'administration nationale.
     */
    private function comptesDuPerimetre(User $utilisateur): Builder
    {
        return User::query()->whereHas(
            'volontaire',
            fn (Builder $q) => $q->perimetre($utilisateur)
        );
    }

    /**
     * Les canaux qui n'envoient RIEN pour de vrai.
     *
     * Avec le pilote « log », un courriel ou un SMS est écrit dans les journaux
     * du serveur et compté comme envoyé. L'écran doit le dire : sinon on attend
     * un message qui ne partira jamais, et le mot de passe qu'il portait est
     * perdu.
     */
    private function canauxSimules(): array
    {
        return [
            'courriel_simule' => in_array(config('mail.default'), ['log', 'array'], true),
            'sms_simule' => config('pnvb.sms.pilote', 'log') !== 'http',
        ];
    }

    /** Combien de comptes dans chaque état : le chiffre clé de l'écran de suivi. */
    private function repartitionParEtat(User $utilisateur): array
    {
        $repartition = $this->comptesDuPerimetre($utilisateur)
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
