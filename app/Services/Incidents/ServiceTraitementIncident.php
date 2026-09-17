<?php

namespace App\Services\Incidents;

use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Section J du canevas : le traitement, « réservé aux responsables habilités ».
 *
 * Le cycle est volontairement court — nouveau, pris en charge, en cours,
 * résolu, clos — et chaque passage écrit une ligne d'action horodatée et
 * nominative. Une fiche d'incident sans historique de traitement ne vaut rien
 * le jour où l'on cherche à comprendre pourquoi personne n'a bougé.
 *
 * LA PRISE EN CHARGE ARRÊTE L'ESCALADE. C'est sa seule fonction automatique, et
 * c'est la plus importante : dès qu'un responsable s'est nommé, le compteur
 * cesse de courir.
 */
class ServiceTraitementIncident
{
    /** @var array<string, array<int, string>> Les passages autorisés d'un statut à l'autre. */
    private const TRANSITIONS = [
        'nouveau' => ['pris_en_charge'],
        'pris_en_charge' => ['en_cours', 'resolu'],
        'en_cours' => ['resolu'],
        'resolu' => ['cloture', 'en_cours'],
        'cloture' => ['en_cours'],
    ];

    /**
     * Un responsable se nomme. C'est ce geste, et lui seul, qui arrête
     * l'escalade : tant que personne ne l'a fait, l'incident remonte.
     */
    public function prendreEnCharge(Incident $incident, User $responsable, ?string $commentaire = null): Incident
    {
        if ($incident->statut !== 'nouveau') {
            throw new \DomainException(
                "Cet incident est déjà « {$this->libelle($incident->statut)} » : "
                .'il a été pris en charge par '
                .($incident->responsable?->nomComplet() ?? 'un autre responsable').'.'
            );
        }

        return DB::transaction(function () use ($incident, $responsable, $commentaire) {
            $ancien = $incident->statut;

            $incident->update([
                'statut' => 'pris_en_charge',
                'responsable_traitement_user_id' => $responsable->id,
                'pris_en_charge_le' => now(),
                // L'ESCALADE S'ARRÊTE ICI : plus d'échéance, plus de relance.
                'echeance_escalade' => null,
            ]);

            $this->tracer($incident, $responsable, 'prise_en_charge', $ancien, 'pris_en_charge',
                $commentaire ?? 'Incident pris en charge.');

            return $incident->fresh(['responsable', 'actions']);
        });
    }

    /** Avancement du traitement, avec les mesures correctives engagées. */
    public function avancer(
        Incident $incident,
        User $auteur,
        string $statutCible,
        array $donnees = []
    ): Incident {
        $this->exigerTransition($incident, $statutCible);

        return DB::transaction(function () use ($incident, $auteur, $statutCible, $donnees) {
            $ancien = $incident->statut;

            $modifications = ['statut' => $statutCible];

            if (! blank($donnees['mesures_correctives'] ?? null)) {
                $modifications['mesures_correctives'] = $donnees['mesures_correctives'];
            }

            if ($statutCible === 'resolu') {
                $modifications['resolu_le'] = now();
            }

            // Rouvrir efface la date de résolution : garder une date de
            // résolution sur un incident rouvert fausserait tous les délais.
            if ($statutCible === 'en_cours' && in_array($ancien, ['resolu', 'cloture'], true)) {
                $modifications['resolu_le'] = null;
            }

            $incident->update($modifications);

            $this->tracer(
                $incident,
                $auteur,
                $statutCible === 'en_cours' && in_array($ancien, ['resolu', 'cloture'], true)
                    ? 'reouverture'
                    : 'changement_statut',
                $ancien,
                $statutCible,
                $donnees['commentaire'] ?? null
            );

            return $incident->fresh(['responsable', 'actions']);
        });
    }

    /**
     * La clôture demande un RAPPORT DE CLÔTURE. Fermer un incident sans écrire
     * ce qui a été fait laisse la fiche muette pour celui qui la rouvrira dans
     * six mois — et il n'y a pas d'autre mémoire que celle-là.
     */
    public function cloturer(Incident $incident, User $auteur, string $rapport): Incident
    {
        if (trim($rapport) === '') {
            throw new \DomainException(
                'Écrivez ce qui a été fait avant de clore : une fiche close sans rapport '
                .'ne sert plus à rien.'
            );
        }

        $this->exigerTransition($incident, 'cloture');

        return DB::transaction(function () use ($incident, $auteur, $rapport) {
            $ancien = $incident->statut;

            $incident->update([
                'statut' => 'cloture',
                'rapport_cloture' => $rapport,
                'resolu_le' => $incident->resolu_le ?? now(),
                'echeance_escalade' => null,
            ]);

            $this->tracer($incident, $auteur, 'cloture', $ancien, 'cloture', $rapport);

            return $incident->fresh(['responsable', 'actions']);
        });
    }

    /** Un commentaire n'change pas le statut : il documente. */
    public function commenter(Incident $incident, User $auteur, string $commentaire): IncidentAction
    {
        return $this->tracer($incident, $auteur, 'commentaire', null, null, $commentaire);
    }

    /** Ajout de preuves après coup — section G. */
    public function ajouterPreuves(Incident $incident, User $auteur, array $preuves): Incident
    {
        $incident->update([
            'preuves' => array_values(array_unique([...($incident->preuves ?? []), ...$preuves])),
        ]);

        $this->tracer($incident, $auteur, 'ajout_preuve', null, null,
            'Preuves ajoutées : '.implode(', ', $preuves));

        return $incident->fresh();
    }

    private function exigerTransition(Incident $incident, string $cible): void
    {
        $possibles = self::TRANSITIONS[$incident->statut] ?? [];

        if (! in_array($cible, $possibles, true)) {
            throw new \DomainException(
                "Un incident « {$this->libelle($incident->statut)} » ne peut pas passer "
                ."à « {$this->libelle($cible)} »."
            );
        }
    }

    private function tracer(
        Incident $incident,
        ?User $auteur,
        string $type,
        ?string $ancien,
        ?string $nouveau,
        ?string $commentaire
    ): IncidentAction {
        return IncidentAction::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $auteur?->id,
            'type_action' => $type,
            'ancien_statut' => $ancien,
            'nouveau_statut' => $nouveau,
            'commentaire' => $commentaire,
            'effectue_le' => now(),
        ]);
    }

    private function libelle(string $statut): string
    {
        return match ($statut) {
            'nouveau' => 'nouveau',
            'pris_en_charge' => 'pris en charge',
            'en_cours' => 'en cours de traitement',
            'resolu' => 'résolu',
            'cloture' => 'clos',
            default => $statut,
        };
    }
}
