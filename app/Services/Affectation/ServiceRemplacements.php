<?php

namespace App\Services\Affectation;

use App\Enums\CategorieVolontaire;
use App\Enums\MotifReserve;
use App\Enums\StatutAffectation;
use App\Enums\StatutVague;
use App\Enums\StatutVolontaire;
use App\Enums\TypeMouvementKit;
use App\Models\Affectation;
use App\Models\Kit;
use App\Models\KitMouvement;
use App\Models\Remplacement;
use App\Models\User;
use App\Models\Volontaire;
use App\Services\Comptes\ServiceCycleDeVieCompte;
use App\Services\Kits\ServiceAlertesKits;
use App\Services\Kits\ServiceMouvementsKit;
use App\Services\Support\NumeroteurAlerte;
use Illuminate\Support\Facades\DB;

/**
 * REMPLACEMENT D'UN AGENT PAR UN RÉSERVISTE (cadrage, section 6).
 *
 * Le cadrage décrit quatre effets, et ce service les produit tous les quatre,
 * dans une seule transaction — un remplacement à moitié fait laisserait un kit
 * sans détenteur ou un site sans agent :
 *
 *   1. le réserviste prend l'affectation, son accès s'ouvre ;
 *   2. l'agent remplacé bascule dans la réserve, AVEC UN MOTIF OBLIGATOIRE ;
 *   3. le KIT est transféré de l'agent remplacé au remplaçant, avec état
 *      constaté et photo ;
 *   4. l'accès de l'agent remplacé suit la règle de sa catégorie — DISPONIBLE
 *      pour un opérateur ou un superviseur, FERMÉ pour un A-OPK.
 *
 * DEUX RÈGLES FERMES, vérifiées avant toute écriture :
 *   - LES CATÉGORIES SONT ÉTANCHES : on ne remplace un opérateur que par un
 *     opérateur. Un A-OPK ne prend jamais la place d'un OPK.
 *   - LE TRANSFERT DU KIT EST OBLIGATOIRE dès que l'agent sortant en détient
 *     un : le kit suit la personne, il ne reste pas orphelin.
 */
class ServiceRemplacements
{
    public function __construct(
        private readonly ServiceCycleDeVieCompte $cycleDeVie
            = new ServiceCycleDeVieCompte,
        private readonly ServiceMouvementsKit $mouvements
            = new ServiceMouvementsKit(
                new ServiceAlertesKits(
                    new NumeroteurAlerte
                )
            ),
    ) {}

    /**
     * @param  array{
     *     motif: string,
     *     commentaire?: string|null,
     *     etat_kit_constate?: string|null,
     *     photo_source?: string|null,
     *     photo_destination?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null
     * }  $donnees
     */
    public function remplacer(
        Affectation $affectationSortante,
        Volontaire $remplacant,
        array $donnees,
        User $auteur
    ): Remplacement {
        $sortant = $affectationSortante->volontaire;

        $this->verifierEligibilite($affectationSortante, $sortant, $remplacant, $donnees);

        $kit = Kit::query()->where('volontaire_detenteur_id', $sortant->id)->first();

        return DB::transaction(function () use (
            $affectationSortante, $sortant, $remplacant, $donnees, $auteur, $kit
        ) {
            // 1. L'affectation sortante est close AVANT que l'entrante soit
            //    créée : la colonne générée cle_unicite_active n'accepte qu'une
            //    seule affectation active par agent, dans un sens comme dans
            //    l'autre.
            $statutRepris = $affectationSortante->statut;

            $affectationSortante->update([
                'statut' => StatutAffectation::Remplacee->value,
                'date_fin' => now()->toDateString(),
            ]);

            // 2. Le réserviste redevient opérationnel et reprend l'affectation
            //    telle quelle : même centre, même localité, même unité.
            $this->cycleDeVie->mobiliserReserviste($remplacant);

            $affectationEntrante = Affectation::query()->create([
                'vague_id' => $affectationSortante->vague_id,
                'volontaire_id' => $remplacant->id,
                'role_terrain' => $affectationSortante->role_terrain->value,
                'centre_id' => $affectationSortante->centre_id,
                'unite_supervision_id' => $affectationSortante->unite_supervision_id,
                // L'A-OPK reste rattaché à SA localité, pas à celle du sortant :
                // c'est ce qui définit son affectation.
                'localite_id' => $remplacant->localite_id ?? $affectationSortante->localite_id,
                'kit_id' => $kit?->id,
                'date_debut' => now()->toDateString(),
                'statut' => $statutRepris->value,
                'origine' => 'remplacement',
                'affectation_remplacee_id' => $affectationSortante->id,
            ]);

            // 3. Transfert du kit, avec état constaté et photos des deux parties.
            $mouvement = $kit
                ? $this->transfererKit($kit, $sortant, $remplacant, $affectationSortante, $donnees, $auteur)
                : null;

            // 4. Le sortant bascule en réserve avec son motif, et son accès est
            //    recalculé selon la règle de sa catégorie.
            $this->cycleDeVie->basculerEnReserve($sortant, $donnees['motif'], $auteur);

            $remplacement = Remplacement::query()->create([
                'vague_id' => $affectationSortante->vague_id,
                'volontaire_sortant_id' => $sortant->id,
                'volontaire_entrant_id' => $remplacant->id,
                'affectation_sortante_id' => $affectationSortante->id,
                'affectation_entrante_id' => $affectationEntrante->id,
                'motif' => $donnees['motif'],
                'commentaire' => $donnees['commentaire'] ?? null,
                'kit_mouvement_id' => $mouvement?->id,
                'decide_par' => $auteur->id,
                'decide_le' => now(),
            ]);

            // 5. L'accès du remplaçant s'ouvre, mais seulement si l'affectation
            //    reprise est active : une proposition ne notifie personne.
            if ($statutRepris === StatutAffectation::Active) {
                $this->cycleDeVie->ouvrirPourAffectation($remplacant->fresh(), $auteur);
            }

            activity('remplacement')
                ->causedBy($auteur)
                ->performedOn($remplacement)
                ->withProperties([
                    'sortant' => $sortant->matricule,
                    'entrant' => $remplacant->matricule,
                    'motif' => $donnees['motif'],
                    'kit' => $kit?->reference,
                ])
                ->log("Remplacement de {$sortant->matricule} par {$remplacant->matricule}");

            return $remplacement->fresh();
        });
    }

    /**
     * Le vivier de réserve mobilisable, par catégorie.
     * Un agent en réserve peut être remobilisé plus tard : l'historique conserve
     * tous ses passages (cadrage, section 6).
     */
    public function vivierDeReserve(?string $categorie = null, ?int $regionId = null)
    {
        return Volontaire::query()
            ->where('statut', StatutVolontaire::Reserve->value)
            ->when($categorie, fn ($q) => $q->where('categorie', $categorie))
            ->when($regionId, fn ($q) => $q->where('region_origine_id', $regionId))
            ->with(['user:id,nom,prenoms,telephone,statut_compte', 'localite:id,nom']);
    }

    /**
     * Les remplaçants possibles pour une affectation donnée : même catégorie,
     * en réserve, et — pour un A-OPK — rattachés à la même localité, puisqu'il
     * n'est jamais redéployé ailleurs.
     */
    public function remplacantsPossibles(Affectation $affectation)
    {
        $sortant = $affectation->volontaire;

        return $this->vivierDeReserve($sortant->categorie->value)
            ->when(
                $sortant->categorie === CategorieVolontaire::Assistant,
                fn ($q) => $q->where('localite_id', $sortant->localite_id)
            )
            ->whereDoesntHave('affectations', fn ($q) => $q->whereIn('statut', ['proposee', 'active']));
    }

    // ------------------------------------------------------------------

    private function verifierEligibilite(
        Affectation $affectation,
        ?Volontaire $sortant,
        Volontaire $remplacant,
        array $donnees
    ): void {
        if (! $sortant) {
            throw new \DomainException("L'affectation à remplacer n'a pas de titulaire.");
        }

        if (! $affectation->statut->mobiliseLAgent()) {
            throw new \DomainException(
                "Cette affectation est « {$affectation->statut->libelle()} » : "
                .'il n\'y a rien à remplacer.'
            );
        }

        if ($affectation->vague?->statut === StatutVague::Cloturee) {
            throw new \DomainException(
                'Cette vague est clôturée : un remplacement ne s\'y applique plus.'
            );
        }

        // LES TROIS CATÉGORIES SONT ÉTANCHES (cadrage, sections 3 et 15).
        if ($remplacant->categorie !== $sortant->categorie) {
            throw new \DomainException(sprintf(
                'Les catégories sont étanches : %s est %s, il ne peut pas remplacer un %s.',
                $remplacant->matricule,
                $remplacant->categorie?->libelle() ?? 'sans profil',
                $sortant->categorie->libelle()
            ));
        }

        if ($remplacant->id === $sortant->id) {
            throw new \DomainException('Un agent ne peut pas se remplacer lui-même.');
        }

        if ($remplacant->statut !== StatutVolontaire::Reserve) {
            throw new \DomainException(
                "{$remplacant->matricule} n'est pas dans la réserve : "
                .'seul un réserviste peut être mobilisé en remplacement.'
            );
        }

        $dejaEngage = Affectation::query()
            ->where('volontaire_id', $remplacant->id)
            ->whereIn('statut', ['proposee', 'active'])
            ->exists();

        if ($dejaEngage) {
            throw new \DomainException(
                "{$remplacant->matricule} est déjà engagé sur une vague : il n'est pas disponible."
            );
        }

        // L'A-OPK est rattaché en permanence à SA localité : il ne remplace que
        // sur son propre territoire.
        if ($sortant->categorie === CategorieVolontaire::Assistant
            && $remplacant->localite_id !== $sortant->localite_id) {
            throw new \DomainException(
                'Un A-OPK est rattaché en permanence à sa localité : '
                ."{$remplacant->matricule} ne peut remplacer que sur la sienne."
            );
        }

        // LE MOTIF EST OBLIGATOIRE (cadrage, section 6).
        $motifsRecevables = array_map(
            fn (MotifReserve $m) => $m->value,
            MotifReserve::motifsRemplacement()
        );

        if (! in_array($donnees['motif'] ?? null, $motifsRecevables, true)) {
            throw new \DomainException(
                'Indiquez le motif du remplacement : désistement, abandon, indisponibilité ou performance.'
            );
        }

        // LE TRANSFERT DU KIT EST OBLIGATOIRE quand le sortant en détient un.
        $detientUnKit = Kit::query()->where('volontaire_detenteur_id', $sortant->id)->exists();

        if ($detientUnKit && blank($donnees['etat_kit_constate'] ?? null)) {
            throw new \DomainException(
                "{$sortant->matricule} détient un kit : constatez son état avant le transfert "
                .'(bon, usagé, endommagé ou incomplet).'
            );
        }
    }

    /**
     * TRANSFERT lors d'un remplacement (cadrage, section 13) : du remplacé vers
     * le réserviste, avec état constaté et photo par les deux parties.
     *
     * Le geste est DÉLÉGUÉ au service des mouvements de kit. C'est lui qui tient
     * le journal et l'état du parc dans la même transaction, et lui seul qui
     * écrit volontaire_detenteur_id. Réécrire ici la même logique, ce serait
     * accepter qu'un jour les deux divergent — et sur 966 kits dispersés sur
     * douze régions, cet écart ne se rattrape jamais.
     *
     * Le centre est passé explicitement, depuis l'affectation remplacée : à ce
     * moment du traitement, l'affectation du remplaçant vient d'être créée et
     * peut n'être encore qu'une proposition.
     */
    private function transfererKit(
        Kit $kit,
        Volontaire $sortant,
        Volontaire $remplacant,
        Affectation $affectation,
        array $donnees,
        User $auteur
    ): KitMouvement {
        return $this->mouvements->declarer($kit, TypeMouvementKit::Transfert, $auteur, [
            'volontaire_destination_id' => $remplacant->id,
            'centre_id' => $affectation->centre_id,
            'site_id' => $kit->site_courant_id,
            'etat_constate' => $donnees['etat_kit_constate'],
            'commentaire' => $donnees['commentaire'] ?? null,
            'photo_source' => $donnees['photo_source'] ?? null,
            'photo_destination' => $donnees['photo_destination'] ?? null,
            'latitude' => $donnees['latitude'] ?? null,
            'longitude' => $donnees['longitude'] ?? null,
        ]);
    }
}
