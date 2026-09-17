<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ecarts de presence issus du rapprochement automatique (cadrage, section 8.4).
 *
 * C'est le SEUL objet expose, et au seul CHEF D'ANTENNE REGIONAL. Le chef
 * d'antenne voit l'ecart constate, jamais l'historique des deplacements de la
 * personne : la table ne porte donc qu'un COMPTE de releves, aucune position.
 *
 * Cette migration referme aussi les deux cles etrangeres laissees ouvertes sur
 * alertes, maintenant que incidents et ecarts_presence existent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecarts_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feuille_presence_id')->constrained('feuilles_presence')->cascadeOnDelete();
            $table->foreignId('ligne_presence_id')->nullable()->constrained('lignes_presence')->nullOnDelete();
            $table->foreignId('volontaire_id')->constrained('volontaires')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete()
                ->comment('Perimetre du chef d\'antenne destinataire');
            $table->date('date_constat');
            $table->enum('type_ecart', ['present_sans_releve', 'releve_zone_declare_absent']);
            $table->unsignedSmallInteger('nb_releves_zone')->default(0)
                ->comment('Le compte, jamais les positions');
            $table->foreignId('alerte_id')->nullable()->constrained('alertes')->nullOnDelete();
            $table->enum('statut', ['ouvert', 'examine', 'clos'])->default('ouvert');
            $table->foreignId('examine_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('examine_le')->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->index(['date_constat', 'statut']);
            $table->index(['region_id', 'statut']);
            $table->index('volontaire_id');
        });

        Schema::table('alertes', function (Blueprint $table) {
            $table->foreign('incident_id')->references('id')->on('incidents')->nullOnDelete();
            $table->foreign('ecart_presence_id')->references('id')->on('ecarts_presence')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alertes', function (Blueprint $table) {
            $table->dropForeign(['ecart_presence_id']);
            $table->dropForeign(['incident_id']);
        });

        Schema::dropIfExists('ecarts_presence');
    }
};
