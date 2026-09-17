<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LES EXPORTS PLANIFIÉS (cadrage, tâche 18).
 *
 * Un export planifié est un FICHIER DÉPOSÉ, pas un envoi : il est produit la
 * nuit, rangé hors du dossier public, et téléchargé depuis le back-office par
 * qui a le droit de le voir. Rien ne part par courriel — donc aucune donnée ne
 * s'échappe par une boîte mal fermée, et la production ne dépend pas d'un SMTP.
 *
 * LA PORTÉE EST DANS LA LIGNE : un fichier national, et un fichier par région.
 * Le périmètre du lecteur décide de ce qu'il voit, comme partout ailleurs — un
 * chef d'antenne du Sahel ne télécharge pas le fichier du Nakambé.
 *
 * Un statut « vide » existe à côté de « prêt » : une journée sans donnée n'est
 * pas une panne, et un fichier de zéro ligne qui se présente comme prêt ferait
 * croire à une perte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports_planifies', function (Blueprint $table) {
            $table->id();

            $table->enum('type', [
                'rapports', 'presences', 'incidents', 'tableau_bord', 'couverture', 'kits',
            ]);
            $table->enum('portee', ['national', 'region']);
            $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnDelete();

            $table->date('date_debut');
            $table->date('date_fin')->comment('Égale à date_debut pour un export quotidien');

            $table->string('nom_fichier', 160);
            $table->string('chemin', 255)->nullable()
                ->comment('Hors dossier public : servi par le contrôleur, jamais par une URL directe');
            $table->string('format', 10)->default('csv');
            $table->unsignedBigInteger('taille_octets')->default(0);
            $table->unsignedInteger('nb_lignes')->default(0);

            $table->enum('statut', ['pret', 'vide', 'echec'])->default('pret')
                ->comment('vide = periode sans donnee, ce n\'est pas une panne');
            $table->text('message')->nullable();

            $table->timestamp('genere_le');
            $table->foreignId('genere_par')->nullable()->constrained('users')->nullOnDelete()
                ->comment('null = production planifiee de nuit');

            $table->timestamps();

            /*
             * Rejouer une journée REMPLACE son fichier au lieu d'en empiler un
             * second. ATTENTION : MySQL considère deux NULL comme distincts, donc
             * cet index ne protège pas les lignes nationales (region_id NULL) —
             * c'est le service qui garantit l'unicité, par updateOrCreate.
             */
            $table->unique(['type', 'portee', 'region_id', 'date_debut'], 'uq_export_type_portee_jour');
            $table->index(['date_debut', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports_planifies');
    }
};
