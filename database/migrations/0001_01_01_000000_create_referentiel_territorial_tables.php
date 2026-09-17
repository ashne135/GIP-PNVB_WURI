<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referentiel territorial et dispositif de terrain.
 *
 *   REGION (12) > PROVINCE (34) > COMMUNE (355) > LOCALITE (7 453)
 *                                      |
 *                                   CENTRE (~966) > SITE (12 294)
 *
 * Le CENTRE est rattache a la COMMUNE : 966 centres pour 7 453 localites, et la
 * codification <REGION>-<COMMUNE>-C<nnn> ne porte aucune localite.
 * Le SITE porte deux rattachements : sa LOCALITE (population, denominateur du
 * taux de couverture) et son CENTRE (supervision, consolidation).
 *
 * Les colonnes region_id denormalisees sur communes, localites, centres et sites
 * existent pour que le filtre de perimetre regional reste un simple where.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique()->comment('KAD GUI NAN DJO NAK OUB YAA KOU NAZ BAN TAN GOU');
            $table->string('nom', 80)->unique();
            $table->unsignedInteger('population_hommes')->default(0);
            $table->unsignedInteger('population_femmes')->default(0);
            $table->unsignedInteger('population_totale')->default(0);
            $table->unsignedSmallInteger('nombre_sites_alloues')->default(0)
                ->comment('Chiffre du plan projet : 661 a 2 247, total national 12 294');
            $table->json('contour_geojson')->nullable()->comment('Fond de carte Leaflet');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('code', 8);
            $table->string('nom', 80);
            $table->unsignedInteger('population_hommes')->default(0);
            $table->unsignedInteger('population_femmes')->default(0);
            $table->unsignedInteger('population_totale')->default(0);
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['region_id', 'nom']);
        });

        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('code', 4)
                ->comment('Nom sans accent, majuscules, tronque a 4, collisions resolues. Fige a vie.');
            $table->string('nom', 80);
            $table->enum('type', ['rurale', 'urbaine']);
            $table->unsignedInteger('population_hommes')->default(0);
            $table->unsignedInteger('population_femmes')->default(0);
            $table->unsignedInteger('population_totale')->default(0);
            $table->unsignedInteger('population_localites')->default(0)
                ->comment('Somme recalculee des localites : rend visible l\'ecart du fichier source');
            $table->boolean('est_zone_defis_securitaires')->default(false)
                ->comment('Communes urbaines concernees par les 32 kits permanents');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['region_id', 'code']);
            $table->unique(['province_id', 'nom']);
            $table->index('type');
        });

        Schema::create('localites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commune_id')->constrained('communes')->restrictOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('nom', 120);
            $table->enum('type', ['village', 'quartier', 'secteur'])->default('village');
            $table->unsignedInteger('population_hommes')->default(0);
            $table->unsignedInteger('population_femmes')->default(0);
            $table->unsignedInteger('population_totale')->default(0)
                ->comment('Denominateur du taux de couverture');
            $table->decimal('quota_sites_brut', 10, 6)->default(0)
                ->comment('Prorata intra-regional brut, conserve pour audit');
            $table->unsignedSmallInteger('quota_sites')->default(0)
                ->comment('Plancher de 1, puis plus forts restes sur le solde regional');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['commune_id', 'nom']);
            $table->index('region_id');
            $table->index('population_totale');
        });

        Schema::create('centres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commune_id')->constrained('communes')->restrictOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('code', 20)->unique()->comment('Exemple : KAD-OUAG-C012');
            $table->string('nom', 120);
            $table->unsignedTinyInteger('nombre_kits')->default(1)->comment('1 ou 2');
            $table->boolean('est_permanent')->default(false)
                ->comment('Centre permanent de commune urbaine en zone a defis securitaires');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('statut', ['planifie', 'ouvert', 'ferme'])->default('planifie');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index(['region_id', 'statut']);
            $table->index('commune_id');
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete()
                ->comment('Supervision, consolidation, tournee');
            $table->foreignId('localite_id')->constrained('localites')->restrictOnDelete()
                ->comment('Population, taux de couverture');
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('code', 24)->unique()->comment('Exemple : KAD-OUAG-C012-S01');
            $table->string('nom', 120);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('rayon_zone_metres')->default(500)
                ->comment('Defaut repris du parametre presence.rayon_zone_site_metres');
            $table->unsignedSmallInteger('ordre_tournee')->default(1)
                ->comment('Rang du site dans la tournee du kit de son centre');
            $table->enum('statut', ['planifie', 'ouvert', 'couvert', 'ferme'])->default('planifie');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->unique(['centre_id', 'ordre_tournee']);
            $table->index('localite_id');
            $table->index(['region_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
        Schema::dropIfExists('centres');
        Schema::dropIfExists('localites');
        Schema::dropIfExists('communes');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('regions');
    }
};
