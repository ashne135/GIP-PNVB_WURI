<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cadrage v2, section 6 : le canevas reel du fichier des retenus.
 *
 * Trois donnees d'identite s'ajoutent a la fiche du volontaire :
 *   - numero_cnib : DONNEE SENSIBLE. Visible du seul administrateur national et
 *     du super administrateur, masquee B1******** ailleurs, jamais exportee
 *     dans les listes operationnelles. Sert aussi de PREMIERE CLE de
 *     dedoublonnage a l'import.
 *   - date_etablissement_cnib : une date future est refusee a l'import.
 *   - lieu_naissance : texte libre, parfois a l'etranger. Stocke tel quel,
 *     JAMAIS rattache a une commune.
 *
 * La CATEGORIE devient NULLABLE : la colonne « Profil » est vide dans le
 * fichier fourni. Une ligne sans profil est acceptee et placee « a qualifier »
 * — categorie nulle — puis qualifiee en lot par l'administrateur national avant
 * toute affectation.
 *
 * L'etancheite des categories n'est PAS assouplie pour autant : passer de NULL
 * a une valeur est une QUALIFICATION, autorisee une seule fois ; passer d'une
 * valeur a une autre reste interdit (voir le modele Volontaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volontaires', function (Blueprint $table) {
            $table->string('numero_cnib', 30)->nullable()->after('matricule')
                ->comment('SENSIBLE : masque hors administrateur national et DSI');
            $table->date('date_etablissement_cnib')->nullable()->after('numero_cnib');
            $table->string('lieu_naissance', 160)->nullable()->after('date_naissance')
                ->comment('Texte libre, parfois a l\'etranger : jamais rattache a une commune');

            $table->foreignId('qualifie_par')->nullable()->after('import_id')
                ->constrained('users')->nullOnDelete()
                ->comment('Qui a attribue le profil quand le fichier ne le portait pas');
            $table->timestamp('qualifie_le')->nullable()->after('qualifie_par');

            // Le dedoublonnage a l'import porte d'abord sur le CNIB.
            $table->unique('numero_cnib');
        });

        // categorie nullable : « a qualifier » tant que le profil est inconnu.
        DB::statement(
            "ALTER TABLE volontaires MODIFY categorie ENUM('superviseur','operateur','assistant') NULL "
            ."COMMENT 'Nulle = a qualifier. Immuable une fois attribuee.'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE volontaires MODIFY categorie ENUM('superviseur','operateur','assistant') NOT NULL"
        );

        Schema::table('volontaires', function (Blueprint $table) {
            $table->dropUnique(['numero_cnib']);
            $table->dropConstrainedForeignId('qualifie_par');
            $table->dropColumn(['numero_cnib', 'date_etablissement_cnib', 'lieu_naissance', 'qualifie_le']);
        });
    }
};
