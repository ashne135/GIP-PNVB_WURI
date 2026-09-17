<?php

namespace App\Http\Controllers\Api;

use App\Enums\NiveauEtude;
use App\Enums\StatutCompte;
use App\Http\Controllers\Controller;
use App\Http\Requests\Volontaires\ModifierVolontaireRequest;
use App\Http\Responses\ReponseApi;
use App\Models\User;
use App\Models\Volontaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LA FICHE D'UN VOLONTAIRE : la consulter, la corriger, la retirer.
 *
 * CE QUE LA MODIFICATION NE TOUCHE JAMAIS :
 *   - la CATÉGORIE : les trois catégories sont étanches, et le modèle refuse
 *     tout changement, quel que soit l'appelant ;
 *   - le MATRICULE : il figure sur des bordereaux déjà distribués.
 *
 * LE NUMÉRO DE TÉLÉPHONE, lui, se corrige : c'est l'identifiant de connexion,
 * et une erreur de saisie condamnerait l'agent à ne jamais se connecter. Le
 * changement ferme les sessions ouvertes.
 *
 * RETIRER n'efface rien (décision du client, 17/09/2026) : la fiche sort des
 * listes et des tirages, son accès se ferme, ses feuilles de présence et ses
 * rapports restent. Le geste se défait.
 */
class VolontairesController extends Controller
{
    public function show(Request $requete, Volontaire $volontaire): JsonResponse
    {
        $this->authorize('view', $volontaire);

        $volontaire->load([
            'user:id,nom,prenoms,telephone,email,statut_compte,etat_remise,derniere_connexion_le',
            'localite:id,nom,commune_id', 'localite.commune:id,nom',
            'regionOrigine:id,code,nom',
            'affectationActive.centre:id,code,nom',
        ]);

        return ReponseApi::succes('Fiche récupérée.', [
            'volontaire' => $volontaire,
            // Le N° CNIB reste masqué pour qui n'est pas habilité : c'est le
            // modèle qui tranche, pas l'écran.
            'numero_cnib' => $volontaire->numeroCnibPour($requete->user()),
            'niveaux' => $this->niveaux(),
        ]);
    }

    public function update(ModifierVolontaireRequest $requete, Volontaire $volontaire): JsonResponse
    {
        $this->authorize('update', $volontaire);

        $valide = $requete->validated();
        $identite = array_intersect_key($valide, array_flip(['nom', 'prenoms', 'telephone', 'email']));
        $fiche = array_diff_key($valide, $identite);

        try {
            DB::transaction(function () use ($volontaire, $identite, $fiche, $requete) {
                if ($identite !== []) {
                    $utilisateur = $volontaire->user;
                    $telephoneChange = array_key_exists('telephone', $identite)
                        && $identite['telephone'] !== $utilisateur->telephone;

                    $utilisateur->update([
                        ...$identite,
                        ...(array_key_exists('nom', $identite)
                            ? ['nom' => mb_strtoupper($identite['nom'], 'UTF-8')]
                            : []),
                    ]);

                    // L'identifiant de connexion a changé : les sessions
                    // ouvertes portent encore l'ancien.
                    if ($telephoneChange) {
                        $utilisateur->tokens()->delete();
                    }
                }

                if ($fiche !== []) {
                    $volontaire->update($fiche);
                }

                activity('volontaire')
                    ->causedBy($requete->user())
                    ->performedOn($volontaire)
                    ->withProperties(['champs' => array_keys($requete->validated())])
                    ->log('Fiche de volontaire modifiée');
            });
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Fiche enregistrée.', [
            'volontaire' => $volontaire->fresh()->load(['user:id,nom,prenoms,telephone,email,statut_compte']),
        ]);
    }

    public function retirer(Request $requete, Volontaire $volontaire): JsonResponse
    {
        $this->authorize('update', $volontaire);

        $valide = $this->validerMotif($requete);

        try {
            DB::transaction(function () use ($volontaire, $valide, $requete) {
                $volontaire->retirer($valide['motif'], $requete->user());
                $this->fermerAcces($volontaire->user);
            });
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            "{$volontaire->matricule} retiré du dispositif : son accès est fermé, "
            .'et ses feuilles de présence comme ses rapports restent consultables.',
            ['volontaire' => $volontaire->fresh()]
        );
    }

    public function reintegrer(Request $requete, Volontaire $volontaire): JsonResponse
    {
        $this->authorize('update', $volontaire);

        $valide = $this->validerMotif($requete);

        try {
            $volontaire->reintegrer($valide['motif'], $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            "{$volontaire->matricule} réintégré, en réserve : il redevient mobilisable pour une vague.",
            ['volontaire' => $volontaire->fresh()]
        );
    }

    /**
     * Retrait EN LOT. Chaque fiche est traitée pour elle-même : une fiche
     * engagée dans une vague ne doit pas empêcher de retirer les autres.
     */
    public function retirerEnLot(Request $requete): JsonResponse
    {
        // Le lot ne désigne pas un modèle unique : le DROIT se vérifie ici, et
        // le PÉRIMÈTRE par le scope, fiche par fiche, juste en dessous.
        abort_unless($requete->user()->can('volontaires.modifier'), 403);

        $valide = $requete->validate([
            'volontaire_ids' => ['required', 'array', 'min:1', 'max:500'],
            'volontaire_ids.*' => ['integer', 'exists:volontaires,id'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'volontaire_ids.required' => 'Choisissez au moins une fiche.',
            'volontaire_ids.max' => 'Vous ne pouvez pas retirer plus de 500 fiches à la fois.',
            'motif.required' => 'Indiquez le motif du retrait : il figurera sur chaque fiche.',
        ]);

        $auteur = $requete->user();
        $retires = [];
        $refusees = [];

        Volontaire::query()
            ->perimetre($auteur)
            ->whereIn('id', $valide['volontaire_ids'])
            ->with('user')
            ->each(function (Volontaire $volontaire) use ($valide, $auteur, &$retires, &$refusees) {
                try {
                    DB::transaction(function () use ($volontaire, $valide, $auteur) {
                        $volontaire->retirer($valide['motif'], $auteur);
                        $this->fermerAcces($volontaire->user);
                    });

                    $retires[] = ['volontaire_id' => $volontaire->id, 'matricule' => $volontaire->matricule];
                } catch (\DomainException $e) {
                    $refusees[] = [
                        'volontaire_id' => $volontaire->id,
                        'matricule' => $volontaire->matricule,
                        'motif' => $e->getMessage(),
                    ];
                }
            });

        $message = count($retires).' fiches retirées.';

        if ($refusees !== []) {
            $message .= ' '.count($refusees).' n\'ont pas pu l\'être : consultez le détail.';
        }

        return ReponseApi::succes($message, ['retires' => $retires, 'refusees' => $refusees]);
    }

    /** Les niveaux de l'échelle, pour les listes déroulantes de la fiche. */
    private function niveaux(): array
    {
        return collect(NiveauEtude::cases())
            ->map(fn (NiveauEtude $n) => ['valeur' => $n->value, 'libelle' => $n->libelle(), 'rang' => $n->rang()])
            ->all();
    }

    private function validerMotif(Request $requete): array
    {
        return $requete->validate(
            ['motif' => ['required', 'string', 'min:5', 'max:500']],
            ['motif.required' => 'Indiquez le motif : il reste inscrit sur la fiche.']
        );
    }

    /**
     * L'accès se ferme avec le retrait. On passe par le statut, jamais par une
     * suppression : le compte porte l'historique des connexions et des remises.
     */
    private function fermerAcces(User $utilisateur): void
    {
        $utilisateur->update(['statut_compte' => StatutCompte::Ferme->value]);
        $utilisateur->tokens()->delete();
    }
}
