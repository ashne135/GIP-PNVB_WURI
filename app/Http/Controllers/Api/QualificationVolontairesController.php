<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategorieVolontaire;
use App\Enums\NiveauEtude;
use App\Http\Controllers\Controller;
use App\Http\Requests\Volontaires\QualifierEnLotRequest;
use App\Http\Responses\ReponseApi;
use App\Models\Volontaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attribution des profils aux fiches importées sans colonne « Profil »
 * (cadrage v2, section 6).
 *
 * « PROFIL est INDISPENSABLE : sans lui, la plateforme ne peut ni créer le bon
 * rôle, ni affecter. Une ligne sans profil est acceptée mais placée en
 * à qualifier, et l'administrateur national dispose d'un écran pour attribuer
 * les profils en lot avant affectation. »
 *
 * Le lot est traité ligne à ligne et NON transactionnellement en bloc : une
 * fiche refusée — un A-OPK sans localité, par exemple — ne doit pas annuler les
 * 400 autres déjà correctes. Le compte rendu dit exactement ce qui est passé et
 * ce qui reste à traiter.
 */
class QualificationVolontairesController extends Controller
{
    /** Les fiches en attente de profil, avec de quoi décider. */
    public function index(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $fiches = Volontaire::query()
            ->aQualifier()
            ->with([
                'user:id,nom,prenoms,telephone,email,statut_compte',
                'localite:id,nom,commune_id',
                'localite.commune:id,nom',
                'regionOrigine:id,code,nom',
            ])
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->whereHas('user', fn ($r) => $r
                    ->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenoms', 'like', "%{$recherche}%")
                    ->orWhere('telephone', 'like', "%{$recherche}%"));
            })
            ->when($requete->filled('import_id'),
                fn ($q) => $q->where('import_id', $requete->integer('import_id')))
            ->orderBy('matricule')
            ->paginate(min($requete->integer('par_page', 50), 200));

        return ReponseApi::succes(
            $fiches->total() === 0
                ? 'Aucune fiche en attente de profil.'
                : "{$fiches->total()} fiches attendent un profil. Attribuez-le avant de planifier une vague.",
            [
                'fiches' => $fiches,
                'profils_possibles' => collect(CategorieVolontaire::cases())
                    ->map(fn (CategorieVolontaire $c) => [
                        'valeur' => $c->value,
                        'libelle' => $c->libelle(),
                        'localite_obligatoire' => $c === CategorieVolontaire::Assistant,
                        // Le niveau exigé, pour que l'écran dise AVANT le clic
                        // quelles fiches demanderont une dérogation.
                        'niveau_minimum' => NiveauEtude::minimumPour($c)->value,
                        'niveau_minimum_libelle' => NiveauEtude::minimumPour($c)->libelle(),
                    ]),
                'niveaux' => collect(NiveauEtude::cases())
                    ->map(fn (NiveauEtude $n) => [
                        'valeur' => $n->value,
                        'libelle' => $n->libelle(),
                        'rang' => $n->rang(),
                    ]),
            ]
        );
    }

    /** Attribution en lot. Chaque ligne est traitée pour elle-même. */
    public function qualifier(QualifierEnLotRequest $requete): JsonResponse
    {
        $this->autoriser($requete);

        $auteur = $requete->user();
        $qualifiees = [];
        $refusees = [];

        foreach ($requete->validated('qualifications') as $ligne) {
            $volontaire = Volontaire::query()->find($ligne['volontaire_id']);

            if (! $volontaire) {
                $refusees[] = [
                    'volontaire_id' => $ligne['volontaire_id'],
                    'motif' => 'Fiche introuvable.',
                ];

                continue;
            }

            try {
                DB::transaction(fn () => $volontaire->qualifier(
                    CategorieVolontaire::from($ligne['categorie']),
                    $auteur,
                    $ligne['localite_id'] ?? null,
                    $ligne['motif_derogation'] ?? null
                ));

                $qualifiees[] = [
                    'volontaire_id' => $volontaire->id,
                    'matricule' => $volontaire->fresh()->matricule,
                    'nom_complet' => $volontaire->user->nomComplet(),
                    'categorie' => $ligne['categorie'],
                ];
            } catch (\DomainException $e) {
                $refusees[] = [
                    'volontaire_id' => $volontaire->id,
                    'matricule' => $volontaire->matricule,
                    'nom_complet' => $volontaire->user->nomComplet(),
                    'motif' => $e->getMessage(),
                ];
            }
        }

        $message = count($qualifiees).' fiches qualifiées.';

        if ($refusees !== []) {
            $message .= ' '.count($refusees).' fiches n\'ont pas pu l\'être : '
                .'consultez le détail pour savoir quoi corriger.';
        }

        return ReponseApi::succes($message, [
            'qualifiees' => $qualifiees,
            'refusees' => $refusees,
            'restantes' => Volontaire::query()->aQualifier()->count(),
        ]);
    }

    private function autoriser(Request $requete): void
    {
        abort_unless($requete->user()->can('volontaires.qualifier'), 403);
    }
}
