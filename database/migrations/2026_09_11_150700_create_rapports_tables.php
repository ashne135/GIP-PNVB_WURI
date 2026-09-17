<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaine de remontee des rapports.
 *
 *   OPERATEUR (rapport de SITE) > SUPERVISEUR (rapport de CENTRE, chiffres
 *   PRE-REMPLIS, jamais ressaisis) > CHEF D'ANTENNE > NATIONAL
 *
 * AUCUNE DONNEE D'IDENTITE DE CITOYEN n'est stockee. nb_personnes_enregistrees
 * est une donnee DECLAREE par l'operateur, rien de plus (cadrage, section 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapports_site', function (Blueprint $table) {
            $table->id();
            $table->char('uuid_client', 36)->unique();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->restrictOnDelete();
            $table->foreignId('tournee_site_id')->nullable()->constrained('tournees_site')->nullOnDelete();
            $table->date('date_rapport');
            $table->time('heure_debut')->nullable();
            $table->time('heure_fin')->nullable();
            $table->unsignedInteger('nb_personnes_enregistrees')->default(0)
                ->comment('Declaratif : aucune identite associee');
            $table->unsignedInteger('nb_dossiers_rejetes')->default(0);
            $table->enum('etat_kit', ['fonctionnel', 'panne_partielle', 'panne_totale'])
                ->default('fonctionnel');
            $table->text('difficultes')->nullable()->comment('Reellement facultatif');
            $table->text('observations')->nullable()->comment('Reellement facultatif');
            $table->foreignId('redige_par_volontaire_id')->constrained('volontaires')->restrictOnDelete();
            $table->enum('statut', ['brouillon', 'soumis', 'consolide'])->default('brouillon');
            $table->timestamp('horodatage_telephone')->nullable();
            $table->timestamp('soumis_le')->nullable();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'date_rapport']);
            $table->index(['centre_id', 'date_rapport']);
            $table->index(['vague_id', 'date_rapport']);
            $table->index('statut');
        });

        Schema::create('rapports_centre', function (Blueprint $table) {
            $table->id();
            $table->char('uuid_client', 36)->unique();
            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->restrictOnDelete();
            $table->date('date_rapport');
            $table->unsignedSmallInteger('nb_sites_couverts')->default(0)
                ->comment('Calcule depuis les tournees du jour');
            $table->unsignedInteger('total_enregistres')->default(0)
                ->comment('Somme des rapports_site du centre : PRE-REMPLI, jamais ressaisi');
            $table->unsignedInteger('total_rejetes')->default(0)->comment('PRE-REMPLI');
            $table->unsignedSmallInteger('effectif_present')->default(0)
                ->comment('Repris des lignes_presence validees : PRE-REMPLI');
            $table->unsignedSmallInteger('effectif_absent')->default(0)->comment('PRE-REMPLI');
            $table->text('incidents_du_jour')->nullable();
            $table->text('decisions_prises')->nullable();
            $table->text('besoins_remontes')->nullable();
            $table->text('commentaire')->nullable();
            $table->foreignId('superviseur_id')->constrained('volontaires')->restrictOnDelete();
            $table->enum('statut', ['brouillon', 'soumis', 'valide_region', 'rejete'])->default('brouillon');
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Chef d\'antenne regional');
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamp('soumis_le')->nullable();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['centre_id', 'date_rapport']);
            $table->index(['vague_id', 'date_rapport']);
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapports_centre');
        Schema::dropIfExists('rapports_site');
    }
};
