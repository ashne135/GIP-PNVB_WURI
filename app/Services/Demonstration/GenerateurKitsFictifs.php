<?php

namespace App\Services\Demonstration;

use Illuminate\Support\Facades\DB;

/**
 * Un kit par centre généré (cadrage, Partie D : « Composition des kits — un
 * contenu type, en attendant votre canevas de traçabilité »).
 *
 * Le kit n'est PAS rattaché au centre en base — il est rattaché à l'agent qui
 * le détiendra (cadrage, section 13) — mais à la création, avant toute
 * affectation, il n'a encore ni détenteur ni position : centre_courant_id et
 * site_courant_id restent nuls jusqu'à la première remise.
 */
class GenerateurKitsFictifs
{
    /** Contenu type, en attendant le canevas de traçabilité du client. */
    private const COMPOSITION_TYPE = [
        'tablette de saisie', 'scanner d\'empreintes', 'imprimante portable',
        'panneau solaire et batterie', 'câbles et chargeurs', 'housse de transport',
        'registre papier de secours',
    ];

    public function generer(): int
    {
        $centres = DB::table('centres')->where('est_fictif', true)->pluck('code', 'id');
        $compteur = 0;

        foreach ($centres->chunk(500) as $lot) {
            $lignes = [];

            foreach ($lot as $id => $code) {
                $lignes[] = [
                    'reference' => "KIT-{$code}",
                    'composition' => json_encode(self::COMPOSITION_TYPE, JSON_UNESCAPED_UNICODE),
                    'etat' => 'fonctionnel',
                    'est_permanent_zone_defis' => false,
                    'est_fictif' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $compteur++;
            }

            DB::table('kits')->insert($lignes);
        }

        return $compteur;
    }
}
