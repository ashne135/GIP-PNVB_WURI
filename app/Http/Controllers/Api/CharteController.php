<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Services\Comptes\ServiceCharte;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * GET /charte — le texte de la version courante, le même pour le web et le mobile.
 *
 * Accessible avant l'acceptation, évidemment : c'est ce texte qu'on accepte.
 */
class CharteController extends Controller
{
    public function courante(ServiceCharte $service): JsonResponse
    {
        $version = $service->versionCourante();

        try {
            return ReponseApi::succes('Charte du volontaire.', $service->texte($version));
        } catch (\RuntimeException|\JsonException $e) {
            Log::error('Texte de charte indisponible', ['version' => $version, 'erreur' => $e->getMessage()]);

            return ReponseApi::echec(
                "Le texte de la charte n'est pas disponible pour le moment. Prévenez votre superviseur.",
                ['version' => $version],
                503
            );
        }
    }
}
