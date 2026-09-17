<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadrage v2, section 9 : TROIS rapports journaliers, pas deux, chacun SIGNE
 * par son auteur puis VISE par son superieur direct.
 *
 *     A-OPK  --signe-->  visa OPK
 *     OPK    --signe-->  visa SUPERVISEUR DE CENTRE
 *     SUPERVISEUR --signe--> visa CONTROLEUR TERRAIN / CHEF ARV
 *
 * Remplace rapports_site et rapports_centre, qui portaient le modele a deux
 * niveaux du cadrage v1. Ces deux tables sont vides : aucun rapport n'a encore
 * ete produit, ni par le generateur de demonstration ni par l'API.
 *
 * PRINCIPES STRUCTURANTS, tous portes par ce schema :
 *
 *  1. UN CYCLE DE VIE EXPLICITE : brouillon, soumis, vise, rejete, clos.
 *     Un rapport vise n'est plus modifiable par son auteur ; un rejet exige un
 *     motif. Chaque transition enregistre auteur, date, heure et position :
 *     c'est la table rapport_visas.
 *
 *  2. LE PRE-REMPLISSAGE EST UNE REGLE, PAS UN CONFORT. Les chiffres d'un
 *     rapport de niveau N viennent des rapports de niveau N-1 DEJA VISES. Une
 *     valeur pre-remplie peut etre corrigee, mais la correction est tracee :
 *     valeur d'origine, valeur corrigee, motif, auteur — table
 *     rapport_corrections.
 *
 *  3. LES COLONNES CALCULEES NE SONT JAMAIS STOCKEES COMME SAISIES. L'ecart, le
 *     taux et le taux de conformite sont des colonnes GENEREES en base : elles
 *     ne peuvent pas diverger de leurs operandes, quelle que soit l'origine de
 *     l'ecriture.
 *
 *  4. LES BLOCS LIBRES SONT DES TABLES, PAS TROIS CASES FIGEES : difficultes et
 *     solutions, points a ameliorer, ressources logistiques.
 *
 *  5. LA NOTATION DES AGENTS EST CONTRADICTOIRE : l'agent note consulte son
 *     appreciation et peut y repondre, sans que le superieur puisse modifier
 *     sa reponse.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Le modele a deux niveaux du cadrage v1 laisse la place.
        Schema::dropIfExists('rapports_centre');
        Schema::dropIfExists('rapports_site');

        // ------------------------------------------------------------------
        // Table mere : ce qui est commun aux trois rapports
        // ------------------------------------------------------------------
        Schema::create('rapports_journaliers', function (Blueprint $table) {
            $table->id();
            $table->char('uuid_client', 36)->unique()->comment('Idempotence hors ligne');

            $table->enum('type', ['aopk', 'opk', 'superviseur'])
                ->comment('Le niveau du rapport dans la chaine de visas');
            $table->date('date_rapport');

            // Bloc d'identification : PRE-REMPLI depuis l'affectation en cours.
            // L'agent ne saisit aucun element d'identification, il les verifie.
            $table->foreignId('auteur_volontaire_id')->constrained('volontaires')->restrictOnDelete();
            $table->foreignId('affectation_id')->nullable()->constrained('affectations')->nullOnDelete();
            $table->foreignId('vague_id')->constrained('vagues_deploiement')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('centre_id')->constrained('centres')->restrictOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('tournee_site_id')->nullable()->constrained('tournees_site')->nullOnDelete();

            // Le superieur qui doit viser ce rapport, fige a la soumission.
            $table->foreignId('superieur_volontaire_id')->nullable()
                ->constrained('volontaires')->nullOnDelete();
            $table->foreignId('superieur_user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Renseigne quand le viseur n\'est pas un volontaire : controleur terrain');

            // Section 9.3 : le superviseur indique son CARV de rattachement.
            // Le CARV n'est pas modelise comme entite tant que le client n'a pas
            // dit s'il s'agit d'un lieu ou d'une personne : texte libre en attendant.
            $table->string('carv_rattachement', 120)->nullable();

            $table->enum('statut', ['brouillon', 'soumis', 'vise', 'rejete', 'clos'])
                ->default('brouillon');

            // Section 9.1 D : presence declaree par l'agent dans son rapport.
            $table->time('heure_arrivee')->nullable();
            $table->time('heure_depart')->nullable();

            $table->timestamp('horodatage_telephone')->nullable()
                ->comment('Date de l\'action, fait foi (cadrage 11.7)');
            $table->timestamp('soumis_le')->nullable();
            $table->timestamp('vise_le')->nullable();
            $table->text('motif_rejet')->nullable()->comment('Obligatoire lors d\'un rejet');
            $table->boolean('est_fictif')->default(false);
            $table->timestamps();

            // UN rapport par auteur, par type et par jour.
            $table->unique(['auteur_volontaire_id', 'type', 'date_rapport'], 'uq_rapport_auteur_jour');
            $table->index(['centre_id', 'date_rapport']);
            $table->index(['site_id', 'date_rapport']);
            $table->index(['region_id', 'date_rapport']);
            $table->index(['type', 'statut']);
            $table->index(['superieur_volontaire_id', 'statut']);
        });

        // ------------------------------------------------------------------
        // Signature et visa : la trace de chaque transition
        // ------------------------------------------------------------------
        Schema::create('rapport_visas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->enum('acte', ['signature', 'visa', 'rejet', 'cloture', 'reouverture']);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role_tenu', 60)->comment('Role au moment de l\'acte, fige');
            $table->text('commentaire')->nullable()->comment('Motif obligatoire pour un rejet');

            // Chaque transition enregistre l'auteur, la date, l'heure ET la position.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('horodatage_telephone')->nullable();
            $table->timestamp('effectue_le');

            $table->index(['rapport_id', 'effectue_le']);
            $table->index('acte');
        });

        // ------------------------------------------------------------------
        // Tracabilite des corrections de valeurs PRE-REMPLIES
        // ------------------------------------------------------------------
        Schema::create('rapport_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->string('champ', 80)->comment('Champ corrige, en notation pointee');
            $table->string('valeur_origine', 255)->nullable()->comment('Ce que le pre-remplissage avait calcule');
            $table->string('valeur_corrigee', 255)->nullable();
            $table->text('motif')->comment('Obligatoire : on ne corrige pas un chiffre agrege sans dire pourquoi');
            $table->foreignId('corrige_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('corrige_le');

            $table->index(['rapport_id', 'champ']);
        });

        // ------------------------------------------------------------------
        // Blocs libres, communs aux trois rapports
        // ------------------------------------------------------------------
        Schema::create('rapport_difficultes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre')->default(1);
            $table->text('difficulte');
            $table->text('solution')->nullable()->comment('Une difficulte sans solution reste recevable');

            $table->index('rapport_id');
        });

        Schema::create('rapport_points_amelioration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre')->default(1);
            $table->text('point');

            $table->index('rapport_id');
        });

        // ------------------------------------------------------------------
        // 9.1 — Rapport A-OPK : l'accueil du site
        // ------------------------------------------------------------------
        // L'A-OPK N'ENREGISTRE PERSONNE (cadrage v2, section 5, acteur 1).
        // Aucune colonne de production ici : justificatifs, plaintes, affluence.
        Schema::create('rapport_aopk_activites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->unique()->constrained('rapports_journaliers')->cascadeOnDelete();

            $table->enum('affluence_prevue', ['faible', 'moyen', 'eleve'])->nullable()
                ->comment('faible 1-25, moyen 25-50, eleve 50-100');
            $table->enum('affluence_realisee', ['faible', 'moyen', 'eleve'])->nullable();

            $table->unsignedInteger('justificatifs_recus_prevu')->nullable();
            $table->unsignedInteger('justificatifs_recus_realise')->nullable();
            $table->unsignedInteger('justificatifs_transmis_prevu')->nullable();
            $table->unsignedInteger('justificatifs_transmis_realise')->nullable();

            // Le cadrage demande de COMPTER les plaintes. Un module de suivi
            // (objet, plaignant, orientation, cloture) serait un autre travail :
            // question 2 restee ouverte.
            $table->unsignedInteger('plaintes_enregistrees_prevu')->nullable();
            $table->unsignedInteger('plaintes_enregistrees_realise')->nullable();
            $table->unsignedInteger('plaintes_reversees_prevu')->nullable();
            $table->unsignedInteger('plaintes_reversees_realise')->nullable();
        });

        // ------------------------------------------------------------------
        // 9.2 — Rapport OPK : la production
        // ------------------------------------------------------------------
        Schema::create('rapport_opk_production', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->unique()->constrained('rapports_journaliers')->cascadeOnDelete();

            // L'objectif est FIGE a la creation du rapport, depuis la vague :
            // changer l'objectif d'une vague ne doit pas reecrire l'ecart des
            // rapports deja signes.
            $table->unsignedInteger('objectif_enregistrements')->nullable();
            $table->unsignedInteger('enregistrements_realises')->default(0)
                ->comment('Donnee DECLAREE : aucune identite de citoyen n\'est stockee');

            $table->unsignedInteger('recepisses_transmis')->default(0);
            $table->unsignedInteger('enregistrements_non_valides')->default(0);
            $table->text('motif_non_valides')->nullable()
                ->comment('Obligatoire des que enregistrements_non_valides > 0');

            $table->enum('etat_kit', ['fonctionnel', 'panne_partielle', 'panne_totale'])
                ->default('fonctionnel');

            // COLONNES CALCULEES : jamais saisissables, jamais divergentes.
            $table->integer('ecart_enregistrements')
                ->storedAs('cast(enregistrements_realises as signed) - cast(coalesce(objectif_enregistrements, 0) as signed)');
            $table->decimal('taux_realisation', 6, 2)
                ->storedAs('if(coalesce(objectif_enregistrements, 0) = 0, null, round(enregistrements_realises * 100.0 / objectif_enregistrements, 2))')
                ->nullable();
        });

        // ------------------------------------------------------------------
        // 9.3 — Rapport superviseur de centre
        // ------------------------------------------------------------------
        Schema::create('rapport_sup_evolution', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->unique()->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->unsignedInteger('personnes_enregistrees')->default(0)->comment('PRE-REMPLI depuis les rapports OPK vises');
            $table->unsignedInteger('dossiers_valides')->default(0);
            $table->unsignedInteger('dossiers_a_reprendre')->default(0);
        });

        Schema::create('rapport_sup_qualite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->unique()->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->unsignedInteger('dossiers_controles')->default(0);
            $table->unsignedInteger('dossiers_conformes')->default(0);
            $table->unsignedInteger('dossiers_non_conformes')->default(0);
            $table->unsignedInteger('doublons_detectes')->default(0);
            $table->unsignedInteger('erreurs_saisie')->default(0);
            $table->unsignedInteger('corrections_effectuees')->default(0);
            $table->unsignedSmallInteger('incidents_majeurs')->default(0);

            // Taux de conformite : CALCULE (cadrage 9.3 E).
            $table->decimal('taux_conformite', 6, 2)
                ->storedAs('if(dossiers_controles = 0, null, round(dossiers_conformes * 100.0 / dossiers_controles, 2))')
                ->nullable();
        });

        // Une ligne par ressource, lignes ajoutables (cadrage 9.3 F).
        Schema::create('rapport_sup_logistique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->string('ressource', 80)->comment('kits, tablettes, consommables, connexion, electricite, autre');
            $table->unsignedSmallInteger('disponible')->default(0);
            $table->unsignedSmallInteger('fonctionnelle')->default(0);
            $table->unsignedSmallInteger('besoin')->default(0);
            $table->string('observation', 255)->nullable();
            $table->unsignedSmallInteger('ordre')->default(1);

            $table->index('rapport_id');
        });

        // ------------------------------------------------------------------
        // Suivi des equipes et appreciations individuelles
        // ------------------------------------------------------------------
        Schema::create('rapport_suivi_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapport_id')->constrained('rapports_journaliers')->cascadeOnDelete();
            $table->foreignId('volontaire_id')->constrained('volontaires')->cascadeOnDelete()
                ->comment('L\'agent apprecie');
            $table->enum('categorie_agent', ['aopk', 'opk'])
                ->comment('Le rapport du superviseur separe les deux tableaux');

            // La presence vient de la FEUILLE DE PRESENCE du jour : elle n'est
            // pas ressaisie (cadrage 9, regles transverses).
            $table->foreignId('ligne_presence_id')->nullable()
                ->constrained('lignes_presence')->nullOnDelete();
            $table->enum('presence', ['present', 'absent', 'absent_justifie'])->nullable();

            $table->enum('production', ['passable', 'peu_satisfaisant', 'satisfaisant'])->nullable();
            $table->json('anomalies')->nullable()
                ->comment('retard, absenteisme, propos_discourtois, autre');
            $table->text('observation')->nullable()->comment('Observation du superieur');

            // Journalisation d'une modification APRES visa (cadrage, notation).
            $table->timestamp('modifie_apres_visa_le')->nullable();
            $table->foreignId('modifie_par')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['rapport_id', 'volontaire_id'], 'uq_suivi_rapport_agent');
            $table->index(['volontaire_id', 'created_at']);
        });

        /*
         * DROIT DE REPONSE (cadrage v2, section 9, encadrement de la notation).
         *
         * « Une anomalie signalee doit permettre a l'agent d'ajouter une
         * OBSERVATION en reponse, horodatee, NON MODIFIABLE PAR LE SUPERIEUR. »
         *
         * La table est donc separee de rapport_suivi_agents : le superieur ecrit
         * dans l'une, l'agent dans l'autre, et aucune Policy ne donne au
         * superieur le droit d'ecrire ici.
         */
        Schema::create('appreciations_reponses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suivi_agent_id')->constrained('rapport_suivi_agents')->cascadeOnDelete();
            $table->foreignId('volontaire_id')->constrained('volontaires')->cascadeOnDelete()
                ->comment('L\'agent qui repond : lui seul');
            $table->text('reponse');
            $table->timestamp('repondu_le');
            $table->timestamp('lu_par_superieur_le')->nullable();

            $table->index('suivi_agent_id');
            $table->index('volontaire_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appreciations_reponses');
        Schema::dropIfExists('rapport_suivi_agents');
        Schema::dropIfExists('rapport_sup_logistique');
        Schema::dropIfExists('rapport_sup_qualite');
        Schema::dropIfExists('rapport_sup_evolution');
        Schema::dropIfExists('rapport_opk_production');
        Schema::dropIfExists('rapport_aopk_activites');
        Schema::dropIfExists('rapport_points_amelioration');
        Schema::dropIfExists('rapport_difficultes');
        Schema::dropIfExists('rapport_corrections');
        Schema::dropIfExists('rapport_visas');
        Schema::dropIfExists('rapports_journaliers');
    }
};
