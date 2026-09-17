<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mouvements de kits et remplacements par la reserve.
 *
 * Un kit non restitue ou non transfere a la cloture d'une vague declenche une
 * alerte automatique vers l'administrateur national (cadrage, section 13).
 *
 * Lors d'un remplacement : le reserviste prend l'affectation, l'agent remplace
 * bascule en reserve AVEC UN MOTIF OBLIGATOIRE, et le kit est transfere avec
 * etat constate et photo des deux parties (cadrage, section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_mouvements', function (Blueprint $table) {
            $table->id();
            $table->char('uuid_client', 36)->nullable()->unique()
                ->comment('Idempotence de la synchronisation hors ligne');
            $table->foreignId('kit_id')->constrained('kits')->restrictOnDelete();
            $table->enum('type', [
                'remise', 'changement_site', 'transfert', 'restitution', 'panne', 'perte_vol',
            ]);
            $table->foreignId('volontaire_source_id')->nullable()->constrained('volontaires')->nullOnDelete();
            $table->foreignId('volontaire_destination_id')->nullable()->constrained('volontaires')->nullOnDelete();
            $table->foreignId('vague_id')->nullable()->constrained('vagues_deploiement')->nullOnDelete();
            $table->foreignId('centre_id')->nullable()->constrained('centres')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->enum('etat_constate', ['bon', 'usage', 'endommage', 'incomplet'])->nullable();
            $table->text('commentaire')->nullable();
            $table->string('photo_source_chemin', 255)->nullable()
                ->comment('Photo de la partie qui remet');
            $table->string('photo_destination_chemin', 255)->nullable()
                ->comment('Photo de la partie qui recoit');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('horodatage_telephone')->nullable()
                ->comment('Date de l\'action, fait foi (cadrage 11.7)');
            $table->foreignId('effectue_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('effectue_le');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index(['kit_id', 'effectue_le']);
            $table->index(['vague_id', 'type']);
            $table->index('type');
        });

        Schema::create('remplacements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->cascadeOnDelete();
            $table->foreignId('volontaire_sortant_id')->constrained('volontaires')->restrictOnDelete();
            $table->foreignId('volontaire_entrant_id')->constrained('volontaires')->restrictOnDelete();
            $table->foreignId('affectation_sortante_id')->constrained('affectations')->restrictOnDelete();
            $table->foreignId('affectation_entrante_id')->nullable()
                ->constrained('affectations')->nullOnDelete();
            $table->enum('motif', ['desistement', 'abandon', 'indisponibilite', 'performance'])
                ->comment('Obligatoire : aucun remplacement sans motif');
            $table->text('commentaire')->nullable();
            $table->foreignId('kit_mouvement_id')->nullable()->constrained('kit_mouvements')->nullOnDelete()
                ->comment('Obligatoire si le sortant detenait un kit');
            $table->foreignId('decide_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le');
            $table->timestamps();

            $table->index('vague_id');
            $table->index('volontaire_sortant_id');
            $table->index('volontaire_entrant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remplacements');
        Schema::dropIfExists('kit_mouvements');
    }
};
