<?php

namespace App\Services\Rapports;

use App\Enums\CategorieVolontaire;
use App\Enums\StatutRapport;
use App\Enums\TypeRapport;
use App\Models\Affectation;
use App\Models\FeuillePresence;
use App\Models\RapportJournalier;
use App\Models\Site;
use App\Models\TourneeSite;
use App\Models\UniteSupervision;
use App\Models\Volontaire;
use Illuminate\Support\Collection;

/**
 * PRÉ-REMPLISSAGE DES RAPPORTS (cadrage v2, section 9, règles transverses).
 *
 * « Les chiffres d'un rapport de niveau N sont agrégés automatiquement depuis
 * les rapports de niveau N-1 DÉJÀ VISÉS. Le superviseur ne recopie jamais à la
 * main les chiffres de ses OPK. »
 *
 * Deux garde-fous portés par ce service :
 *   - seuls les rapports VISÉS remontent — un brouillon ou un rapport rejeté
 *     n'alimente rien, sinon un chiffre non validé se propagerait vers le haut ;
 *   - la PRÉSENCE vient de la feuille de présence du jour, jamais d'une saisie.
 */
class ServicePreRemplissage
{
    /**
     * Le bloc d'identification, commun aux trois rapports.
     * L'agent ne saisit aucun de ces champs : il les vérifie.
     */
    public function blocIdentification(Volontaire $auteur, string $date): array
    {
        // L'affectation qui couvrait LA JOURNÉE DU RAPPORT : un rapport saisi
        // hors ligne le dernier jour et remonté après la clôture de la vague
        // s'ouvre encore, pendant le délai de rattrapage.
        $affectation = Affectation::query()
            // L'UNITÉ DE SUPERVISION est chargée avec le reste, et ce n'est pas
            // un détail : un SUPERVISEUR n'a pas de centre_id — il couvre DEUX
            // centres réunis dans une unité. Sans elle, son rapport n'avait
            // aucun centre à inscrire, et l'ouverture échouait en erreur 500 sur
            // une colonne que la base refuse de laisser vide.
            ->with(['centre.commune.province', 'localite', 'vague', 'uniteSupervision.centrePrincipal'])
            ->where('volontaire_id', $auteur->id)
            ->couvrant($date)
            ->activeDabord()
            ->first();

        if (! $affectation) {
            throw new \DomainException(
                "Vous n'avez pas d'affectation active : aucun rapport ne peut être ouvert."
            );
        }

        $site = $this->siteDuJour($auteur, $affectation, $date);

        /*
         * LE CENTRE DU RAPPORT, selon la catégorie de celui qui l'écrit :
         *
         *   OPÉRATEUR   son affectation porte le centre ;
         *   A-OPK       il vient du site du jour ;
         *   SUPERVISEUR ni l'un ni l'autre — il en couvre deux. Le rapport est
         *               rattaché au centre PRINCIPAL de son unité, celui qui
         *               nomme l'unité ; le second reste dans le détail.
         *
         * Le centre est OBLIGATOIRE en base : un rapport qu'on ne saurait
         * rattacher à aucun centre ne remonterait dans aucun agrégat.
         */
        $centrePrincipal = $affectation->uniteSupervision?->centrePrincipal;
        $centreId = $affectation->centre_id ?? $site?->centre_id ?? $centrePrincipal?->id;

        if ($centreId === null) {
            // Refuser en le disant vaut mieux que laisser la base refuser en
            // erreur 500 : sur le téléphone, la première se lit, la seconde
            // devient « ces informations ne sont pas sur le téléphone ».
            throw new \DomainException(
                "Votre affectation n'est rattachée à aucun centre : le rapport ne peut pas "
                .'être ouvert. Signalez-le à l\'administration nationale.'
            );
        }

        return [
            'affectation_id' => $affectation->id,
            'vague_id' => $affectation->vague_id,
            'centre_id' => $centreId,
            'site_id' => $site?->id,
            'region_id' => $affectation->centre?->region_id ?? $site?->region_id ?? $centrePrincipal?->region_id,
            'tournee_site_id' => $site?->tournee_courante_id,
            'superieur_volontaire_id' => $this->superieurDe($auteur, $affectation, $date)?->id,
        ];
    }

    /**
     * Le supérieur qui devra viser le rapport, figé à la soumission.
     *
     *   A-OPK  → l'opérateur du kit qui couvre son site ce jour-là
     *   OPK    → le superviseur de son centre
     *   SUPERVISEUR → personne ici : c'est le contrôleur terrain, qui n'est pas
     *                 un volontaire et se rattache par superieur_user_id
     */
    public function superieurDe(Volontaire $auteur, Affectation $affectation, string $date): ?Volontaire
    {
        return match ($auteur->categorie) {
            CategorieVolontaire::Assistant => $this->operateurDuSite($affectation, $date),
            CategorieVolontaire::Operateur => $this->superviseurDuCentre($affectation),
            default => null,
        };
    }

    /**
     * Chiffres pré-remplis du rapport OPK : la liste de ses A-OPK, avec leur
     * présence reprise de la feuille du jour.
     */
    public function suiviAopkPour(Volontaire $operateur, string $date): Collection
    {
        $affectation = Affectation::query()
            ->where('volontaire_id', $operateur->id)
            ->couvrant($date)
            ->activeDabord()
            ->first();

        if (! $affectation) {
            return collect();
        }

        $site = $this->siteDuJour($operateur, $affectation, $date);

        if (! $site) {
            return collect();
        }

        // 1 KIT = 1 OPK + 1 ASSISTANT : l'assistant est rattaché à la localité
        // du site où le kit se trouve ce jour-là (cadrage, sections 3 et 7).
        $assistants = Volontaire::query()
            ->where('categorie', CategorieVolontaire::Assistant->value)
            ->where('localite_id', $site->localite_id)
            ->whereHas('affectations', fn ($q) => $q->couvrant($date)
                ->where('centre_id', $affectation->centre_id))
            ->get();

        $presences = $this->presencesDuJour($site->id, $date);

        return $assistants->map(fn (Volontaire $assistant) => [
            'volontaire_id' => $assistant->id,
            'categorie_agent' => 'aopk',
            'matricule' => $assistant->matricule,
            'nom_complet' => $assistant->user->nomComplet(),
            'ligne_presence_id' => $presences[$assistant->id]['id'] ?? null,
            'presence' => $presences[$assistant->id]['statut'] ?? null,
        ]);
    }

    /**
     * Chiffres pré-remplis du rapport du superviseur : la somme des rapports
     * OPK VISÉS de ses centres pour la journée.
     */
    public function evolutionPour(Volontaire $superviseur, string $date): array
    {
        $rapportsOpk = $this->rapportsVisesDuNiveauInferieur($superviseur, TypeRapport::Opk, $date);

        $enregistrees = 0;
        $nonValides = 0;

        foreach ($rapportsOpk as $rapport) {
            $enregistrees += (int) ($rapport->productionOpk?->enregistrements_realises ?? 0);
            $nonValides += (int) ($rapport->productionOpk?->enregistrements_non_valides ?? 0);
        }

        return [
            'personnes_enregistrees' => $enregistrees,
            'dossiers_valides' => max(0, $enregistrees - $nonValides),
            'dossiers_a_reprendre' => $nonValides,
            'nombre_rapports_sources' => $rapportsOpk->count(),
        ];
    }

    /**
     * Les rapports du niveau inférieur, VISÉS, qui alimentent celui-ci.
     *
     * @return Collection<int, RapportJournalier>
     */
    public function rapportsVisesDuNiveauInferieur(
        Volontaire $auteur,
        TypeRapport $typeInferieur,
        string $date
    ): Collection {
        $centres = match ($auteur->categorie) {
            CategorieVolontaire::Superviseur => $auteur->user->idsCentresAccessibles(),
            default => Affectation::query()
                ->where('volontaire_id', $auteur->id)
                ->couvrant($date)
                ->pluck('centre_id')
                ->filter()
                ->all(),
        };

        if ($centres === []) {
            return collect();
        }

        return RapportJournalier::query()
            ->with(['productionOpk', 'activitesAopk', 'auteur.user'])
            ->duType($typeInferieur)
            ->duJour($date)
            ->whereIn('centre_id', $centres)
            ->whereIn('statut', [StatutRapport::Vise->value, StatutRapport::Clos->value])
            ->get();
    }

    /**
     * Présences du jour sur un site, indexées par volontaire.
     * Elles viennent de la feuille de présence VALIDÉE : seule pièce qui fait foi.
     */
    private function presencesDuJour(int $siteId, string $date): array
    {
        $feuille = FeuillePresence::query()
            ->with('lignes')
            ->where('site_id', $siteId)
            ->whereDate('date_presence', $date)
            ->first();

        if (! $feuille) {
            return [];
        }

        return $feuille->lignes
            ->mapWithKeys(fn ($ligne) => [
                $ligne->volontaire_id => [
                    'id' => $ligne->id,
                    'statut' => $ligne->statut?->value,
                ],
            ])
            ->all();
    }

    /** Le site où l'agent travaille ce jour-là. */
    private function siteDuJour(Volontaire $auteur, Affectation $affectation, string $date)
    {
        if ($auteur->categorie === CategorieVolontaire::Assistant) {
            $site = Site::query()
                ->where('localite_id', $auteur->localite_id)
                ->when($affectation->centre_id, fn ($q) => $q->where('centre_id', $affectation->centre_id))
                ->first();

            if ($site) {
                $site->tournee_courante_id = TourneeSite::query()
                    ->where('site_id', $site->id)
                    ->couvrant($date)
                    ->value('id');
            }

            return $site;
        }

        $tournee = TourneeSite::query()
            ->with('site')
            ->where('affectation_operateur_id', $affectation->id)
            ->couvrant($date)
            ->first();

        if ($tournee?->site) {
            $tournee->site->tournee_courante_id = $tournee->id;

            return $tournee->site;
        }

        return null;
    }

    private function operateurDuSite(Affectation $affectationAssistant, string $date): ?Volontaire
    {
        $site = Site::query()
            ->where('localite_id', $affectationAssistant->localite_id)
            ->where('centre_id', $affectationAssistant->centre_id)
            ->first();

        if (! $site) {
            return null;
        }

        $tournee = TourneeSite::query()
            ->with('affectationOperateur.volontaire')
            ->where('site_id', $site->id)
            ->couvrant($date)
            ->first();

        return $tournee?->affectationOperateur?->volontaire;
    }

    private function superviseurDuCentre(Affectation $affectationOperateur): ?Volontaire
    {
        if (! $affectationOperateur->centre_id) {
            return null;
        }

        $unite = UniteSupervision::query()
            ->with('superviseur')
            ->where('vague_id', $affectationOperateur->vague_id)
            ->where(fn ($q) => $q->where('centre_principal_id', $affectationOperateur->centre_id)
                ->orWhere('centre_secondaire_id', $affectationOperateur->centre_id))
            ->first();

        return $unite?->superviseur;
    }
}
