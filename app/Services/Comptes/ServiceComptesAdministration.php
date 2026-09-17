<?php

namespace App\Services\Comptes;

use App\Enums\EtatRemise;
use App\Enums\NiveauPerimetre;
use App\Enums\RolePnvb;
use App\Enums\StatutCompte;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LES COMPTES D'ADMINISTRATION : chef d'antenne, contrôleur terrain,
 * observateur, administrateur national, super administrateur.
 *
 * Ils ne viennent pas du recrutement : aucun import ne les crée (voir
 * ComptesAdministrationSeeder). Ils se créent ici, un par un, et SEUL LE SUPER
 * ADMINISTRATEUR le fait — il est le seul à porter roles.attribuer, et créer un
 * compte, c'est attribuer des droits.
 *
 * TROIS DÉCISIONS DU CLIENT (17/09/2026) :
 *
 *  - LE MOT DE PASSE PROVISOIRE EST RENDU UNE SEULE FOIS, au créateur, qui le
 *    transmet. Il n'est ni journalisé ni stocké en clair ; le titulaire le
 *    change à sa première connexion.
 *
 *  - UN COMPTE SE FERME ET SE ROUVRE, avec un motif. La fermeture coupe les
 *    sessions ouvertes : un chef d'antenne parti ne garde pas un jeton valide
 *    sur son téléphone.
 *
 *  - Le cycle de vie automatique (import, affectation, vague) ne concerne que
 *    les VOLONTAIRES. Ce service refuse de toucher un compte de volontaire : ces
 *    accès-là ne changent jamais à la main.
 */
class ServiceComptesAdministration
{
    /** Les rôles qu'on attribue ici. Jamais un rôle de volontaire, jamais « système ». */
    public const ROLES = [
        RolePnvb::ChefAntenneRegional,
        RolePnvb::ControleurTerrain,
        RolePnvb::Observateur,
        RolePnvb::AdministrateurNational,
        RolePnvb::SuperAdministrateur,
    ];

    /**
     * @param  array{nom: string, prenoms: string, telephone: string, email: ?string, role: string, region_id: ?int}  $donnees
     * @return array{compte: User, mot_de_passe: string}
     */
    public function creer(array $donnees, User $auteur): array
    {
        $role = RolePnvb::from($donnees['role']);
        $motDePasse = $this->motDePasseProvisoire();

        $compte = DB::transaction(function () use ($donnees, $role, $motDePasse) {
            $compte = User::query()->create([
                'nom' => mb_strtoupper(trim($donnees['nom']), 'UTF-8'),
                'prenoms' => trim($donnees['prenoms']),
                'telephone' => $donnees['telephone'],
                'email' => $donnees['email'] ?? null,
                'password' => $motDePasse,
                'statut_compte' => StatutCompte::Actif->value,
                'doit_changer_mot_de_passe' => true,
                'region_id' => $this->regionPour($role, $donnees['region_id'] ?? null),
                // Le mot de passe est remis de la main à la main par le créateur.
                'etat_remise' => EtatRemise::RemisMainPropre->value,
                'est_fictif' => false,
            ]);

            $compte->assignRole($role->value);

            return $compte;
        });

        activity('compte')
            ->causedBy($auteur)
            ->performedOn($compte)
            ->withProperties(['role' => $role->value, 'region_id' => $compte->region_id])
            ->log("Compte d'administration créé : {$role->libelle()}");

        return ['compte' => $compte->fresh(), 'mot_de_passe' => $motDePasse];
    }

    /** @param array{nom: string, prenoms: string, email: ?string, role: string, region_id: ?int} $donnees */
    public function modifier(User $compte, array $donnees, User $auteur): User
    {
        $this->exigerCompteAdministration($compte);

        $role = RolePnvb::from($donnees['role']);
        $roleAvant = $this->roleDe($compte);

        if ($compte->is($auteur) && $role !== $roleAvant) {
            throw new \DomainException(
                'Vous ne pouvez pas changer votre propre rôle : demandez-le à un autre super administrateur.'
            );
        }

        if ($roleAvant === RolePnvb::SuperAdministrateur && $role !== RolePnvb::SuperAdministrateur) {
            $this->exigerUnAutreSuperAdministrateur($compte);
        }

        DB::transaction(function () use ($compte, $donnees, $role) {
            $compte->update([
                'nom' => mb_strtoupper(trim($donnees['nom']), 'UTF-8'),
                'prenoms' => trim($donnees['prenoms']),
                'email' => $donnees['email'] ?? null,
                'region_id' => $this->regionPour($role, $donnees['region_id'] ?? null),
            ]);

            $compte->syncRoles([$role->value]);
            // Un changement de rôle change les droits : les jetons en cours
            // portent encore l'ancien profil dans l'application ouverte.
            $compte->tokens()->delete();
        });

        activity('compte')
            ->causedBy($auteur)
            ->performedOn($compte)
            ->withProperties([
                'role_avant' => $roleAvant?->value,
                'role' => $role->value,
                'region_id' => $compte->region_id,
            ])
            ->log("Compte d'administration modifié");

        return $compte->fresh();
    }

    public function fermer(User $compte, string $motif, User $auteur): User
    {
        $this->exigerCompteAdministration($compte);

        if ($compte->is($auteur)) {
            throw new \DomainException('Vous ne pouvez pas fermer votre propre compte.');
        }

        if ($compte->statut_compte === StatutCompte::Ferme) {
            throw new \DomainException('Ce compte est déjà fermé.');
        }

        if ($this->roleDe($compte) === RolePnvb::SuperAdministrateur) {
            $this->exigerUnAutreSuperAdministrateur($compte);
        }

        DB::transaction(function () use ($compte) {
            $compte->update(['statut_compte' => StatutCompte::Ferme->value]);
            $compte->tokens()->delete();
        });

        activity('compte')
            ->causedBy($auteur)
            ->performedOn($compte)
            ->withProperties(['motif' => $motif])
            ->log("Compte d'administration fermé");

        return $compte->fresh();
    }

    public function rouvrir(User $compte, string $motif, User $auteur): User
    {
        $this->exigerCompteAdministration($compte);

        if ($compte->statut_compte !== StatutCompte::Ferme) {
            throw new \DomainException("Ce compte n'est pas fermé.");
        }

        $compte->update(['statut_compte' => StatutCompte::Actif->value]);

        activity('compte')
            ->causedBy($auteur)
            ->performedOn($compte)
            ->withProperties(['motif' => $motif])
            ->log("Compte d'administration rouvert");

        return $compte->fresh();
    }

    /**
     * Un mot de passe perdu. Sans ce geste, « affiché une seule fois »
     * voudrait dire « compte perdu au premier oubli ».
     *
     * @return array{compte: User, mot_de_passe: string}
     */
    public function reinitialiserMotDePasse(User $compte, User $auteur): array
    {
        $this->exigerCompteAdministration($compte);

        if ($compte->is($auteur)) {
            throw new \DomainException(
                'Pour votre propre compte, changez votre mot de passe depuis votre profil.'
            );
        }

        $motDePasse = $this->motDePasseProvisoire();

        DB::transaction(function () use ($compte, $motDePasse) {
            $compte->forceFill([
                'password' => $motDePasse,
                'doit_changer_mot_de_passe' => true,
                'etat_remise' => EtatRemise::RemisMainPropre->value,
            ])->save();

            // L'ancien mot de passe a pu circuler : ses sessions tombent.
            $compte->tokens()->delete();
        });

        activity('compte')
            ->causedBy($auteur)
            ->performedOn($compte)
            ->log("Mot de passe d'un compte d'administration réinitialisé");

        return ['compte' => $compte->fresh(), 'mot_de_passe' => $motDePasse];
    }

    public function roleDe(User $compte): ?RolePnvb
    {
        foreach (self::ROLES as $role) {
            if ($compte->hasRole($role->value)) {
                return $role;
            }
        }

        return null;
    }

    /** Le chef d'antenne et le contrôleur ont une région ; les rôles nationaux n'en ont pas. */
    private function regionPour(RolePnvb $role, ?int $regionId): ?int
    {
        if ($role->niveauPerimetre() !== NiveauPerimetre::SaRegion) {
            return null;
        }

        if (! $regionId) {
            throw new \DomainException("Un compte « {$role->libelle()} » doit être rattaché à une région.");
        }

        return $regionId;
    }

    private function exigerCompteAdministration(User $compte): void
    {
        if ($compte->volontaire()->exists() || $this->roleDe($compte) === null) {
            throw new \DomainException(
                "Ce compte n'est pas un compte d'administration : l'accès d'un volontaire suit "
                .'son affectation et ne se modifie pas à la main.'
            );
        }
    }

    /** Fermer ou rétrograder le dernier super administrateur rendrait la plateforme ingouvernable. */
    private function exigerUnAutreSuperAdministrateur(User $compte): void
    {
        $autres = User::role(RolePnvb::SuperAdministrateur->value)
            ->whereKeyNot($compte->id)
            ->where('statut_compte', '!=', StatutCompte::Ferme->value)
            ->exists();

        if (! $autres) {
            throw new \DomainException(
                "C'est le dernier super administrateur actif : créez-en un autre avant de le fermer ou de changer son rôle."
            );
        }
    }

    private function motDePasseProvisoire(): string
    {
        return Str::password(
            length: max(10, Parametre::entier('comptes.longueur_mot_de_passe_initial', 10)),
            symbols: false
        );
    }
}
