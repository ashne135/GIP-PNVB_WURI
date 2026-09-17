<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace des appels groupes POST /api/sync.
 *
 * Sans cette trace, un agent qui affirme "j'ai envoye mon rapport" n'est ni
 * verifiable ni contredit. Le detail conserve la reponse rendue au telephone :
 * uuid acceptes, uuid rejetes avec motif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('uuid_lot', 36)->unique()->comment('Idempotence au niveau du lot entier');
            $table->timestamp('recu_le');
            $table->unsignedSmallInteger('nb_elements')->default(0);
            $table->unsignedSmallInteger('nb_acceptes')->default(0);
            $table->unsignedSmallInteger('nb_rejetes')->default(0);
            $table->json('detail')->nullable();
            $table->unsignedInteger('duree_ms')->nullable();

            $table->index(['user_id', 'recu_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_lots');
    }
};
