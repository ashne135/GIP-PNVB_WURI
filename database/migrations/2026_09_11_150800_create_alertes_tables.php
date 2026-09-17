<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alertes descendantes et alertes emises par l'acteur SYSTEME.
 *
 * Les cles etrangeres incident_id et ecart_presence_id sont ajoutees plus tard
 * (migration 2026_09_11_151000) : ces deux tables n'existent pas encore, et les
 * declarer ici creerait une dependance circulaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Exemple : ALR-2026-000412');
            $table->enum('type', [
                'descendante', 'systeme', 'ecart_presence', 'kit_non_restitue',
                'escalade_incident', 'absence',
            ]);
            $table->string('titre', 160);
            $table->text('message');
            $table->enum('niveau', ['info', 'important', 'critique'])->default('info');
            $table->foreignId('emetteur_user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Nul : alerte emise par l\'acteur SYSTEME');
            $table->enum('portee', ['nationale', 'regionale', 'centre', 'volontaire', 'role'])
                ->default('nationale');
            $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnDelete();
            $table->foreignId('centre_id')->nullable()->constrained('centres')->cascadeOnDelete();
            $table->foreignId('volontaire_id')->nullable()->constrained('volontaires')->cascadeOnDelete();
            $table->string('role_cible', 60)->nullable();

            // Objet d'origine, pour rebondir depuis l'alerte.
            $table->foreignId('kit_id')->nullable()->constrained('kits')->nullOnDelete();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->unsignedBigInteger('ecart_presence_id')->nullable();

            $table->timestamp('publiee_le');
            $table->timestamp('expire_le')->nullable();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index(['portee', 'region_id']);
            $table->index(['niveau', 'publiee_le']);
            $table->index('type');
        });

        Schema::create('alerte_lectures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alerte_id')->constrained('alertes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('lu_le');

            $table->unique(['alerte_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerte_lectures');
        Schema::dropIfExists('alertes');
    }
};
