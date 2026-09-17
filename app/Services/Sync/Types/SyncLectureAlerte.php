<?php

namespace App\Services\Sync\Types;

use App\Models\Alerte;
use App\Models\User;
use App\Services\Sync\TypeSynchronisable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * L'accusé de lecture d'une alerte, donné hors ligne.
 *
 * Savoir QUI a lu une alerte critique, et quand, fait partie de ce qu'on doit
 * pouvoir établir après coup. L'agent la lit sur son téléphone, sans réseau :
 * l'accusé part avec la file, à l'heure où il l'a lue.
 *
 * L'idempotence tient au couple (alerte, lecteur), unique en base : pas besoin
 * d'uuid.
 */
class SyncLectureAlerte extends TypeSynchronisable
{
    public function cle(): string
    {
        return 'lecture_alerte';
    }

    public function permission(): ?string
    {
        return 'alertes.consulter';
    }

    public function exigeUuidClient(): bool
    {
        return false;
    }

    public function regles(): array
    {
        return [
            'alerte_id' => ['required', 'integer'],
            'horodatage_telephone' => ['nullable', 'date'],
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $alerte = Alerte::query()->find($donnees['alerte_id']);

        if (! $alerte) {
            throw new SyncIntrouvable("Cette alerte n'existe pas sur le serveur.");
        }

        if (Gate::forUser($auteur)->denies('view', $alerte)) {
            throw new SyncDroitRefuse("Cette alerte ne vous est pas adressée.");
        }

        if ($alerte->lecteurs()->whereKey($auteur->id)->exists()) {
            return ['id' => $alerte->id, 'action' => 'existant'];
        }

        $alerte->lecteurs()->attach($auteur->id, [
            'lu_le' => filled($donnees['horodatage_telephone'] ?? null)
                ? Carbon::parse($donnees['horodatage_telephone'])
                : now(),
        ]);

        return ['id' => $alerte->id, 'action' => 'cree'];
    }
}
