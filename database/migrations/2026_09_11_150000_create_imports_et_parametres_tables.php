<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imports de referentiels et parametres du dispositif.
 *
 * Regle du cadrage (section 2) : aucun import partiel silencieux. Rien n'est
 * ecrit tant que l'apercu n'est pas confirme. C'est import_lignes qui rend
 * l'apercu persistable : sans elle, il serait perdu au rafraichissement.
 *
 * Aucun seuil n'est code en dur : tout passe par parametres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->enum('type_referentiel', [
                'territoire', 'centres', 'sites', 'volontaires_retenus',
                'volontaires_reserve', 'kits', 'population',
            ]);
            $table->enum('mode', ['completer', 'remplacer'])->default('completer')
                ->comment('remplacer ne purge que les lignes est_fictif = true');
            $table->string('fichier_nom', 255);
            $table->string('fichier_chemin', 255);
            $table->char('fichier_empreinte', 64)
                ->comment('SHA-256 : bloque le double import du meme fichier');
            $table->enum('statut', [
                'televerse', 'analyse', 'apercu_pret', 'confirme', 'applique', 'echec', 'annule',
            ])->default('televerse');
            $table->unsignedInteger('lignes_total')->default(0);
            $table->unsignedInteger('lignes_valides')->default(0);
            $table->unsignedInteger('lignes_erreur')->default(0);
            $table->json('resume')->nullable()->comment('Compteurs par type d\'anomalie');
            $table->string('rapport_chemin', 255)->nullable()->comment('Compte rendu telechargeable');
            $table->foreignId('televerse_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirme_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirme_le')->nullable();
            $table->timestamps();

            $table->index(['type_referentiel', 'statut']);
            $table->index('fichier_empreinte');
        });

        Schema::create('import_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->unsignedInteger('numero_ligne')
                ->comment('Numero dans le fichier source, pour que l\'utilisateur le retrouve');
            $table->json('donnees')->comment('La ligne brute telle que lue');
            $table->boolean('valide')->default(true);
            $table->string('motif_erreur', 255)->nullable()->comment('En francais simple');
            $table->enum('action', ['creation', 'mise_a_jour', 'ignoree', 'erreur'])->default('creation');

            $table->index(['import_id', 'valide']);
            $table->index(['import_id', 'numero_ligne']);
        });

        Schema::create('parametres', function (Blueprint $table) {
            $table->id();
            $table->string('cle', 80)->unique();
            $table->text('valeur')->nullable();
            $table->enum('type_valeur', ['entier', 'decimal', 'booleen', 'chaine', 'json'])->default('chaine');
            $table->string('groupe', 40)->default('dispositif')
                ->comment('dispositif, affectation, presence, incidents, kits, retention');
            $table->string('libelle', 160);
            $table->text('description')->nullable();
            $table->string('modifiable_par', 40)->default('super_administrateur')
                ->comment('Role minimal requis pour modifier');
            $table->timestamps();

            $table->index('groupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
        Schema::dropIfExists('import_lignes');
        Schema::dropIfExists('imports');
    }
};
