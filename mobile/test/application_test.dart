import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:volontaires/application.dart';
import 'package:volontaires/donnees/coffre.dart';
import 'package:volontaires/donnees/depot_file.dart';
import 'package:volontaires/donnees/depot_profil.dart';
import 'package:volontaires/outils/itineraire.dart';
import 'package:volontaires/session/controleur_session.dart';

import 'doublures.dart';

/// L'APPLICATION ENTIÈRE, AFFICHÉE.
///
/// Ces tests existent parce que l'application a été livrée avec un thème qui
/// faisait échouer l'affichage dès le premier écran, sur le téléphone : aucun
/// test n'affichait l'application complète, avec son thème et ses textes.
void main() {
  _testsItineraire();

  final horsLigne = MockClient((_) async => throw http.ClientException('Network is unreachable'));

  const profil = {
    'utilisateur': {
      'id': 7,
      'nom_complet': 'Awa Ouédraogo',
      'statut_compte': 'actif',
      'statut_compte_libelle': 'Actif',
    },
    'volontaire': {'matricule': 'PNVB-OPK000001', 'categorie_libelle': 'Volontaire opérateur de kit'},
    'perimetre': {
      'affectation_courante': {
        'vague': {'libelle': 'Vague Bankui'},
        'centre': {'code': 'BAN-BAGA-C001', 'nom': 'Centre Bagassi'},
      },
      'site_du_jour': {'code': 'BAN-BAGA-C001-S01', 'nom': 'Site 1', 'kit_present_aujourdhui': true},
    },
    'actions_requises': {'changer_mot_de_passe': false, 'accepter_charte': false},
  };

  void grandEcran(WidgetTester tester) {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.reset);
  }

  testWidgets("affiche l'écran de connexion au premier lancement, avec le thème de l'application", (tester) async {
    grandEcran(tester);

    final session = await tester.runAsync(() async {
      final session = ControleurSession(
        coffre: CoffreMemoire(),
        base: await ouvrirBaseDeTest(),
        urlApi: 'https://pnvb.test/api/v1',
        client: horsLigne,
      );
      await session.demarrer();

      return session;
    });

    await tester.pumpWidget(ApplicationPnvb(ouvrirSession: () async => session!));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('Se connecter'), findsOneWidget);
    expect(find.text('Numéro de téléphone'), findsOneWidget);
  });

  testWidgets("ouvre l'accueil sans réseau, avec la mission et le travail en attente", (tester) async {
    grandEcran(tester);

    final session = await tester.runAsync(() async {
      final base = await ouvrirBaseDeTest();
      final coffre = CoffreMemoire()
        ..valeurs[CleCoffre.jeton] = 'jeton'
        ..valeurs[CleCoffre.utilisateur] = '7';

      await DepotProfil(base).enregistrer(7, profil);
      await DepotFile(base).ajouter(utilisateurId: 7, type: 'incident', contenu: {});

      final session = ControleurSession(coffre: coffre, base: base, urlApi: 'https://pnvb.test/api/v1', client: horsLigne);
      await session.demarrer();
      // Le rafraîchissement du profil, lancé en arrière-plan, échoue hors ligne.
      await Future<void>.delayed(const Duration(milliseconds: 50));

      return session;
    });

    await tester.pumpWidget(ApplicationPnvb(ouvrirSession: () async => session!));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('Bonjour Awa Ouédraogo'), findsOneWidget);
    expect(find.text('Site du jour : BAN-BAGA-C001-S01 — Site 1'), findsOneWidget);
    expect(find.text('1 élément attend d’être envoyé.'), findsOneWidget);
    expect(find.text('Envoyer maintenant'), findsOneWidget);
  });

  testWidgets("dit que le démarrage a échoué au lieu d'un écran noir, et permet de réessayer", (tester) async {
    grandEcran(tester);

    var essais = 0;

    await tester.pumpWidget(ApplicationPnvb(ouvrirSession: () async {
      essais++;

      throw StateError('coffre illisible');
    }));
    await tester.pumpAndSettle();

    expect(find.text('L’application n’a pas pu démarrer'), findsOneWidget);

    await tester.tap(find.text('Réessayer'));
    await tester.pumpAndSettle();

    expect(essais, 2);
  });
}

/// L'ITINÉRAIRE VERS UN SITE (demande du client, 18/09/2026).
///
/// Ce qu'on protège : sans coordonnées exploitables, aucun trajet n'est
/// proposé — envoyer un agent vers un point approximatif est pire que de ne
/// rien proposer du tout.
void _testsItineraire() {
  group('l’itinéraire vers un site', () {
    test('lit les coordonnées, quel que soit le format rendu par l’API', () {
      expect(Itineraire.coordonnees({'latitude': 11.9456, 'longitude': -3.0021}), (11.9456, -3.0021));
      // L'API sérialise les décimaux en chaînes : le cas doit passer aussi.
      expect(Itineraire.coordonnees({'latitude': '11.9456', 'longitude': '-3.0021'}), (11.9456, -3.0021));
    });

    test('ne propose rien sans coordonnées utilisables', () {
      expect(Itineraire.coordonnees(null), isNull);
      expect(Itineraire.coordonnees(const {}), isNull);
      expect(Itineraire.coordonnees(const {'latitude': null, 'longitude': null}), isNull);
      expect(Itineraire.coordonnees(const {'latitude': 'abc', 'longitude': 'def'}), isNull);
      // 0,0 : un point de l'Atlantique, jamais un site du Burkina Faso.
      expect(Itineraire.coordonnees(const {'latitude': 0, 'longitude': 0}), isNull);
    });
  });
}
