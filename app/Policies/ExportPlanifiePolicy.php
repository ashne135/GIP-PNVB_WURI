<?php

namespace App\Policies;

use App\Models\ExportPlanifie;
use App\Models\User;

/**
 * Qui consulte et télécharge un export planifié.
 *
 * Le DROIT est `exports.generer` — porté par le chef d'antenne régional,
 * l'administration nationale et l'observateur. Le PÉRIMÈTRE est celui du
 * modèle : un régional ne voit que le fichier de sa région.
 *
 * L'observateur est en lecture seule stricte : le socle lui refuse déjà toute
 * capacité autre que consulter, donc il télécharge mais ne relance jamais une
 * production.
 */
class ExportPlanifiePolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'exports.generer';
    }

    protected function modele(): string
    {
        return ExportPlanifie::class;
    }

    /** Relancer la production d'une journée : une écriture, refusée à l'observateur. */
    public function produire(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'exports.generer');
    }
}
