<?php

namespace App\Policies;

use App\Models\EcartPresence;
use App\Models\User;

/**
 * Écarts issus du rapprochement automatique (cadrage, section 8.4).
 *
 * « Le chef d'antenne voit l'ALERTE et l'écart constaté, jamais un historique
 * de déplacements. » Le scope perimetre() du modèle ne rend déjà ces lignes
 * visibles qu'au niveau régional ; cette Policy ajoute le droit correspondant.
 *
 * Aucune capacité de consultation des relevés de position bruts n'existe, ici
 * ni ailleurs : cette table n'est exposée par aucune Policy et par aucune route.
 */
class EcartPresencePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'ecarts.consulter';
    }

    protected function modele(): string
    {
        return EcartPresence::class;
    }

    /**
     * Toute consultation d'un écart est journalisée : le cadrage l'impose pour
     * ces données (« toute consultation de ces données est journalisée »).
     */
    public function view(User $utilisateur, $modele): bool
    {
        $autorise = parent::view($utilisateur, $modele);

        if ($autorise) {
            activity('rapprochement')
                ->causedBy($utilisateur)
                ->performedOn($modele)
                ->log('Consultation d\'un écart de présence');
        }

        return $autorise;
    }

    /**
     * Marquer un écart examiné ou clos, avec commentaire. Droit distinct de la
     * consultation (décision du client, 17/09/2026) : lire un constat et le
     * clore ne sont pas le même acte. Le périmètre reste celui de la région.
     */
    public function traiter(User $utilisateur, EcartPresence $ecart): bool
    {
        return $this->autoriser($utilisateur, 'ecarts.traiter', $ecart);
    }
}
