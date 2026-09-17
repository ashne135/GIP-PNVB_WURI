<?php

namespace App\Jobs;

use App\Models\Alerte;
use App\Models\EcartPresence;
use App\Models\FeuillePresence;
use App\Models\RelevePosition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * JOB QUOTIDIEN DE RAPPROCHEMENT (cadrage, section 8.4).
 *
 * « Un job quotidien compare la feuille de présence aux relevés. En cas
 * d'écart — agent déclaré présent sans aucun relevé dans la zone, ou relevé
 * dans la zone d'un agent déclaré absent — une ALERTE part vers le CHEF
 * D'ANTENNE RÉGIONAL. »
 *
 * CE QUI SORT DE CE JOB : un ÉCART, et un COMPTE de relevés. Jamais une
 * position, jamais une trajectoire. « Le chef d'antenne voit l'alerte et
 * l'écart constaté, jamais un historique de déplacements. »
 *
 * Le job ne travaille que sur les feuilles VALIDÉES : comparer un brouillon
 * n'aurait aucun sens, puisque seule la feuille validée fait foi.
 */
class RapprocherPresencesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly ?string $date = null)
    {
    }

    public function handle(): void
    {
        $date = $this->date ?? now()->subDay()->toDateString();

        FeuillePresence::query()
            ->whereIn('statut', ['validee', 'corrigee'])
            ->whereDate('date_presence', $date)
            ->with(['lignes', 'site:id,code,region_id,rayon_zone_metres'])
            ->chunkById(100, function ($feuilles) use ($date) {
                foreach ($feuilles as $feuille) {
                    $this->rapprocherUneFeuille($feuille, $date);
                }
            });
    }

    private function rapprocherUneFeuille(FeuillePresence $feuille, string $date): void
    {
        foreach ($feuille->lignes as $ligne) {
            // Le COMPTE de relevés dans la zone, et rien d'autre : c'est la
            // seule donnée qui franchira la frontière de ce job.
            $relevesDansZone = RelevePosition::query()
                ->where('volontaire_id', $ligne->volontaire_id)
                ->whereDate('horodatage', $date)
                ->where('site_id_attendu', $feuille->site_id)
                ->where('dans_zone', true)
                ->count();

            $declarePresent = $ligne->statut?->compteCommePresent() ?? false;

            $typeEcart = match (true) {
                $declarePresent && $relevesDansZone === 0 => 'present_sans_releve',
                ! $declarePresent && $relevesDansZone > 0 => 'releve_zone_declare_absent',
                default => null,
            };

            if ($typeEcart === null) {
                continue;
            }

            // Un écart déjà constaté ne se redouble pas à chaque exécution.
            $existant = EcartPresence::query()
                ->where('ligne_presence_id', $ligne->id)
                ->where('type_ecart', $typeEcart)
                ->exists();

            if ($existant) {
                continue;
            }

            DB::transaction(function () use ($feuille, $ligne, $typeEcart, $relevesDansZone, $date) {
                $ecart = EcartPresence::query()->create([
                    'feuille_presence_id' => $feuille->id,
                    'ligne_presence_id' => $ligne->id,
                    'volontaire_id' => $ligne->volontaire_id,
                    'region_id' => $feuille->site->region_id,
                    'date_constat' => $date,
                    'type_ecart' => $typeEcart,
                    'nb_releves_zone' => $relevesDansZone,
                    'statut' => 'ouvert',
                ]);

                $alerte = Alerte::query()->create([
                    'code' => app(\App\Services\Support\NumeroteurAlerte::class)->suivant(),
                    'type' => 'ecart_presence',
                    'titre' => 'Écart de présence constaté — site '.$feuille->site->code,
                    // Le message décrit l'ÉCART, pas le déplacement.
                    'message' => $ecart->libelleEcart()
                        ." le {$date} sur le site {$feuille->site->code}. "
                        .'Examinez la feuille de présence de ce jour avec le superviseur.',
                    'niveau' => 'important',
                    'emetteur_user_id' => null,
                    'portee' => 'regionale',
                    'region_id' => $feuille->site->region_id,
                    'ecart_presence_id' => $ecart->id,
                    'publiee_le' => now(),
                ]);

                $ecart->update(['alerte_id' => $alerte->id]);
            });
        }
    }
}
