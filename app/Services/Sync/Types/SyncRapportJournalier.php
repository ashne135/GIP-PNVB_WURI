<?php

namespace App\Services\Sync\Types;

use App\Http\Requests\Rapports\SaisirRapportRequest;
use App\Models\RapportJournalier;
use App\Models\User;
use App\Services\Rapports\ServiceCycleDeVieRapport;
use App\Services\Rapports\ServicePreparationRapport;
use App\Services\Rapports\ServiceSaisieRapport;
use App\Services\Sync\TypeSynchronisable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Le rapport journalier saisi hors ligne, et signé dans la foulée.
 *
 * Le rapport est OUVERT côté serveur, avec son bloc d'identification et ses
 * chiffres pré-remplis : le téléphone ne dicte ni la région, ni le site, ni le
 * supérieur, ni l'objectif. Il n'apporte que ce que l'agent a réellement saisi.
 *
 * Quand le rapport a été ouvert hors ligne, son uuid vient du téléphone. Quand
 * il l'avait déjà été en ligne, le serveur garde le sien et rend son
 * identifiant : c'est au client de se recaler, pas au serveur de créer un
 * doublon que l'unicité (auteur, type, jour) refuserait de toute façon.
 *
 * « soumettre » dans le même élément évite un aller-retour : hors ligne, l'agent
 * remplit et signe d'un seul geste, et son téléphone ne repassera peut-être
 * jamais deux fois dans la journée.
 */
class SyncRapportJournalier extends TypeSynchronisable
{
    public function __construct(
        private readonly ServicePreparationRapport $preparation,
        private readonly ServiceSaisieRapport $saisie,
        private readonly ServiceCycleDeVieRapport $cycleDeVie,
    ) {}

    public function cle(): string
    {
        return 'rapport_journalier';
    }

    public function permission(): ?string
    {
        return 'rapports.saisir';
    }

    public function regles(): array
    {
        // Le contenu suit les règles du formulaire en ligne : elles sont
        // reprises telles quelles pour qu'une saisie hors ligne ne soit jamais
        // acceptée sur des critères plus larges.
        $regles = (new SaisirRapportRequest)->rules();

        return $regles + [
            'date_rapport' => ['required', 'date'],
            'soumettre' => ['nullable', 'boolean'],
            // Position au moment de la signature : le cycle de vie la trace,
            // comme il le fait pour une signature posee en ligne.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return (new SaisirRapportRequest)->messages() + [
            'date_rapport.required' => 'Précisez la journée que couvre ce rapport.',
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $volontaire = $auteur->volontaire;

        if (! $volontaire) {
            throw new \DomainException("Ce compte n'est rattaché à aucune fiche de volontaire.");
        }

        $date = Carbon::parse($donnees['date_rapport'])->toDateString();

        $rapport = RapportJournalier::query()
            ->where('uuid_client', $donnees['uuid_client'])
            ->first();

        $action = 'mis_a_jour';

        if (! $rapport) {
            $rapport = $this->preparation->ouvrir($volontaire, $date, $donnees['uuid_client']);
            $action = $rapport->wasRecentlyCreated ? 'cree' : 'mis_a_jour';
        }

        if (Gate::forUser($auteur)->denies('update', $rapport)) {
            // Un rapport déjà signé n'est plus modifiable : si le téléphone
            // rejoue le même élément, l'envoi précédent était simplement passé.
            if (! $rapport->estModifiableParAuteur()) {
                return ['id' => $rapport->id, 'action' => 'existant'];
            }

            throw new SyncDroitRefuse('Vous ne pouvez pas modifier ce rapport.');
        }

        $rapport = $this->saisie->enregistrer($rapport, $donnees, $auteur);

        if ($donnees['soumettre'] ?? false) {
            $rapport = $this->cycleDeVie->soumettre($rapport, $auteur, [
                'latitude' => $donnees['latitude'] ?? null,
                'longitude' => $donnees['longitude'] ?? null,
                'horodatage_telephone' => $donnees['horodatage_telephone'] ?? null,
            ]);
            $action = 'soumis';
        }

        return ['id' => $rapport->id, 'action' => $action];
    }
}
