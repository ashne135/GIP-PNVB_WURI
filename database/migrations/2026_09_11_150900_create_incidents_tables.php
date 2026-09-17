<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module incidents : canevas client repris integralement, sections A a J.
 *
 * Les listes a cases multiples (C natures, E impacts, H mesures, I personnes
 * informees) sont des NOMENCLATURES EN TABLES avec pivots, et non du JSON :
 * statistiques par nature, libelles modifiables sans redeploiement, traductions
 * moore et dioula prevues des l'architecture.
 *
 * Seule la section G (preuves) reste en JSON : elle decrit la nature des pieces
 * jointes, pas un axe d'analyse, et les pieces elles-memes sont des fichiers.
 *
 * Le niveau de gravite pilote la matrice de notification et le delai d'escalade,
 * tous deux parametrables (table parametres).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Nomenclatures ----------
        foreach ([
            'natures_incident' => 'Section C : nature de l\'incident',
            'impacts_incident' => 'Section E : impact constate',
            'mesures_incident' => 'Section H : mesure immediate prise',
            'destinataires_incident' => 'Section I : personne ou service informe',
        ] as $nomTable => $commentaire) {
            Schema::create($nomTable, function (Blueprint $table) use ($commentaire) {
                $table->id();
                $table->string('code', 60)->unique()->comment($commentaire);
                $table->string('libelle', 160);
                $table->string('libelle_moore', 160)->nullable();
                $table->string('libelle_dioula', 160)->nullable();
                $table->unsignedSmallInteger('ordre')->default(0);
                $table->boolean('actif')->default(true);
                $table->timestamps();

                $table->index(['actif', 'ordre']);
            });
        }

        // ---------- Fiche d'incident ----------
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();

            // A. Identification
            $table->char('uuid_client', 36)->unique();
            $table->string('numero', 20)->unique()->comment('Attribue automatiquement : INC-2026-000123');
            $table->foreignId('declarant_user_id')->constrained('users')->restrictOnDelete();
            $table->string('declarant_telephone', 20)->nullable()->comment('Repris automatiquement');
            $table->timestamp('declare_le');
            $table->enum('canal', ['mobile', 'web'])->default('mobile');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('centre_id')->nullable()->constrained('centres')->nullOnDelete();
            $table->foreignId('localite_id')->nullable()->constrained('localites')->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->constrained('communes')->nullOnDelete();
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->decimal('latitude_site', 10, 7)->nullable()->comment('GPS du site, automatique');
            $table->decimal('longitude_site', 10, 7)->nullable();
            $table->decimal('latitude_declarant', 10, 7)->nullable()->comment('GPS du declarant, automatique');
            $table->decimal('longitude_declarant', 10, 7)->nullable();
            $table->boolean('deja_signale')->default(false);

            // B. Localisation
            $table->enum('type_lieu', ['site', 'trajet_aller', 'trajet_retour', 'autre'])->default('site');
            $table->string('lieu_precision', 255)->nullable();
            $table->boolean('encore_sur_les_lieux')->nullable();

            // D. Description
            $table->timestamp('survenu_le')->nullable();
            $table->text('recit');
            $table->text('personnes_concernees')->nullable();
            $table->enum('toujours_en_cours', ['oui', 'non', 'inconnu'])->default('inconnu');
            $table->boolean('danger_immediat')->default(false);

            // E. Impact (complement du pivot)
            $table->unsignedSmallInteger('nb_personnes_affectees')->nullable()->comment('Estimatif');

            // F. Gravite
            $table->unsignedTinyInteger('gravite')
                ->comment('1 mineur, 2 modere, 3 majeur, 4 critique');

            // G. Preuves
            $table->json('preuves')->nullable()
                ->comment('photo, video, document, capture, aucun');

            // H. Mesures (complement du pivot)
            $table->text('mesures_precisions')->nullable();

            // J. Traitement, reserve aux responsables habilites
            $table->enum('statut', ['nouveau', 'pris_en_charge', 'en_cours', 'resolu', 'cloture'])
                ->default('nouveau');
            $table->foreignId('responsable_traitement_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pris_en_charge_le')->nullable();
            $table->text('mesures_correctives')->nullable();
            $table->timestamp('resolu_le')->nullable();
            $table->text('rapport_cloture')->nullable();

            // Escalade automatique
            $table->timestamp('echeance_escalade')->nullable()
                ->comment('declare_le + delai d\'escalade du niveau de gravite');
            $table->unsignedTinyInteger('niveau_escalade')->default(0);
            $table->timestamp('derniere_escalade_le')->nullable();

            $table->timestamp('horodatage_telephone')->nullable();
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            $table->index(['region_id', 'statut']);
            $table->index(['gravite', 'statut']);
            $table->index('echeance_escalade');
            $table->index('declare_le');
            $table->index(['centre_id', 'statut']);
        });

        // ---------- Pivots ----------
        // Les noms d'index sont donnes explicitement : MySQL plafonne les
        // identifiants a 64 caracteres, et la convention automatique de Laravel
        // depasse cette limite sur ces tables.
        foreach ([
            'incident_nature' => ['natures_incident', 'nature_incident_id', 'nature'],
            'incident_impact' => ['impacts_incident', 'impact_incident_id', 'impact'],
            'incident_mesure' => ['mesures_incident', 'mesure_incident_id', 'mesure'],
            'incident_destinataire' => ['destinataires_incident', 'destinataire_incident_id', 'destinataire'],
        ] as $nomPivot => [$tableCible, $colonne, $court]) {
            Schema::create($nomPivot, function (Blueprint $table) use ($tableCible, $colonne, $court) {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->foreignId($colonne)->constrained($tableCible)->restrictOnDelete();

                $table->unique(['incident_id', $colonne], "uq_inc_{$court}");
                $table->index($colonne, "idx_inc_{$court}");
            });
        }

        // ---------- Tracabilite ----------
        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Nul : action de l\'acteur SYSTEME');
            $table->enum('type_action', [
                'creation', 'notification', 'prise_en_charge', 'commentaire',
                'changement_statut', 'escalade', 'ajout_preuve', 'cloture', 'reouverture',
            ]);
            $table->string('ancien_statut', 30)->nullable();
            $table->string('nouveau_statut', 30)->nullable();
            $table->text('commentaire')->nullable();
            $table->json('destinataires')->nullable()->comment('Qui a ete notifie, par quel canal');
            $table->timestamp('effectue_le');

            $table->index(['incident_id', 'effectue_le']);
            $table->index('type_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_actions');
        foreach (['incident_destinataire', 'incident_mesure', 'incident_impact', 'incident_nature'] as $pivot) {
            Schema::dropIfExists($pivot);
        }
        Schema::dropIfExists('incidents');
        foreach ([
            'destinataires_incident', 'mesures_incident', 'impacts_incident', 'natures_incident',
        ] as $nomenclature) {
            Schema::dropIfExists($nomenclature);
        }
    }
};
