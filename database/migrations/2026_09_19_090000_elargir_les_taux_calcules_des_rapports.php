<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UN TAUX QUI NE TIENT PAS DANS SA COLONNE FAIT PERDRE LE RAPPORT.
 *
 * taux_realisation = réalisés × 100 / objectif. La colonne était en
 * DECIMAL(6,2) : elle s'arrête à 9 999,99. Il suffit d'un objectif de 1 —
 * valeur parfaitement légitime, et celle des vagues d'essai — et de 200
 * enregistrements réalisés pour obtenir 20 000 %, que MariaDB refuse d'écrire.
 *
 * CE N'ÉTAIT PAS UNE SAISIE ABERRANTE : 200 enregistrements dans une journée,
 * c'est le travail normal d'un kit. C'est le RAPPORT ENTIER qui était rejeté à
 * la synchronisation, avec un message promettant qu'il passerait « à la
 * prochaine tentative » — ce qui ne pouvait pas arriver. Le téléphone
 * réessayait indéfiniment.
 *
 * Les deux taux passent en DECIMAL(12,2). Le taux reste JUSTE : on élargit la
 * colonne au lieu de plafonner la valeur, car un taux plafonné à 9 999,99 %
 * serait un chiffre faux dans un rapport signé.
 *
 * taux_conformite portait la même faille, latente : conformes × 100 / contrôlés
 * dépasse rarement 100 %, mais rien en base ne l'y oblige.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $colonnes = [
        [
            'rapport_opk_production',
            'taux_realisation',
            'if(coalesce(objectif_enregistrements, 0) = 0, null, round(enregistrements_realises * 100.0 / objectif_enregistrements, 2))',
        ],
        [
            'rapport_sup_qualite',
            'taux_conformite',
            'if(dossiers_controles = 0, null, round(dossiers_conformes * 100.0 / dossiers_controles, 2))',
        ],
    ];

    public function up(): void
    {
        $this->redefinir('DECIMAL(12,2)');
    }

    public function down(): void
    {
        $this->redefinir('DECIMAL(6,2)');
    }

    /**
     * Une colonne GÉNÉRÉE ne s'élargit pas par un simple change() : elle se
     * retire et se repose. Aucune donnée n'est perdue — sa valeur est
     * recalculée depuis les colonnes qui la nourrissent.
     */
    private function redefinir(string $type): void
    {
        foreach ($this->colonnes as [$table, $colonne, $formule]) {
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$colonne}`");
            DB::statement(
                "ALTER TABLE `{$table}` ADD COLUMN `{$colonne}` {$type} "
                ."GENERATED ALWAYS AS ({$formule}) STORED NULL"
            );
        }
    }
};
