<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadrage v2, section 9.2 : le rapport de l'operateur compare OBJECTIF et
 * REALISE, et l'ecart comme le taux sont CALCULES, jamais saisis.
 *
 * DECISION PRISE FAUTE DE REPONSE CLIENT (question 3 restee ouverte) :
 * l'objectif est fixe PAR KIT ET PAR JOUR, a la planification de la vague par
 * l'administrateur national. Le chef d'antenne regional peut l'ajuster ; toute
 * modification est tracee par le journal d'activite de la vague.
 *
 * Ce choix est le plus petit engagement possible : une seule valeur, portee par
 * la vague, qu'on pourra remplacer par une table d'objectifs par centre ou par
 * site si le client tranche autrement — sans toucher aux rapports deja saisis,
 * puisque chaque rapport fige son objectif au moment de sa creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vagues_deploiement', function (Blueprint $table) {
            $table->unsignedInteger('objectif_enregistrements_par_kit_jour')->nullable()
                ->after('contraintes_tirage')
                ->comment('Objectif journalier par kit. Nul = aucun objectif fixe, ecart non calcule.');
        });
    }

    public function down(): void
    {
        Schema::table('vagues_deploiement', function (Blueprint $table) {
            $table->dropColumn('objectif_enregistrements_par_kit_jour');
        });
    }
};
