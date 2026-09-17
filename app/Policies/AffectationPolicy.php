<?php

namespace App\Policies;

use App\Enums\StatutAffectation;
use App\Models\Affectation;
use App\Models\User;

class AffectationPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'affectations.consulter';
    }

    protected function modele(): string
    {
        return Affectation::class;
    }

    /**
     * Ajustement manuel d'une affectation AVANT validation de la proposition
     * (cadrage, section 7).
     *
     * L'état du cycle de vie est vérifié ici, comme dans VagueDeploiementPolicy :
     * une affectation déjà active ne s'ajuste plus, elle se remplace — un autre
     * acte, avec un motif obligatoire. Le service le revérifie de son côté :
     * une Policy protège l'API, elle ne protège pas un appel interne.
     */
    public function ajuster(User $utilisateur, Affectation $affectation): bool
    {
        return $this->autoriser($utilisateur, 'affectations.ajuster', $affectation)
            && $affectation->statut === StatutAffectation::Proposee;
    }

    /**
     * Déplacer un agent DÉJÀ DÉPLOYÉ vers un autre centre.
     *
     * L'inverse exact d'ajuster() : celui-ci ne vaut qu'avant validation,
     * celui-là qu'une fois l'affectation active. Les deux ne se recouvrent
     * jamais, et c'est voulu — une proposition se corrige, un déploiement en
     * cours se déplace, avec motif et trace.
     *
     * Les règles de terrain — catégorie de l'agent, journée déjà attestée,
     * centre hors vague — sont vérifiées par le service : une Policy protège
     * l'API, elle ne protège pas un appel interne.
     */
    public function deplacer(User $utilisateur, Affectation $affectation): bool
    {
        return $this->autoriser($utilisateur, 'affectations.deplacer', $affectation)
            && $affectation->statut === StatutAffectation::Active;
    }
}
