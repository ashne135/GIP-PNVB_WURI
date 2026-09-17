<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parc de kits.
 *
 * LE KIT EST RATTACHE A UN AGENT, PAS A UN SITE (cadrage, section 13). Il suit
 * la personne : lors d'un redeploiement d'une region a une autre, l'operateur
 * emporte son kit.
 *
 * volontaire_detenteur_id est unique et nullable : MySQL accepte plusieurs NULL
 * dans un index unique, donc un kit non attribue reste possible, mais un agent
 * qui detiendrait deux kits est refuse par la base, pas seulement par le service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kits', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->json('composition')->nullable()
                ->comment('Contenu type en attendant le canevas de tracabilite du client');
            $table->enum('etat', ['fonctionnel', 'panne', 'perdu', 'vole', 'reforme'])
                ->default('fonctionnel');
            $table->foreignId('volontaire_detenteur_id')->nullable()->unique()
                ->constrained('volontaires')->nullOnDelete()
                ->comment('Un agent ne detient qu\'un seul kit a la fois');
            $table->foreignId('centre_courant_id')->nullable()->constrained('centres')->nullOnDelete();
            $table->foreignId('site_courant_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->boolean('est_permanent_zone_defis')->default(false)
                ->comment('Les 32 kits des communes urbaines en zone a defis securitaires');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index('etat');
            $table->index('centre_courant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kits');
    }
};
