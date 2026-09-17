<?php

namespace App\Services\Rapports;

use App\Enums\StatutRapport;
use App\Enums\TypeRapport;
use App\Models\Affectation;
use App\Models\RapportJournalier;
use App\Models\RapportSupLogistique;
use App\Models\Volontaire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ouverture d'un rapport journalier, AVEC SON CONTENU PRÉ-REMPLI.
 *
 * POINT DE VIGILANCE DE LA TÂCHE : « les chiffres sont PRÉ-REMPLIS, JAMAIS
 * RESSAISIS » (cadrage, section 9, règles transverses).
 *
 * Ce service applique cette règle à trois endroits :
 *
 *   1. LE BLOC D'IDENTIFICATION — région, commune, site, matricule, supérieur —
 *      vient de l'affectation en cours. « L'agent ne saisit aucun élément
 *      d'identification : il les vérifie. »
 *
 *   2. LES CHIFFRES DU NIVEAU INFÉRIEUR remontent automatiquement, et seulement
 *      depuis les rapports DÉJÀ VISÉS : un brouillon ou un rapport rejeté
 *      n'alimente rien, sinon un chiffre non validé se propagerait vers le haut.
 *
 *   3. LA PRÉSENCE des agents suivis vient de la FEUILLE DE PRÉSENCE du jour,
 *      jamais d'une saisie — seule la feuille validée fait foi.
 *
 * L'OBJECTIF de l'opérateur est FIGÉ à l'ouverture, copié depuis la vague :
 * modifier l'objectif d'une vague ne doit pas réécrire l'écart d'un rapport
 * déjà signé.
 */
class ServicePreparationRapport
{
    public function __construct(
        private readonly ServicePreRemplissage $preRemplissage = new ServicePreRemplissage,
    ) {}

    /**
     * Ouvre — ou récupère — le rapport du jour pour un agent.
     * Idempotent : deux appels le même jour rendent le même rapport, grâce à
     * l'unicité (auteur, type, date) en base.
     *
     * @param  string|null  $uuidClient  L'identifiant produit par le téléphone
     *        quand le rapport a été ouvert hors ligne. Si le serveur avait déjà
     *        ouvert le rapport de la journée, c'est le sien qui reste : il ne
     *        réécrit pas une clé sur laquelle un autre appareil s'appuie peut-être.
     */
    public function ouvrir(
        Volontaire $auteur,
        string $date,
        ?string $uuidClient = null
    ): RapportJournalier {
        if ($auteur->categorie === null) {
            throw new \DomainException(
                "Votre profil n'est pas encore attribué : aucun rapport ne peut être ouvert."
            );
        }

        $type = $auteur->categorie->typeRapport();

        $existant = RapportJournalier::query()
            ->where('auteur_volontaire_id', $auteur->id)
            ->where('type', $type->value)
            ->whereDate('date_rapport', $date)
            ->first();

        if ($existant) {
            return $this->charger($existant);
        }

        $identification = $this->preRemplissage->blocIdentification($auteur, $date);

        return DB::transaction(function () use ($auteur, $date, $type, $identification, $uuidClient) {
            $rapport = RapportJournalier::query()->create([
                // Ouvert depuis un telephone hors ligne, le rapport garde l'uuid
                // que l'appareil lui a donne : c'est la seule cle dont il dispose
                // pour se recaler apres la synchronisation.
                'uuid_client' => $uuidClient ?? (string) Str::uuid(),
                'type' => $type->value,
                'date_rapport' => $date,
                'auteur_volontaire_id' => $auteur->id,
                'statut' => StatutRapport::Brouillon->value,
                ...$identification,
            ]);

            match ($type) {
                TypeRapport::Aopk => $this->preparerAopk($rapport),
                TypeRapport::Opk => $this->preparerOpk($rapport, $auteur, $date),
                TypeRapport::Superviseur => $this->preparerSuperviseur($rapport, $auteur, $date),
            };

            return $this->charger($rapport->fresh());
        });
    }

    /**
     * Recalcule les valeurs pré-remplies d'un rapport encore ouvert.
     * Utile quand un rapport du niveau inférieur est visé APRÈS l'ouverture :
     * le superviseur rafraîchit ses chiffres sans repartir de zéro.
     */
    public function rafraichir(RapportJournalier $rapport): RapportJournalier
    {
        if (! $rapport->estModifiableParAuteur()) {
            throw new \DomainException(
                "Ce rapport est « {$rapport->statut->libelle()} » : ses chiffres ne changent plus."
            );
        }

        $auteur = $rapport->auteur;
        $date = $rapport->date_rapport->toDateString();

        DB::transaction(function () use ($rapport, $auteur, $date) {
            if ($rapport->type === TypeRapport::Opk) {
                $this->synchroniserSuiviAgents($rapport, $auteur, $date);
            }

            if ($rapport->type === TypeRapport::Superviseur) {
                $evolution = $this->preRemplissage->evolutionPour($auteur, $date);

                $rapport->evolution()->updateOrCreate([], [
                    'personnes_enregistrees' => $evolution['personnes_enregistrees'],
                    'dossiers_valides' => $evolution['dossiers_valides'],
                    'dossiers_a_reprendre' => $evolution['dossiers_a_reprendre'],
                ]);

                $this->synchroniserSuiviEquipes($rapport, $auteur, $date);
            }
        });

        return $this->charger($rapport->fresh());
    }

    // ------------------------------------------------------------------
    // 9.1 — A-OPK : l'accueil du site
    // ------------------------------------------------------------------

    /**
     * L'A-OPK N'ENREGISTRE PERSONNE : rien à pré-remplir depuis un niveau
     * inférieur, il est au bas de la chaîne. On crée la ligne d'activités
     * vide, prête à être renseignée en PRÉVU et RÉALISÉ.
     */
    private function preparerAopk(RapportJournalier $rapport): void
    {
        $rapport->activitesAopk()->create([]);
    }

    // ------------------------------------------------------------------
    // 9.2 — Opérateur : la production
    // ------------------------------------------------------------------

    private function preparerOpk(RapportJournalier $rapport, Volontaire $auteur, string $date): void
    {
        // L'objectif est FIGÉ ici, copié depuis la vague : changer l'objectif
        // d'une vague ne réécrit jamais l'écart d'un rapport déjà produit.
        $rapport->productionOpk()->create([
            'objectif_enregistrements' => $rapport->vague?->objectif_enregistrements_par_kit_jour,
        ]);

        $this->synchroniserSuiviAgents($rapport, $auteur, $date);
    }

    /** Une ligne par A-OPK rattaché au kit, avec sa présence reprise de la feuille. */
    private function synchroniserSuiviAgents(RapportJournalier $rapport, Volontaire $auteur, string $date): void
    {
        foreach ($this->preRemplissage->suiviAopkPour($auteur, $date) as $agent) {
            $rapport->suiviAgents()->updateOrCreate(
                ['volontaire_id' => $agent['volontaire_id']],
                [
                    'categorie_agent' => 'aopk',
                    'ligne_presence_id' => $agent['ligne_presence_id'],
                    'presence' => $agent['presence'],
                ]
            );
        }
    }

    // ------------------------------------------------------------------
    // 9.3 — Superviseur de centre
    // ------------------------------------------------------------------

    private function preparerSuperviseur(RapportJournalier $rapport, Volontaire $auteur, string $date): void
    {
        // Les chiffres viennent des rapports OPK DÉJÀ VISÉS de ses centres.
        // « Le superviseur ne recopie jamais à la main les chiffres de ses OPK. »
        $evolution = $this->preRemplissage->evolutionPour($auteur, $date);

        $rapport->evolution()->create([
            'personnes_enregistrees' => $evolution['personnes_enregistrees'],
            'dossiers_valides' => $evolution['dossiers_valides'],
            'dossiers_a_reprendre' => $evolution['dossiers_a_reprendre'],
        ]);

        $rapport->qualite()->create([]);

        // La situation logistique part des ressources du canevas client, et
        // reste ouverte : « lignes ajoutables ».
        foreach (RapportSupLogistique::RESSOURCES_TYPE as $rang => $ressource) {
            $rapport->logistique()->create([
                'ressource' => $ressource,
                'ordre' => $rang + 1,
            ]);
        }

        $this->synchroniserSuiviEquipes($rapport, $auteur, $date);
    }

    /**
     * DEUX tableaux séparés — un pour les A-OPK, un pour les OPK (cadrage 9.3 D).
     * Les agents suivis sont ceux dont le rapport est remonté jusqu'à lui, plus
     * les opérateurs de ses centres.
     */
    private function synchroniserSuiviEquipes(RapportJournalier $rapport, Volontaire $auteur, string $date): void
    {
        $rapportsOpk = $this->preRemplissage->rapportsVisesDuNiveauInferieur($auteur, TypeRapport::Opk, $date);

        foreach ($rapportsOpk as $rapportOpk) {
            $rapport->suiviAgents()->updateOrCreate(
                ['volontaire_id' => $rapportOpk->auteur_volontaire_id],
                ['categorie_agent' => 'opk']
            );

            // Les A-OPK appréciés par cet opérateur remontent aussi : le
            // superviseur voit l'équipe entière de ses centres.
            foreach ($rapportOpk->suiviAgents as $suivi) {
                $rapport->suiviAgents()->updateOrCreate(
                    ['volontaire_id' => $suivi->volontaire_id],
                    [
                        'categorie_agent' => 'aopk',
                        'ligne_presence_id' => $suivi->ligne_presence_id,
                        'presence' => $suivi->presence?->value,
                    ]
                );
            }
        }
    }

    // ------------------------------------------------------------------

    private function charger(RapportJournalier $rapport): RapportJournalier
    {
        return $rapport->load([
            'auteur:id,user_id,matricule,categorie', 'auteur.user:id,nom,prenoms',
            'superieur:id,user_id,matricule', 'superieur.user:id,nom,prenoms',
            'site:id,code,nom', 'centre:id,code,nom,commune_id',
            'centre.commune:id,nom,province_id', 'centre.commune.province:id,nom',
            'region:id,code,nom', 'vague:id,code,libelle',
            'activitesAopk', 'productionOpk', 'evolution', 'qualite', 'logistique',
            'difficultes', 'pointsAmelioration',
            'suiviAgents.volontaire:id,user_id,matricule,categorie',
            'suiviAgents.volontaire.user:id,nom,prenoms',
            'suiviAgents.reponses',
            'visas.user:id,nom,prenoms', 'corrections',
        ]);
    }
}
