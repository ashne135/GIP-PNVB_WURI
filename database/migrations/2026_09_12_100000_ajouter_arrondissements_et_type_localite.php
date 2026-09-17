<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadrage v2, section 2 : l'ARRONDISSEMENT s'insère entre la commune urbaine et
 * la localité.
 *
 *   COMMUNE (urbaine) > ARRONDISSEMENT > LOCALITÉ
 *
 * Le niveau est FACULTATIF : il n'existe que dans les communes urbaines, et
 * localites.arrondissement_id reste nul partout ailleurs.
 *
 * Le cadrage annonce ce niveau comme « absent du fichier Excel fourni ». Le
 * fichier en contient pourtant 19 — 12 dans la province du Kadiogo, 7 dans
 * celle du Houet — aujourd'hui enregistrés comme des communes faute de niveau
 * dédié au moment du chargement. Leur conversion est faite par une commande
 * dédiée (pnvb:convertir-arrondissements), pas par cette migration : une
 * migration crée des structures, elle ne réorganise pas des données métier.
 *
 * Renomme aussi localites.type en type_localite, vocabulaire du cadrage v2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrondissements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commune_id')->constrained('communes')->restrictOnDelete()
                ->comment('Toujours une commune urbaine');
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete()
                ->comment('Denormalise, pour le scope de perimetre');
            $table->string('code', 8)->comment('Derive du numero : AR01, AR02...');
            $table->string('nom', 120);
            $table->unsignedSmallInteger('numero')->comment('Rang de l\'arrondissement dans la commune');
            $table->unsignedInteger('population_hommes')->default(0);
            $table->unsignedInteger('population_femmes')->default(0);
            $table->unsignedInteger('population_totale')->default(0);
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['commune_id', 'numero']);
            $table->unique(['commune_id', 'nom']);
            $table->index('region_id');
        });

        Schema::table('localites', function (Blueprint $table) {
            // Facultatif : renseigne uniquement dans les communes urbaines
            // decoupees en arrondissements.
            $table->foreignId('arrondissement_id')->nullable()->after('commune_id')
                ->constrained('arrondissements')->nullOnDelete();

            $table->renameColumn('type', 'type_localite');
        });
    }

    public function down(): void
    {
        Schema::table('localites', function (Blueprint $table) {
            $table->renameColumn('type_localite', 'type');
            $table->dropConstrainedForeignId('arrondissement_id');
        });

        Schema::dropIfExists('arrondissements');
    }
};
