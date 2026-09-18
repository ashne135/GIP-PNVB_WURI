<?php

namespace App\Services\Suppression;

use App\Models\Centre;
use App\Models\Kit;
use App\Models\Site;
use App\Models\User;
use App\Models\Volontaire;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SUPPRIMER POUR DE BON — et refuser plus souvent qu'accepter.
 *
 * Le dispositif ne supprime normalement rien : un volontaire se RETIRE, un
 * centre se FERME, un kit se RÉFORME. Ces gestes gardent la trace, et c'est
 * ce que la traçabilité exige.
 *
 * Reste le cas du JEU D'ESSAI : des fiches créées pour tester, qu'il faut
 * pouvoir faire disparaître avant la mise en service réelle. C'est le seul
 * emploi de ce service, et il est bâti pour être le plus timide possible.
 *
 * TROIS PRINCIPES :
 *
 *  1. ON REFUSE PLUTÔT QUE DE FORCER. Dès qu'une donnée qui FAIT FOI dépend de
 *     la ligne — une feuille de présence, un rapport, un incident, un mouvement
 *     de kit — la suppression est refusée, et le motif nomme l'obstacle avec
 *     son nombre. Une feuille de présence ne disparaît pas parce qu'on efface
 *     un site.
 *
 *  2. CE QUI EST DÉTACHÉ N'EST PAS SUPPRIMÉ. Un kit appartient à l'agent, pas
 *     au centre : effacer un centre détache ses kits, il ne les détruit pas.
 *
 *  3. AUCUNE ERREUR 500. La liste des obstacles est explicite — elle se lit et
 *     se vérifie — mais une contrainte oubliée resterait possible. La
 *     suppression est donc enveloppée dans une transaction, et une violation de
 *     clé étrangère devient un refus lisible plutôt qu'une page d'erreur.
 */
class ServiceSuppressionDefinitive
{
    /**
     * Ce qui INTERDIT la suppression, par famille.
     *
     * Chaque entrée dit la table à compter, la colonne qui pointe vers la ligne
     * visée, et le mot par lequel l'administrateur la reconnaîtra.
     */
    private const OBSTACLES = [
        'volontaire' => [
            ['affectations', 'volontaire_id', 'affectations'],
            ['lignes_presence', 'volontaire_id', 'lignes de feuille de présence'],
            ['feuilles_presence', 'superviseur_id', 'feuilles de présence signées'],
            ['rapports_journaliers', 'auteur_volontaire_id', 'rapports journaliers'],
            ['rapports_journaliers', 'superieur_volontaire_id', 'rapports journaliers'],
            ['rapport_suivi_agents', 'volontaire_id', 'appréciations reçues'],
            ['appreciations_reponses', 'volontaire_id', 'réponses à une appréciation'],
            ['kit_mouvements', 'volontaire_source_id', 'mouvements de kit'],
            ['kit_mouvements', 'volontaire_destination_id', 'mouvements de kit'],
            ['ecarts_presence', 'volontaire_id', 'écarts de présence'],
            ['signaux_arrivee', 'volontaire_id', 'signaux d\'arrivée'],
            ['remplacements', 'volontaire_sortant_id', 'remplacements'],
            ['remplacements', 'volontaire_entrant_id', 'remplacements'],
            ['unites_supervision', 'volontaire_superviseur_id', 'unités de supervision'],
            ['kits', 'volontaire_detenteur_id', 'kits détenus'],
        ],
        'centre' => [
            ['vague_centres', 'centre_id', 'ouvertures de centre dans une vague'],
            ['affectations', 'centre_id', 'affectations'],
            ['unites_supervision', 'centre_principal_id', 'unités de supervision'],
            ['unites_supervision', 'centre_secondaire_id', 'unités de supervision'],
            ['feuilles_presence', 'centre_id', 'feuilles de présence'],
            ['rapports_journaliers', 'centre_id', 'rapports journaliers'],
            ['kit_mouvements', 'centre_id', 'mouvements de kit'],
            ['tournees_site', 'centre_id', 'passages de kit programmés'],
            ['incidents', 'centre_id', 'incidents'],
            ['agregats_jour_centre', 'centre_id', 'agrégats de centre'],
        ],
        'site' => [
            ['tournees_site', 'site_id', 'passages de kit programmés'],
            ['feuilles_presence', 'site_id', 'feuilles de présence'],
            ['signaux_arrivee', 'site_id', 'signaux d\'arrivée'],
            ['rapports_journaliers', 'site_id', 'rapports journaliers'],
            ['kit_mouvements', 'site_id', 'mouvements de kit'],
            ['incidents', 'site_id', 'incidents'],
            ['agregats_jour_site', 'site_id', 'agrégats de site'],
        ],
        'kit' => [
            ['kit_mouvements', 'kit_id', 'mouvements'],
            ['affectations', 'kit_id', 'affectations'],
            ['tournees_site', 'kit_id', 'passages programmés'],
        ],
    ];

    /**
     * Ce qui est DÉTACHÉ avant la suppression : la colonne repasse à NULL, la
     * ligne qui la portait continue d'exister.
     */
    private const DETACHEMENTS = [
        'centre' => [['kits', 'centre_courant_id'], ['alertes', 'centre_id']],
        'site' => [['kits', 'site_courant_id'], ['releves_position', 'site_id_attendu']],
        'kit' => [['alertes', 'kit_id']],
        'volontaire' => [['alertes', 'volontaire_id']],
    ];

    /**
     * Les TRACES DU COMPTE, supprimées avec le volontaire.
     *
     * Ce ne sont pas des données de terrain : ce sont les traces de l'existence
     * du compte lui-même — sa charte acceptée, ses identifiants remis, ses
     * relevés de position. Elles n'ont aucun sens sans lui, et les garder
     * orphelines conserverait des positions d'un agent qui n'existe plus.
     */
    private const TRACES_DU_COMPTE = [
        ['releves_position', 'volontaire_id'],
        ['remises_identifiants', 'user_id', 'user'],
        ['consentements', 'user_id', 'user'],
        ['alerte_lectures', 'user_id', 'user'],
        ['personal_access_tokens', 'tokenable_id', 'user'],
    ];

    /**
     * Ce qui empêche de supprimer cette ligne, en clair et chiffré.
     *
     * @return string[] Vide quand la suppression est possible.
     */
    public function obstacles(string $famille, int $id): array
    {
        $obstacles = [];

        foreach (self::OBSTACLES[$famille] ?? [] as [$table, $colonne, $libelle]) {
            $nombre = DB::table($table)->where($colonne, $id)->count();

            if ($nombre > 0) {
                // Deux colonnes peuvent nommer le même obstacle (source et
                // destination d'un mouvement) : on ne le dit qu'une fois.
                $obstacles[$libelle] = "{$nombre} {$libelle}";
            }
        }

        // Un CENTRE emporte ses sites : ce qui bloque un de ses sites le bloque.
        if ($famille === 'centre') {
            foreach (Site::query()->where('centre_id', $id)->pluck('id') as $siteId) {
                foreach ($this->obstacles('site', $siteId) as $obstacle) {
                    $obstacles[$obstacle] = $obstacle.' sur un de ses sites';
                }
            }
        }

        return array_values($obstacles);
    }

    /**
     * Supprime la ligne, ou explique pourquoi elle reste.
     *
     * @return array{supprime: bool, motif: string|null}
     */
    public function supprimer(string $famille, Model $modele): array
    {
        $obstacles = $this->obstacles($famille, $modele->getKey());

        if ($obstacles !== []) {
            return [
                'supprime' => false,
                'motif' => 'Suppression refusée : '.implode(', ', $obstacles)
                    .' en dépendent. Ces données font foi.',
            ];
        }

        try {
            DB::transaction(function () use ($famille, $modele) {
                $this->detacher($famille, $modele->getKey());

                if ($famille === 'centre') {
                    // Les sites d'abord : ils pointent vers le centre.
                    Site::query()->where('centre_id', $modele->getKey())->get()
                        ->each(function (Site $site) {
                            $this->detacher('site', $site->id);
                            $site->delete();
                        });
                }

                if ($famille === 'volontaire') {
                    $this->effacerLesTracesDuCompte($modele);
                }

                $utilisateur = $famille === 'volontaire' ? $modele->user : null;

                $modele->delete();

                // Le compte part avec la fiche : un User sans volontaire ne
                // pourrait plus se connecter à rien, et resterait pourtant dans
                // la liste des comptes.
                $utilisateur?->delete();
            });
        } catch (QueryException $e) {
            /*
             * LE FILET. La liste des obstacles est explicite, donc lisible et
             * vérifiable — mais rien ne garantit qu'elle soit exhaustive après
             * l'ajout d'une table. Une contrainte oubliée doit rester un refus
             * compréhensible, jamais une erreur 500 au milieu d'une liste.
             */
            return [
                'supprime' => false,
                'motif' => 'Suppression refusée : d\'autres données y sont rattachées. '
                    .'Traitez-les d\'abord.',
            ];
        }

        return ['supprime' => true, 'motif' => null];
    }

    private function detacher(string $famille, int $id): void
    {
        foreach (self::DETACHEMENTS[$famille] ?? [] as [$table, $colonne]) {
            DB::table($table)->where($colonne, $id)->update([$colonne => null]);
        }
    }

    private function effacerLesTracesDuCompte(Volontaire $volontaire): void
    {
        $userId = $volontaire->user_id;

        foreach (self::TRACES_DU_COMPTE as $trace) {
            [$table, $colonne] = $trace;
            $cible = ($trace[2] ?? null) === 'user' ? $userId : $volontaire->id;

            DB::table($table)->where($colonne, $cible)->delete();
        }
    }

    /** La famille d'un modèle, pour n'écrire la correspondance qu'ici. */
    public function famille(Model $modele): string
    {
        return match (true) {
            $modele instanceof Volontaire => 'volontaire',
            $modele instanceof Centre => 'centre',
            $modele instanceof Site => 'site',
            $modele instanceof Kit => 'kit',
            default => throw new \InvalidArgumentException('Famille inconnue : '.$modele::class),
        };
    }

    /** Ce qui nomme la ligne dans le compte rendu, pour qu'on la reconnaisse. */
    public function libelle(Model $modele): string
    {
        return match (true) {
            $modele instanceof Volontaire => $modele->matricule,
            $modele instanceof Centre, $modele instanceof Site, $modele instanceof Kit => $modele->code ?? $modele->reference,
            default => (string) $modele->getKey(),
        };
    }
}
