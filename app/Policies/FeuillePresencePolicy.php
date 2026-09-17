<?php

namespace App\Policies;

use App\Models\FeuillePresence;
use App\Models\User;

/**
 * Feuille de présence : la SEULE pièce qui fait foi (cadrage, section 8.3).
 *
 * Deux règles y sont posées, et elles sont le cœur du module :
 *   - le superviseur ne peut plus modifier une feuille qu'il a validée ;
 *   - toute correction passe par le CHEF D'ANTENNE RÉGIONAL, et est journalisée.
 */
class FeuillePresencePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'presence.consulter_feuille';
    }

    protected function modele(): string
    {
        return FeuillePresence::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'presence.valider_feuille');
    }

    /** Tant qu'elle est au brouillon, le superviseur la remplit librement. */
    public function update(User $utilisateur, FeuillePresence $feuille): bool
    {
        return $this->autoriser($utilisateur, 'presence.valider_feuille', $feuille)
            && $feuille->estModifiableParSuperviseur();
    }

    public function valider(User $utilisateur, FeuillePresence $feuille): bool
    {
        return $this->autoriser($utilisateur, 'presence.valider_feuille', $feuille)
            && ! $feuille->estValidee();
    }

    /**
     * Corriger une feuille DÉJÀ VALIDÉE : réservé au chef d'antenne régional,
     * qui seul porte presence.corriger_feuille. Le superviseur validant ne peut
     * pas revenir sur sa propre validation.
     */
    public function corriger(User $utilisateur, FeuillePresence $feuille): bool
    {
        return $this->autoriser($utilisateur, 'presence.corriger_feuille', $feuille)
            && $feuille->estValidee();
    }

    public function exporter(User $utilisateur, FeuillePresence $feuille): bool
    {
        return $this->autoriser($utilisateur, 'presence.exporter', $feuille);
    }
}
