<?php

namespace App\Services\Rapports;

use App\Enums\TypeRapport;
use App\Models\RapportJournalier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saisie du contenu d'un rapport journalier.
 *
 * TROIS RÈGLES DU CADRAGE (section 9) que ce service fait respecter :
 *
 *  1. LES COLONNES CALCULÉES NE SONT JAMAIS SAISIES. L'écart, le taux de
 *     réalisation et le taux de conformité sont des colonnes générées en base :
 *     elles ne figurent dans aucun `fillable`, et ce service ne les écrit
 *     jamais. Un client qui les enverrait verrait ses valeurs ignorées.
 *
 *  2. UN MOTIF EST EXIGÉ pour les enregistrements non validés : un chiffre de
 *     rejet sans explication ne remonte pas la chaîne.
 *
 *  3. LES BLOCS LIBRES sont des listes, pas trois cases figées : difficultés et
 *     solutions, points à améliorer, ressources logistiques. Chaque envoi
 *     remplace la liste entière — c'est ce qu'attend un formulaire où l'on
 *     ajoute et retire des lignes.
 */
class ServiceSaisieRapport
{
    public function enregistrer(RapportJournalier $rapport, array $donnees, User $auteur): RapportJournalier
    {
        if (! $rapport->estModifiableParAuteur()) {
            throw new \DomainException(
                "Ce rapport est « {$rapport->statut->libelle()} » : il n'est plus modifiable."
            );
        }

        return DB::transaction(function () use ($rapport, $donnees, $auteur) {
            $this->enregistrerEnTete($rapport, $donnees);

            match ($rapport->type) {
                TypeRapport::Aopk => $this->enregistrerAopk($rapport, $donnees),
                TypeRapport::Opk => $this->enregistrerOpk($rapport, $donnees),
                TypeRapport::Superviseur => $this->enregistrerSuperviseur($rapport, $donnees),
            };

            $this->enregistrerBlocsLibres($rapport, $donnees);
            $this->enregistrerSuiviAgents($rapport, $donnees, $auteur);

            return $rapport->fresh([
                'activitesAopk', 'productionOpk', 'evolution', 'qualite', 'logistique',
                'difficultes', 'pointsAmelioration', 'suiviAgents.volontaire',
            ]);
        });
    }

    private function enregistrerEnTete(RapportJournalier $rapport, array $donnees): void
    {
        $rapport->update(array_filter([
            'heure_arrivee' => $donnees['heure_arrivee'] ?? null,
            'heure_depart' => $donnees['heure_depart'] ?? null,
            'carv_rattachement' => $donnees['carv_rattachement'] ?? null,
            'horodatage_telephone' => $donnees['horodatage_telephone'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /** 9.1 — Activités de l'A-OPK, en colonnes PRÉVU et RÉALISÉ. */
    private function enregistrerAopk(RapportJournalier $rapport, array $donnees): void
    {
        $activites = $donnees['activites'] ?? null;

        if ($activites === null) {
            return;
        }

        $rapport->activitesAopk()->updateOrCreate([], [
            'affluence_prevue' => $activites['affluence_prevue'] ?? null,
            'affluence_realisee' => $activites['affluence_realisee'] ?? null,
            'justificatifs_recus_prevu' => $activites['justificatifs_recus_prevu'] ?? null,
            'justificatifs_recus_realise' => $activites['justificatifs_recus_realise'] ?? null,
            'justificatifs_transmis_prevu' => $activites['justificatifs_transmis_prevu'] ?? null,
            'justificatifs_transmis_realise' => $activites['justificatifs_transmis_realise'] ?? null,
            'plaintes_enregistrees_prevu' => $activites['plaintes_enregistrees_prevu'] ?? null,
            'plaintes_enregistrees_realise' => $activites['plaintes_enregistrees_realise'] ?? null,
            'plaintes_reversees_prevu' => $activites['plaintes_reversees_prevu'] ?? null,
            'plaintes_reversees_realise' => $activites['plaintes_reversees_realise'] ?? null,
        ]);
    }

    /** 9.2 — Production de l'opérateur. L'objectif reste celui figé à l'ouverture. */
    private function enregistrerOpk(RapportJournalier $rapport, array $donnees): void
    {
        $production = $donnees['production'] ?? null;

        if ($production === null) {
            return;
        }

        $nonValides = (int) ($production['enregistrements_non_valides'] ?? 0);

        if ($nonValides > 0 && blank($production['motif_non_valides'] ?? null)) {
            throw new \DomainException(
                'Indiquez pourquoi ces enregistrements ne sont pas validés : '
                .'le chiffre seul ne suffit pas à la hiérarchie.'
            );
        }

        // Ni ecart_enregistrements ni taux_realisation : ce sont des colonnes
        // générées, la base les calcule seule.
        $rapport->productionOpk()->updateOrCreate([], [
            'enregistrements_realises' => $production['enregistrements_realises'] ?? 0,
            'recepisses_transmis' => $production['recepisses_transmis'] ?? 0,
            'enregistrements_non_valides' => $nonValides,
            'motif_non_valides' => $production['motif_non_valides'] ?? null,
            'etat_kit' => $production['etat_kit'] ?? 'fonctionnel',
        ]);
    }

    /** 9.3 — Évolution, qualité et logistique du centre. */
    private function enregistrerSuperviseur(RapportJournalier $rapport, array $donnees): void
    {
        if (isset($donnees['evolution'])) {
            $rapport->evolution()->updateOrCreate([], [
                'personnes_enregistrees' => $donnees['evolution']['personnes_enregistrees'] ?? 0,
                'dossiers_valides' => $donnees['evolution']['dossiers_valides'] ?? 0,
                'dossiers_a_reprendre' => $donnees['evolution']['dossiers_a_reprendre'] ?? 0,
            ]);
        }

        if (isset($donnees['qualite'])) {
            // taux_conformite absent : colonne générée.
            $rapport->qualite()->updateOrCreate([], [
                'dossiers_controles' => $donnees['qualite']['dossiers_controles'] ?? 0,
                'dossiers_conformes' => $donnees['qualite']['dossiers_conformes'] ?? 0,
                'dossiers_non_conformes' => $donnees['qualite']['dossiers_non_conformes'] ?? 0,
                'doublons_detectes' => $donnees['qualite']['doublons_detectes'] ?? 0,
                'erreurs_saisie' => $donnees['qualite']['erreurs_saisie'] ?? 0,
                'corrections_effectuees' => $donnees['qualite']['corrections_effectuees'] ?? 0,
                'incidents_majeurs' => $donnees['qualite']['incidents_majeurs'] ?? 0,
            ]);
        }

        if (isset($donnees['logistique'])) {
            $rapport->logistique()->delete();

            foreach ($donnees['logistique'] as $rang => $ligne) {
                $rapport->logistique()->create([
                    'ressource' => $ligne['ressource'],
                    'disponible' => $ligne['disponible'] ?? 0,
                    'fonctionnelle' => $ligne['fonctionnelle'] ?? 0,
                    'besoin' => $ligne['besoin'] ?? 0,
                    'observation' => $ligne['observation'] ?? null,
                    'ordre' => $rang + 1,
                ]);
            }
        }
    }

    /**
     * Difficultés et points à améliorer : « un nombre libre de lignes, pas
     * trois cases figées ». La liste envoyée remplace la précédente.
     */
    private function enregistrerBlocsLibres(RapportJournalier $rapport, array $donnees): void
    {
        if (isset($donnees['difficultes'])) {
            $rapport->difficultes()->delete();

            foreach ($donnees['difficultes'] as $rang => $ligne) {
                if (blank($ligne['difficulte'] ?? null)) {
                    continue;
                }

                $rapport->difficultes()->create([
                    'difficulte' => $ligne['difficulte'],
                    // Une difficulté sans solution reste recevable : c'est
                    // souvent l'aveu qu'il faut faire remonter.
                    'solution' => $ligne['solution'] ?? null,
                    'ordre' => $rang + 1,
                ]);
            }
        }

        if (isset($donnees['points_amelioration'])) {
            $rapport->pointsAmelioration()->delete();

            foreach ($donnees['points_amelioration'] as $rang => $point) {
                if (blank($point)) {
                    continue;
                }

                $rapport->pointsAmelioration()->create(['point' => $point, 'ordre' => $rang + 1]);
            }
        }
    }

    /**
     * Le suivi des agents — production, anomalies, observation.
     *
     * La PRÉSENCE n'est pas modifiable ici : elle vient de la feuille de
     * présence du jour, et la ressaisir ouvrirait la porte à deux vérités.
     */
    private function enregistrerSuiviAgents(RapportJournalier $rapport, array $donnees, User $auteur): void
    {
        if (! isset($donnees['suivi_agents'])) {
            return;
        }

        foreach ($donnees['suivi_agents'] as $suivi) {
            $ligne = $rapport->suiviAgents()
                ->where('volontaire_id', $suivi['volontaire_id'])
                ->first();

            if (! $ligne) {
                continue;
            }

            $ligne->update([
                'production' => $suivi['production'] ?? null,
                'anomalies' => $suivi['anomalies'] ?? null,
                'observation' => $suivi['observation'] ?? null,
                // Toute modification APRÈS visa est journalisée : une
                // appréciation peut décider du maintien d'une personne.
                ...($rapport->statut->alimenteLeNiveauSuperieur() ? [
                    'modifie_apres_visa_le' => now(),
                    'modifie_par' => $auteur->id,
                ] : []),
            ]);
        }
    }
}
