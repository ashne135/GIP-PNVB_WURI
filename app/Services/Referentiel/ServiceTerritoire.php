<?php

namespace App\Services\Referentiel;

use App\Models\Commune;
use App\Models\Localite;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * CORRIGER LE RÉFÉRENTIEL TERRITORIAL À L'ÉCRAN.
 *
 * DÉROGATION AU CADRAGE (section 2 : « un import avec aperçu, pas une édition
 * ligne à ligne »), décidée par le client le 17/09/2026. Ce service garde
 * l'esprit de la règle : RIEN ne s'enregistre sans que l'on ait pu voir ses
 * conséquences. Chaque modification se joue d'abord en SIMULATION — la
 * transaction est annulée et l'écran reçoit ce qui changerait.
 *
 * TROIS INVARIANTS, recalculés après chaque modification :
 *
 *  1. LES POPULATIONS REMONTENT. Une localité porte la population ; sa commune
 *     en garde la somme (population_localites), sa province et sa région en
 *     portent le total. La population DÉCLARÉE d'une commune, elle, reste celle
 *     du fichier : c'est l'écart entre les deux qui révèle une incohérence.
 *
 *  2. LES QUOTAS SE RECALCULENT SUR TOUTE LA RÉGION (décision du client), par
 *     la règle de RepartiteurSites. Leur somme reste égale aux sites alloués.
 *
 *  3. LES CODES NE CHANGENT JAMAIS : ils sont imprimés sur des documents.
 *
 * Aucune suppression : centres, sites et volontaires sont rattachés au
 * référentiel.
 */
class ServiceTerritoire
{
    public function __construct(
        private readonly RepartiteurSites $repartiteur = new RepartiteurSites,
    ) {
    }

    /** @return array{objet: Region, changements: array, alertes: array, simulation: bool} */
    public function modifierRegion(Region $region, array $donnees, User $auteur, bool $simulation): array
    {
        return $this->executer($region, $region, array_keys($donnees), function () use ($region, $donnees) {
            $region->update($donnees);

            return $region;
        }, $auteur, $simulation, 'Région modifiée');
    }

    /** Le nom seul : la population d'une province est un total, pas une saisie. */
    public function modifierProvince(Province $province, array $donnees, User $auteur): Province
    {
        $avant = $province->only(array_keys($donnees));
        $province->update($donnees);

        $this->journaliser($auteur, $province, 'Province modifiée', $avant, $province->only(array_keys($donnees)));

        return $province->fresh();
    }

    /**
     * La commune porte sa population DÉCLARÉE, qui n'entre pas dans le calcul
     * des quotas (fait sur les localités) : aucune simulation n'est utile.
     */
    public function modifierCommune(Commune $commune, array $donnees, User $auteur): Commune
    {
        if (array_key_exists('population_hommes', $donnees) || array_key_exists('population_femmes', $donnees)) {
            $donnees['population_totale'] = (int) ($donnees['population_hommes'] ?? $commune->population_hommes)
                + (int) ($donnees['population_femmes'] ?? $commune->population_femmes);
        }

        $avant = $commune->only(array_keys($donnees));
        $commune->update($donnees);

        $this->journaliser($auteur, $commune, 'Commune modifiée', $avant, $commune->only(array_keys($donnees)));

        return $commune->fresh();
    }

    /** @return array{objet: Localite, changements: array, alertes: array, simulation: bool} */
    public function modifierLocalite(Localite $localite, array $donnees, User $auteur, bool $simulation): array
    {
        return $this->executer($localite->region, $localite, array_keys($donnees), function () use ($localite, $donnees) {
            $localite->fill($donnees);
            $localite->population_totale = (int) $localite->population_hommes + (int) $localite->population_femmes;
            $localite->save();

            $this->recalculerCommune($localite->commune);

            return $localite;
        }, $auteur, $simulation, 'Localité modifiée');
    }

    /**
     * Un village absent du fichier officiel. Il naît avec un quota recalculé
     * comme les autres : il prend sa part des sites de la région.
     *
     * @return array{objet: Localite, changements: array, alertes: array, simulation: bool}
     */
    public function ajouterLocalite(Commune $commune, array $donnees, User $auteur, bool $simulation): array
    {
        return $this->executer($commune->region, null, array_keys($donnees), function () use ($commune, $donnees) {
            $localite = Localite::query()->create([
                ...$donnees,
                'commune_id' => $commune->id,
                'region_id' => $commune->region_id,
                'population_totale' => (int) ($donnees['population_hommes'] ?? 0)
                    + (int) ($donnees['population_femmes'] ?? 0),
                'quota_sites_brut' => 0,
                'quota_sites' => 0,
                'est_fictif' => false,
            ]);

            $this->recalculerCommune($commune);

            return $localite;
        }, $auteur, $simulation, 'Localité ajoutée au référentiel');
    }

    /**
     * Joue la modification, recalcule, compare — puis valide ou annule.
     *
     * L'état « avant » est lu AVANT la modification : une fois enregistré, un
     * modèle a déjà oublié ses anciennes valeurs.
     *
     * @param  string[]  $champs
     * @param  callable(): Model  $modification
     */
    private function executer(
        Region $region,
        ?Model $cible,
        array $champs,
        callable $modification,
        User $auteur,
        bool $simulation,
        string $libelle
    ): array {
        $avant = $cible ? $cible->only($champs) : [];

        DB::beginTransaction();

        try {
            $quotasAvant = $this->quotas($region);
            $objet = $modification();

            $region->refresh();
            $this->recalculerRegion($region);
            $this->recalculerQuotas($region);

            $changements = $this->comparer($quotasAvant, $this->quotas($region));
            $alertes = array_values(array_filter(
                $changements,
                fn (array $c) => $c['sites_existants'] > $c['quota_apres']
            ));

            $objet->refresh();
            $apres = $objet->only($champs);

            if ($simulation) {
                DB::rollBack();
            } else {
                $this->journaliser($auteur, $objet, $libelle, $avant, $apres, [
                    'quotas_modifies' => count($changements),
                ]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return [
            'objet' => $objet,
            'changements' => $changements,
            'alertes' => $alertes,
            'simulation' => $simulation,
        ];
    }

    /** La commune garde la somme de ses localités, à côté de sa population déclarée. */
    private function recalculerCommune(Commune $commune): void
    {
        $commune->update([
            'population_localites' => (int) Localite::query()
                ->where('commune_id', $commune->id)
                ->sum('population_totale'),
        ]);
    }

    /** Provinces et région : des totaux de localités, comme au chargement du fichier. */
    private function recalculerRegion(Region $region): void
    {
        $parProvince = Localite::query()
            ->join('communes', 'communes.id', '=', 'localites.commune_id')
            ->where('localites.region_id', $region->id)
            ->groupBy('communes.province_id')
            ->selectRaw('communes.province_id, SUM(localites.population_hommes) as hommes, SUM(localites.population_femmes) as femmes')
            ->get();

        foreach ($parProvince as $ligne) {
            Province::query()->whereKey($ligne->province_id)->update([
                'population_hommes' => (int) $ligne->hommes,
                'population_femmes' => (int) $ligne->femmes,
                'population_totale' => (int) $ligne->hommes + (int) $ligne->femmes,
            ]);
        }

        $hommes = (int) $parProvince->sum('hommes');
        $femmes = (int) $parProvince->sum('femmes');

        $region->update([
            'population_hommes' => $hommes,
            'population_femmes' => $femmes,
            'population_totale' => $hommes + $femmes,
        ]);
    }

    /**
     * Rejoue la répartition officielle sur toute la région. Les localités sont
     * prises dans l'ordre de leur création — celui du fichier — pour que les
     * départages aux plus forts restes retombent comme au chargement.
     */
    private function recalculerQuotas(Region $region): void
    {
        $localites = Localite::query()
            ->where('region_id', $region->id)
            ->orderBy('id')
            ->get(['id', 'population_hommes', 'population_femmes', 'quota_sites', 'quota_sites_brut']);

        $repartition = $this->repartiteur->repartir(
            $localites->map(fn (Localite $l) => [
                'cle' => $l->id,
                'population' => (int) $l->population_hommes + (int) $l->population_femmes,
            ])->all(),
            (int) $region->nombre_sites_alloues
        );

        foreach ($localites as $localite) {
            $quota = $repartition['quotas'][$localite->id] ?? ['brut' => 0, 'entier' => 0];

            if ((int) $localite->quota_sites !== (int) $quota['entier']
                || abs((float) $localite->quota_sites_brut - (float) $quota['brut']) > 0.000001) {
                Localite::query()->whereKey($localite->id)->update([
                    'quota_sites' => $quota['entier'],
                    'quota_sites_brut' => $quota['brut'],
                ]);
            }
        }
    }

    /** @return array<int, int> */
    private function quotas(Region $region): array
    {
        return Localite::query()
            ->where('region_id', $region->id)
            ->pluck('quota_sites', 'id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /** Les localités dont le quota change, avec les sites qu'elles ont déjà. */
    private function comparer(array $avant, array $apres): array
    {
        $ids = [];

        foreach ($apres as $id => $quota) {
            if (! array_key_exists($id, $avant) || $avant[$id] !== $quota) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Localite::query()
            ->with('commune:id,nom')
            ->withCount('sites')
            ->whereIn('id', $ids)
            ->orderBy('nom')
            ->get(['id', 'nom', 'commune_id'])
            ->map(fn (Localite $l) => [
                'localite_id' => $l->id,
                'localite' => $l->nom,
                'commune' => $l->commune?->nom,
                'quota_avant' => $avant[$l->id] ?? null,
                'quota_apres' => $apres[$l->id],
                'sites_existants' => (int) $l->sites_count,
            ])
            ->all();
    }

    private function journaliser(User $auteur, Model $objet, string $libelle, array $avant, array $apres, array $plus = []): void
    {
        activity('referentiel')
            ->causedBy($auteur)
            ->performedOn($objet)
            ->withProperties(['avant' => $avant, 'apres' => $apres, ...$plus])
            ->log($libelle);
    }
}
