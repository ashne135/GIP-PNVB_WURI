<?php

namespace App\Policies;

use App\Models\RapportSuiviAgent;
use App\Models\User;

/**
 * Appréciation individuelle d'un agent (cadrage v2, section 9, encadrement de
 * la notation).
 *
 * « L'agent noté PEUT CONSULTER les appréciations le concernant. Pas de
 * notation invisible. » C'est pourquoi view() autorise explicitement l'agent
 * lui-même, en plus de sa hiérarchie.
 *
 * « Les appréciations ne sont pas exportables en masse par un rôle qui n'a pas
 * le périmètre correspondant » : d'où le scope de périmètre, appliqué comme
 * partout ailleurs.
 */
class RapportSuiviAgentPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'appreciations.consulter_equipe';
    }

    protected function modele(): string
    {
        return RapportSuiviAgent::class;
    }

    public function viewAny(User $utilisateur): bool
    {
        // L'agent noté n'a pas besoin de « consulter_equipe » pour voir les
        // siennes : c'est un droit distinct, et c'est le sien.
        return $this->peut($utilisateur, 'appreciations.consulter_equipe')
            || $this->peut($utilisateur, 'appreciations.consulter_les_miennes');
    }

    /**
     * La signature reprend celle de PolitiqueDeBase (Model, et non le modèle
     * concret) : c'est Gate qui appelle cette méthode, avec le contrat de la
     * classe parente.
     */
    public function view(User $utilisateur, \Illuminate\Database\Eloquent\Model $modele): bool
    {
        if ($modele instanceof RapportSuiviAgent && $this->estLAgentNote($utilisateur, $modele)) {
            return $this->peut($utilisateur, 'appreciations.consulter_les_miennes');
        }

        return parent::view($utilisateur, $modele);
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'appreciations.apprecier');
    }

    public function update(User $utilisateur, RapportSuiviAgent $suivi): bool
    {
        // L'agent noté ne réécrit jamais son appréciation : il y RÉPOND.
        if ($this->estLAgentNote($utilisateur, $suivi)) {
            return false;
        }

        return $this->autoriser($utilisateur, 'appreciations.apprecier', $suivi);
    }

    /**
     * Répondre : réservé à l'AGENT NOTÉ, et à lui seul. Le supérieur ne peut
     * pas écrire dans le droit de réponse de son subordonné.
     */
    public function repondre(User $utilisateur, RapportSuiviAgent $suivi): bool
    {
        return $this->estLAgentNote($utilisateur, $suivi)
            && $this->peut($utilisateur, 'appreciations.repondre');
    }

    private function estLAgentNote(User $utilisateur, RapportSuiviAgent $suivi): bool
    {
        return $utilisateur->volontaire !== null
            && $suivi->volontaire_id === $utilisateur->volontaire->id;
    }
}
