<?php

namespace App\Services\Comptes;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deuxième maillon de la cascade : le SMS.
 *
 * Le cadrage ne désigne aucun opérateur ni aucune passerelle : le pilote est
 * donc choisi par configuration (pnvb.sms.pilote). En développement, « log »
 * écrit le message dans les journaux au lieu de l'envoyer — on voit exactement
 * ce qui partirait, sans consommer de crédit SMS ni déranger un volontaire.
 *
 * Le pilote « http » est un squelette générique : l'URL, la méthode et le
 * format du corps viendront du contrat passé avec l'opérateur retenu.
 */
class PasserelleSms
{
    public function envoyer(string $numero, string $message): bool
    {
        return match (config('pnvb.sms.pilote', 'log')) {
            'http' => $this->envoyerParHttp($numero, $message),
            'log' => $this->ecrireDansLesJournaux($numero, $message),
            default => $this->ecrireDansLesJournaux($numero, $message),
        };
    }

    private function ecrireDansLesJournaux(string $numero, string $message): bool
    {
        Log::channel('pnvb_comptes')->info('sms_simule', [
            'destinataire' => $numero,
            'expediteur' => config('pnvb.sms.expediteur'),
            'message' => $message,
        ]);

        return true;
    }

    private function envoyerParHttp(string $numero, string $message): bool
    {
        $url = config('pnvb.sms.url');

        if (! $url) {
            Log::channel('pnvb_comptes')->warning('sms_non_configure', ['destinataire' => $numero]);

            return false;
        }

        $reponse = Http::timeout(15)
            ->withToken((string) config('pnvb.sms.jeton'))
            ->post($url, [
                'expediteur' => config('pnvb.sms.expediteur'),
                'destinataire' => $numero,
                'message' => $message,
            ]);

        if ($reponse->failed()) {
            Log::channel('pnvb_comptes')->error('sms_echec', [
                'destinataire' => $numero,
                'statut' => $reponse->status(),
            ]);
        }

        return $reponse->successful();
    }
}
