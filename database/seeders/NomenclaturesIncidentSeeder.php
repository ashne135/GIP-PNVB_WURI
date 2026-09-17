<?php

namespace Database\Seeders;

use App\Models\DestinataireIncident;
use App\Models\ImpactIncident;
use App\Models\MesureIncident;
use App\Models\NatureIncident;
use Illuminate\Database\Seeder;

/**
 * Les listes à cases multiples du canevas d'incident fourni par le client :
 * 17 natures (C), 12 impacts (E), 8 mesures immédiates (H), 8 destinataires (I).
 *
 * Reprises à l'identique, dans l'ordre du canevas. Aucun élément ajouté, aucun
 * retiré. Les colonnes de traduction restent vides : le mooré et le dioula
 * viendront après le français.
 *
 * Idempotent.
 */
class NomenclaturesIncidentSeeder extends Seeder
{
    public function run(): void
    {
        $this->charger(NatureIncident::class, [
            'securite_personnes' => 'Sécurité des personnes',
            'accident_blessure' => 'Accident ou blessure',
            'menace_agression' => 'Menace ou agression',
            'mobilite_deplacement' => 'Mobilité et déplacement',
            'transport' => 'Transport',
            'absence_abandon_poste' => 'Absence ou abandon de poste',
            'conflit_tension_site' => 'Conflit ou tension sur le site',
            'incident_population' => 'Incident avec la population',
            'incident_coordination' => 'Incident avec la coordination',
            'equipement_materiel' => 'Équipement et matériel',
            'panne_informatique' => 'Panne informatique',
            'probleme_connexion' => 'Problème de connexion',
            'pointage' => 'Pointage',
            'geolocalisation' => 'Géolocalisation',
            'environnement_climat' => 'Incident environnemental ou climatique',
            'catastrophe_sinistre' => 'Catastrophe ou sinistre',
            'autre' => 'Autre',
        ]);

        $this->charger(ImpactIncident::class, [
            'aucun_impact' => 'Aucun impact',
            'blessure' => 'Blessure',
            'risque_vital' => 'Risque vital',
            'interruption_travail' => 'Interruption du travail',
            'interruption_deploiement' => 'Interruption du déploiement',
            'retard_operationnel' => 'Retard opérationnel',
            'perte_deterioration_materiel' => 'Perte ou détérioration de matériel',
            'incident_donnees' => 'Incident de données',
            'atteinte_image' => 'Atteinte à l\'image',
            'conflit_population' => 'Conflit avec la population',
            'impact_financier' => 'Impact financier',
            'autre' => 'Autre',
        ]);

        $this->charger(MesureIncident::class, [
            'mise_en_securite' => 'Mise en sécurité',
            'arret_temporaire' => 'Arrêt temporaire',
            'evacuation' => 'Évacuation',
            'information_responsable' => 'Information du responsable',
            'appel_services_competents' => 'Appel aux services compétents',
            'assistance_medicale' => 'Assistance médicale',
            'assistance_technique' => 'Assistance technique',
            'autre' => 'Autre',
        ]);

        $this->charger(DestinataireIncident::class, [
            'superviseur' => 'Superviseur',
            'coordination_regionale' => 'Coordination régionale',
            'direction_generale_gip_pnvb' => 'Direction générale GIP-PNVB',
            'responsable_wuri' => 'Responsable WURI',
            'service_securite' => 'Service de sécurité',
            'service_sante' => 'Service de santé',
            'autorite_administrative' => 'Autorité administrative',
            'autre' => 'Autre',
        ]);

        $this->command?->info(sprintf(
            '  Nomenclatures incident : %d natures, %d impacts, %d mesures, %d destinataires',
            NatureIncident::count(),
            ImpactIncident::count(),
            MesureIncident::count(),
            DestinataireIncident::count()
        ));
    }

    /** @param  class-string  $modele */
    private function charger(string $modele, array $valeurs): void
    {
        $ordre = 0;

        foreach ($valeurs as $code => $libelle) {
            $modele::query()->updateOrCreate(
                ['code' => $code],
                ['libelle' => $libelle, 'ordre' => ++$ordre, 'actif' => true]
            );
        }
    }
}
