<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vagues de deploiement et unites de supervision.
 *
 * Une vague est une region, une periode, une equipe et une liste de centres
 * ouverts. Le tirage au sort des affectations utilise une graine ENREGISTREE :
 * on doit pouvoir rejouer et expliquer une affectation (cadrage, section 7).
 *
 * L'unite de supervision (1 superviseur, 2 centres) passe par une table et non
 * par une cle sur le centre : sinon l'historique par vague devient impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vagues_deploiement', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Exemple : BAN-2026-V1');
            $table->string('libelle', 160);
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->date('date_debut_prevue');
            $table->date('date_fin_prevue');
            $table->timestamp('date_ouverture_reelle')->nullable();
            $table->timestamp('date_cloture_reelle')->nullable();
            $table->enum('statut', [
                'brouillon', 'proposee', 'validee', 'active', 'cloturee', 'annulee',
            ])->default('brouillon');
            $table->unsignedBigInteger('graine_tirage')->nullable()
                ->comment('Seed enregistree : le tirage doit pouvoir etre rejoue et explique');
            $table->json('contraintes_tirage')->nullable()
                ->comment('Photo des parametres au moment du tirage');
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_le')->nullable()
                ->comment('Rien n\'est ecrit ni notifie avant cette date');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index(['region_id', 'statut']);
            $table->index('statut');
            $table->index('date_debut_prevue');
        });

        Schema::create('vague_centres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->cascadeOnDelete();
            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete();
            $table->date('date_ouverture')->nullable();
            $table->date('date_fermeture')->nullable();
            $table->enum('statut', ['planifie', 'ouvert', 'ferme'])->default('planifie');
            $table->timestamps();

            $table->unique(['vague_id', 'centre_id']);
            $table->index('centre_id');
        });

        Schema::create('unites_supervision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->cascadeOnDelete();
            $table->foreignId('volontaire_superviseur_id')->constrained('volontaires')->restrictOnDelete();
            $table->foreignId('centre_principal_id')->constrained('centres')->restrictOnDelete();
            $table->foreignId('centre_secondaire_id')->nullable()->constrained('centres')->restrictOnDelete()
                ->comment('Peut rester nul en fin de tirage, avec alerte');
            $table->decimal('distance_km', 6, 2)->nullable()->comment('Distance entre les deux centres');
            $table->boolean('meme_commune')->default(false)->comment('Critere preferentiel du tirage');
            $table->boolean('contrainte_respectee')->default(true)
                ->comment('Faux : alerte affichee dans la proposition avant validation');
            $table->timestamps();

            $table->unique(['vague_id', 'centre_principal_id']);
            $table->unique(['vague_id', 'centre_secondaire_id']);
            $table->index('volontaire_superviseur_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unites_supervision');
        Schema::dropIfExists('vague_centres');
        Schema::dropIfExists('vagues_deploiement');
    }
};
