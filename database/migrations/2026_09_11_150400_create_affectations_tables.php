<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affectations et tournees de site.
 *
 * Une affectation est un PASSAGE DATE, pas un rattachement permanent
 * (cadrage, section 4). L'historique est conserve, jamais ecrase.
 *
 * cle_unicite_active est une colonne generee : elle vaut volontaire_id tant que
 * le statut est "active", NULL sinon. Son index unique garantit EN BASE qu'un
 * agent deja affecte a une vague active n'est pas eligible a une autre, y compris
 * si l'ecriture vient d'une commande ou d'un seeder qui contournerait le service.
 *
 * tournees_site date le passage d'un kit sur un site : 12 294 sites pour 966 kits,
 * soit 12,7 sites par kit. Un kit couvre les sites de son centre en sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->cascadeOnDelete();
            $table->foreignId('volontaire_id')->constrained('volontaires')->restrictOnDelete();
            $table->enum('role_terrain', ['superviseur', 'operateur', 'assistant'])
                ->comment('Copie de la categorie et fige');
            $table->foreignId('centre_id')->nullable()->constrained('centres')->restrictOnDelete()
                ->comment('Operateur et assistant');
            $table->foreignId('unite_supervision_id')->nullable()
                ->constrained('unites_supervision')->nullOnDelete()->comment('Superviseur');
            $table->foreignId('localite_id')->nullable()->constrained('localites')->restrictOnDelete()
                ->comment('Assistant : sa localite de rattachement permanent');
            $table->foreignId('kit_id')->nullable()->constrained('kits')->nullOnDelete()
                ->comment('Operateur');
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->enum('statut', ['proposee', 'active', 'terminee', 'remplacee', 'annulee'])
                ->default('proposee');
            $table->enum('origine', ['tirage_auto', 'ajustement_manuel', 'remplacement'])
                ->default('tirage_auto');
            $table->unsignedInteger('rang_tirage')->nullable()
                ->comment('Position dans le tirage, pour le rejouer');
            $table->foreignId('affectation_remplacee_id')->nullable()
                ->constrained('affectations')->nullOnDelete();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            // Colonne generee : placee en dernier, MySQL exige que les colonnes
            // referencees soient deja definies.
            $table->string('cle_unicite_active', 24)
                ->storedAs("if(statut = 'active', cast(volontaire_id as char), null)");

            $table->unique('cle_unicite_active');
            $table->index(['vague_id', 'statut']);
            $table->index(['centre_id', 'statut']);
            $table->index(['volontaire_id', 'statut']);
        });

        Schema::create('tournees_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->cascadeOnDelete();
            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('kit_id')->nullable()->constrained('kits')->nullOnDelete();
            $table->foreignId('affectation_operateur_id')->nullable()
                ->constrained('affectations')->nullOnDelete()
                ->comment('L\'operateur qui tient le kit sur ce passage');
            $table->unsignedSmallInteger('ordre')->default(1)
                ->comment('Rang dans la tournee du centre');
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->enum('statut', ['planifiee', 'en_cours', 'terminee', 'reportee'])
                ->default('planifiee');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['vague_id', 'site_id']);
            $table->index(['centre_id', 'ordre']);
            $table->index(['site_id', 'date_debut']);
            $table->index(['kit_id', 'date_debut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournees_site');
        Schema::dropIfExists('affectations');
    }
};
