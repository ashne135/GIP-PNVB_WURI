<?php

namespace App\Services\Kits;

use App\Enums\TypeMouvementKit;
use App\Models\Affectation;
use App\Models\Kit;
use App\Models\KitMouvement;
use App\Models\Parametre;
use App\Models\User;
use App\Models\Volontaire;
use Illuminate\Support\Facades\DB;

/**
 * TOUS LES MOUVEMENTS DE KIT PASSENT PAR ICI (cadrage, section 13).
 *
 * Un mouvement, c'est deux choses indissociables : une LIGNE DE JOURNAL qui
 * dit ce qui s'est passé, et un ÉTAT DU KIT qui change. Les séparer, c'est
 * accepter qu'un jour le journal dise une chose et le parc une autre — et sur
 * 966 kits confiés à des agents dispersés sur douze régions, cet écart ne se
 * rattrape jamais.
 *
 * D'où la règle : l'écriture du journal et la mise à jour du kit sont dans LA
 * MÊME TRANSACTION, et aucun autre code ne touche à volontaire_detenteur_id.
 *
 * LE KIT SUIT LA PERSONNE, PAS LE SITE. Un changement de site n'est donc pas une
 * restitution : le détenteur ne change pas, seule la localisation bouge.
 */
class ServiceMouvementsKit
{
    public function __construct(private readonly ServiceAlertesKits $alertes) {}

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function declarer(
        Kit $kit,
        TypeMouvementKit $type,
        User $auteur,
        array $donnees = []
    ): KitMouvement {
        // Idempotence hors ligne : un uuid déjà reçu rend le mouvement
        // existant, sans rejouer ses effets sur l'état du kit.
        if (! blank($donnees['uuid_client'] ?? null)) {
            $existant = KitMouvement::query()->where('uuid_client', $donnees['uuid_client'])->first();

            if ($existant) {
                return $existant;
            }
        }

        $this->verifier($kit, $type, $donnees);

        $mouvement = DB::transaction(function () use ($kit, $type, $auteur, $donnees) {
            $mouvement = $this->journaliser($kit, $type, $auteur, $donnees);

            $this->appliquerAuKit($kit, $type, $donnees);

            return $mouvement;
        });

        // Les alertes partent APRÈS la transaction : un mouvement enregistré ne
        // doit pas être annulé parce qu'une alerte n'a pas pu être publiée.
        $this->alerter($kit->fresh(), $type, $mouvement, $auteur, $donnees);

        return $mouvement->fresh(['kit', 'source.user', 'destination.user', 'effectuePar']);
    }

    // ------------------------------------------------------------------
    // Vérifications, avant toute écriture
    // ------------------------------------------------------------------

    private function verifier(Kit $kit, TypeMouvementKit $type, array $donnees): void
    {
        match ($type) {
            TypeMouvementKit::Remise => $this->verifierRemise($kit, $donnees),
            TypeMouvementKit::Transfert => $this->verifierTransfert($kit, $donnees),
            TypeMouvementKit::Restitution,
            TypeMouvementKit::ChangementSite,
            TypeMouvementKit::Panne,
            TypeMouvementKit::PerteVol => $this->verifierDetention($kit, $type),
        };

        // L'état constaté est exigé partout où du matériel change de mains :
        // sans lui, on ne saura jamais qui a cassé quoi.
        if ($type->exigeUnePhoto() && blank($donnees['etat_constate'] ?? null)) {
            throw new \DomainException(
                "Constatez l'état du kit {$kit->reference} avant de valider ce mouvement : "
                ."c'est cet état qui engage la responsabilité de chacun."
            );
        }
    }

    private function verifierRemise(Kit $kit, array $donnees): void
    {
        $destinataire = $this->destinataire($donnees);

        /*
         * DEUX ACTES DISTINCTS, ET C'EST VOULU.
         *
         * La VALIDATION D'UNE VAGUE attribue déjà le kit à son opérateur : le
         * tirage a désigné qui travaille sur quel kit, et le parc doit le
         * refléter le jour même. Mais cette attribution est administrative :
         * personne n'a encore ouvert la mallette.
         *
         * LA REMISE EST L'ACTE PHYSIQUE : on constate l'état, on photographie,
         * et c'est cela qui engage la responsabilité de l'agent. La refuser
         * parce que l'attribution existe déjà rendrait ce constat IMPOSSIBLE
         * après toute validation — c'est-à-dire, en pratique, toujours.
         *
         * On n'oppose donc un refus que si le kit est entre D'AUTRES mains :
         * là, c'est un transfert, avec l'état constaté des deux côtés.
         */
        if ($kit->volontaire_detenteur_id !== null
            && $kit->volontaire_detenteur_id !== $destinataire->id) {
            throw new \DomainException(
                "Le kit {$kit->reference} est déjà détenu par "
                .($kit->detenteur?->matricule ?? 'un autre agent')
                .'. Passez par un transfert ou une restitution.'
            );
        }

        if (! in_array($kit->etat, ['fonctionnel', 'panne'], true)) {
            throw new \DomainException(
                "Le kit {$kit->reference} est « {$kit->etat} » : il ne peut pas être remis à un agent."
            );
        }

        // UN AGENT NE DÉTIENT QU'UN SEUL KIT. La base le garantit par un index
        // unique ; on le dit ici en français plutôt que de laisser remonter une
        // erreur SQL à un agent de terrain. Le kit qu'on lui remet à l'instant
        // ne compte évidemment pas contre lui.
        $dejaDetenu = Kit::query()
            ->where('volontaire_detenteur_id', $destinataire->id)
            ->whereKeyNot($kit->id)
            ->value('reference');

        if ($dejaDetenu) {
            throw new \DomainException(
                "{$destinataire->matricule} détient déjà le kit {$dejaDetenu}. "
                .'Faites-le restituer avant de lui en remettre un autre.'
            );
        }
    }

    private function verifierTransfert(Kit $kit, array $donnees): void
    {
        if ($kit->volontaire_detenteur_id === null) {
            throw new \DomainException(
                "Le kit {$kit->reference} n'est détenu par personne : c'est une remise, pas un transfert."
            );
        }

        $destinataire = $this->destinataire($donnees);

        if ($destinataire->id === $kit->volontaire_detenteur_id) {
            throw new \DomainException('Un kit ne se transfère pas à son propre détenteur.');
        }

        $dejaDetenu = Kit::query()
            ->where('volontaire_detenteur_id', $destinataire->id)
            ->value('reference');

        if ($dejaDetenu) {
            throw new \DomainException(
                "{$destinataire->matricule} détient déjà le kit {$dejaDetenu}."
            );
        }
    }

    private function verifierDetention(Kit $kit, TypeMouvementKit $type): void
    {
        if ($kit->volontaire_detenteur_id === null) {
            throw new \DomainException(
                "Le kit {$kit->reference} n'est détenu par personne : "
                ."« {$type->libelle()} » n'a pas de sens ici."
            );
        }
    }

    private function destinataire(array $donnees): Volontaire
    {
        $id = $donnees['volontaire_destination_id'] ?? null;

        if (! $id) {
            throw new \DomainException('Précisez à quel agent ce kit est remis.');
        }

        return Volontaire::query()->findOrFail($id);
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    private function journaliser(
        Kit $kit,
        TypeMouvementKit $type,
        User $auteur,
        array $donnees
    ): KitMouvement {
        $affectation = $this->affectationCourante($donnees, $kit);

        return KitMouvement::query()->create([
            'uuid_client' => $donnees['uuid_client'] ?? null,
            'kit_id' => $kit->id,
            'type' => $type->value,
            // La source est TOUJOURS le détenteur d'avant, jamais une valeur
            // envoyée par le client : c'est ce que dit le parc, pas ce que
            // croit le téléphone.
            'volontaire_source_id' => $kit->volontaire_detenteur_id,
            'volontaire_destination_id' => in_array(
                $type, [TypeMouvementKit::Remise, TypeMouvementKit::Transfert], true
            ) ? ($donnees['volontaire_destination_id'] ?? null) : null,
            'vague_id' => $affectation?->vague_id,
            'centre_id' => $donnees['centre_id'] ?? $affectation?->centre_id ?? $kit->centre_courant_id,
            'site_id' => $donnees['site_id'] ?? $kit->site_courant_id,
            'etat_constate' => $donnees['etat_constate'] ?? null,
            'commentaire' => $donnees['commentaire'] ?? null,
            'photo_source_chemin' => $donnees['photo_source'] ?? null,
            'photo_destination_chemin' => $donnees['photo_destination'] ?? null,
            'latitude' => $donnees['latitude'] ?? null,
            'longitude' => $donnees['longitude'] ?? null,
            // L'heure du geste vient du téléphone ; effectue_le date l'arrivée.
            'horodatage_telephone' => $donnees['horodatage_telephone'] ?? null,
            'photos_attendues_jusqu_au' => $this->echeancePhotos($type, $donnees),
            'effectue_par' => $auteur->id,
            'effectue_le' => now(),
        ]);
    }

    /** L'effet de chaque type sur l'état du parc. */
    private function appliquerAuKit(Kit $kit, TypeMouvementKit $type, array $donnees): void
    {
        $affectation = $this->affectationCourante($donnees, $kit);

        match ($type) {
            TypeMouvementKit::Remise, TypeMouvementKit::Transfert => $kit->update([
                'volontaire_detenteur_id' => $donnees['volontaire_destination_id'],
                'centre_courant_id' => $donnees['centre_id'] ?? $affectation?->centre_id
                    ?? $kit->centre_courant_id,
                'site_courant_id' => $donnees['site_id'] ?? $kit->site_courant_id,
            ]),

            // Le kit suit la personne : le détenteur ne change pas.
            TypeMouvementKit::ChangementSite => $kit->update([
                'site_courant_id' => $donnees['site_id'] ?? $kit->site_courant_id,
                'centre_courant_id' => $donnees['centre_id'] ?? $kit->centre_courant_id,
            ]),

            TypeMouvementKit::Restitution => $kit->update([
                'volontaire_detenteur_id' => null,
                'site_courant_id' => null,
                // L'état constaté à la restitution devient l'état du kit :
                // un kit rendu endommagé ne repart pas « fonctionnel ».
                'etat' => ($donnees['etat_constate'] ?? null) === 'endommage' ? 'panne' : $kit->etat,
            ]),

            TypeMouvementKit::Panne => $kit->update(['etat' => 'panne']),

            // Perte ou vol : le kit sort du parc, et son détenteur est libéré —
            // sans quoi il ne pourrait jamais en recevoir un autre.
            TypeMouvementKit::PerteVol => $kit->update([
                'etat' => ($donnees['circonstance'] ?? null) === 'vol' ? 'vole' : 'perdu',
                'volontaire_detenteur_id' => null,
                'site_courant_id' => null,
            ]),
        };
    }

    private function alerter(
        Kit $kit,
        TypeMouvementKit $type,
        KitMouvement $mouvement,
        User $auteur,
        array $donnees
    ): void {
        if ($type === TypeMouvementKit::PerteVol) {
            $this->alertes->perteOuVol($kit, $mouvement);

            return;
        }

        // Le cadrage veut une photo des DEUX parties. Elle n'est pas exigée à
        // l'écriture — le geste physique précède souvent la saisie — mais son
        // absence ne passe pas inaperçue.
        $photosIncompletes = blank($donnees['photo_source'] ?? null) || blank($donnees['photo_destination'] ?? null);

        // Photos annoncées par le téléphone : elles partent APRÈS les données
        // (cadrage, section 11.8). L'alerte attend leur échéance ; c'est le job
        // planifié qui la lèvera si elles ne sont jamais arrivées.
        if ($type->exigeUnePhoto() && $photosIncompletes && $mouvement->photos_attendues_jusqu_au === null) {
            $this->alertes->photosManquantes($kit, $mouvement);
        }
    }

    /**
     * L'échéance d'arrivée des photos de constat, quand le téléphone les
     * annonce. Le délai est un paramètre, jamais une constante.
     */
    private function echeancePhotos(TypeMouvementKit $type, array $donnees): ?\Illuminate\Support\Carbon
    {
        if (! $type->exigeUnePhoto() || ! filter_var($donnees['photos_a_suivre'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return now()->addHours(Parametre::entier('kits.delai_photos_constat_heures', 24));
    }

    private function affectationCourante(array $donnees, Kit $kit): ?Affectation
    {
        $volontaireId = $donnees['volontaire_destination_id'] ?? $kit->volontaire_detenteur_id;

        if (! $volontaireId) {
            return null;
        }

        // Le jour du GESTE, quand le téléphone le donne : une restitution faite
        // le dernier jour et remontée après la clôture reste rattachée à sa vague.
        $date = filled($donnees['horodatage_telephone'] ?? null)
            ? \Illuminate\Support\Carbon::parse($donnees['horodatage_telephone'])->toDateString()
            : now()->toDateString();

        return Affectation::query()
            ->where('volontaire_id', $volontaireId)
            ->couvrant($date)
            ->activeDabord()
            ->first();
    }
}
