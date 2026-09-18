<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LE NIVEAU D'ÉTUDE conditionne le profil (décision du client, 17/09/2026) :
 * 4ème pour un A-OPK, BAC pour un opérateur de kit, Licence pour un
 * superviseur de centre.
 *
 * DEUX COLONNES, et pas une :
 *   - niveau_etude : l'échelle ordonnée, la seule qu'on puisse comparer ;
 *   - diplome      : l'intitulé exact, en texte, pour le dossier de l'agent.
 *
 * LA DÉROGATION est tracée sur la fiche : qui l'a accordée, quand, et pourquoi.
 * Sans cela, « le niveau ne suffisait pas mais on l'a pris quand même » ne
 * serait plus explicable six mois après.
 *
 * LE RETRAIT d'un volontaire n'efface rien : la fiche sort des listes et des
 * tirages, son accès se ferme, et ses feuilles de présence comme ses rapports
 * restent intacts — ce sont des pièces qui font foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volontaires', function (Blueprint $table) {
            $table->enum('niveau_etude', [
                'aucun', 'cep', 'quatrieme', 'troisieme_bepc', 'bac',
                'bac_plus_1', 'bac_plus_2', 'licence', 'master',
            ])->nullable()->after('date_naissance')
                ->comment('Echelle ordonnee : conditionne le profil');

            $table->string('diplome', 150)->nullable()->after('niveau_etude')
                ->comment('Intitule exact, pour le dossier — jamais compare');

            $table->text('derogation_niveau_motif')->nullable()->after('diplome')
                ->comment('Profil accorde malgre un niveau insuffisant');
            $table->foreignId('derogation_niveau_par')->nullable()->after('derogation_niveau_motif')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('derogation_niveau_le')->nullable()->after('derogation_niveau_par');

            $table->text('motif_retrait')->nullable()->after('date_entree_reserve');
            $table->foreignId('retire_par')->nullable()->after('motif_retrait')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('retire_le')->nullable()->after('retire_par');
        });
    }

    public function down(): void
    {
        Schema::table('volontaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('derogation_niveau_par');
            $table->dropConstrainedForeignId('retire_par');
            $table->dropColumn([
                'niveau_etude', 'diplome', 'derogation_niveau_motif', 'derogation_niveau_le',
                'motif_retrait', 'retire_le',
            ]);
        });
    }
};
