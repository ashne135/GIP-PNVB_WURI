<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence : quatre mecanismes distincts, a ne jamais confondre.
 *
 *  8.1 signaux_arrivee   : l'agent SIGNALE son arrivee. Aucune valeur administrative.
 *  8.2 la carte          : lecture des signaux, pas une table.
 *  8.3 feuilles_presence : le superviseur VALIDE. Seule piece qui fait foi.
 *  8.4 releves_position  : rapprochement automatique, encadre, jamais restitue.
 *
 * Une feuille par SITE et par JOUR, jamais une par centre : c'est l'index unique
 * (site_id, date_presence) qui le garantit.
 *
 * AUCUNE interface ne lit releves_position. Seul le job de rapprochement y accede,
 * et il n'en sort que des ecarts, jamais un historique de deplacements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signaux_arrivee', function (Blueprint $table) {
            $table->id();
            $table->char('uuid_client', 36)->unique();
            $table->foreignId('volontaire_id')->constrained('volontaires')->cascadeOnDelete();
            $table->foreignId('affectation_id')->nullable()->constrained('affectations')->nullOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('vague_id')->nullable()->constrained('vagues_deploiement')->nullOnDelete();
            $table->enum('type', ['arrivee', 'depart']);
            $table->timestamp('horodatage_telephone')->comment('Retenu comme date de l\'action');
            $table->timestamp('recu_le')->nullable()->comment('Arrivee sur le serveur, pour le diagnostic');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('precision_gps')->nullable()->comment('Metres');
            $table->unsignedInteger('distance_site_metres')->nullable()->comment('Calculee cote serveur');
            $table->boolean('dans_zone')->default(false)
                ->comment('Vert si vrai, orange sinon, sur la carte du superviseur');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'horodatage_telephone']);
            $table->index(['volontaire_id', 'horodatage_telephone']);
        });

        Schema::create('feuilles_presence', function (Blueprint $table) {
            $table->id();
            $table->char('uuid_client', 36)->unique();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->restrictOnDelete();
            $table->foreignId('tournee_site_id')->nullable()->constrained('tournees_site')->nullOnDelete()
                ->comment('Le passage du kit dont releve cette journee');
            $table->date('date_presence');
            $table->enum('statut', ['brouillon', 'validee', 'corrigee'])->default('brouillon');
            $table->foreignId('superviseur_id')->constrained('volontaires')->restrictOnDelete()
                ->comment('Le validant');
            $table->timestamp('valide_le')->nullable();
            $table->decimal('latitude_superviseur', 10, 7)->nullable();
            $table->decimal('longitude_superviseur', 10, 7)->nullable();
            $table->unsignedInteger('distance_site_metres')->nullable()
                ->comment('Distance du superviseur au site au moment de valider');
            $table->foreignId('corrige_par')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Reserve au chef d\'antenne regional');
            $table->timestamp('corrige_le')->nullable();
            $table->text('motif_correction')->nullable();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            // UNE feuille par site et par jour : la regle est dans la base.
            $table->unique(['site_id', 'date_presence']);
            $table->index(['centre_id', 'date_presence']);
            $table->index(['vague_id', 'date_presence']);
            $table->index('statut');
        });

        Schema::create('lignes_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feuille_presence_id')->constrained('feuilles_presence')->cascadeOnDelete();
            $table->foreignId('volontaire_id')->constrained('volontaires')->restrictOnDelete();
            $table->enum('categorie', ['superviseur', 'operateur', 'assistant'])
                ->comment('Recopiee pour figer l\'historique');
            $table->enum('statut', ['present', 'absent', 'absent_justifie']);
            $table->string('motif_absence', 255)->nullable()
                ->comment('Obligatoire si absent_justifie');
            $table->foreignId('signal_arrivee_id')->nullable()->constrained('signaux_arrivee')->nullOnDelete();
            $table->timestamp('heure_arrivee_signalee')->nullable();
            $table->unsignedInteger('distance_signalee')->nullable();
            $table->boolean('dans_zone')->nullable();
            $table->string('commentaire', 255)->nullable();
            $table->timestamps();

            $table->unique(['feuille_presence_id', 'volontaire_id']);
            $table->index('volontaire_id');
            $table->index('statut');
        });

        Schema::create('releves_position', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volontaire_id')->constrained('volontaires')->cascadeOnDelete();
            $table->foreignId('affectation_id')->nullable()->constrained('affectations')->nullOnDelete();
            $table->foreignId('site_id_attendu')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestamp('horodatage')
                ->comment('Heures de service et jours d\'affectation active uniquement');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('distance_metres')->nullable();
            $table->boolean('dans_zone')->default(false);
            $table->date('purge_prevue_le')
                ->comment('horodatage + parametre retention.releves_jours, defaut 90');
            $table->timestamp('created_at')->nullable();

            $table->index(['volontaire_id', 'horodatage']);
            $table->index('purge_prevue_le');
            $table->index(['site_id_attendu', 'horodatage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releves_position');
        Schema::dropIfExists('lignes_presence');
        Schema::dropIfExists('feuilles_presence');
        Schema::dropIfExists('signaux_arrivee');
    }
};
