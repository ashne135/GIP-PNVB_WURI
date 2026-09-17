<?php

namespace App\Services\Sync\Types;

use App\Models\SignalArrivee;
use App\Models\User;
use App\Services\Presence\ServiceSignalArrivee;
use App\Services\Sync\TypeSynchronisable;
use Illuminate\Validation\Rule;

/**
 * Le gros bouton « JE SUIS ARRIVÉ », remonté après coup.
 *
 * C'est le cas d'usage le plus fréquent du hors ligne : l'agent arrive sur un
 * site sans réseau, appuie, et le signal part des heures plus tard. L'heure
 * retenue reste celle du TÉLÉPHONE au moment du geste, jamais celle de
 * l'arrivée sur le serveur.
 */
class SyncSignalArrivee extends TypeSynchronisable
{
    public function __construct(private readonly ServiceSignalArrivee $service) {}

    public function cle(): string
    {
        return 'signal_arrivee';
    }

    public function permission(): ?string
    {
        return 'presence.signaler_arrivee';
    }

    public function regles(): array
    {
        return [
            'type_signal' => ['required', Rule::in(['arrivee', 'depart'])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'horodatage_telephone' => ['required', 'date'],
            'precision_gps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'type_signal.required' => "Précisez s'il s'agit d'une arrivée ou d'un départ.",
            'latitude.required' => 'La position GPS est manquante.',
            'longitude.required' => 'La position GPS est manquante.',
            'horodatage_telephone.required' => "L'heure de l'appareil est manquante.",
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $volontaire = $auteur->volontaire;

        if (! $volontaire) {
            throw new \DomainException("Ce compte n'est rattaché à aucune fiche de volontaire.");
        }

        $dejaLa = SignalArrivee::query()->where('uuid_client', $donnees['uuid_client'])->exists();

        // Le service porte déjà l'idempotence : un uuid connu rend le signal
        // existant sans le modifier. On se contente de nommer l'action pour que
        // le téléphone sache que son envoi précédent était bien passé.
        $signal = $this->service->enregistrer($volontaire, [
            'uuid_client' => $donnees['uuid_client'],
            'type' => $donnees['type_signal'],
            'latitude' => $donnees['latitude'],
            'longitude' => $donnees['longitude'],
            'horodatage_telephone' => $donnees['horodatage_telephone'],
            'precision_gps' => $donnees['precision_gps'] ?? null,
            'site_id' => $donnees['site_id'] ?? null,
        ]);

        return ['id' => $signal->id, 'action' => $dejaLa ? 'existant' : 'cree'];
    }
}
