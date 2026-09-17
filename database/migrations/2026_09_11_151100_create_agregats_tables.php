<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agregats pre-calcules.
 *
 * 12 294 sites et des rapports quotidiens interdisent toute agregation a la
 * volee. Le tableau de bord lit CES tables, JAMAIS la table de detail.
 *
 * agregats_jour_region.effectif_deploye est un PIC SIMULTANE, pas un cumul :
 * ce sont les memes equipes qui tournent d'une region a l'autre. Un indicateur
 * national ne somme jamais cette colonne entre regions (cadrage, section 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agregats_jour_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('centre_id')->constrained('centres')->cascadeOnDelete();
            $table->foreignId('vague_id')->nullable()->constrained('vagues_deploiement')->nullOnDelete();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->date('date_jour');
            $table->unsignedInteger('nb_enregistres')->default(0);
            $table->unsignedInteger('nb_rejetes')->default(0);
            $table->unsignedSmallInteger('effectif_attendu')->default(0);
            $table->unsignedSmallInteger('effectif_present')->default(0);
            $table->unsignedSmallInteger('effectif_absent')->default(0);
            $table->decimal('taux_presence', 5, 2)->default(0);
            $table->unsignedSmallInteger('nb_incidents')->default(0);
            $table->timestamp('recalcule_le')->nullable();

            $table->unique(['site_id', 'date_jour']);
            $table->index(['centre_id', 'date_jour']);
            $table->index(['region_id', 'date_jour']);
            $table->index('date_jour');
        });

        Schema::create('agregats_jour_centre', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained('centres')->cascadeOnDelete();
            $table->foreignId('vague_id')->nullable()->constrained('vagues_deploiement')->nullOnDelete();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->date('date_jour');
            $table->unsignedSmallInteger('nb_sites_actifs')->default(0);
            $table->unsignedSmallInteger('nb_kits_actifs')->default(0);
            $table->unsignedInteger('nb_enregistres')->default(0);
            $table->unsignedInteger('nb_rejetes')->default(0);
            $table->unsignedSmallInteger('effectif_attendu')->default(0);
            $table->unsignedSmallInteger('effectif_present')->default(0);
            $table->unsignedSmallInteger('effectif_absent')->default(0);
            $table->decimal('taux_presence', 5, 2)->default(0);
            $table->unsignedSmallInteger('nb_incidents_ouverts')->default(0);
            $table->timestamp('recalcule_le')->nullable();

            $table->unique(['centre_id', 'date_jour']);
            $table->index(['region_id', 'date_jour']);
        });

        Schema::create('agregats_jour_region', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->date('date_jour');
            $table->unsignedInteger('nb_enregistres')->default(0);
            $table->unsignedInteger('nb_rejetes')->default(0);
            $table->unsignedSmallInteger('nb_centres_ouverts')->default(0);
            $table->unsignedSmallInteger('nb_sites_couverts')->default(0);
            $table->unsignedSmallInteger('effectif_deploye')->default(0)
                ->comment('Effectif simultane du jour : un pic, jamais un cumul entre regions');
            $table->decimal('taux_presence', 5, 2)->default(0);
            $table->json('nb_incidents_ouverts_par_gravite')->nullable();
            $table->timestamp('recalcule_le')->nullable();

            $table->unique(['region_id', 'date_jour']);
            $table->index('date_jour');
        });

        Schema::create('agregats_mois_region', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->unsignedTinyInteger('mois');
            $table->unsignedInteger('nb_enregistres_mois')->default(0);
            $table->unsignedInteger('cumul_depuis_debut')->default(0);
            $table->decimal('taux_couverture', 5, 2)->default(0)
                ->comment('Cumul rapporte a la population regionale');
            $table->unsignedTinyInteger('nb_jours_actifs')->default(0);
            $table->timestamp('recalcule_le')->nullable();

            $table->unique(['region_id', 'annee', 'mois']);
        });

        Schema::create('agregats_couverture_localite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('localite_id')->unique()->constrained('localites')->cascadeOnDelete();
            $table->foreignId('commune_id')->constrained('communes')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->unsignedInteger('population_cible')->default(0)
                ->comment('Copiee de localites.population_totale');
            $table->unsignedInteger('cumul_enregistres')->default(0);
            $table->decimal('taux_couverture', 5, 2)->default(0);
            $table->unsignedSmallInteger('nb_sites')->default(0);
            $table->unsignedSmallInteger('nb_sites_couverts')->default(0);
            $table->date('derniere_activite_le')->nullable();
            $table->timestamp('recalcule_le')->nullable();

            $table->index(['region_id', 'taux_couverture']);
            $table->index('commune_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agregats_couverture_localite');
        Schema::dropIfExists('agregats_mois_region');
        Schema::dropIfExists('agregats_jour_region');
        Schema::dropIfExists('agregats_jour_centre');
        Schema::dropIfExists('agregats_jour_site');
    }
};
