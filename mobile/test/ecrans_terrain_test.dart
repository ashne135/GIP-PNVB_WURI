import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:volontaires/donnees/coffre.dart';
import 'package:volontaires/donnees/depot_profil.dart';
import 'package:volontaires/donnees/depot_travail.dart';
import 'package:volontaires/ecrans/ecran_accueil.dart';
import 'package:volontaires/ecrans/ecran_alertes.dart';
import 'package:volontaires/ecrans/ecran_feuilles_presence.dart';
import 'package:volontaires/ecrans/ecran_rapport.dart';
import 'package:volontaires/ecrans/ecran_signal_arrivee.dart';
import 'package:volontaires/l10n/textes.dart';
import 'package:volontaires/services/service_position.dart';
import 'package:volontaires/session/controleur_session.dart';
import 'package:volontaires/theme.dart';

import 'doublures.dart';

/// LES ÉCRANS DU TERRAIN, SANS RÉSEAU.
///
/// Ces tests vérifient ce qui compte pour un agent au milieu d'un site sans
/// couverture : l'écran s'ouvre sur les données gardées, le geste est confirmé
/// tout de suite, et ce qu'il a fait attend dans la file — avec l'heure et la
/// position du geste, pas celles de l'envoi.
class PositionFixe extends ServicePosition {
  const PositionFixe();

  @override
  Future<PositionTelephone> relever() async =>
      const PositionTelephone(latitude: 12.36, longitude: -1.53, precisionMetres: 8);
}

void main() {
  final horsLigne = MockClient((_) async => throw http.ClientException('Network is unreachable'));

  // L'état du réseau vient d'un plugin, absent d'une machine de test : il est
  // doublé, sinon chaque écran signalerait un canal introuvable.
  setUp(() {
    final messagerie = TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;

    messagerie.setMockMethodCallHandler(
      const MethodChannel('dev.fluttercommunity.plus/connectivity'),
      (_) async => ['wifi'],
    );
    messagerie.setMockStreamHandler(
      const EventChannel('dev.fluttercommunity.plus/connectivity_status'),
      MockStreamHandler.inline(onListen: (_, sink) => sink.success(['wifi'])),
    );
  });

  Map<String, dynamic> profil({
    required List<String> permissions,
    bool bloquerHorsZone = false,
    bool siteLocalise = false,
  }) => {
        'utilisateur': {'id': 7, 'nom_complet': 'Awa Ouédraogo', 'statut_compte': 'actif'},
        'volontaire': {'id': 3, 'matricule': 'PNVB-OPK000001', 'categorie_libelle': 'Volontaire opérateur de kit'},
        'permissions': permissions,
        'perimetre': {
          'site_du_jour': {
            'id': 5,
            'code': 'BAN-BAGA-C001-S01',
            'nom': 'Site 1',
            // Loin de la position rendue par PositionFixe : hors zone.
            if (siteLocalise) 'latitude': 11.0,
            if (siteLocalise) 'longitude': -1.5,
            'rayon_zone_metres': 500,
          },
        },
        'reglages': {'bloquer_signal_hors_zone': bloquerHorsZone},
        'actions_requises': {'changer_mot_de_passe': false, 'accepter_charte': false},
      };

  Future<ControleurSession> ouvrirSession(Map<String, dynamic> donnees) async {
    final base = await ouvrirBaseDeTest();
    final coffre = CoffreMemoire()
      ..valeurs[CleCoffre.jeton] = 'jeton'
      ..valeurs[CleCoffre.utilisateur] = '7';

    await DepotProfil(base).enregistrer(7, donnees);

    final session = ControleurSession(coffre: coffre, base: base, urlApi: 'https://pnvb.test/api/v1', client: horsLigne);
    await session.demarrer();

    return session;
  }

  Widget ecran(Widget enfant) => MaterialApp(
        locale: const Locale('fr'),
        supportedLocales: Textes.supportedLocales,
        localizationsDelegates: Textes.localizationsDelegates,
        theme: themePnvb(),
        home: enfant,
      );

  /// Affiche un écran et laisse les lectures de la base locale se terminer :
  /// elles sont réellement asynchrones, comme sur le téléphone.
  Future<void> afficher(WidgetTester tester, Widget enfant) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(ecran(enfant));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
    await tester.pumpAndSettle();
  }

  Future<void> laisserFaire(WidgetTester tester) async {
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
    await tester.pumpAndSettle();
  }

  Future<void> toucher(WidgetTester tester, Finder cible) async {
    // Un écran du terrain est long : ce qui n'est pas à l'image n'existe pas
    // encore dans l'arbre, il faut y faire défiler l'écran d'abord.
    if (cible.evaluate().isEmpty) {
      await tester.scrollUntilVisible(cible, 300, scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
    }

    await tester.ensureVisible(cible);
    await tester.pumpAndSettle();
    await tester.tap(cible);
    await laisserFaire(tester);
  }

  testWidgets("ne propose à l'accueil que les écrans permis par les droits du compte", (tester) async {
    final session = await tester.runAsync(() => ouvrirSession(profil(permissions: [
          'presence.signaler_arrivee',
          'rapports.saisir',
          'rapports.viser',
          'incidents.declarer',
          'alertes.consulter',
          'appreciations.consulter_les_miennes',
        ])));

    await afficher(tester, EcranAccueil(session: session!));

    expect(find.text('Je suis arrivé / Je pars'), findsOneWidget);
    expect(find.text('Mon rapport du jour'), findsOneWidget);
    expect(find.text('Rapports à viser'), findsOneWidget);
    expect(find.text('Déclarer un incident'), findsOneWidget);

    // Sans le droit, l'écran n'est pas proposé — et le serveur refuserait de
    // toute façon ce que l'interface promettrait.
    expect(find.text('Feuilles de présence'), findsNothing);
    // Le kit appartient à l'agent : sans kit détenu, rien à déclarer.
    expect(find.text('Mon kit'), findsNothing);
  });

  testWidgets('enregistre le signal d’arrivée avec sa position et confirme tout de suite', (tester) async {
    final session = await tester.runAsync(() => ouvrirSession(profil(permissions: ['presence.signaler_arrivee'])));

    await afficher(tester, EcranSignalArrivee(session: session!, position: const PositionFixe()));
    await toucher(tester, find.text('JE SUIS ARRIVÉ'));

    expect(find.textContaining('Arrivée enregistrée à'), findsOneWidget);

    final file = await tester.runAsync(() => session.file.aEnvoyer(7, limite: 10));
    final signal = file!.single;

    expect(signal.type, 'signal_arrivee');
    expect(signal.contenu['type_signal'], 'arrivee');
    expect(signal.contenu['latitude'], 12.36);
    expect(signal.contenu['site_id'], 5);
    // L'heure retenue est celle du téléphone au moment du geste.
    expect(signal.contenu['horodatage_telephone'], isNotNull);
  });

  testWidgets('refuse le signal hors zone quand le dispositif le bloque, sans rien mettre dans la file', (tester) async {
    final session = await tester.runAsync(() => ouvrirSession(profil(
          permissions: ['presence.signaler_arrivee'],
          bloquerHorsZone: true,
          siteLocalise: true,
        )));

    await afficher(tester, EcranSignalArrivee(session: session!, position: const PositionFixe()));
    await toucher(tester, find.text('JE SUIS ARRIVÉ'));

    expect(find.textContaining('au-delà de sa zone'), findsOneWidget);
    expect(find.textContaining('prévenez votre superviseur'), findsOneWidget);

    // RIEN n'entre dans la file : l'agent l'apprend maintenant, et non des
    // heures plus tard en découvrant son signal rejeté.
    expect(await tester.runAsync(() => session.file.aEnvoyer(7, limite: 10)), isEmpty);
  });

  testWidgets('signale quand même hors zone quand le blocage est inactif, en annonçant l’écart', (tester) async {
    final session = await tester.runAsync(() => ouvrirSession(profil(
          permissions: ['presence.signaler_arrivee'],
          siteLocalise: true,
        )));

    await afficher(tester, EcranSignalArrivee(session: session!, position: const PositionFixe()));
    await toucher(tester, find.text('JE SUIS ARRIVÉ'));

    // L'agent signale — c'est le cadrage — et l'écart est annoncé, pas caché.
    expect(find.textContaining('Arrivée enregistrée à'), findsOneWidget);
    expect(find.textContaining('verra cet écart'), findsOneWidget);

    final file = await tester.runAsync(() => session.file.aEnvoyer(7, limite: 10));
    expect(file!.single.type, 'signal_arrivee');
  });

  testWidgets('refuse de valider une feuille dont tous les agents ne sont pas marqués', (tester) async {
    final session = await tester.runAsync(() async {
      final session = await ouvrirSession(profil(permissions: ['presence.valider_feuille']));
      await session.travail.ecrire(7, CleTravail.feuillesDuJour, [feuilleDuJour]);

      return session;
    });

    await afficher(tester, EcranFeuillePresence(session: session!, feuille: feuilleDuJour, position: const PositionFixe()));
    await toucher(tester, find.text('Valider la feuille'));

    expect(find.text('Marquez la présence de chaque agent avant de valider.'), findsOneWidget);
    expect(await tester.runAsync(() => session.file.aEnvoyer(7, limite: 10)), isEmpty);
  });

  testWidgets('valide une feuille de présence hors ligne, avec la position du superviseur', (tester) async {
    final session = await tester.runAsync(() async {
      final session = await ouvrirSession(profil(permissions: ['presence.valider_feuille']));
      await session.travail.ecrire(7, CleTravail.feuillesDuJour, [feuilleDuJour]);

      return session;
    });

    await afficher(tester, EcranFeuillePresence(session: session!, feuille: feuilleDuJour, position: const PositionFixe()));

    expect(find.text('Présent'), findsNWidgets(2));
    await toucher(tester, find.text('Présent').at(0));
    await toucher(tester, find.text('Présent').at(1));

    await toucher(tester, find.text('Valider la feuille'));
    // La validation est définitive : elle est confirmée avant d'être enregistrée.
    await toucher(tester, find.descendant(of: find.byType(AlertDialog), matching: find.text('Valider la feuille')));

    final file = await tester.runAsync(() => session.file.aEnvoyer(7, limite: 10));
    final feuille = file!.single;

    expect(feuille.type, 'feuille_presence');
    expect(feuille.uuidClient, feuilleDuJour['uuid_client']);
    expect(feuille.contenu['site_id'], 5);
    expect(feuille.contenu['latitude'], 12.36);
    expect(feuille.contenu['lignes'], [
      {'volontaire_id': 9, 'statut': 'present'},
      {'volontaire_id': 10, 'statut': 'present'},
    ]);

    // Le superviseur la retrouve signée, en attendant qu'elle parte.
    final gardees = await tester.runAsync(() => session.travail.liste(7, CleTravail.feuillesDuJour));
    expect(gardees!.single['statut'], 'validee');
    expect(gardees.single['en_attente_envoi'], isTrue);
  });

  testWidgets('enregistre le rapport du jour sans le signer, sur les chiffres saisis', (tester) async {
    final session = await tester.runAsync(() async {
      final session = await ouvrirSession(profil(permissions: ['rapports.saisir']));
      await session.travail.ecrire(7, CleTravail.rapportDuJour, rapportOpk);

      return session;
    });

    await afficher(tester, EcranRapport(session: session!, position: const PositionFixe()));

    expect(find.text('Objectif du jour : 60'), findsOneWidget);

    await tester.enterText(find.widgetWithText(TextField, 'Enregistrements réalisés'), '42');
    await tester.pumpAndSettle();
    await toucher(tester, find.text('Enregistrer sans signer'));

    final file = await tester.runAsync(() => session.file.aEnvoyer(7, limite: 10));
    final rapport = file!.single;

    expect(rapport.type, 'rapport_journalier');
    expect(rapport.uuidClient, rapportOpk['uuid_client']);
    expect((rapport.contenu['production'] as Map)['enregistrements_realises'], 42);
    expect(rapport.contenu['soumettre'], isFalse);
    // Rien de calculé n'est saisi : ni l'écart, ni le taux de réalisation.
    expect((rapport.contenu['production'] as Map).containsKey('ecart_enregistrements'), isFalse);
    expect((rapport.contenu['production'] as Map).containsKey('taux_realisation'), isFalse);
  });

  testWidgets('accuse la lecture d’une alerte sans réseau, et ne la redit pas non lue', (tester) async {
    final session = await tester.runAsync(() async {
      final session = await ouvrirSession(profil(permissions: ['alertes.consulter']));
      await session.travail.ecrire(7, CleTravail.alertes, [
        {'id': 4, 'titre': 'Rupture de fiches', 'message': 'Le centre manque de fiches.', 'niveau': 'important', 'lue': false},
      ]);

      return session;
    });

    await afficher(tester, EcranAlertes(session: session!));

    expect(find.text('Non lue'), findsOneWidget);

    await toucher(tester, find.text('J’ai lu cette alerte'));

    final file = await tester.runAsync(() => session.file.aEnvoyer(7, limite: 10));
    expect(file!.single.type, 'lecture_alerte');
    expect(file.single.contenu['alerte_id'], 4);

    expect(find.text('Lue'), findsOneWidget);
    expect(find.text('Non lue'), findsNothing);
  });
}

final Map<String, dynamic> feuilleDuJour = {
  'id': 3,
  'uuid_client': '6f0f2d7e-1c8b-4f0e-9d3a-8a1b2c3d4e5f',
  'site_id': 5,
  'statut': 'brouillon',
  'date_presence': '2026-09-16T00:00:00.000000Z',
  'site': {'id': 5, 'code': 'BAN-BAGA-C001-S01', 'nom': 'Site 1'},
  'lignes': [
    {
      'volontaire_id': 9,
      'categorie': 'operateur',
      'statut': 'absent',
      'heure_arrivee_signalee': '2026-09-16T07:42:00.000000Z',
      'distance_signalee': 80,
      'dans_zone': true,
      'volontaire': {'matricule': 'PNVB-OPK000001', 'user': {'nom': 'Ouédraogo', 'prenoms': 'Awa'}},
    },
    {
      'volontaire_id': 10,
      'categorie': 'assistant',
      'statut': 'absent',
      'volontaire': {'matricule': 'PNVB-AOPK00002', 'user': {'nom': 'Sawadogo', 'prenoms': 'Issa'}},
    },
  ],
};

final Map<String, dynamic> rapportOpk = {
  'id': 11,
  'uuid_client': 'c1f0a2b3-4d5e-6f70-8192-a3b4c5d6e7f8',
  'type': 'opk',
  'statut': 'brouillon',
  'date_rapport': '2026-09-16T00:00:00.000000Z',
  'centre': {'code': 'BAN-BAGA-C001', 'nom': 'Centre Bagassi'},
  'site': {'code': 'BAN-BAGA-C001-S01', 'nom': 'Site 1'},
  'production_opk': {
    'objectif_enregistrements': 60,
    'enregistrements_realises': 0,
    'recepisses_transmis': 0,
    'enregistrements_non_valides': 0,
    'etat_kit': 'fonctionnel',
  },
  'suivi_agents': [],
  'difficultes': [],
  'points_amelioration': [],
};
