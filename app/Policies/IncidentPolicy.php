<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy extends PolitiqueDeBase
{
    protected function permissionConsulter(): string
    {
        return 'incidents.consulter';
    }

    protected function modele(): string
    {
        return Incident::class;
    }

    public function create(User $utilisateur): bool
    {
        return $this->peut($utilisateur, 'incidents.declarer');
    }

    /**
     * Section J du canevas : le traitement est « réservé aux responsables
     * habilités ». Le déclarant, lui, ne traite pas son propre incident.
     */
    public function traiter(User $utilisateur, Incident $incident): bool
    {
        return $this->autoriser($utilisateur, 'incidents.traiter', $incident);
    }

    public function cloturer(User $utilisateur, Incident $incident): bool
    {
        return $this->autoriser($utilisateur, 'incidents.cloturer', $incident)
            && in_array($incident->statut, ['resolu', 'en_cours'], true);
    }
}
