<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LE PLAFOND DE DEUX KITS PAR CENTRE EST LEVÉ (décision du client, 18/09/2026).
 *
 * Le nombre de kits n'est plus une règle mais une donnée : un centre en reçoit
 * autant que le Programme en déploie. Rien ne change dans le TYPE de la colonne
 * — un entier d'un octet monte à 255, ce qui dépasse de loin le parc réel — mais
 * son COMMENTAIRE annonçait « 1 ou 2 ». Le laisser tel quel aurait laissé en
 * base une règle abolie, que le prochain lecteur aurait crue en vigueur.
 *
 * La borne qui subsiste est le paramètre affectation.kits_par_centre_max, lisible
 * et modifiable dans l'écran des paramètres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            $table->unsignedTinyInteger('nombre_kits')->default(1)
                ->comment('Sans plafond metier ; borne par affectation.kits_par_centre_max')
                ->change();
        });

        /*
         * LE PARAMÈTRE DÉJÀ EN BASE, qu'aucun seeder ne relèvera.
         *
         * ParametresSeeder crée les paramètres en firstOrCreate : rejoué, il
         * respecte les valeurs existantes — c'est voulu, un réglage du Programme
         * ne doit pas être écrasé par un déploiement. Conséquence : sans cette
         * mise à jour, la base de production garderait 2, et le champ resterait
         * plafonné là où on vient de le libérer.
         *
         * On ne relève QUE la valeur 2, celle d'origine. Si quelqu'un a déjà
         * fixé son propre plafond, c'est une décision, et elle tient.
         */
        DB::table('parametres')
            ->where('cle', 'affectation.kits_par_centre_max')
            ->where('valeur', '2')
            ->update([
                'valeur' => '255',
                'description' => 'Le plafond métier de 2 a été levé le 18/09/2026 : un centre reçoit '
                    .'autant de kits que le Programme en déploie. 255 est la borne de la colonne, pas '
                    .'une règle : abaissez ce paramètre pour vous fixer un plafond, il s\'applique '
                    .'à la saisie comme à l\'import.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            $table->unsignedTinyInteger('nombre_kits')->default(1)->comment('1 ou 2')->change();
        });
    }
};
