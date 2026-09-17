<?php

namespace App\Services\Incidents;

use App\Enums\GraviteIncident;
use App\Enums\RolePnvb;
use App\Models\Centre;
use App\Models\Incident;
use App\Models\Parametre;
use App\Models\UniteSupervision;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * QUI EST PRÉVENU, POUR QUEL NIVEAU DE GRAVITÉ.
 *
 * Le paramètre ne donne que des NOMS DE RÔLES. Les transformer en personnes est
 * tout le travail, et c'est là que se joue la justesse du dispositif :
 *
 *   « volontaire_superviseur » ne veut pas dire les 483 superviseurs du pays.
 *   Il veut dire LE superviseur du centre où l'incident s'est produit.
 *
 * Prévenir tout un rôle sur 12 régions rendrait l'alerte inutile en une
 * semaine — chacun apprendrait à l'ignorer. La matrice croise donc TOUJOURS le
 * rôle avec le TERRITOIRE de l'incident : centre pour les superviseurs, région
 * pour le chef d'antenne et le contrôleur terrain, national pour le reste.
 */
class MatriceNotification
{
    /**
     * Les rôles à prévenir pour ce niveau, éventuellement relevés d'un ou
     * plusieurs crans par l'escalade.
     *
     * @return array<int, string>
     */
    public function rolesPour(GraviteIncident $gravite, int $niveauEscalade = 0): array
    {
        $roles = $this->rolesDuNiveau($gravite);

        // CHAQUE ESCALADE AJOUTE LE NIVEAU AU-DESSUS, sans jamais retirer les
        // précédents : le superviseur reste informé de ce qui se passe chez lui,
        // même quand l'affaire est remontée au national.
        for ($cran = 1; $cran <= $niveauEscalade; $cran++) {
            $superieur = GraviteIncident::tryFrom($gravite->value + $cran);

            if (! $superieur) {
                // Déjà au sommet : on y ajoute le super administrateur, et la
                // liste ne grandit plus. Une escalade sans plafond enverrait
                // indéfiniment les mêmes alertes aux mêmes personnes.
                $roles[] = RolePnvb::SuperAdministrateur->value;

                break;
            }

            $roles = [...$roles, ...$this->rolesDuNiveau($superieur)];
        }

        return array_values(array_unique($roles));
    }

    /**
     * Les personnes à prévenir, rôle par rôle, DANS LE TERRITOIRE DE L'INCIDENT.
     *
     * @return Collection<int, User>
     */
    public function destinatairesPour(Incident $incident, int $niveauEscalade = 0): Collection
    {
        $roles = $this->rolesPour($incident->gravite, $niveauEscalade);

        $destinataires = collect();

        foreach ($roles as $role) {
            $destinataires = $destinataires->merge($this->pourLeRole($role, $incident));
        }

        return $destinataires
            ->unique('id')
            // Le déclarant sait déjà : le prévenir de sa propre déclaration
            // ajouterait du bruit sans rien apprendre à personne.
            ->reject(fn (User $user) => $user->id === $incident->declarant_user_id)
            ->values();
    }

    /** @return Collection<int, User> */
    private function pourLeRole(string $role, Incident $incident): Collection
    {
        return match ($role) {
            RolePnvb::VolontaireSuperviseur->value => $this->superviseursDuCentre($incident),
            RolePnvb::ChefAntenneRegional->value,
            RolePnvb::ControleurTerrain->value => $this->deLaRegion($role, $incident),
            default => $this->auNational($role),
        };
    }

    /** Le ou les superviseurs dont l'unité couvre le centre de l'incident. */
    private function superviseursDuCentre(Incident $incident): Collection
    {
        if (! $incident->centre_id) {
            return collect();
        }

        $idsSuperviseurs = UniteSupervision::query()
            ->where(fn ($q) => $q->where('centre_principal_id', $incident->centre_id)
                ->orWhere('centre_secondaire_id', $incident->centre_id))
            ->pluck('volontaire_superviseur_id');

        return User::query()
            ->whereHas('volontaire', fn ($q) => $q->whereIn('id', $idsSuperviseurs))
            ->where('statut_compte', 'actif')
            ->get();
    }

    /**
     * Les titulaires d'un rôle régional pour la région de l'incident.
     * Un chef d'antenne du Kadiogo n'est pas alerté d'un incident du Sahel.
     */
    private function deLaRegion(string $role, Incident $incident): Collection
    {
        $region = $incident->region_id ?? Centre::query()
            ->whereKey($incident->centre_id)
            ->value('region_id');

        if (! $region) {
            return collect();
        }

        return User::query()
            ->role($role)
            ->where('region_id', $region)
            ->where('statut_compte', 'actif')
            ->get();
    }

    /** @return Collection<int, User> */
    private function auNational(string $role): Collection
    {
        return User::query()
            ->role($role)
            ->where('statut_compte', 'actif')
            ->get();
    }

    /** @return array<int, string> */
    private function rolesDuNiveau(GraviteIncident $gravite): array
    {
        $roles = Parametre::tableau($gravite->cleMatriceNotification(), []);

        return array_values(array_filter($roles, 'is_string'));
    }
}
