<?php

namespace App\Services\Sync\Types;

use App\Models\AppreciationReponse;
use App\Models\RapportSuiviAgent;
use App\Models\User;
use App\Services\Sync\TypeSynchronisable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Le droit de réponse de l'agent noté, exercé hors ligne (cadrage, section 9).
 *
 * « Une anomalie signalée doit permettre à l'agent d'ajouter une OBSERVATION en
 * réponse, horodatée, non modifiable par le supérieur. » L'agent la rédige sur
 * son téléphone, sans réseau ; elle part avec la file.
 *
 * L'idempotence tient à l'uuid de la réponse : renvoyée, elle n'est jamais
 * enregistrée deux fois. L'heure retenue est celle du téléphone au moment où
 * l'agent l'a écrite.
 */
class SyncReponseAppreciation extends TypeSynchronisable
{
    public function cle(): string
    {
        return 'reponse_appreciation';
    }

    public function permission(): ?string
    {
        return 'appreciations.repondre';
    }

    public function regles(): array
    {
        return [
            'suivi_agent_id' => ['required', 'integer'],
            'reponse' => ['required', 'string', 'max:3000'],
            'horodatage_telephone' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'suivi_agent_id.required' => "Précisez l'appréciation à laquelle vous répondez.",
            'reponse.required' => 'Écrivez votre observation.',
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $existante = AppreciationReponse::query()->where('uuid_client', $donnees['uuid_client'])->first();

        if ($existante) {
            return ['id' => $existante->id, 'action' => 'existant'];
        }

        $suivi = RapportSuiviAgent::query()->find($donnees['suivi_agent_id']);

        if (! $suivi) {
            throw new SyncIntrouvable("L'appréciation visée n'existe pas sur le serveur.");
        }

        // Seul l'agent noté répond, et à lui seul.
        if (Gate::forUser($auteur)->denies('repondre', $suivi)) {
            throw new SyncDroitRefuse("Vous ne pouvez répondre qu'aux appréciations qui vous concernent.");
        }

        $reponse = AppreciationReponse::query()->create([
            'uuid_client' => $donnees['uuid_client'],
            'suivi_agent_id' => $suivi->id,
            'volontaire_id' => $auteur->volontaire->id,
            'reponse' => $donnees['reponse'],
            'repondu_le' => filled($donnees['horodatage_telephone'] ?? null)
                ? Carbon::parse($donnees['horodatage_telephone'])
                : now(),
        ]);

        activity('appreciation')
            ->causedBy($auteur)
            ->performedOn($suivi)
            ->withProperties(['canal' => 'mobile'])
            ->log("Réponse de l'agent à une appréciation");

        return ['id' => $reponse->id, 'action' => 'cree'];
    }
}
