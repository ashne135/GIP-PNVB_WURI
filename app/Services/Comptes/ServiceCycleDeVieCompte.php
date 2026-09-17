<?php

namespace App\Services\Comptes;

use App\Enums\CategorieVolontaire;
use App\Enums\EtatRemise;
use App\Enums\StatutAffectation;
use App\Enums\StatutCompte;
use App\Enums\StatutVolontaire;
use App\Models\Affectation;
use App\Models\User;
use App\Models\Volontaire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cycle de vie des accès (cadrage, section 6).
 *
 * AUCUN changement d'accès n'est fait à la main : tout passe par ce service, lui
 * même appelé par les points de déclenchement métier (validation d'une vague,
 * clôture d'une vague, remplacement) et par le job planifié en filet de
 * sécurité. Chaque changement d'état est journalisé.
 *
 * Règles de fermeture, différentes selon la catégorie :
 *  - OPÉRATEUR et SUPERVISEUR : entre deux vagues, ils passent en DISPONIBLE et
 *    conservent leur accès.
 *  - ASSISTANT : son accès se FERME dès qu'aucune vague n'est active sur sa
 *    localité, et se rouvre automatiquement à la vague suivante.
 *  - RÉSERVISTE : compte fermé, ouvert le jour où il est mobilisé.
 */
class ServiceCycleDeVieCompte
{
    /**
     * Étape 1 du cadrage : création inactive à l'import.
     * Le compte est créé sans accès ; c'est l'affectation qui l'ouvrira.
     */
    public function creerCompteInactif(
        array $donneesUser,
        array $donneesVolontaire,
        ?User $auteur = null
    ): Volontaire {
        return DB::transaction(function () use ($donneesUser, $donneesVolontaire, $auteur) {
            $user = User::query()->create([
                ...$donneesUser,
                'statut_compte' => StatutCompte::Inactif->value,
                'doit_changer_mot_de_passe' => true,
                'etat_remise' => EtatRemise::NonEnvoye->value,
            ]);

            $volontaire = Volontaire::query()->create([
                ...$donneesVolontaire,
                'user_id' => $user->id,
            ]);

            // Le DROIT (section 5) est porté par le rôle Spatie correspondant à
            // la catégorie, jamais par un test sur la colonne categorie elle-même
            // dans les Policies — c'est ce rôle que consultent permissions et
            // Policies pour décider ce que l'agent peut faire.
            //
            // Une fiche importée sans Profil arrive « à qualifier », catégorie
            // nulle : elle ne reçoit AUCUN rôle tant que l'administrateur
            // national ne l'a pas qualifiée. C'est cohérent — sans catégorie,
            // on ne sait pas ce que cet agent a le droit de faire, et le compte
            // est de toute façon inactif.
            if ($volontaire->categorie !== null) {
                $user->assignRole($volontaire->categorie->role()->value);
            }

            $this->journaliser($user, StatutCompte::Inactif, StatutCompte::Inactif, 'creation_import', $auteur);

            return $volontaire;
        });
    }

    /**
     * C'est L'AFFECTATION qui ouvre l'accès (cadrage, section 6). Appelé à la
     * validation d'une vague, pour chaque volontaire qui y reçoit une
     * affectation active.
     */
    public function ouvrirPourAffectation(Volontaire $volontaire, ?User $auteur = null): void
    {
        $user = $volontaire->user;
        $etatAvant = $user->statut_compte;

        if ($etatAvant === StatutCompte::Actif) {
            return;
        }

        $this->changerStatut($user, $etatAvant, StatutCompte::Actif, 'ouverture_affectation', $auteur);

        // Le compte n'a encore jamais reçu ses identifiants : on amorce la
        // cascade courriel > SMS > bordereau. Sans cela, ouvrir l'accès sans
        // jamais l'annoncer laisserait un agent sans moyen de se connecter.
        if ($user->etat_remise === EtatRemise::NonEnvoye) {
            app(ServiceRemiseIdentifiants::class)->amorcerCascade($user, $auteur);
        }
    }

    /**
     * Recalcule l'état d'accès d'UN volontaire selon sa catégorie et sa
     * situation courante. C'est la méthode que le job planifié appelle en
     * boucle, et qu'un déclencheur métier (clôture de vague, remplacement)
     * appelle ponctuellement pour un agent précis.
     */
    public function recalculer(Volontaire $volontaire, ?User $auteur = null): void
    {
        $user = $volontaire->user;
        $etatAvant = $user->statut_compte;
        $etatCible = $this->determinerEtatCible($volontaire);

        if ($etatAvant === $etatCible) {
            return;
        }

        // Appelé par le job planifié, l'auteur reste NUL — et c'est juste :
        // personne n'a décidé ce recalcul, c'est le filet de sécurité qui
        // tourne. Le journal dira « acteur système » plutôt que d'inventer
        // un responsable.
        $this->changerStatut($user, $etatAvant, $etatCible, 'recalcul_planifie', $auteur);
    }

    /**
     * Le changement de statut, et ce qu'il fait aux JETONS DÉJÀ DÉLIVRÉS.
     *
     * Refuser la connexion ne suffit pas à fermer un accès : le téléphone garde
     * son jeton. À la fermeture, ce jeton est restreint au seul envoi du travail
     * fait pendant la mission, pour le délai de rattrapage ; à la réouverture, il
     * retrouve ses droits s'il n'a pas expiré.
     */
    private function changerStatut(
        User $user,
        StatutCompte $avant,
        StatutCompte $apres,
        string $evenement,
        ?User $auteur = null
    ): void {
        $user->update(['statut_compte' => $apres->value]);

        if ($avant->autoriseConnexion() && ! $apres->autoriseConnexion()) {
            app(ServiceAccesRattrapage::class)->restreindre($user);
        } elseif (! $avant->autoriseConnexion() && $apres->autoriseConnexion()) {
            app(ServiceAccesRattrapage::class)->retablir($user);
        }

        $this->journaliser($user, $avant, $apres, $evenement, $auteur);
    }

    /**
     * Détermine l'état cible d'un compte, sans l'écrire — utilisé par
     * recalculer() et testable indépendamment de tout effet de bord.
     */
    public function determinerEtatCible(Volontaire $volontaire): StatutCompte
    {
        // Un compte encore inactif (jamais affecté une seule fois) le reste :
        // ce n'est pas au job planifié de l'activer, seule une affectation le fait.
        if ($volontaire->user->statut_compte === StatutCompte::Inactif) {
            return StatutCompte::Inactif;
        }

        // Un agent RETIRÉ du dispositif n'a plus d'accès, quoi qu'il arrive.
        if ($volontaire->statut === StatutVolontaire::Retire) {
            return StatutCompte::Ferme;
        }

        /*
         * EN RÉSERVE : deux situations que le cadrage traite différemment.
         *
         *  - Le RÉSERVISTE issu de la liste d'attente, jamais mobilisé :
         *    « compte créé mais fermé. Il s'ouvre le jour où il est mobilisé
         *    en remplacement » (section 6).
         *
         *  - L'agent qui a DÉJÀ SERVI et bascule en réserve après un
         *    remplacement : « l'accès de l'agent remplacé suit LA RÈGLE DE SA
         *    CATÉGORIE » (section 6, remplacement). Un opérateur ou un
         *    superviseur conserve donc son accès en DISPONIBLE, comme entre
         *    deux vagues ; un A-OPK, lui, voit le sien fermé.
         *
         * Les confondre reviendrait à couper l'accès d'un opérateur écarté pour
         * indisponibilité, alors qu'il reste dans le dispositif et consultera
         * son historique et ses alertes.
         */
        if ($volontaire->statut === StatutVolontaire::Reserve) {
            $aDejaServi = Affectation::query()
                ->where('volontaire_id', $volontaire->id)
                ->exists();

            if (! $aDejaServi) {
                return StatutCompte::Ferme;
            }

            return $volontaire->categorie?->estTournante()
                ? StatutCompte::Disponible
                : StatutCompte::Ferme;
        }

        $aUneAffectationActive = Affectation::query()
            ->where('volontaire_id', $volontaire->id)
            ->where('statut', StatutAffectation::Active->value)
            ->exists();

        if ($aUneAffectationActive) {
            return StatutCompte::Actif;
        }

        // Entre deux vagues : DISPONIBLE pour les catégories tournantes,
        // FERMÉ pour l'assistant, qui n'existe que sur sa localité.
        return $volontaire->categorie->estTournante()
            ? StatutCompte::Disponible
            : StatutCompte::Ferme;
    }

    /**
     * Bascule en réserve lors d'un remplacement (cadrage, section 6).
     * L'accès de l'agent remplacé suit ensuite la règle normale de sa catégorie
     * via recalculer() — un opérateur remplacé passe DISPONIBLE, un assistant
     * remplacé passe FERMÉ.
     */
    public function basculerEnReserve(Volontaire $volontaire, string $motif, ?User $auteur = null): void
    {
        $volontaire->update([
            'statut' => StatutVolontaire::Reserve->value,
            'motif_reserve' => $motif,
            'date_entree_reserve' => now(),
        ]);

        $this->recalculer($volontaire->fresh(), $auteur);
    }

    /**
     * Mobilisation d'un réserviste : il redevient opérationnel, et
     * recalculer() lui ouvrira l'accès dès qu'il reçoit son affectation active.
     */
    public function mobiliserReserviste(Volontaire $volontaire): void
    {
        $volontaire->update(['statut' => StatutVolontaire::Operationnel->value]);
    }

    private function journaliser(
        User $user,
        StatutCompte $avant,
        StatutCompte $apres,
        string $evenement,
        ?User $auteur = null
    ): void {
        activity('compte')
            // Fermer ou rouvrir un accès se décide : quand un humain en est à
            // l'origine, le journal le nomme. Quand c'est le job planifié,
            // l'auteur reste nul — c'est l'information exacte, pas un trou.
            ->causedBy($auteur)
            ->performedOn($user)
            ->withProperties(['avant' => $avant->value, 'apres' => $apres->value, 'evenement' => $evenement])
            ->log("Changement de statut de compte : {$avant->value} → {$apres->value} ({$evenement})");

        Log::channel('pnvb_comptes')->info('changement_statut_compte', [
            'user_id' => $user->id,
            'telephone' => $user->telephone,
            'avant' => $avant->value,
            'apres' => $apres->value,
            'evenement' => $evenement,
        ]);
    }
}
