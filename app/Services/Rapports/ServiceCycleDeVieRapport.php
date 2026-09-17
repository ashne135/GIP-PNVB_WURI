<?php

namespace App\Services\Rapports;

use App\Enums\ActeVisa;
use App\Enums\StatutRapport;
use App\Models\RapportCorrection;
use App\Models\RapportJournalier;
use App\Models\RapportVisa;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CYCLE DE VIE ET CHAÎNE DE VISAS (cadrage v2, section 9).
 *
 *     brouillon → soumis → visé → clos
 *                    ↘ rejeté → (correction, puis nouvelle soumission)
 *
 * Deux règles que ce service fait respecter, et qui ne sont jamais laissées à
 * l'interface :
 *   - un rapport visé n'est plus modifiable par son auteur ;
 *   - un rejet exige un motif.
 *
 * Chaque transition écrit une ligne dans rapport_visas : acte, auteur, rôle
 * tenu, date, heure ET POSITION. La signature et le visa sont électroniques —
 * identité, horodatage, trace.
 */
class ServiceCycleDeVieRapport
{
    /**
     * L'auteur signe et soumet son rapport à son supérieur.
     *
     * @param  array{latitude?: float, longitude?: float, horodatage_telephone?: string}  $position
     */
    public function soumettre(RapportJournalier $rapport, User $auteur, array $position = []): RapportJournalier
    {
        $this->exigerTransition($rapport, StatutRapport::Soumis);

        return DB::transaction(function () use ($rapport, $auteur, $position) {
            $rapport->update([
                'statut' => StatutRapport::Soumis->value,
                'soumis_le' => now(),
                'motif_rejet' => null,
            ]);

            $this->tracer($rapport, ActeVisa::Signature, $auteur, null, $position);

            return $rapport->fresh();
        });
    }

    /** Le supérieur vise : le rapport devient définitif et remonte d'un niveau. */
    public function viser(
        RapportJournalier $rapport,
        User $viseur,
        ?string $commentaire = null,
        array $position = []
    ): RapportJournalier {
        $this->exigerTransition($rapport, StatutRapport::Vise);

        return DB::transaction(function () use ($rapport, $viseur, $commentaire, $position) {
            $rapport->update([
                'statut' => StatutRapport::Vise->value,
                'vise_le' => now(),
            ]);

            $this->tracer($rapport, ActeVisa::Visa, $viseur, $commentaire, $position);

            return $rapport->fresh();
        });
    }

    /**
     * Le supérieur rejette pour correction. LE MOTIF EST OBLIGATOIRE : sans
     * lui, l'auteur ne sait pas quoi corriger.
     */
    public function rejeter(
        RapportJournalier $rapport,
        User $viseur,
        string $motif,
        array $position = []
    ): RapportJournalier {
        $this->exigerTransition($rapport, StatutRapport::Rejete);

        if (trim($motif) === '') {
            throw new \DomainException('Indiquez ce qui doit être corrigé : un rejet sans motif est inexploitable.');
        }

        return DB::transaction(function () use ($rapport, $viseur, $motif, $position) {
            $rapport->update([
                'statut' => StatutRapport::Rejete->value,
                'motif_rejet' => $motif,
                'vise_le' => null,
            ]);

            $this->tracer($rapport, ActeVisa::Rejet, $viseur, $motif, $position);

            return $rapport->fresh();
        });
    }

    public function cloturer(RapportJournalier $rapport, User $auteur, array $position = []): RapportJournalier
    {
        $this->exigerTransition($rapport, StatutRapport::Clos);

        return DB::transaction(function () use ($rapport, $auteur, $position) {
            $rapport->update(['statut' => StatutRapport::Clos->value]);
            $this->tracer($rapport, ActeVisa::Cloture, $auteur, null, $position);

            return $rapport->fresh();
        });
    }

    /**
     * Corrige une valeur PRÉ-REMPLIE, en gardant trace de l'original.
     *
     * Le cadrage autorise la correction d'un chiffre agrégé, mais impose de
     * pouvoir expliquer plus tard l'écart entre deux niveaux : d'où la valeur
     * d'origine, la valeur retenue, le motif et l'auteur.
     */
    public function corriger(
        RapportJournalier $rapport,
        string $champ,
        mixed $valeurOrigine,
        mixed $valeurCorrigee,
        string $motif,
        User $auteur
    ): RapportCorrection {
        if (trim($motif) === '') {
            throw new \DomainException(
                'Indiquez pourquoi vous corrigez ce chiffre : il vient des rapports de vos agents.'
            );
        }

        $correction = RapportCorrection::query()->create([
            'rapport_id' => $rapport->id,
            'champ' => $champ,
            'valeur_origine' => $valeurOrigine === null ? null : (string) $valeurOrigine,
            'valeur_corrigee' => $valeurCorrigee === null ? null : (string) $valeurCorrigee,
            'motif' => $motif,
            'corrige_par' => $auteur->id,
            'corrige_le' => now(),
        ]);

        activity('rapport')
            ->causedBy($auteur)
            ->performedOn($rapport)
            ->withProperties([
                'champ' => $champ,
                'origine' => $valeurOrigine,
                'corrigee' => $valeurCorrigee,
                'motif' => $motif,
            ])
            ->log('Correction d\'une valeur pré-remplie');

        return $correction;
    }

    /** Refuse une transition que le cycle de vie n'autorise pas. */
    private function exigerTransition(RapportJournalier $rapport, StatutRapport $cible): void
    {
        if ($rapport->statut->peutAllerVers($cible)) {
            return;
        }

        throw new \DomainException(sprintf(
            'Ce rapport est « %s » : il ne peut pas passer à « %s ».',
            $rapport->statut->libelle(),
            $cible->libelle()
        ));
    }

    private function tracer(
        RapportJournalier $rapport,
        ActeVisa $acte,
        User $auteur,
        ?string $commentaire,
        array $position
    ): RapportVisa {
        return RapportVisa::query()->create([
            'rapport_id' => $rapport->id,
            'acte' => $acte->value,
            'user_id' => $auteur->id,
            'role_tenu' => $auteur->getRoleNames()->first() ?? 'inconnu',
            'commentaire' => $commentaire,
            'latitude' => $position['latitude'] ?? null,
            'longitude' => $position['longitude'] ?? null,
            'horodatage_telephone' => $position['horodatage_telephone'] ?? null,
            'effectue_le' => now(),
        ]);
    }
}
