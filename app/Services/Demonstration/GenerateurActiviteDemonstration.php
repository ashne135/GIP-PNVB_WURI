<?php

namespace App\Services\Demonstration;

use App\Models\FeuillePresence;
use App\Models\Incident;
use App\Models\LignePresence;
use App\Models\NatureIncident;
use App\Models\RapportJournalier;
use App\Models\RapportOpkProduction;
use App\Models\RapportVisa;
use App\Models\VagueDeploiement;
use App\Services\Agregats\CalculateurAgregats;
use App\Services\Agregats\CalculateurCouverture;
use App\Services\Incidents\NumeroteurIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * ACTIVITÉ FICTIVE DE LA VAGUE DE DÉMONSTRATION.
 *
 * Sans rapport visé ni feuille validée, les agrégats restent vides et le
 * tableau de bord ne montre rien. Ce générateur fabrique, pour les jours ouvrés
 * écoulés de la vague de démonstration, ce que le terrain aurait remonté :
 *
 *   - un rapport d'opérateur VISÉ par jour et par kit en tournée, avec sa
 *     signature et son visa ;
 *   - une feuille de présence VALIDÉE par site et par jour ;
 *   - quelques incidents, déjà pris en charge ou clos.
 *
 * TROIS GARDE-FOUS :
 *
 *  1. TOUT EST MARQUÉ FICTIF, et PurgeurActiviteFictive le retire. Sans cette
 *     purge, les auteurs de ces rapports — tenus par des clés restrictives —
 *     ne pourraient plus jamais être supprimés.
 *
 *  2. LA VAGUE EST RECULÉE, pas l'activité antidatée. Un rapport daté d'avant
 *     l'ouverture de sa vague serait incohérent : c'est la vague entière —
 *     dates, tournées, affectations — qui est décalée dans le passé, en gardant
 *     l'enchaînement des tournées intact.
 *
 *  3. LE TIRAGE EST REPRODUCTIBLE : même graine, même activité. Le générateur
 *     de hasard est local ; mt_srand, lui, agirait sur tout le processus.
 *
 * Seuls les jours ouvrés sont remplis, comme pour les relevés de position. La
 * plateforme ne conserve aucune donnée d'identité des personnes enregistrées :
 * l'activité ne fabrique que des volumes.
 */
class GenerateurActiviteDemonstration
{
    /** Récits d'incident : aucun ne désigne une personne enregistrée. */
    private const INCIDENTS = [
        ['panne_informatique', 'La tablette a redémarré seule deux fois dans la matinée ; les dossiers en cours ont été repris.'],
        ['probleme_connexion', 'Réseau coupé de 10 h à midi : les enregistrements ont été conservés hors ligne puis synchronisés.'],
        ['equipement_materiel', "L'imprimante de récépissés s'est bloquée ; nettoyage effectué, fonctionnement rétabli."],
        ['incident_population', "Affluence plus forte que prévu et tension dans la file d'attente ; le calme est revenu avec l'appui du chef de village."],
        ['transport', "Arrivée tardive de l'équipe : la moto de l'opérateur est tombée en panne sur la piste."],
    ];

    public function __construct(
        private readonly PurgeurActiviteFictive $purgeur = new PurgeurActiviteFictive,
        private readonly CalculateurAgregats $agregats = new CalculateurAgregats,
        private readonly CalculateurCouverture $couverture = new CalculateurCouverture,
        private readonly NumeroteurIncident $numeroteur = new NumeroteurIncident,
    ) {}

    /**
     * @return array{vague: string, decalage_jours: int, jours: int, rapports: int, feuilles: int, incidents: int}
     */
    public function generer(int $jours = 14, bool $refaire = false): array
    {
        if ($jours < 1 || $jours > 60) {
            throw new \DomainException('Le nombre de jours doit être compris entre 1 et 60.');
        }

        $vague = VagueDeploiement::query()
            ->where('est_fictif', true)
            ->where('statut', 'active')
            ->latest('id')
            ->first();

        if (! $vague) {
            throw new \DomainException(
                "Aucune vague de démonstration active : lancez d'abord le seeder de démonstration."
            );
        }

        $existe = DB::table('rapports_journaliers')
            ->where('vague_id', $vague->id)
            ->where('est_fictif', true)
            ->exists();

        if ($existe && ! $refaire) {
            throw new \DomainException(
                "La vague {$vague->code} a déjà une activité de démonstration. "
                .'Utilisez l’option de régénération pour la remplacer.'
            );
        }

        // Le journal d'activité est réservé aux actes réels : des milliers de
        // lignes « rapport créé » fictives y noieraient les vrais événements.
        activity()->disableLogging();

        try {
            if ($existe) {
                $this->purgeur->purger($vague->id);
            }

            $decalage = $this->reculerLaVague($vague, $jours);
            $vague->refresh();

            $hasard = new Randomizer(new Mt19937((int) config('pnvb.demonstration.graine', 20260911)));
            $superviseurs = $this->superviseursParCentre($vague->id);
            $assistants = $this->assistantsParLocalite($vague->id);
            $natures = NatureIncident::query()->pluck('id', 'code')->all();

            $debut = Carbon::parse($vague->date_debut_prevue)->startOfDay();
            $hier = now()->subDay()->startOfDay();

            $compte = ['jours' => 0, 'rapports' => 0, 'feuilles' => 0, 'incidents' => 0];
            $dates = [];

            for ($jour = $debut->copy(); $jour->lessThanOrEqualTo($hier); $jour->addDay()) {
                if ($jour->isWeekend()) {
                    continue;
                }

                $date = $jour->toDateString();
                $rang = (int) round($debut->diffInDays($jour));

                $resultat = DB::transaction(fn () => $this->genererJournee(
                    $vague, $date, $rang, $hasard, $superviseurs, $assistants, $natures
                ));

                if ($resultat['rapports'] > 0) {
                    $dates[] = $date;
                    $compte['jours']++;
                }

                $compte['rapports'] += $resultat['rapports'];
                $compte['feuilles'] += $resultat['feuilles'];
                $compte['incidents'] += $resultat['incidents'];
            }
        } finally {
            activity()->enableLogging();
        }

        // Les agrégats de chaque journée, puis la couverture cumulée qui les lit.
        foreach ($dates as $date) {
            $this->agregats->recalculerJournee($date);
        }

        $this->couverture->recalculer();

        return ['vague' => $vague->code, 'decalage_jours' => $decalage, ...$compte];
    }

    /**
     * Recule la vague entière pour qu'elle ait commencé il y a $jours jours.
     *
     * Dates de la vague, des tournées, des affectations et des ouvertures de
     * centre bougent du même décalage : l'enchaînement des passages est
     * conservé. Les statuts des tournées suivent la nouvelle date du jour.
     */
    private function reculerLaVague(VagueDeploiement $vague, int $jours): int
    {
        $debutVoulu = now()->subDays($jours)->startOfDay();
        $debutActuel = Carbon::parse($vague->date_debut_prevue)->startOfDay();
        $decalage = (int) round($debutVoulu->diffInDays($debutActuel));

        if ($decalage <= 0) {
            return 0;
        }

        $recul = fn (string $colonne) => DB::raw("DATE_SUB({$colonne}, INTERVAL {$decalage} DAY)");

        DB::transaction(function () use ($vague, $recul) {
            DB::table('vagues_deploiement')->where('id', $vague->id)->update([
                'date_debut_prevue' => $recul('date_debut_prevue'),
                'date_fin_prevue' => $recul('date_fin_prevue'),
                'date_ouverture_reelle' => $recul('date_ouverture_reelle'),
            ]);

            DB::table('tournees_site')->where('vague_id', $vague->id)->update([
                'date_debut' => $recul('date_debut'),
                'date_fin' => $recul('date_fin'),
            ]);

            DB::table('affectations')->where('vague_id', $vague->id)->update([
                'date_debut' => $recul('date_debut'),
            ]);

            DB::table('vague_centres')->where('vague_id', $vague->id)->whereNotNull('date_ouverture')->update([
                'date_ouverture' => $recul('date_ouverture'),
            ]);

            $aujourdhui = now()->toDateString();
            $tournees = fn () => DB::table('tournees_site')->where('vague_id', $vague->id);

            $tournees()->where('date_fin', '<', $aujourdhui)->update(['statut' => 'terminee']);
            $tournees()->where('date_debut', '<=', $aujourdhui)->where('date_fin', '>=', $aujourdhui)
                ->update(['statut' => 'en_cours']);
            $tournees()->where('date_debut', '>', $aujourdhui)->update(['statut' => 'planifiee']);
        });

        return $decalage;
    }

    /**
     * Une journée : pour chaque kit en tournée ce jour-là, un rapport visé, la
     * feuille de présence validée du site et, parfois, un incident.
     *
     * @return array{rapports: int, feuilles: int, incidents: int}
     */
    private function genererJournee(
        VagueDeploiement $vague,
        string $date,
        int $rang,
        Randomizer $hasard,
        array $superviseurs,
        array $assistants,
        array $natures
    ): array {
        $tournees = DB::table('tournees_site as t')
            ->join('affectations as a', 'a.id', '=', 't.affectation_operateur_id')
            ->join('volontaires as v', 'v.id', '=', 'a.volontaire_id')
            ->join('sites as s', 's.id', '=', 't.site_id')
            ->join('centres as c', 'c.id', '=', 't.centre_id')
            ->where('t.vague_id', $vague->id)
            ->whereDate('t.date_debut', '<=', $date)
            ->where(fn ($q) => $q->whereNull('t.date_fin')->orWhereDate('t.date_fin', '>=', $date))
            ->orderBy('t.id')
            ->get([
                't.id as tournee_id', 't.site_id', 't.centre_id', 'a.id as affectation_id',
                'v.id as operateur_id', 'v.user_id as operateur_user_id', 's.localite_id', 'c.region_id',
            ]);

        $compte = ['rapports' => 0, 'feuilles' => 0, 'incidents' => 0];

        // Les équipes gagnent en rythme pendant la première semaine.
        $rodage = min(1.0, 0.55 + 0.09 * $rang);

        foreach ($tournees as $tournee) {
            $superviseur = $superviseurs[$tournee->centre_id] ?? null;

            // Un rapport visé exige un superviseur désigné : sans lui, pas de visa.
            if (! $superviseur) {
                continue;
            }

            $this->genererRapport($vague, $tournee, $superviseur, $date, $rodage, $hasard);
            $compte['rapports']++;

            $agents = $assistants["{$tournee->centre_id}|{$tournee->localite_id}"] ?? [];
            $this->genererFeuille($vague, $tournee, $superviseur, $agents, $date, $hasard);
            $compte['feuilles']++;

            if ($hasard->getInt(1, 100) <= 4) {
                $this->genererIncident($tournee, $superviseur, $date, $hasard, $natures);
                $compte['incidents']++;
            }
        }

        return $compte;
    }

    private function genererRapport(
        VagueDeploiement $vague,
        object $tournee,
        array $superviseur,
        string $date,
        float $rodage,
        Randomizer $hasard
    ): void {
        $realises = (int) round($hasard->getInt(70, 140) * $rodage);
        $rejetes = $hasard->getInt(1, 100) <= 30 ? $hasard->getInt(1, 5) : 0;
        $soumis = "{$date} 17:40:00";
        $vise = "{$date} 19:15:00";

        $rapport = RapportJournalier::query()->create([
            'uuid_client' => (string) Str::uuid(),
            'type' => 'opk',
            'date_rapport' => $date,
            'auteur_volontaire_id' => $tournee->operateur_id,
            'affectation_id' => $tournee->affectation_id,
            'vague_id' => $vague->id,
            'site_id' => $tournee->site_id,
            'centre_id' => $tournee->centre_id,
            'region_id' => $tournee->region_id,
            'tournee_site_id' => $tournee->tournee_id,
            'superieur_volontaire_id' => $superviseur['volontaire_id'],
            'statut' => 'vise',
            'heure_arrivee' => '07:30',
            'heure_depart' => '17:30',
            'soumis_le' => $soumis,
            'vise_le' => $vise,
            'est_fictif' => true,
        ]);

        RapportOpkProduction::query()->create([
            'rapport_id' => $rapport->id,
            // L'objectif est celui de la vague — rien s'il n'est pas fixé :
            // l'écart reste alors vide plutôt que calculé sur un chiffre inventé.
            'objectif_enregistrements' => $vague->objectif_enregistrements_par_kit_jour,
            'enregistrements_realises' => $realises,
            'recepisses_transmis' => max(0, $realises - $rejetes),
            'enregistrements_non_valides' => $rejetes,
            'motif_non_valides' => $rejetes > 0 ? 'Photographie illisible : dossier à reprendre le lendemain.' : null,
            'etat_kit' => 'fonctionnel',
        ]);

        RapportVisa::query()->create([
            'rapport_id' => $rapport->id,
            'acte' => 'signature',
            'user_id' => $tournee->operateur_user_id,
            'role_tenu' => 'volontaire_operateur',
            'effectue_le' => $soumis,
        ]);

        RapportVisa::query()->create([
            'rapport_id' => $rapport->id,
            'acte' => 'visa',
            'user_id' => $superviseur['user_id'],
            'role_tenu' => 'volontaire_superviseur',
            'effectue_le' => $vise,
        ]);
    }

    /** @param  int[]  $assistants */
    private function genererFeuille(
        VagueDeploiement $vague,
        object $tournee,
        array $superviseur,
        array $assistants,
        string $date,
        Randomizer $hasard
    ): void {
        $feuille = FeuillePresence::query()->create([
            'uuid_client' => (string) Str::uuid(),
            'site_id' => $tournee->site_id,
            'centre_id' => $tournee->centre_id,
            'vague_id' => $vague->id,
            'tournee_site_id' => $tournee->tournee_id,
            'date_presence' => $date,
            'statut' => 'validee',
            'superviseur_id' => $superviseur['volontaire_id'],
            'valide_le' => "{$date} 09:10:00",
            'est_fictif' => true,
        ]);

        // L'opérateur est présent : il a produit le rapport du jour. Une
        // absence ici contredirait la production déclarée.
        LignePresence::query()->create([
            'feuille_presence_id' => $feuille->id,
            'volontaire_id' => $tournee->operateur_id,
            'categorie' => 'operateur',
            'statut' => 'present',
        ]);

        foreach ($assistants as $idAssistant) {
            $tirage = $hasard->getInt(1, 100);
            $statut = $tirage <= 91 ? 'present' : ($tirage <= 96 ? 'absent_justifie' : 'absent');

            LignePresence::query()->create([
                'feuille_presence_id' => $feuille->id,
                'volontaire_id' => $idAssistant,
                'categorie' => 'assistant',
                'statut' => $statut,
                'motif_absence' => $statut === 'absent_justifie' ? 'Raison de santé, signalée au superviseur.' : null,
            ]);
        }
    }

    private function genererIncident(
        object $tournee,
        array $superviseur,
        string $date,
        Randomizer $hasard,
        array $natures
    ): void {
        [$codeNature, $recit] = self::INCIDENTS[$hasard->getInt(0, count(self::INCIDENTS) - 1)];
        $gravite = [1, 1, 1, 2, 2, 3][$hasard->getInt(0, 5)];
        $statut = ['cloture', 'cloture', 'resolu', 'pris_en_charge', 'en_cours'][$hasard->getInt(0, 4)];
        $regle = in_array($statut, ['resolu', 'cloture'], true);

        $incident = Incident::query()->create([
            'uuid_client' => (string) Str::uuid(),
            'numero' => $this->numeroteur->suivant((int) substr($date, 0, 4)),
            'declarant_user_id' => $tournee->operateur_user_id,
            'declare_le' => "{$date} 11:20:00",
            'canal' => 'mobile',
            'site_id' => $tournee->site_id,
            'centre_id' => $tournee->centre_id,
            'localite_id' => $tournee->localite_id,
            'region_id' => $tournee->region_id,
            'type_lieu' => 'site',
            'survenu_le' => "{$date} 11:00:00",
            'recit' => $recit,
            'toujours_en_cours' => 'non',
            'danger_immediat' => false,
            'gravite' => $gravite,
            'statut' => $statut,
            'responsable_traitement_user_id' => $superviseur['user_id'],
            'pris_en_charge_le' => "{$date} 12:00:00",
            'resolu_le' => $regle ? "{$date} 16:00:00" : null,
            'rapport_cloture' => $statut === 'cloture'
                ? 'Situation rétablie le jour même ; aucune suite nécessaire.'
                : null,
            // Déjà pris en charge : aucune échéance, donc aucune escalade ni
            // alerte fictive envoyée aux responsables.
            'echeance_escalade' => null,
            'niveau_escalade' => 0,
            'est_fictif' => true,
        ]);

        if (isset($natures[$codeNature])) {
            $incident->natures()->attach($natures[$codeNature]);
        }
    }

    /** @return array<int, array{volontaire_id: int, user_id: int}> */
    private function superviseursParCentre(int $idVague): array
    {
        $lignes = DB::table('unites_supervision as u')
            ->join('volontaires as v', 'v.id', '=', 'u.volontaire_superviseur_id')
            ->where('u.vague_id', $idVague)
            ->get(['u.centre_principal_id', 'u.centre_secondaire_id', 'v.id', 'v.user_id']);

        $carte = [];

        foreach ($lignes as $ligne) {
            foreach ([$ligne->centre_principal_id, $ligne->centre_secondaire_id] as $idCentre) {
                if ($idCentre) {
                    $carte[$idCentre] = ['volontaire_id' => $ligne->id, 'user_id' => $ligne->user_id];
                }
            }
        }

        return $carte;
    }

    /** @return array<string, int[]> Les assistants par « centre|localité ». */
    private function assistantsParLocalite(int $idVague): array
    {
        return DB::table('affectations')
            ->where('vague_id', $idVague)
            ->where('role_terrain', 'assistant')
            ->where('statut', 'active')
            ->get(['volontaire_id', 'centre_id', 'localite_id'])
            ->groupBy(fn ($a) => "{$a->centre_id}|{$a->localite_id}")
            ->map(fn ($groupe) => $groupe->pluck('volontaire_id')->unique()->values()->all())
            ->all();
    }
}
