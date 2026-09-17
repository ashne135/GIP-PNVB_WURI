<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registre des volontaires, remise des identifiants, consentement.
 *
 * Les trois categories sont ETANCHES (cadrage, section 3) : un assistant ne peut
 * pas etre promu operateur. Aucune route, aucun service et aucun seeder ne
 * modifie la colonne categorie.
 *
 * La remise des identifiants suit une cascade courriel > SMS > bordereau signe
 * en formation. Le courriel n'est pas bloquant a l'import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volontaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('matricule', 20)->unique()->comment('Exemple : PNVB-OPK000123');
            $table->enum('categorie', ['superviseur', 'operateur', 'assistant'])
                ->comment('IMMUABLE : les trois categories sont etanches');
            $table->enum('statut', ['operationnel', 'reserve', 'retire'])->default('reserve');
            $table->foreignId('localite_id')->nullable()->constrained('localites')->restrictOnDelete()
                ->comment('Obligatoire pour un assistant, nul pour les categories tournantes');
            $table->foreignId('region_origine_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->enum('sexe', ['M', 'F'])->nullable();
            $table->date('date_naissance')->nullable();
            $table->enum('motif_reserve', [
                'desistement', 'abandon', 'indisponibilite', 'performance', 'non_mobilise',
            ])->nullable()->comment('Obligatoire au passage en reserve');
            $table->timestamp('date_entree_reserve')->nullable();
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['categorie', 'statut']);
            $table->index('localite_id');
            $table->index('est_fictif');
        });

        Schema::create('remises_identifiants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('canal', ['courriel', 'sms', 'main_propre']);
            $table->unsignedTinyInteger('rang')->comment('1 courriel, 2 SMS, 3 bordereau de formation');
            $table->enum('statut', ['en_attente', 'envoye', 'echec', 'remis'])->default('en_attente');
            $table->string('destinataire', 150)->nullable()
                ->comment('Adresse ou numero effectivement utilise');
            $table->string('session_formation', 80)->nullable()
                ->comment('Regroupe les lignes d\'un meme bordereau PDF');
            $table->text('erreur')->nullable()->comment('Motif d\'echec, lisible par l\'administrateur');
            $table->timestamp('envoye_le')->nullable();
            $table->timestamp('remis_le')->nullable();
            $table->foreignId('remis_par')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Signataire de la remise en main propre');
            $table->timestamps();

            $table->index(['user_id', 'rang']);
            $table->index(['statut', 'canal']);
            $table->index('session_formation');
        });

        Schema::create('consentements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('version_charte', 20);
            $table->timestamp('accepte_le');
            $table->string('adresse_ip', 45)->nullable();
            $table->string('agent_utilisateur', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'version_charte']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consentements');
        Schema::dropIfExists('remises_identifiants');
        Schema::dropIfExists('volontaires');
    }
};
