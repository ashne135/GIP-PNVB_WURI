<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes de connexion.
 *
 * L'identifiant de connexion est le NUMERO DE TELEPHONE, jamais le courriel :
 * une partie des volontaires, notamment les assistants recrutes en milieu rural,
 * n'a pas d'adresse de courriel exploitable (cadrage, section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Identite
            $table->string('telephone', 20)->unique()->comment('Identifiant de connexion');
            $table->string('email', 150)->nullable()->comment('Facultatif : non bloquant a l\'import');
            $table->string('nom', 80);
            $table->string('prenoms', 120);
            $table->string('password');

            // Cycle de vie de l'acces (section 6) : pilote par l'affectation, jamais a la main
            $table->enum('statut_compte', ['inactif', 'actif', 'disponible', 'ferme'])
                ->default('inactif');
            $table->boolean('doit_changer_mot_de_passe')->default(true);
            $table->timestamp('mot_de_passe_change_le')->nullable();
            $table->timestamp('premiere_connexion_le')->nullable();
            $table->timestamp('derniere_connexion_le')->nullable();

            // Perimetre : renseigne pour le chef d'antenne regional
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();

            // Etat de remise des identifiants, denormalise depuis remises_identifiants
            // pour que l'ecran de suivi filtre sans jointure.
            $table->enum('etat_remise', [
                'non_envoye', 'envoye', 'echec', 'remis_main_propre', 'premiere_connexion_effectuee',
            ])->default('non_envoye');

            $table->boolean('est_fictif')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('statut_compte');
            $table->index('etat_remise');
            $table->index('est_fictif');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('telephone', 20)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
