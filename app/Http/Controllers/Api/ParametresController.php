<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolePnvb;
use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\Parametre;
use App\Services\Comptes\ServiceCharte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Les paramètres du dispositif (cadrage, sections 5 et 15).
 *
 *   GET /parametres          la liste, avec ce que le compte peut en faire
 *   PUT /parametres/{cle}    une nouvelle valeur, contrôlée selon son type
 *
 * SÉPARATION DES POUVOIRS : l'administrateur national consulte, seul le super
 * administrateur modifie. La Policy le porte ; ce contrôleur ne fait que
 * l'appliquer.
 *
 * On ne crée ni ne supprime de paramètre ici : la liste est celle que le code
 * lit. Un paramètre ajouté à la main ne serait lu par personne, et un
 * paramètre supprimé ferait retomber le code sur sa valeur de repli, sans
 * que personne ne le voie.
 *
 * Chaque modification est journalisée avec l'ancienne et la nouvelle valeur :
 * un délai d'escalade ou un seuil de kits qui change en silence est
 * exactement ce qu'on cherche à reconstituer après un incident.
 */
class ParametresController extends Controller
{
    public function index(Request $requete): JsonResponse
    {
        $this->authorize('viewAny', Parametre::class);

        $parametres = Parametre::query()
            ->orderBy('groupe')
            ->orderBy('cle')
            ->get()
            ->map(fn (Parametre $parametre) => $this->presenter($parametre))
            ->values();

        return ReponseApi::succes('Paramètres du dispositif.', [
            'peut_modifier' => $requete->user()->can('parametres.modifier'),
            'parametres' => $parametres,
        ]);
    }

    public function update(Request $requete, Parametre $parametre): JsonResponse
    {
        $this->authorize('update', $parametre);

        $valide = $requete->validate($this->regles($parametre), $this->messages($parametre));

        $nouvelle = $this->serialiser($parametre, $valide['valeur']);
        $ancienne = (string) $parametre->valeur;

        if ($nouvelle === $ancienne) {
            return ReponseApi::succes('Aucune modification : la valeur est identique.', $this->presenter($parametre));
        }

        // Par le modèle : son événement « saved » vide le cache de ce paramètre,
        // et la nouvelle valeur est lue dès la requête suivante.
        $parametre->update(['valeur' => $nouvelle]);

        activity('parametre')
            ->causedBy($requete->user())
            ->performedOn($parametre)
            ->withProperties([
                'cle' => $parametre->cle,
                'ancienne_valeur' => $ancienne,
                'nouvelle_valeur' => $nouvelle,
            ])
            ->log("Paramètre modifié : {$parametre->cle}");

        return ReponseApi::succes(
            "« {$parametre->libelle} » modifié. La nouvelle valeur s’applique immédiatement.",
            $this->presenter($parametre->fresh())
        );
    }

    /** Les règles de la valeur, selon le type du paramètre. */
    private function regles(Parametre $parametre): array
    {
        return match ($parametre->type_valeur) {
            'entier' => ['valeur' => ['required', 'integer', 'min:0']],
            'decimal' => ['valeur' => ['required', 'numeric', 'min:0']],
            'booleen' => ['valeur' => ['required', 'boolean']],
            'json' => str_starts_with($parametre->cle, 'incidents.notification.')
                ? [
                    // Une matrice vide ne préviendrait personne.
                    'valeur' => ['required', 'array', 'min:1'],
                    'valeur.*' => ['string', 'distinct', Rule::in($this->rolesNotifiables())],
                ]
                : ['valeur' => ['required', 'array']],
            default => match (true) {
                str_contains($parametre->cle, '.heure_') => ['valeur' => ['required', 'date_format:H:i']],
                $parametre->cle === 'comptes.charte_version_courante' => [
                    'valeur' => ['required', 'string', 'max:40', $this->texteDeCharteExistant()],
                ],
                default => ['valeur' => ['required', 'string', 'max:255']],
            },
        };
    }

    /**
     * CHANGER DE VERSION DE CHARTE sans en avoir déposé le texte bloquerait tous
     * les volontaires : le serveur leur demanderait d'accepter un texte qu'il ne
     * peut pas leur montrer. La version n'est acceptée que si son texte existe.
     */
    private function texteDeCharteExistant(): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec) {
            if (! app(ServiceCharte::class)->existe((string) $valeur)) {
                $echec("Aucun texte de charte n'existe pour la version « {$valeur} » : "
                    .'déposez-le avant de changer de version.');
            }
        };
    }

    /**
     * LES RÔLES QU'UNE MATRICE DE NOTIFICATION PEUT CONTENIR.
     *
     * MatriceNotification ne sait croiser avec le territoire que le
     * superviseur (son centre), le chef d'antenne et le contrôleur terrain (leur
     * région). Tout autre rôle y est prévenu AU NATIONAL : ajouter « opérateur
     * de kit » enverrait chaque alerte aux 966 opérateurs du pays. Ces rôles-là
     * sont donc refusés à la saisie, pas découverts à la première alerte.
     */
    private function rolesNotifiables(): array
    {
        return [
            RolePnvb::VolontaireSuperviseur->value,
            RolePnvb::ControleurTerrain->value,
            RolePnvb::ChefAntenneRegional->value,
            RolePnvb::AdministrateurNational->value,
            RolePnvb::SuperAdministrateur->value,
        ];
    }

    private function messages(Parametre $parametre): array
    {
        // Une liste vide échoue dès « required », avant « min » : les deux
        // règles doivent donc porter le même message pour une matrice.
        $matriceVide = $parametre->type_valeur === 'json'
            ? 'Cochez au moins un rôle : une matrice vide ne préviendrait personne.'
            : null;

        return [
            'valeur.required' => $matriceVide ?? 'Indiquez une valeur.',
            'valeur.min' => $matriceVide ?? 'Ce paramètre ne peut pas être négatif.',
            'valeur.integer' => 'Ce paramètre attend un nombre entier.',
            'valeur.numeric' => 'Ce paramètre attend un nombre.',
            'valeur.boolean' => 'Ce paramètre attend oui ou non.',
            'valeur.date_format' => 'Indiquez une heure au format HH:MM, par exemple 07:30.',
            'valeur.array' => 'Ce paramètre attend une liste.',
            'valeur.*.in' => 'Ce rôle ne peut pas recevoir les alertes d’incident : '
                .'il serait prévenu dans tout le pays.',
            'valeur.*.distinct' => 'Un même rôle est coché deux fois.',
        ];
    }

    /** La valeur telle qu'elle est stockée, sous la même forme que le seeder. */
    private function serialiser(Parametre $parametre, mixed $valeur): string
    {
        return match ($parametre->type_valeur) {
            'json' => json_encode(array_values((array) $valeur), JSON_UNESCAPED_UNICODE),
            'booleen' => filter_var($valeur, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'entier' => (string) (int) $valeur,
            'decimal' => (string) (float) $valeur,
            default => (string) $valeur,
        };
    }

    private function presenter(Parametre $parametre): array
    {
        return [
            'cle' => $parametre->cle,
            'libelle' => $parametre->libelle,
            'description' => $parametre->description,
            'groupe' => $parametre->groupe,
            'type_valeur' => $parametre->type_valeur,
            'valeur' => $parametre->valeurTypee(),
            'modifiable_par' => $parametre->modifiable_par,
            'modifie_le' => $parametre->updated_at?->toIso8601String(),
        ];
    }
}
