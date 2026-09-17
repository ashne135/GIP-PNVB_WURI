<?php

namespace App\Services\Comptes;

use App\Enums\CategorieVolontaire;
use App\Enums\NiveauPerimetre;
use App\Models\Affectation;
use App\Models\Centre;
use App\Models\Region;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;

/**
 * Compose le PÉRIMÈTRE tel qu'il est renvoyé au client à la connexion.
 *
 * Point de vigilance de la tâche 2 : « la réponse de connexion renvoie rôles,
 * permissions et périmètre ». Le client (React, Flutter) a besoin de savoir
 * NON SEULEMENT son niveau, mais aussi sur quels objets concrets il porte —
 * sinon il ne sait pas quoi afficher au démarrage.
 *
 * Ce que ce service renvoie n'est JAMAIS une autorisation : le serveur
 * réapplique le scope perimetre() à chaque requête. C'est une aide à
 * l'affichage, pas un laissez-passer.
 */
class ServicePerimetre
{
    public function pour(User $utilisateur): array
    {
        $niveau = NiveauPerimetre::pour($utilisateur);

        return [
            'niveau' => $niveau->value,
            'libelle' => $niveau->libelle(),
            'regions' => $this->regions($utilisateur, $niveau),
            'centres' => $this->centres($utilisateur, $niveau),
            'nombre_sites' => $this->nombreSites($utilisateur, $niveau),
            'affectation_courante' => $this->affectationCourante($utilisateur),
            'site_du_jour' => $this->siteDuJour($utilisateur),
        ];
    }

    /**
     * Au niveau national, la liste des 12 régions est renvoyée en entier : elle
     * sert de filtre au tableau de bord. Aux autres niveaux, seule la région
     * concernée.
     */
    private function regions(User $utilisateur, NiveauPerimetre $niveau): array
    {
        if ($niveau === NiveauPerimetre::National) {
            return Region::query()
                ->orderBy('nom')
                ->get(['id', 'code', 'nom'])
                ->toArray();
        }

        $idRegion = $utilisateur->idRegionAccessible();

        if ($idRegion === null) {
            return [];
        }

        return Region::query()
            ->where('id', $idRegion)
            ->get(['id', 'code', 'nom'])
            ->toArray();
    }

    /**
     * La liste des centres n'est renvoyée que si elle est courte : au niveau
     * national elle ferait 972 lignes à chaque connexion, sur un réseau de
     * terrain instable. Le client la demandera par une requête dédiée.
     */
    private function centres(User $utilisateur, NiveauPerimetre $niveau): ?array
    {
        if (in_array($niveau, [NiveauPerimetre::National, NiveauPerimetre::SaRegion], true)) {
            return null;
        }

        $ids = $utilisateur->idsCentresAccessibles();

        if ($ids === []) {
            return [];
        }

        return Centre::query()
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'nom', 'commune_id'])
            ->toArray();
    }

    private function nombreSites(User $utilisateur, NiveauPerimetre $niveau): int
    {
        return match ($niveau) {
            NiveauPerimetre::National => Site::query()->count(),
            NiveauPerimetre::SaRegion => Site::query()
                ->where('region_id', $utilisateur->idRegionAccessible())
                ->count(),
            default => count($utilisateur->idsSitesAccessibles()),
        };
    }

    /** L'affectation en cours du volontaire : vague, centre, rôle tenu. */
    private function affectationCourante(User $utilisateur): ?array
    {
        $volontaire = $utilisateur->volontaire;

        if (! $volontaire) {
            return null;
        }

        $affectation = Affectation::query()
            ->with(['vague:id,code,libelle,statut', 'centre:id,code,nom', 'localite:id,nom'])
            ->where('volontaire_id', $volontaire->id)
            ->where('statut', 'active')
            ->first();

        if (! $affectation) {
            return null;
        }

        return [
            'id' => $affectation->id,
            'role_terrain' => $affectation->role_terrain->value,
            'date_debut' => $affectation->date_debut?->toDateString(),
            'vague' => $affectation->vague?->only(['id', 'code', 'libelle']),
            'centre' => $affectation->centre?->only(['id', 'code', 'nom']),
            'localite' => $affectation->localite?->only(['id', 'nom']),
        ];
    }

    /**
     * Le site sur lequel l'agent doit se trouver AUJOURD'HUI.
     *
     * Le kit couvre les sites de son centre en séquence (cadrage, section 4) :
     * l'opérateur suit la tournée en cours. L'assistant, lui, est rattaché en
     * permanence à sa localité — son site est celui de sa localité, et il n'est
     * réellement mobilisé que les jours où le kit y passe.
     *
     * C'est l'information dont le mobile a besoin au premier écran : « où
     * dois-je être et pour quel site est-ce que je saisis ? »
     */
    private function siteDuJour(User $utilisateur): ?array
    {
        $volontaire = $utilisateur->volontaire;

        if (! $volontaire) {
            return null;
        }

        $affectation = Affectation::query()
            ->where('volontaire_id', $volontaire->id)
            ->where('statut', 'active')
            ->first();

        if (! $affectation) {
            return null;
        }

        $aujourdhui = now()->toDateString();

        if ($volontaire->categorie === CategorieVolontaire::Assistant) {
            $site = Site::query()
                ->where('localite_id', $volontaire->localite_id)
                ->where('centre_id', $affectation->centre_id)
                ->first(['id', 'code', 'nom', 'localite_id', 'rayon_zone_metres', 'latitude', 'longitude']);

            if (! $site) {
                return null;
            }

            $tournee = TourneeSite::query()
                ->where('site_id', $site->id)
                ->couvrant($aujourdhui)
                ->first();

            return [
                ...$site->toArray(),
                'kit_present_aujourdhui' => $tournee !== null,
            ];
        }

        $tournee = TourneeSite::query()
            ->with('site:id,code,nom,localite_id,rayon_zone_metres,latitude,longitude')
            ->where('affectation_operateur_id', $affectation->id)
            ->couvrant($aujourdhui)
            ->first();

        if (! $tournee?->site) {
            return null;
        }

        return [
            ...$tournee->site->toArray(),
            'tournee_id' => $tournee->id,
            'ordre' => $tournee->ordre,
            'date_fin' => $tournee->date_fin?->toDateString(),
            'kit_present_aujourdhui' => true,
        ];
    }
}
