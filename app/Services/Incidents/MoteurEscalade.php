<?php

namespace App\Services\Incidents;

use App\Models\Incident;
use App\Models\Parametre;
use Illuminate\Support\Facades\DB;

/**
 * L'ESCALADE AUTOMATIQUE : « un incident non pris en charge dans le délai
 * remonte au niveau supérieur » (cadrage, section 10).
 *
 * Quatre décisions de conception, et leur raison :
 *
 *  1. C'EST L'ABSENCE DE PRISE EN CHARGE QUI DÉCLENCHE, pas l'absence de
 *     résolution. Un incident difficile peut rester ouvert des jours sans que
 *     ce soit une défaillance ; un incident que personne n'a seulement ouvert
 *     au bout de deux heures, si.
 *
 *  2. L'ESCALADE NE TOUCHE JAMAIS LA GRAVITÉ. Le niveau de gravité est le
 *     jugement du déclarant sur ce qu'il a vu ; le remonter d'office
 *     falsifierait sa déclaration. Ce qui monte, c'est le niveau de
 *     NOTIFICATION — donc la liste des personnes prévenues.
 *
 *  3. L'ESCALADE S'ARRÊTE AU SOMMET. Passé le dernier cran, l'échéance est
 *     effacée : continuer à réalerter les mêmes personnes toutes les deux
 *     heures les entraînerait à ignorer le canal entier.
 *
 *  4. LE MOTEUR EST REJOUABLE SANS DOMMAGE. Il ne traite que les incidents dont
 *     l'échéance est passée, et repousse l'échéance dans la même transaction :
 *     deux exécutions concurrentes n'escaladent pas deux fois.
 */
class MoteurEscalade
{
    /** Au-delà, on cesse de remonter : il n'y a plus personne au-dessus. */
    private const CRANS_MAXIMUM = 3;

    public function __construct(private readonly ServiceNotificationIncident $notification) {}

    /**
     * @return array{examines: int, escalades: int, au_sommet: int, numeros: array<int, string>}
     */
    public function executer(): array
    {
        $aTraiter = Incident::query()
            ->aEscalader()
            ->with(['site:id,nom', 'centre:id,nom', 'region:id,nom', 'declarant:id,nom,prenoms'])
            ->orderBy('echeance_escalade')
            ->get();

        $escalades = 0;
        $auSommet = 0;
        $numeros = [];

        foreach ($aTraiter as $incident) {
            if ($incident->niveau_escalade >= self::CRANS_MAXIMUM) {
                // Plus personne au-dessus : on arrête d'alerter, mais l'incident
                // reste ouvert et visible dans les listes de retard.
                $incident->update(['echeance_escalade' => null]);
                $auSommet++;

                continue;
            }

            $this->escalader($incident);
            $escalades++;
            $numeros[] = $incident->numero;
        }

        return [
            'examines' => $aTraiter->count(),
            'escalades' => $escalades,
            'au_sommet' => $auSommet,
            'numeros' => $numeros,
        ];
    }

    /** Remonte un incident d'un cran, et prévient le niveau ainsi atteint. */
    public function escalader(Incident $incident): Incident
    {
        DB::transaction(function () use ($incident) {
            $incident->update([
                'niveau_escalade' => (int) $incident->niveau_escalade + 1,
                'derniere_escalade_le' => now(),
                // La nouvelle échéance part de MAINTENANT : le prochain cran se
                // déclenchera un délai plus tard, pas immédiatement.
                'echeance_escalade' => now()->addMinutes($this->delaiRelance($incident)),
            ]);
        });

        $this->notification->notifier($incident->refresh(), 'escalade');

        return $incident;
    }

    /**
     * Le délai avant le cran suivant. Par défaut celui de la gravité : un
     * incident critique qui n'a toujours personne au bout de 30 minutes doit
     * remonter au même rythme, pas plus lentement.
     */
    private function delaiRelance(Incident $incident): int
    {
        return Parametre::entier(
            $incident->gravite->cleDelaiEscalade(),
            match ($incident->gravite->value) {
                1 => 480, 2 => 240, 3 => 120, default => 30,
            }
        );
    }
}
