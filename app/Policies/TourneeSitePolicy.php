<?php

namespace App\Policies;

use App\Models\TourneeSite;
use App\Models\User;

class TourneeSitePolicy extends PolitiqueDeBase
{
    /**
     * CONSULTER un passage relève du même droit que consulter les affectations :
     * c'est la même question — qui travaille où, et quand. Seule la CORRECTION
     * d'un passage a demandé un droit propre, parce qu'elle déplace un agent.
     */
    protected function permissionConsulter(): string
    {
        return 'affectations.consulter';
    }

    protected function modele(): string
    {
        return TourneeSite::class;
    }

    /**
     * Corriger un passage, ou lui désigner un autre opérateur.
     *
     * Les règles de cycle de vie — vague clôturée, feuille de présence déjà
     * validée — sont vérifiées par le service et non ici : une Policy protège
     * l'API, elle ne protège pas un appel interne.
     */
    /**
     * Programmer un passage : même droit que l'ajuster. Le périmètre, lui, se
     * vérifie sur le SITE au moment de la création — il n'existe pas encore de
     * passage sur lequel l'appliquer.
     */
    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'tournees.ajuster');
    }

    public function ajuster(User $utilisateur, TourneeSite $tournee): bool
    {
        return $this->autoriser($utilisateur, 'tournees.ajuster', $tournee);
    }
}
