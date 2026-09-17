<?php

namespace App\Services\Sync\Types;

use App\Models\RapportJournalier;
use App\Models\User;
use App\Services\Rapports\ServiceCycleDeVieRapport;
use App\Services\Sync\TypeSynchronisable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Le visa — ou le renvoi pour correction — posé hors ligne par le supérieur.
 *
 * Un opérateur qui vise les rapports de ses A-OPK le soir, sur un site sans
 * réseau, doit pouvoir le faire : sans cela, toute la chaîne de visas se bloque
 * au premier maillon hors couverture.
 *
 * L'idempotence ne repose pas ici sur un uuid d'objet créé, mais sur l'ÉTAT du
 * rapport : un rapport déjà visé n'est pas visé deux fois, et le téléphone
 * reçoit « existant » plutôt qu'une erreur.
 */
class SyncVisaRapport extends TypeSynchronisable
{
    public function __construct(private readonly ServiceCycleDeVieRapport $cycleDeVie) {}

    public function cle(): string
    {
        return 'visa_rapport';
    }

    public function permission(): ?string
    {
        return 'rapports.viser';
    }

    public function exigeUuidClient(): bool
    {
        return false;
    }

    public function regles(): array
    {
        return [
            'rapport_uuid' => ['required', 'uuid'],
            'acte' => ['required', Rule::in(['visa', 'rejet'])],
            'commentaire' => ['nullable', 'string', 'max:2000'],
            'motif' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'horodatage_telephone' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'rapport_uuid.required' => 'Précisez le rapport visé.',
            'acte.required' => "Précisez s'il s'agit d'un visa ou d'un renvoi.",
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $rapport = RapportJournalier::query()
            ->where('uuid_client', $donnees['rapport_uuid'])
            ->first();

        if (! $rapport) {
            throw new SyncIntrouvable(
                "Le rapport visé n'existe pas sur le serveur : il n'a pas encore été remonté."
            );
        }

        $position = [
            'latitude' => $donnees['latitude'] ?? null,
            'longitude' => $donnees['longitude'] ?? null,
            'horodatage_telephone' => $donnees['horodatage_telephone'] ?? null,
        ];

        if ($donnees['acte'] === 'rejet') {
            if (blank($donnees['motif'] ?? null)) {
                throw new \DomainException(
                    'Indiquez ce qui doit être corrigé : un rejet sans motif est inexploitable.'
                );
            }

            if (Gate::forUser($auteur)->denies('rejeter', $rapport)) {
                return $this->dejaTranche($rapport, $auteur);
            }

            $rapport = $this->cycleDeVie->rejeter($rapport, $auteur, $donnees['motif'], $position);

            return ['id' => $rapport->id, 'action' => 'rejete'];
        }

        if (Gate::forUser($auteur)->denies('viser', $rapport)) {
            return $this->dejaTranche($rapport, $auteur);
        }

        $rapport = $this->cycleDeVie->viser(
            $rapport, $auteur, $donnees['commentaire'] ?? null, $position
        );

        return ['id' => $rapport->id, 'action' => 'vise'];
    }

    /**
     * Le droit est refusé : est-ce parce que ce rapport n'attend plus de visa —
     * le téléphone rejoue alors un envoi déjà passé — ou parce que cet
     * utilisateur n'avait pas à le viser ?
     */
    private function dejaTranche(RapportJournalier $rapport, User $auteur): array
    {
        if (! $rapport->attendUnVisa()) {
            return ['id' => $rapport->id, 'action' => 'existant'];
        }

        throw new SyncDroitRefuse(
            "Vous n'êtes pas le supérieur désigné de ce rapport : son visa ne vous revient pas."
        );
    }
}
