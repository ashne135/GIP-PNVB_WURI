<?php

namespace App\Policies;

use App\Models\RapportJournalier;
use App\Models\User;

/**
 * Rapports journaliers et chaîne de visas (cadrage v2, section 9).
 *
 * Deux règles y sont posées, et elles sont le cœur du module :
 *   - « Un rapport visé n'est plus modifiable par son auteur. »
 *   - Le visa appartient au SUPÉRIEUR DÉSIGNÉ, pas à quiconque porte la
 *     permission : avoir le droit de viser ne suffit pas, encore faut-il être
 *     le supérieur de CE rapport-là.
 */
class RapportJournalierPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'rapports.consulter';
    }

    protected function modele(): string
    {
        return RapportJournalier::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'rapports.saisir')
            && $utilisateur->statut_compte->autoriseSaisie();
    }

    /** Seul l'AUTEUR modifie son rapport, et seulement tant qu'il est ouvert. */
    public function update(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $this->estLAuteur($utilisateur, $rapport)
            && $rapport->estModifiableParAuteur()
            && $this->peut($utilisateur, 'rapports.saisir');
    }

    public function soumettre(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $this->estLAuteur($utilisateur, $rapport)
            && $rapport->estModifiableParAuteur();
    }

    /**
     * Viser : il faut la permission ET être le supérieur désigné de ce rapport.
     * Un opérateur ne vise pas le rapport d'un A-OPK qui n'est pas le sien.
     */
    public function viser(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $this->peut($utilisateur, 'rapports.viser')
            && $rapport->attendUnVisa()
            && $this->estLeSuperieurDesigne($utilisateur, $rapport);
    }

    public function rejeter(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $this->viser($utilisateur, $rapport);
    }

    public function cloturer(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $this->autoriser($utilisateur, 'rapports.cloturer', $rapport)
            && $rapport->statut->peutAllerVers(\App\Enums\StatutRapport::Clos);
    }

    /** Corriger une valeur pré-remplie : réservé à qui consolide, avec motif. */
    public function corriger(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $this->estLAuteur($utilisateur, $rapport)
            && $rapport->estModifiableParAuteur()
            && $this->peut($utilisateur, 'rapports.corriger_prerempli');
    }

    public function exporter(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $this->autoriser($utilisateur, 'rapports.exporter', $rapport);
    }

    /** Un rapport ne se supprime pas : il porte une signature et un visa. */
    public function delete(User $utilisateur, RapportJournalier $rapport): bool
    {
        return false;
    }

    private function estLAuteur(User $utilisateur, RapportJournalier $rapport): bool
    {
        return $utilisateur->volontaire !== null
            && $rapport->auteur_volontaire_id === $utilisateur->volontaire->id;
    }

    private function estLeSuperieurDesigne(User $utilisateur, RapportJournalier $rapport): bool
    {
        if ($rapport->superieur_user_id === $utilisateur->id) {
            return true;
        }

        return $utilisateur->volontaire !== null
            && $rapport->superieur_volontaire_id === $utilisateur->volontaire->id;
    }
}
