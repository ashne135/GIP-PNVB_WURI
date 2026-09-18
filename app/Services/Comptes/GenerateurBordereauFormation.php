<?php

namespace App\Services\Comptes;

use App\Enums\EtatRemise;
use App\Models\RemiseIdentifiants;
use App\Models\User;
use App\Support\NormalisateurTelephone;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Troisième maillon de la cascade : la REMISE EN MAIN PROPRE pendant la
 * formation (cadrage, section 6).
 *
 * « La plateforme génère un BORDEREAU PDF par session, une ligne par volontaire
 * avec son identifiant et un mot de passe temporaire, à découper et à remettre
 * contre signature. »
 *
 * Les mots de passe sont générés ICI, écrits dans le PDF, puis immédiatement
 * hachés en base : le clair n'existe que dans ce document, qui est un document
 * papier à distribuer puis à détruire.
 */
class GenerateurBordereauFormation
{
    /**
     * @param  Collection<int, User>  $utilisateurs
     * @return array{chemin: string, session: string, lignes: int}
     */
    public function generer(Collection $utilisateurs, string $session, User $auteur): array
    {
        $lignes = [];

        foreach ($utilisateurs as $utilisateur) {
            $motDePasse = Str::password(
                length: \App\Models\Parametre::entier('comptes.longueur_mot_de_passe_initial', 10),
                symbols: false
            );

            $utilisateur->forceFill([
                'password' => $motDePasse,
                'doit_changer_mot_de_passe' => true,
            ])->save();

            RemiseIdentifiants::query()->create([
                'user_id' => $utilisateur->id,
                'canal' => 'main_propre',
                'rang' => 3,
                'statut' => 'remis',
                'destinataire' => $utilisateur->telephone,
                'session_formation' => $session,
                'remis_le' => now(),
                'remis_par' => $auteur->id,
            ]);

            $utilisateur->update(['etat_remise' => EtatRemise::RemisMainPropre->value]);

            $lignes[] = [
                'matricule' => $utilisateur->volontaire?->matricule ?? '—',
                'nom_complet' => $utilisateur->nomComplet(),
                // DEUX points d'interrogation, et le second compte autant que le
                // premier : une fiche importée sans profil a bien un volontaire,
                // mais PAS de catégorie. Sans lui, le bordereau d'une session
                // entière échouait en erreur 500 dès qu'un agent était encore
                // « à qualifier ».
                'categorie' => $utilisateur->volontaire?->categorie?->libelle() ?? 'À qualifier',
                'identifiant' => NormalisateurTelephone::pourAffichage($utilisateur->telephone),
                'mot_de_passe' => $motDePasse,
            ];
        }

        $pdf = Pdf::loadView('documents.bordereau-identifiants', [
            'session' => $session,
            'lignes' => $lignes,
            'genere_le' => now(),
            'genere_par' => $auteur->nomComplet(),
        ])->setPaper('a4');

        $chemin = 'bordereaux/bordereau-'.Str::slug($session).'-'.now()->format('Ymd-His').'.pdf';
        Storage::put($chemin, $pdf->output());

        activity('compte')
            ->causedBy($auteur)
            ->withProperties(['session' => $session, 'lignes' => count($lignes)])
            ->log('Bordereau de remise des identifiants généré');

        return ['chemin' => $chemin, 'session' => $session, 'lignes' => count($lignes)];
    }
}
