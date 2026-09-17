<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\DestinataireIncident;
use App\Models\ImpactIncident;
use App\Models\MesureIncident;
use App\Models\NatureIncident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * LES LISTES DU CANEVAS D'INCIDENT : natures (C), impacts (E), mesures (H),
 * destinataires (I).
 *
 * Elles vivent en table pour que leurs libellés changent sans redéploiement.
 * Trois règles tiennent l'historique debout :
 *
 *  - PAS DE SUPPRESSION. Des incidents y sont rattachés ; une entrée qui ne
 *    sert plus se DÉSACTIVE.
 *
 *  - LE CODE NE CHANGE PAS. Il est généré à la création et sert aux
 *    statistiques ; seul le libellé se corrige.
 *
 *  - DÉSACTIVER RETIRE L'ENTRÉE DES NOUVEAUX FORMULAIRES (le canevas ne sert que
 *    les entrées actives), mais une déclaration saisie hors ligne AVANT la
 *    désactivation reste acceptée à la synchronisation : la refuser ferait
 *    perdre un incident réel pour un changement de liste.
 *
 * Droit : incidents.nomenclatures, administration nationale (décision du client,
 * 17/09/2026). Les listes sont nationales : aucun périmètre ne s'y applique.
 */
class NomenclaturesIncidentController extends Controller
{
    private const LISTES = [
        'natures' => ['modele' => NatureIncident::class, 'libelle' => 'Nature de l\'incident'],
        'impacts' => ['modele' => ImpactIncident::class, 'libelle' => 'Impact constaté'],
        'mesures' => ['modele' => MesureIncident::class, 'libelle' => 'Mesure immédiate'],
        'destinataires' => ['modele' => DestinataireIncident::class, 'libelle' => 'Personne ou service informé'],
    ];

    public function index(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $listes = [];

        foreach (self::LISTES as $cle => $definition) {
            $listes[] = [
                'cle' => $cle,
                'libelle' => $definition['libelle'],
                'entrees' => $definition['modele']::query()
                    ->withCount('incidents')
                    ->orderBy('ordre')
                    ->orderBy('libelle')
                    ->get(['id', 'code', 'libelle', 'libelle_moore', 'libelle_dioula', 'ordre', 'actif']),
            ];
        }

        return ReponseApi::succes('Listes du canevas d\'incident récupérées.', $listes);
    }

    public function store(Request $requete, string $liste): JsonResponse
    {
        $this->autoriser($requete);
        $modele = $this->modele($liste);

        $valide = $requete->validate($this->regles($modele), $this->messages());

        $entree = new $modele;
        $entree->fill([
            ...$valide,
            'code' => $this->codeLibre($modele, $valide['libelle']),
            'ordre' => $valide['ordre'] ?? ((int) $modele::query()->max('ordre')) + 1,
            'actif' => true,
        ])->save();

        activity('incident')
            ->causedBy($requete->user())
            ->performedOn($entree)
            ->withProperties(['liste' => $liste, 'code' => $entree->code, 'libelle' => $entree->libelle])
            ->log('Entrée ajoutée au canevas d\'incident');

        return ReponseApi::succes("« {$entree->libelle} » ajouté. Il apparaît dans les nouveaux formulaires.", $entree, 201);
    }

    public function update(Request $requete, string $liste, int $id): JsonResponse
    {
        $this->autoriser($requete);
        $modele = $this->modele($liste);
        $entree = $modele::query()->findOrFail($id);

        $valide = $requete->validate([
            ...$this->regles($modele, $entree),
            'actif' => ['sometimes', 'boolean'],
            'code' => ['prohibited'],
        ], [
            ...$this->messages(),
            'code.prohibited' => 'Le code d\'une entrée ne change pas : il sert aux statistiques.',
        ]);

        $avant = $entree->only(['libelle', 'libelle_moore', 'libelle_dioula', 'ordre', 'actif']);
        $entree->update($valide);

        activity('incident')
            ->causedBy($requete->user())
            ->performedOn($entree)
            ->withProperties(['liste' => $liste, 'code' => $entree->code, 'avant' => $avant, 'apres' => $entree->only(array_keys($avant))])
            ->log('Entrée du canevas d\'incident modifiée');

        $message = match (true) {
            array_key_exists('actif', $valide) && ! $valide['actif'] && $avant['actif'] =>
                "« {$entree->libelle} » désactivé : il n'apparaît plus dans les nouveaux formulaires. "
                .'Les incidents qui le portent le gardent.',
            array_key_exists('actif', $valide) && $valide['actif'] && ! $avant['actif'] =>
                "« {$entree->libelle} » réactivé.",
            default => "« {$entree->libelle} » enregistré.",
        };

        return ReponseApi::succes($message, $entree->loadCount('incidents'));
    }

    /** @param class-string<Model> $modele */
    private function regles(string $modele, ?Model $entree = null): array
    {
        $table = (new $modele)->getTable();

        return [
            'libelle' => [
                'required', 'string', 'max:160',
                Rule::unique($table, 'libelle')->ignore($entree?->getKey()),
            ],
            'libelle_moore' => ['nullable', 'string', 'max:160'],
            'libelle_dioula' => ['nullable', 'string', 'max:160'],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    private function messages(): array
    {
        return [
            'libelle.required' => 'Indiquez le libellé en français.',
            'libelle.unique' => 'Cette liste contient déjà une entrée portant ce libellé.',
            'libelle.max' => 'Le libellé ne doit pas dépasser 160 caractères.',
        ];
    }

    /** Le code dérive du libellé, et reste unique dans sa liste. */
    private function codeLibre(string $modele, string $libelle): string
    {
        $base = Str::limit(Str::slug($libelle, '_'), 55, '') ?: 'entree';
        $code = $base;
        $rang = 2;

        while ($modele::query()->where('code', $code)->exists()) {
            $code = "{$base}_{$rang}";
            $rang++;
        }

        return $code;
    }

    /** @return class-string<Model> */
    private function modele(string $liste): string
    {
        abort_unless(isset(self::LISTES[$liste]), 404);

        return self::LISTES[$liste]['modele'];
    }

    private function autoriser(Request $requete): void
    {
        abort_unless($requete->user()->can('incidents.nomenclatures'), 403);
    }
}
