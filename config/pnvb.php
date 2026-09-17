<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fichier source du référentiel territorial
    |--------------------------------------------------------------------------
    | Classeur hiérarchique fourni par le projet : 12 régions, 34 provinces,
    | 355 communes, 7 453 localités avec leur population.
    */
    'fichier_referentiel' => env('PNVB_FICHIER_REFERENTIEL'),

    /*
    |--------------------------------------------------------------------------
    | Canal SMS
    |--------------------------------------------------------------------------
    | Deuxième maillon de la cascade de remise des identifiants. « log » écrit
    | le message dans les journaux au lieu de l'envoyer : c'est le mode de
    | développement.
    */
    'sms' => [
        'pilote' => env('PNVB_SMS_PILOTE', 'log'),
        'expediteur' => env('PNVB_SMS_EXPEDITEUR', 'GIP-PNVB'),

        // Renseignés quand l'opérateur SMS aura été retenu. Tant qu'ils sont
        // vides, le pilote « http » refuse d'envoyer plutôt que d'échouer en
        // silence.
        'url' => env('PNVB_SMS_URL'),
        'jeton' => env('PNVB_SMS_JETON'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Données de démonstration
    |--------------------------------------------------------------------------
    | Tout enregistrement produit par le générateur porte est_fictif = true et
    | peut être purgé au chargement du réel.
    */
    'demonstration' => [
        'region_vague' => env('PNVB_REGION_DEMO', 'BAN'),
        'graine' => (int) env('PNVB_GRAINE_DEMO', 20260911),
    ],

];
