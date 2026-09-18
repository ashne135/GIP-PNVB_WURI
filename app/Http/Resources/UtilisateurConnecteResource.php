<?php

namespace App\Http\Resources;

use App\Models\Parametre;
use App\Services\Comptes\ServicePerimetre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * L'utilisateur connecté, avec RÔLES, PERMISSIONS et PÉRIMÈTRE.
 *
 * Point de vigilance de la tâche 2 : la réponse de connexion renvoie les trois.
 * Le client s'en sert pour construire son interface ; le serveur, lui, revérifie
 * tout à chaque requête (Policies + scope perimetre()). Masquer un bouton dans
 * React ne sécurise rien — c'est écrit noir sur blanc dans le cadrage.
 */
class UtilisateurConnecteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $volontaire = $this->volontaire;
        $versionCharte = Parametre::valeur('comptes.charte_version_courante', '2026.1');

        return [
            'utilisateur' => [
                'id' => $this->id,
                'telephone' => $this->telephone,
                'email' => $this->email,
                'nom' => $this->nom,
                'prenoms' => $this->prenoms,
                'nom_complet' => $this->nomComplet(),
                'statut_compte' => $this->statut_compte->value,
                'statut_compte_libelle' => $this->statut_compte->libelle(),
                'peut_saisir' => $this->statut_compte->autoriseSaisie(),
                'region_id' => $this->region_id,
            ],

            'volontaire' => $volontaire ? [
                'id' => $volontaire->id,
                'matricule' => $volontaire->matricule,
                // La catégorie peut être NULLE : une fiche importée sans profil
                // attend sa qualification. Le téléphone doit pouvoir afficher ce
                // profil-là plutôt que de recevoir une erreur 500.
                'categorie' => $volontaire->categorie?->value,
                'categorie_libelle' => $volontaire->categorie?->libelle() ?? 'À qualifier',
                'statut' => $volontaire->statut->value,
                'affectation_tournante' => $volontaire->categorie?->estTournante() ?? false,
                'localite_id' => $volontaire->localite_id,
            ] : null,

            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'perimetre' => app(ServicePerimetre::class)->pour($this->resource),

            // Ce que le client doit imposer à l'agent AVANT toute autre action.
            //
            // La charte n'est demandée qu'aux VOLONTAIRES : c'est leur position
            // que relève le mécanisme de rapprochement, ce sont eux que la
            // charte engage (cadrage, section 8.4). Un chef d'antenne ou un
            // observateur n'a pas de relevé de position — l'annoncer ici alors
            // que le middleware ne l'exige pas d'eux afficherait une obligation
            // qui n'existe pas.
            'actions_requises' => [
                'changer_mot_de_passe' => (bool) $this->doit_changer_mot_de_passe,
                'accepter_charte' => $volontaire !== null && ! $this->aAccepteLaCharte($versionCharte),
                'version_charte' => $versionCharte,
            ],

            /*
             * Les réglages que le CLIENT doit connaître pour ne pas promettre ce
             * que le serveur refusera.
             *
             * Le contrôle, lui, reste entièrement serveur : ceci n'évite qu'un
             * malentendu. Sur le mobile il en évite un grave — un signal part
             * dans la file PUIS sur le réseau : sans connaître ce réglage,
             * l'agent hors zone croirait avoir signalé son arrivée et ne
             * l'apprendrait qu'au retour du réseau, son signal rejeté.
             */
            'reglages' => [
                'bloquer_signal_hors_zone' => Parametre::booleen('presence.bloquer_signal_hors_zone', false),
            ],
        ];
    }
}
