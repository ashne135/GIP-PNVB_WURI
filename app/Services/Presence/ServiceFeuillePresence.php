<?php

namespace App\Services\Presence;

use App\Enums\CategorieVolontaire;
use App\Models\Affectation;
use App\Models\FeuillePresence;
use App\Models\LignePresence;
use App\Models\SignalArrivee;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\User;
use App\Models\Volontaire;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LA FEUILLE DE PRÉSENCE — seule pièce qui fait foi (cadrage, section 8.3).
 *
 * UNE feuille par SITE et par JOUR, jamais une par centre : l'index unique
 * (site_id, date_presence) le garantit en base.
 *
 * Elle est PRÉ-REMPLIE avec les agents affectés au site, et l'état de leur
 * signal d'arrivée — couleur, heure, distance. Le superviseur marque chacun,
 * puis valide. À la validation, le serveur enregistre SA position et SON
 * horodatage, ainsi que sa distance au site : c'est ce qui rend le pointage
 * collectif à distance vérifiable.
 *
 * Une feuille validée n'est plus modifiable par le superviseur. Toute
 * correction passe par le chef d'antenne régional, et est journalisée.
 */
class ServiceFeuillePresence
{
    /**
     * Prépare — ou récupère — la feuille du jour pour un site.
     * Idempotent : deux appels le même jour rendent la même feuille.
     *
     * @param  string|null  $uuidClient  L'identifiant produit par le téléphone
     *        quand la feuille a été ouverte hors ligne.
     */
    public function preparer(
        Site $site,
        string $date,
        Volontaire $superviseur,
        ?string $uuidClient = null
    ): FeuillePresence {
        $existante = FeuillePresence::query()
            ->where('site_id', $site->id)
            ->whereDate('date_presence', $date)
            ->first();

        if ($existante) {
            return $existante->load('lignes.volontaire.user');
        }

        $tournee = TourneeSite::query()->where('site_id', $site->id)->couvrant($date)->first();

        if (! $tournee) {
            throw new \DomainException(
                "Aucun kit ne couvre ce site le {$date} : il n'y a pas de feuille à ouvrir."
            );
        }

        return DB::transaction(function () use ($site, $date, $superviseur, $tournee, $uuidClient) {
            $feuille = FeuillePresence::query()->create([
                // Ouverte hors ligne, la feuille garde l'uuid du telephone.
                'uuid_client' => $uuidClient ?? (string) Str::uuid(),
                'site_id' => $site->id,
                'centre_id' => $site->centre_id,
                'vague_id' => $tournee->vague_id,
                'tournee_site_id' => $tournee->id,
                'date_presence' => $date,
                'statut' => 'brouillon',
                'superviseur_id' => $superviseur->id,
            ]);

            $this->preRemplir($feuille, $site, $date);

            return $feuille->load('lignes.volontaire.user');
        });
    }

    /**
     * Les agents attendus sur un site un jour donné.
     *
     *   l'OPÉRATEUR   vient avec son kit, par la tournée du jour ;
     *   l'A-OPK       est rattaché en permanence à la localité du site.
     *
     * @param  Collection<int, Site>  $sites
     */
    public function agentsAttendus(Collection $sites, string $date): array
    {
        $agents = [];

        $tournees = TourneeSite::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->couvrant($date)
            ->with('affectationOperateur.volontaire.user')
            ->get()
            ->keyBy('site_id');

        foreach ($sites as $site) {
            $tournee = $tournees->get($site->id);
            $operateur = $tournee?->affectationOperateur?->volontaire;

            if ($operateur) {
                $agents[] = $this->decrireAgent($operateur, $site);
            }

            // L'A-OPK n'est présent que les jours où le kit passe chez lui :
            // sans tournée, la localité ne suffit pas à l'attendre sur le site.
            if (! $tournee) {
                continue;
            }

            $assistants = Volontaire::query()
                ->where('categorie', CategorieVolontaire::Assistant->value)
                ->where('localite_id', $site->localite_id)
                // L'affectation qui couvrait le jour de la feuille : validée
                // hors ligne le dernier jour, elle garde ses assistants.
                ->whereHas('affectations', fn ($q) => $q->couvrant($date)
                    ->where('centre_id', $site->centre_id))
                ->with('user:id,nom,prenoms')
                ->get();

            foreach ($assistants as $assistant) {
                $agents[] = $this->decrireAgent($assistant, $site);
            }
        }

        return $agents;
    }

    /**
     * Le superviseur marque et valide.
     *
     * @param  array<int, array{volontaire_id: int, statut: string, motif_absence?: string|null, commentaire?: string|null}>  $lignes
     * @param  array{latitude?: float|null, longitude?: float|null}  $position
     */
    public function valider(
        FeuillePresence $feuille,
        array $lignes,
        array $position,
        Volontaire $superviseur
    ): FeuillePresence {
        if ($feuille->estValidee()) {
            throw new \DomainException(
                'Cette feuille est déjà validée : seul le chef d\'antenne régional peut la corriger.'
            );
        }

        $this->verifierMotifs($lignes);

        return DB::transaction(function () use ($feuille, $lignes, $position, $superviseur) {
            foreach ($lignes as $ligne) {
                LignePresence::query()
                    ->where('feuille_presence_id', $feuille->id)
                    ->where('volontaire_id', $ligne['volontaire_id'])
                    ->update([
                        'statut' => $ligne['statut'],
                        'motif_absence' => $ligne['motif_absence'] ?? null,
                        'commentaire' => $ligne['commentaire'] ?? null,
                        'updated_at' => now(),
                    ]);
            }

            $site = $feuille->site;
            $distance = ($position['latitude'] ?? null) !== null
                ? $site?->distanceMetresDepuis($position['latitude'], $position['longitude'])
                : null;

            $feuille->update([
                'statut' => 'validee',
                'valide_le' => now(),
                'superviseur_id' => $superviseur->id,
                // La POSITION DU SUPERVISEUR au moment de valider est enregistrée :
                // c'est elle qui rend le pointage à distance opposable.
                'latitude_superviseur' => $position['latitude'] ?? null,
                'longitude_superviseur' => $position['longitude'] ?? null,
                'distance_site_metres' => $distance,
            ]);

            activity('feuille_presence')
                // LE VALIDANT EST NOMMÉ. Seule la feuille validée fait foi, et
                // c'est ce superviseur qui en répond : un journal qui dirait
                // qu'une feuille a été validée sans dire par qui manquerait
                // précisément ce qu'on lui demandera plus tard.
                ->causedBy($superviseur->user)
                ->performedOn($feuille)
                ->withProperties([
                    'site' => $site?->code,
                    'date' => $feuille->date_presence?->toDateString(),
                    'distance_superviseur_metres' => $distance,
                ])
                ->log('Feuille de présence validée');

            return $feuille->fresh(['lignes.volontaire.user', 'site']);
        });
    }

    /**
     * CORRECTION D'UNE FEUILLE VALIDÉE — réservée au chef d'antenne régional,
     * et journalisée. Le superviseur qui a validé ne revient pas sur sa
     * signature.
     */
    public function corriger(
        FeuillePresence $feuille,
        array $lignes,
        string $motif,
        User $auteur
    ): FeuillePresence {
        if (! $feuille->estValidee()) {
            throw new \DomainException(
                "Cette feuille n'est pas encore validée : il n'y a rien à corriger."
            );
        }

        if (trim($motif) === '') {
            throw new \DomainException('Indiquez pourquoi cette feuille validée est corrigée.');
        }

        $this->verifierMotifs($lignes);

        return DB::transaction(function () use ($feuille, $lignes, $motif, $auteur) {
            $avant = $feuille->lignes()->pluck('statut', 'volontaire_id')->all();

            foreach ($lignes as $ligne) {
                LignePresence::query()
                    ->where('feuille_presence_id', $feuille->id)
                    ->where('volontaire_id', $ligne['volontaire_id'])
                    ->update([
                        'statut' => $ligne['statut'],
                        'motif_absence' => $ligne['motif_absence'] ?? null,
                        'commentaire' => $ligne['commentaire'] ?? null,
                        'updated_at' => now(),
                    ]);
            }

            $feuille->update([
                'statut' => 'corrigee',
                'corrige_par' => $auteur->id,
                'corrige_le' => now(),
                'motif_correction' => $motif,
            ]);

            activity('feuille_presence')
                ->causedBy($auteur)
                ->performedOn($feuille)
                ->withProperties([
                    'motif' => $motif,
                    'avant' => $avant,
                    'apres' => $feuille->fresh()->lignes()->pluck('statut', 'volontaire_id')->all(),
                ])
                ->log('Feuille de présence corrigée après validation');

            return $feuille->fresh(['lignes.volontaire.user', 'site']);
        });
    }

    // ------------------------------------------------------------------

    /**
     * Pré-remplit la feuille : les agents attendus, et l'état de leur signal
     * d'arrivée. Le superviseur ne saisit aucune identité, il marque des cases.
     */
    private function preRemplir(FeuillePresence $feuille, Site $site, string $date): void
    {
        $agents = $this->agentsAttendus(collect([$site]), $date);

        $signaux = SignalArrivee::query()
            ->where('site_id', $site->id)
            ->whereDate('horodatage_telephone', $date)
            ->where('type', 'arrivee')
            ->orderByDesc('horodatage_telephone')
            ->get()
            ->unique('volontaire_id')
            ->keyBy('volontaire_id');

        foreach ($agents as $agent) {
            $signal = $signaux->get($agent['volontaire_id']);

            LignePresence::query()->create([
                'feuille_presence_id' => $feuille->id,
                'volontaire_id' => $agent['volontaire_id'],
                'categorie' => $agent['categorie'],
                // Rien n'est présumé : le superviseur décide.
                'statut' => 'absent',
                'signal_arrivee_id' => $signal?->id,
                'heure_arrivee_signalee' => $signal?->horodatage_telephone,
                'distance_signalee' => $signal?->distance_site_metres,
                'dans_zone' => $signal?->dans_zone,
            ]);
        }
    }

    /** Le motif est OBLIGATOIRE pour toute absence justifiée. */
    private function verifierMotifs(array $lignes): void
    {
        foreach ($lignes as $ligne) {
            if (($ligne['statut'] ?? null) === 'absent_justifie' && blank($ligne['motif_absence'] ?? null)) {
                throw new \DomainException(
                    'Une absence justifiée exige un motif : précisez-le pour chaque agent concerné.'
                );
            }
        }
    }

    private function decrireAgent(Volontaire $volontaire, Site $site): array
    {
        return [
            'volontaire_id' => $volontaire->id,
            'matricule' => $volontaire->matricule,
            'nom_complet' => $volontaire->user?->nomComplet() ?? '—',
            'categorie' => $volontaire->categorie->value,
            'site_id' => $site->id,
            'site_code' => $site->code,
        ];
    }
}
