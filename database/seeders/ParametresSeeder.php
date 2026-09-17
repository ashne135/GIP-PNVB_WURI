<?php

namespace Database\Seeders;

use App\Models\Parametre;
use Illuminate\Database\Seeder;

/**
 * Les seuils du dispositif.
 *
 * Le cadrage (section 15) interdit de les coder en dur : 966 kits, 2 centres par
 * superviseur, délai d'escalade, distance maximale entre deux centres. Ils vivent
 * ici, et le code les lit par Parametre::entier(), ::decimal(), ::tableau().
 *
 * Idempotent : une valeur déjà présente en base n'est PAS écrasée — sinon chaque
 * migrate:fresh --seed annulerait les réglages faits par la DSI.
 */
class ParametresSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->parametres() as $parametre) {
            Parametre::query()->firstOrCreate(
                ['cle' => $parametre['cle']],
                $parametre
            );
        }

        $this->command?->info('  Paramètres : '.Parametre::count());
    }

    private function parametres(): array
    {
        return [
            // ---------- Dispositif ----------
            $this->p('dispositif.nombre_kits_principaux', 966, 'entier', 'dispositif',
                'Nombre de kits principaux',
                'Un kit = 1 opérateur + 1 assistant. Unité de base du déploiement.'),
            $this->p('dispositif.kits_zones_defis', 32, 'entier', 'dispositif',
                'Kits des centres permanents en zone à défis sécuritaires',
                'Concernent 16 communes urbaines. Le mode de couverture — personnel '
                .'supplémentaire ou rotation — reste à trancher par le client.'),
            $this->p('dispositif.effectif_superviseurs_operationnels', 483, 'entier', 'dispositif',
                'Superviseurs opérationnels'),
            $this->p('dispositif.effectif_operateurs_operationnels', 966, 'entier', 'dispositif',
                'Opérateurs de kit opérationnels'),
            $this->p('dispositif.effectif_assistants_operationnels', 966, 'entier', 'dispositif',
                'Assistants opérationnels'),
            $this->p('dispositif.taux_reserve_pourcent', 15, 'entier', 'dispositif',
                'Taux de réserve',
                'Vivier mobilisable en cas de désistement, abandon, indisponibilité ou remplacement.'),

            // ---------- Affectation ----------
            $this->p('affectation.centres_par_superviseur', 2, 'entier', 'affectation',
                'Centres par superviseur'),
            $this->p('affectation.distance_max_centres_km', 25, 'decimal', 'affectation',
                'Distance maximale entre les 2 centres d\'un superviseur',
                'À vol d\'oiseau. La même commune reste préférée. Au-delà, le tirage marque '
                .'la contrainte comme non satisfaite et l\'affiche dans la proposition.'),
            $this->p('affectation.kits_par_centre_max', 2, 'entier', 'affectation',
                'Nombre maximal de kits par centre'),
            $this->p('affectation.duree_passage_site_jours', 3, 'entier', 'affectation',
                'Durée d\'un passage de kit sur un site',
                'En jours ouvrés. 12 294 sites pour 966 kits, soit environ 12,7 sites par kit.'),

            // ---------- Présence ----------
            $this->p('presence.rayon_zone_site_metres', 500, 'entier', 'presence',
                'Rayon de la zone d\'un site',
                'Décide du vert ou de l\'orange sur la carte du superviseur. En dessous de '
                .'500 m, un GPS d\'entrée de gamme produit de faux « hors zone ».'),
            $this->p('presence.frequence_releve_minutes', 30, 'entier', 'presence',
                'Fréquence des relevés de rapprochement',
                'Fréquence basse et volontairement limitée : ce n\'est pas un suivi continu.'),
            $this->p('presence.bloquer_signal_hors_zone', false, 'booleen', 'presence',
                'Refuser un signal envoyé hors de la zone du site',
                'Désactivé, l\'agent signale toujours et l\'écart est simplement constaté — c\'est le '
                .'cadrage : l\'agent SIGNALE, seul le superviseur valide. Activé, le serveur REFUSE '
                .'l\'arrivée et le départ envoyés au-delà du rayon du site. À n\'activer que lorsque les '
                .'sites auront des coordonnées : un site non localisé ne permet aucune mesure, et le '
                .'signal y reste accepté quoi qu\'il arrive.'),
            $this->p('presence.heure_debut_service', '07:00', 'chaine', 'presence',
                'Heure de début de service',
                'Aucun relevé de position n\'est collecté avant cette heure.'),
            $this->p('presence.heure_fin_service', '17:30', 'chaine', 'presence',
                'Heure de fin de service',
                'Aucun relevé de position n\'est collecté après cette heure, ni le week-end, '
                .'ni hors jour d\'affectation active.'),

            // ---------- Synchronisation hors ligne ----------
            $this->p('sync.max_elements_par_lot', 200, 'entier', 'sync',
                "Nombre maximal d'éléments par lot de synchronisation",
                'Un lot trop gros immobilise le serveur et expire sur un réseau lent. '
                .'Le mobile découpe sa file en lots de cette taille.'),

            // ---------- Rétention ----------
            $this->p('retention.releves_jours', 90, 'entier', 'retention',
                'Conservation des relevés de position',
                'Purge automatique au-delà. Aucune interface n\'affiche la trace des '
                .'déplacements d\'une personne : seul l\'écart constaté est exposé.'),
            $this->p('retention.journal_activite_jours', 730, 'entier', 'retention',
                'Conservation du journal d\'activité'),

            // ---------- Incidents ----------
            $this->p('incidents.delai_escalade_minutes.niveau_1', 480, 'entier', 'incidents',
                'Délai d\'escalade — niveau 1 mineur'),
            $this->p('incidents.delai_escalade_minutes.niveau_2', 240, 'entier', 'incidents',
                'Délai d\'escalade — niveau 2 modéré'),
            $this->p('incidents.delai_escalade_minutes.niveau_3', 120, 'entier', 'incidents',
                'Délai d\'escalade — niveau 3 majeur',
                'Valeur de référence du cadrage : 2 heures.'),
            $this->p('incidents.delai_escalade_minutes.niveau_4', 30, 'entier', 'incidents',
                'Délai d\'escalade — niveau 4 critique',
                'Un danger grave ou immédiat ne peut pas attendre 2 heures avant escalade.'),

            $this->p('incidents.gravite_minimale_sms', 3, 'entier', 'incidents',
                'Gravité à partir de laquelle un SMS est envoyé',
                'En dessous, l\'alerte dans l\'application suffit. Un SMS pour chaque incident '
                .'mineur coûterait cher et apprendrait aux responsables à ne plus les lire.'),

            $this->p('incidents.notification.niveau_1', ['volontaire_superviseur'], 'json', 'incidents',
                'Matrice de notification — niveau 1'),
            $this->p('incidents.notification.niveau_2',
                ['volontaire_superviseur', 'chef_antenne_regional'], 'json', 'incidents',
                'Matrice de notification — niveau 2'),
            $this->p('incidents.notification.niveau_3',
                ['volontaire_superviseur', 'chef_antenne_regional', 'administrateur_national'],
                'json', 'incidents', 'Matrice de notification — niveau 3'),
            $this->p('incidents.notification.niveau_4',
                ['volontaire_superviseur', 'chef_antenne_regional', 'administrateur_national',
                    'super_administrateur'],
                'json', 'incidents', 'Matrice de notification — niveau 4'),

            // ---------- Kits ----------
            $this->p('kits.delai_alerte_non_restitue_jours', 3, 'entier', 'kits',
                'Délai avant alerte de kit non restitué',
                'Compté depuis la clôture de la vague.'),
            $this->p('kits.delai_photos_constat_heures', 24, 'entier', 'kits',
                'Délai d\'arrivée des photos de constat',
                'Quand le téléphone annonce les photos d\'une remise, d\'un transfert ou d\'une restitution, '
                .'elles partent après les données, dès que le réseau le permet. L\'alerte « photos manquantes » '
                .'n\'est levée que si elles ne sont pas arrivées dans ce délai.'),

            // ---------- Comptes ----------
            $this->p('comptes.longueur_mot_de_passe_initial', 10, 'entier', 'comptes',
                'Longueur du mot de passe initial'),
            $this->p('comptes.charte_version_courante', '2026.1', 'chaine', 'comptes',
                'Version courante de la charte du volontaire',
                'Un changement de version redemande l\'acceptation à la connexion suivante.'),
            $this->p('comptes.canal_secours_sms', true, 'booleen', 'comptes',
                'Activer le SMS comme canal de secours',
                'Cascade : courriel, puis SMS, puis bordereau remis en formation.'),
            $this->p('comptes.delai_rattrapage_jours', 7, 'entier', 'comptes',
                'Délai de rattrapage après la fin d\'une mission',
                'Pendant ce délai, le téléphone peut encore envoyer le travail fait pendant la mission : '
                .'un agent resté sans réseau le dernier jour ne perd pas sa journée. Le jeton d\'un accès '
                .'fermé ne sert plus qu\'à cet envoi, et expire au terme du délai.'),

            // ---------- Exports planifiés ----------
            $this->p('exports.actif', true, 'booleen', 'exports',
                'Produire les exports planifiés chaque nuit',
                'Désactivé, plus aucun fichier n\'est produit : les exports restent disponibles '
                .'à la demande depuis les écrans concernés.'),
            $this->p('exports.heure_generation', '05:00', 'chaine', 'exports',
                'Heure de production des exports',
                'Après le recalcul des agrégats (01h00) et le rapprochement des présences (02h30) : '
                .'un export produit avant eux porterait des chiffres incomplets.'),
            $this->p('exports.retention_jours', 30, 'entier', 'exports',
                'Conservation des fichiers d\'export',
                'Au-delà, le fichier est effacé du disque et sa ligne marquée. Les données, elles, '
                .'restent en base : un export est une photographie, pas une archive.'),

            // ---------- Cartographie ----------
            $this->p('carte.seuil_clustering', 200, 'entier', 'carte',
                'Seuil de regroupement des marqueurs',
                'Au-delà, les sites sont regroupés en amas : 12 294 marqueurs rendraient '
                .'la page inutilisable.'),
        ];
    }

    private function p(
        string $cle,
        mixed $valeur,
        string $type,
        string $groupe,
        string $libelle,
        ?string $description = null
    ): array {
        return [
            'cle' => $cle,
            'valeur' => is_array($valeur) ? json_encode($valeur, JSON_UNESCAPED_UNICODE) : (string) $valeur,
            'type_valeur' => $type,
            'groupe' => $groupe,
            'libelle' => $libelle,
            'description' => $description,
            'modifiable_par' => 'super_administrateur',
        ];
    }
}
