<?php

namespace App\Services\Sync;

use App\Services\Sync\Types\SyncFeuillePresence;
use App\Services\Sync\Types\SyncIncident;
use App\Services\Sync\Types\SyncLectureAlerte;
use App\Services\Sync\Types\SyncMouvementKit;
use App\Services\Sync\Types\SyncRapportJournalier;
use App\Services\Sync\Types\SyncRelevePosition;
use App\Services\Sync\Types\SyncReponseAppreciation;
use App\Services\Sync\Types\SyncSignalArrivee;
use App\Services\Sync\Types\SyncVisaRapport;

/**
 * Les types que le mobile peut remonter aujourd'hui.
 *
 * Le registre est volontairement EXPLICITE plutôt que découvert par balayage
 * du dossier : ce qui remonte du terrain doit se lire d'un coup d'œil, et
 * ouvrir un nouveau type doit être une décision, pas un effet de bord.
 *
 * Un mobile plus récent que le serveur — qui enverrait un type encore inconnu
 * de lui — reçoit un rejet « type_inconnu » explicite, avec la liste de ce que
 * ce serveur sait recevoir, plutôt qu'un silence.
 */
class RegistreSync
{
    /** @var array<string, TypeSynchronisable>|null */
    private ?array $types = null;

    public function __construct(
        private readonly SyncSignalArrivee $signalArrivee,
        private readonly SyncRelevePosition $relevePosition,
        private readonly SyncFeuillePresence $feuillePresence,
        private readonly SyncRapportJournalier $rapportJournalier,
        private readonly SyncVisaRapport $visaRapport,
        private readonly SyncIncident $incident,
        private readonly SyncMouvementKit $mouvementKit,
        private readonly SyncReponseAppreciation $reponseAppreciation,
        private readonly SyncLectureAlerte $lectureAlerte,
    ) {}

    /** @return array<string, TypeSynchronisable> */
    public function tous(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $this->types = [];

        foreach ([
            $this->signalArrivee,
            $this->relevePosition,
            $this->feuillePresence,
            $this->rapportJournalier,
            $this->visaRapport,
            $this->incident,
            $this->mouvementKit,
            $this->reponseAppreciation,
            $this->lectureAlerte,
        ] as $type) {
            $this->types[$type->cle()] = $type;
        }

        return $this->types;
    }

    public function pour(string $cle): ?TypeSynchronisable
    {
        return $this->tous()[$cle] ?? null;
    }

    /** @return array<int, string> */
    public function cles(): array
    {
        return array_keys($this->tous());
    }
}
