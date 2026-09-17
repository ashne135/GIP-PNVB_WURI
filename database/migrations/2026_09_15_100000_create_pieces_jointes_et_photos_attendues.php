<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LES PHOTOS DU TERRAIN (cadrage, sections 11.8, 12 et 13).
 *
 * Elles partent APRÈS les données texte, compressées sur le téléphone, et une
 * photo qui échoue ne bloque jamais la fiche. D'où trois choses :
 *
 *   - une table de pièces jointes, rattachée à SA fiche par une clé étrangère
 *     explicite — incident ou mouvement de kit — plutôt qu'un lien polymorphe
 *     que la base ne pourrait pas contraindre ;
 *   - sur le mouvement de kit, l'ÉCHÉANCE à laquelle les photos annoncées
 *     doivent être arrivées : l'alerte « photos manquantes » attend jusque-là ;
 *   - un uuid client sur la réponse à une appréciation, pour qu'une réponse
 *     écrite hors ligne et renvoyée ne soit jamais enregistrée deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid_fichier', 36)->unique()->comment('Produit par le telephone : idempotence du depot');
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->restrictOnDelete();
            $table->foreignId('kit_mouvement_id')->nullable()->constrained('kit_mouvements')->restrictOnDelete();
            $table->enum('role', ['preuve_incident', 'constat_source', 'constat_destination']);
            $table->string('chemin', 255)->comment('Disque prive : jamais servi sans verification des droits');
            $table->string('type_mime', 60);
            $table->unsignedInteger('taille_octets');
            $table->unsignedSmallInteger('largeur')->nullable();
            $table->unsignedSmallInteger('hauteur')->nullable();
            $table->char('empreinte_sha256', 64);
            $table->foreignId('deposee_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('deposee_le');
            $table->timestamp('horodatage_telephone')->nullable()->comment('Heure de la prise de vue, fait foi');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index('incident_id');
            $table->index('kit_mouvement_id');
        });

        Schema::table('kit_mouvements', function (Blueprint $table) {
            $table->timestamp('photos_attendues_jusqu_au')->nullable()->after('photo_destination_chemin')
                ->comment('Photos annoncees par le telephone : echeance avant alerte');
            $table->timestamp('alerte_photos_le')->nullable()->after('photos_attendues_jusqu_au');
        });

        Schema::table('appreciations_reponses', function (Blueprint $table) {
            $table->char('uuid_client', 36)->nullable()->unique()->after('id')
                ->comment('Idempotence de la reponse ecrite hors ligne');
        });
    }

    public function down(): void
    {
        Schema::table('appreciations_reponses', function (Blueprint $table) {
            $table->dropUnique(['uuid_client']);
            $table->dropColumn('uuid_client');
        });

        Schema::table('kit_mouvements', function (Blueprint $table) {
            $table->dropColumn(['photos_attendues_jusqu_au', 'alerte_photos_le']);
        });

        Schema::dropIfExists('pieces_jointes');
    }
};
