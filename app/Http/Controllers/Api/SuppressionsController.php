<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\Centre;
use App\Models\Kit;
use App\Models\Site;
use App\Models\Volontaire;
use App\Services\Suppression\ServiceSuppressionDefinitive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SUPPRIMER DÉFINITIVEMENT — une ligne, ou toutes celles qu'on a cochées.
 *
 * Le dispositif ne supprime normalement rien : un volontaire se RETIRE, un
 * centre se FERME, un kit se RÉFORME, et la trace demeure. Ce point d'entrée
 * n'existe que pour le JEU D'ESSAI — les fiches créées pour tester, qu'il faut
 * pouvoir faire disparaître avant la mise en service.
 *
 * TROIS GARDE-FOUS :
 *
 *  1. UN DROIT À PART, donnees.supprimer, réservé à l'administration nationale.
 *     Un chef d'antenne ferme et retire ; il n'efface pas.
 *
 *  2. LE PÉRIMÈTRE d'abord : la requête ne voit que les lignes du périmètre de
 *     son auteur. Un identifiant hors périmètre n'est pas refusé, il n'existe
 *     pas pour lui — et le compte rendu le dit, plutôt que de rester muet.
 *
 *  3. LIGNE PAR LIGNE. Un lot n'est jamais tout ou rien : chaque ligne est
 *     tentée pour elle-même, et le compte rendu nomme celles qui restent avec
 *     leur motif. Refuser le lot entier pour une seule ligne engagée obligerait
 *     à la chercher à la main.
 */
class SuppressionsController extends Controller
{
    private const FAMILLES = [
        'volontaire' => Volontaire::class,
        'centre' => Centre::class,
        'site' => Site::class,
        'kit' => Kit::class,
    ];

    public function __construct(private readonly ServiceSuppressionDefinitive $service) {}

    public function supprimer(Request $requete): JsonResponse
    {
        abort_unless($requete->user()->can('donnees.supprimer'), 403);

        $valide = $requete->validate([
            'famille' => ['required', Rule::in(array_keys(self::FAMILLES))],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ], [
            'ids.required' => 'Choisissez au moins une ligne à supprimer.',
            'ids.max' => 'Vous ne pouvez pas supprimer plus de 500 lignes à la fois.',
            'famille.in' => 'Cette famille de données ne se supprime pas ici.',
        ]);

        $famille = $valide['famille'];
        $modele = self::FAMILLES[$famille];
        $auteur = $requete->user();

        $lignes = $modele::query()
            ->perimetre($auteur)
            ->whereIn('id', $valide['ids'])
            ->get();

        $supprimes = [];
        $refusees = [];

        foreach ($lignes as $ligne) {
            $libelle = $this->service->libelle($ligne);
            $resultat = $this->service->supprimer($famille, $ligne);

            if ($resultat['supprime']) {
                $this->journaliser($famille, $ligne, $libelle, $auteur);
                $supprimes[] = ['id' => $ligne->getKey(), 'libelle' => $libelle];

                continue;
            }

            $refusees[] = ['id' => $ligne->getKey(), 'libelle' => $libelle, 'motif' => $resultat['motif']];
        }

        /*
         * CE QUI N'A PAS ÉTÉ TROUVÉ se dit aussi. Un identifiant hors périmètre
         * ou déjà supprimé disparaîtrait sans cela du compte rendu, et
         * l'administrateur croirait la ligne effacée par lui.
         */
        $introuvables = array_values(array_diff(
            array_map('intval', $valide['ids']),
            $lignes->pluck('id')->map(fn ($id) => (int) $id)->all()
        ));

        foreach ($introuvables as $id) {
            $refusees[] = [
                'id' => $id,
                'libelle' => '#'.$id,
                'motif' => 'Introuvable dans votre périmètre : rien n\'a été supprimé pour cette ligne.',
            ];
        }

        $message = count($supprimes).' '.$this->nom($famille, count($supprimes)).' '
            .(count($supprimes) > 1 ? 'supprimés définitivement.' : 'supprimé définitivement.');

        if ($refusees !== []) {
            $message .= ' '.count($refusees).' n\'ont pas pu l\'être : le détail dit pourquoi.';
        }

        return ReponseApi::succes($message, ['supprimes' => $supprimes, 'refusees' => $refusees]);
    }

    private function nom(string $famille, int $nombre): string
    {
        $noms = [
            'volontaire' => ['fiche', 'fiches'],
            'centre' => ['centre', 'centres'],
            'site' => ['site', 'sites'],
            'kit' => ['kit', 'kits'],
        ];

        return $noms[$famille][$nombre > 1 ? 1 : 0];
    }

    /**
     * LA TRACE SURVIT À LA LIGNE.
     *
     * Une suppression définitive ne laisse rien derrière elle : le journal est
     * le seul endroit où l'on saura qu'elle a eu lieu, qui l'a décidée et sur
     * quoi. Le libellé y est recopié, puisque la ligne n'existe plus pour le
     * fournir.
     */
    private function journaliser(string $famille, Model $ligne, string $libelle, $auteur): void
    {
        activity('suppression')
            ->causedBy($auteur)
            ->withProperties([
                'famille' => $famille,
                'id' => $ligne->getKey(),
                'libelle' => $libelle,
            ])
            ->log("Suppression définitive : {$famille} {$libelle}");
    }
}
