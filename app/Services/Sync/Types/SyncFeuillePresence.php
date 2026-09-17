<?php

namespace App\Services\Sync\Types;

use App\Models\FeuillePresence;
use App\Models\Site;
use App\Models\User;
use App\Services\Presence\ServiceFeuillePresence;
use App\Services\Sync\TypeSynchronisable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * La feuille de présence validée hors ligne par le superviseur.
 *
 * Le superviseur ouvre la feuille sur son téléphone, marque ses agents et
 * valide, souvent sans réseau. À la remontée, deux cas :
 *
 *   - la feuille existe déjà côté serveur (elle a été préparée en ligne) :
 *     on la retrouve par son uuid, ou à défaut par le couple SITE + JOUR, qui
 *     est unique en base ;
 *   - elle n'existe pas : on la prépare, avec l'uuid du téléphone, avant de la
 *     valider.
 *
 * UNE FEUILLE DÉJÀ VALIDÉE N'EST PAS REJOUÉE. Le service lève alors sa propre
 * exception, et l'élément est compté comme accepté « existant » : rejouer une
 * validation écraserait la signature du superviseur, que seule une correction
 * par le chef d'antenne régional peut toucher.
 */
class SyncFeuillePresence extends TypeSynchronisable
{
    public function __construct(private readonly ServiceFeuillePresence $service) {}

    public function cle(): string
    {
        return 'feuille_presence';
    }

    public function regles(): array
    {
        return [
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'date_presence' => ['required', 'date'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.volontaire_id' => ['required', 'integer', 'exists:volontaires,id'],
            'lignes.*.statut' => ['required', Rule::in(['present', 'absent', 'absent_justifie'])],
            'lignes.*.motif_absence' => ['nullable', 'string', 'max:255'],
            'lignes.*.commentaire' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'lignes.required' => 'Marquez la présence de chaque agent.',
            'lignes.*.statut.required' => 'Chaque agent doit être marqué présent, absent ou absent justifié.',
        ];
    }

    public function traiter(User $auteur, array $donnees): array
    {
        $superviseur = $auteur->volontaire;

        if (! $superviseur) {
            throw new \DomainException("Ce compte n'est rattaché à aucune fiche de volontaire.");
        }

        $date = Carbon::parse($donnees['date_presence'])->toDateString();

        $feuille = FeuillePresence::query()
            ->where('uuid_client', $donnees['uuid_client'])
            ->first();

        if (! $feuille) {
            // L'unicité (site, jour) est en base : chercher par ce couple évite
            // de créer une seconde feuille quand le serveur en avait déjà une.
            $feuille = FeuillePresence::query()
                ->where('site_id', $donnees['site_id'])
                ->whereDate('date_presence', $date)
                ->first();
        }

        if (! $feuille) {
            $site = Site::query()->findOrFail($donnees['site_id']);
            $feuille = $this->service->preparer($site, $date, $superviseur, $donnees['uuid_client']);
        }

        // Le DROIT et le PÉRIMÈTRE sont vérifiés sur la feuille elle-même :
        // un superviseur ne valide pas la feuille d'un centre qui n'est pas le sien.
        if (Gate::forUser($auteur)->denies('valider', $feuille)) {
            // La Policy refuse aussi de valider une feuille DÉJÀ validée. Il faut
            // donc distinguer les deux refus : le téléphone du superviseur qui a
            // signé rejoue simplement son envoi, et doit recevoir un accusé, pas
            // une erreur. Tout autre compte reste refusé — sans même apprendre
            // que la feuille existe.
            if ($feuille->estValidee() && $feuille->superviseur_id === $superviseur->id) {
                return ['id' => $feuille->id, 'action' => 'existant'];
            }

            throw new SyncDroitRefuse(
                "Vous n'êtes pas habilité à valider la feuille du site {$feuille->site?->code}."
            );
        }

        $feuille = $this->service->valider(
            $feuille,
            $donnees['lignes'],
            [
                'latitude' => $donnees['latitude'] ?? null,
                'longitude' => $donnees['longitude'] ?? null,
            ],
            $superviseur
        );

        return ['id' => $feuille->id, 'action' => 'cree'];
    }
}
