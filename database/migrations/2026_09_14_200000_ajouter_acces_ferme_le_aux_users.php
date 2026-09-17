<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La date de FERMETURE de l'accès d'un compte.
 *
 * Refuser la connexion ne suffit pas à fermer un accès : le téléphone garde le
 * jeton qu'il a reçu. À la fermeture, ce jeton est restreint au seul envoi du
 * travail fait pendant la mission, et cette date borne ce qui passe encore :
 * une action horodatée après elle est refusée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('acces_ferme_le')->nullable()->after('derniere_connexion_le')
                ->comment('Fermeture de l acces : borne des actions acceptees en rattrapage');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('acces_ferme_le');
        });
    }
};
