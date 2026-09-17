<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Format de réponse constant de toute l'API (cadrage, section 14) :
 *
 *     { "success": bool, "message": string, "data": object|array }
 *
 * Le champ « message » est TOUJOURS en français simple, compréhensible par un
 * agent de terrain. Jamais « Error 422 Unprocessable Entity ».
 *
 * Cette classe est le seul endroit où ce format est construit : toute réponse
 * de l'API passe par elle, y compris les erreurs remontées par le gestionnaire
 * d'exceptions.
 */
class ReponseApi
{
    public static function succes(string $message, mixed $donnees = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $donnees,
        ], $code);
    }

    public static function echec(string $message, mixed $donnees = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $donnees,
        ], $code);
    }

    /**
     * Erreurs de validation : le message reste une phrase lisible, le détail
     * champ par champ va dans data.erreurs pour que le formulaire puisse
     * surligner les bons champs.
     */
    public static function validation(string $message, array $erreurs): JsonResponse
    {
        return self::echec($message, ['erreurs' => $erreurs], 422);
    }
}
