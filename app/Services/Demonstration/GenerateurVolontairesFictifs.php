<?php

namespace App\Services\Demonstration;

use App\Enums\CategorieVolontaire;
use App\Models\Localite;
use App\Models\Region;
use App\Services\Support\RepartitionAuxPlusForts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 2 778 volontaires fictifs, répartis selon les effectifs réels du cadrage
 * (section 3), en attendant le fichier des retenus et de la liste d'attente
 * (Partie D).
 *
 *   Superviseur : 556 recrutés — 483 opérationnels, 73 réserve
 *   Opérateur   : 1 111 recrutés — 966 opérationnels, 145 réserve
 *   Assistant   : 1 111 recrutés — 966 opérationnels, 145 réserve
 *
 * Les assistants sont rattachés à une LOCALITÉ réelle (cadrage, section 4) :
 * sélection des localités les plus peuplées de chaque région, au prorata du
 * nombre de sites alloué à la région — c'est là que la densité d'agents est
 * la plus vraisemblable. Superviseurs et opérateurs, recrutés au niveau
 * national, ne portent qu'une région d'origine indicative.
 *
 * Génère à la fois `users` et `volontaires` par insertion en lot : à ce
 * volume, passer par le service de cycle de vie ligne à ligne serait inutile
 * — aucun de ces comptes n'est affecté à ce stade, ils naissent INACTIFS
 * exactement comme le prescrit le cadrage pour un import (section 6).
 *
 * Le tirage est SEEDÉ (paramètre pnvb.demonstration.graine) : deux exécutions
 * sur la même base produisent la même population fictive.
 */
class GenerateurVolontairesFictifs
{
    private const EFFECTIFS = [
        'superviseur' => ['recrutes' => 556, 'operationnels' => 483, 'reserve' => 73],
        'operateur' => ['recrutes' => 1111, 'operationnels' => 966, 'reserve' => 145],
        'assistant' => ['recrutes' => 1111, 'operationnels' => 966, 'reserve' => 145],
    ];

    private const NOMS = [
        'Ouédraogo', 'Compaoré', 'Kaboré', 'Sawadogo', 'Zongo', 'Traoré', 'Kaméli',
        'Yaméogo', 'Bassolé', 'Nikiéma', 'Sanou', 'Kaboret', 'Ilboudo', 'Congo',
        'Bicaba', 'Zoungrana', 'Tapsoba', 'Kafando', 'Bado', 'Kiendrébéogo',
        'Sana', 'Ouangraoua', 'Dabiré', 'Ouattara', 'Barry', 'Diallo', 'Kambou',
        'Millogo', 'Somé', 'Palm',
    ];

    private const PRENOMS = [
        'Aminata', 'Boureima', 'Fatimata', 'Issa', 'Awa', 'Moussa', 'Rasmata',
        'Adama', 'Salimata', 'Ismaël', 'Mariam', 'Abdoulaye', 'Aïcha', 'Ousmane',
        'Assita', 'Saïdou', 'Bintou', 'Lassané', 'Rahinatou', 'Yacouba',
        'Nathalie', 'Bienvenu', 'Larissa', 'Wendkuni', 'Zenabou', 'Karim',
    ];

    private int $graine;
    private int $compteurTelephone = 71000001;

    public function __construct()
    {
        $this->graine = (int) config('pnvb.demonstration.graine', 20260911);
    }

    /** @return array{crees: int, par_categorie: array} */
    public function generer(): array
    {
        mt_srand($this->graine);

        $localitesAssistants = $this->selectionnerLocalitesAssistants(
            self::EFFECTIFS['assistant']['operationnels'] + self::EFFECTIFS['assistant']['reserve']
        );

        $regions = Region::query()->pluck('id')->all();
        $total = 0;
        $parCategorie = [];

        foreach (self::EFFECTIFS as $categorie => $effectif) {
            $lignes = [];
            $lignesUsers = [];
            $mtdpHash = Hash::make('MotDePasseDemo#1');

            for ($i = 1; $i <= $effectif['recrutes']; $i++) {
                $statut = $i <= $effectif['operationnels'] ? 'operationnel' : 'reserve';
                $numeroTelephone = '+226'.$this->compteurTelephone++;
                $matricule = sprintf('PNVB-%s%06d', CategorieVolontaire::from($categorie)->prefixeMatricule(), $i);

                // Cascade de remise (section 6) : les assistants ruraux, recrutés
                // localement, ont moins souvent une adresse de courriel exploitable
                // que les agents recrutés au national — la génération le reflète.
                $probabiliteEmail = $categorie === 'assistant' ? 0.30 : 0.85;
                $email = (mt_rand(1, 100) / 100) <= $probabiliteEmail
                    ? Str::slug($this->nomAleatoire().'.'.$this->prenomAleatoire()).$i.'@pnvb-demo.bf'
                    : null;

                $lignesUsers[] = [
                    'telephone' => $numeroTelephone,
                    'email' => $email,
                    'nom' => $this->nomAleatoire(),
                    'prenoms' => $this->prenomAleatoire(),
                    'password' => $mtdpHash,
                    'statut_compte' => 'inactif',
                    'doit_changer_mot_de_passe' => true,
                    'etat_remise' => 'non_envoye',
                    'est_fictif' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $lignes[] = [
                    'matricule' => $matricule,
                    'categorie' => $categorie,
                    'statut' => $statut,
                    'localite_id' => $categorie === 'assistant' ? array_shift($localitesAssistants) : null,
                    'region_origine_id' => $categorie !== 'assistant'
                        ? $regions[array_rand($regions)]
                        : null,
                    'sexe' => mt_rand(0, 1) ? 'M' : 'F',
                    'date_naissance' => now()->subYears(mt_rand(22, 45))->subDays(mt_rand(0, 365))->toDateString(),
                    'motif_reserve' => $statut === 'reserve' ? 'non_mobilise' : null,
                    'date_entree_reserve' => $statut === 'reserve' ? now() : null,
                    'est_fictif' => true,
                ];
            }

            // Les users et volontaires sont insérés en un seul bloc par
            // catégorie (≤ 1 111 lignes). L'ID de départ, capturé juste avant
            // l'insertion, donne la plage d'identifiants attribués : un
            // seeder est mono-thread, aucune écriture concurrente ne peut
            // s'intercaler entre les deux requêtes.
            $idDepart = 1 + (int) (DB::table('users')->max('id') ?? 0);
            DB::table('users')->insert($lignesUsers);

            $lignesVolontaires = [];

            foreach ($lignes as $position => $volontaire) {
                $lignesVolontaires[] = [
                    ...$volontaire,
                    'user_id' => $idDepart + $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($lignesVolontaires, 500) as $lot) {
                DB::table('volontaires')->insert($lot);
            }

            // Le DROIT (section 5) est porté par le rôle Spatie correspondant
            // à la catégorie. Ce générateur contourne Eloquent pour le volume
            // — aucun événement de modèle ne s'exécute — le rattachement au
            // pivot model_has_roles est donc fait explicitement ici, sans quoi
            // 2 778 comptes se retrouveraient sans le moindre droit.
            $this->attribuerRoleEnMasse($categorie, $idDepart, count($lignes));

            $parCategorie[$categorie] = $effectif;
            $total += $effectif['recrutes'];
        }

        return ['crees' => $total, 'par_categorie' => $parCategorie];
    }

    /**
     * Sélectionne $n localités destinées à recevoir un assistant, réparties
     * entre régions au prorata du nombre de sites alloué, en privilégiant dans
     * chaque région les localités les plus peuplées.
     *
     * @return int[]
     */
    private function selectionnerLocalitesAssistants(int $n): array
    {
        $regions = Region::query()->get(['id', 'nombre_sites_alloues']);
        $poids = $regions->pluck('nombre_sites_alloues', 'id')->all();
        $parRegion = RepartitionAuxPlusForts::repartir($poids, $n);

        $localites = [];

        foreach ($parRegion as $regionId => $nombre) {
            if ($nombre <= 0) {
                continue;
            }

            $ids = Localite::query()
                ->where('region_id', $regionId)
                ->orderByDesc('population_totale')
                ->limit($nombre)
                ->pluck('id')
                ->all();

            $localites = [...$localites, ...$ids];
        }

        shuffle($localites);

        return $localites;
    }

    private function nomAleatoire(): string
    {
        return self::NOMS[array_rand(self::NOMS)];
    }

    private function prenomAleatoire(): string
    {
        return self::PRENOMS[array_rand(self::PRENOMS)];
    }

    /**
     * Rattache en masse le rôle Spatie correspondant à la catégorie, pour la
     * plage d'utilisateurs [$idDepart, $idDepart + $nombre[.
     *
     * Écrit directement dans le pivot model_has_roles : c'est ce que fait
     * assignRole() sous le capot, sans le coût d'un aller-retour Eloquent par
     * utilisateur. Le rôle est cherché sur le guard par défaut de
     * l'application (voir config/auth.php) — le même que celui que résout
     * assignRole() ailleurs dans le code, pour rester cohérent.
     */
    private function attribuerRoleEnMasse(string $categorie, int $idDepart, int $nombre): void
    {
        $nomRole = CategorieVolontaire::from($categorie)->role()->value;
        $idRole = DB::table('roles')
            ->where('name', $nomRole)
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->value('id');

        if (! $idRole) {
            throw new \RuntimeException(
                "Rôle « {$nomRole} » introuvable : exécutez RolesEtPermissionsSeeder avant ce générateur."
            );
        }

        $lignes = [];

        for ($id = $idDepart; $id < $idDepart + $nombre; $id++) {
            $lignes[] = ['role_id' => $idRole, 'model_type' => \App\Models\User::class, 'model_id' => $id];
        }

        foreach (array_chunk($lignes, 1000) as $lot) {
            DB::table('model_has_roles')->insert($lot);
        }
    }
}
