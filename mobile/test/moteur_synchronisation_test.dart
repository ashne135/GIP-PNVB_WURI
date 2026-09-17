import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:volontaires/api/client_api.dart';
import 'package:volontaires/donnees/depot_file.dart';
import 'package:volontaires/sync/moteur_synchronisation.dart';

import 'doublures.dart';

/// LE MOTEUR D'ENVOI.
///
/// Ne retirer que ce que le serveur a accepté ; mettre à part ce qu'il refuse
/// pour de bon ; garder ce qui vaut une nouvelle tentative ; ne rien perdre
/// sans réseau ; ne pas dépasser la taille de lot que le serveur annonce.
void main() {
  late Database base;
  late DepotFile file;

  setUp(() async {
    base = await ouvrirBaseDeTest();
    file = DepotFile(base);
  });

  tearDown(() => base.close());

  MoteurSynchronisation moteur(MockClient serveur) => MoteurSynchronisation(
        api: ClientApi(urlBase: 'https://pnvb.test/api/v1', jeton: () async => 'jeton', client: serveur),
        file: file,
      );

  /// Un serveur qui annonce sa taille de lot, et répond à chaque lot selon [decider].
  MockClient serveur({
    int tailleLot = 200,
    required Map<String, dynamic> Function(List<Map<String, dynamic>> elements) decider,
    List<List<Map<String, dynamic>>>? lotsRecus,
  }) =>
      MockClient((requete) async {
        if (requete.url.path.endsWith('/sync/types')) {
          return reponseServeur(200, donnees: {'types': ['incident'], 'max_elements_par_lot': tailleLot});
        }

        final elements = ((jsonDecode(requete.body) as Map)['elements'] as List).cast<Map<String, dynamic>>();
        lotsRecus?.add(elements);

        return reponseServeur(200, message: 'Synchronisation terminée.', donnees: decider(elements));
      });

  test("retire les acceptés, met à part les refus définitifs et garde les autres pour plus tard", () async {
    await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {'titre': 'accepté'});
    await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {'titre': 'invalide'});
    await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {'titre': 'panne serveur'});

    final lots = <List<Map<String, dynamic>>>[];

    final bilan = await moteur(serveur(
      lotsRecus: lots,
      decider: (elements) => {
        'acceptes': [
          {'rang': 0, 'uuid_client': elements[0]['uuid_client'], 'type': 'incident', 'id': 1, 'action': 'cree'},
        ],
        'rejetes': [
          {'rang': 1, 'uuid_client': elements[1]['uuid_client'], 'code': 'donnees_invalides', 'motif': 'Incomplet', 'reessayer': false},
          {'rang': 2, 'uuid_client': elements[2]['uuid_client'], 'code': 'erreur_serveur', 'motif': 'Panne', 'reessayer': true},
        ],
      },
    )).envoyer(7);

    expect(bilan.issue, IssueEnvoi.partiel);
    expect(bilan.acceptes, 1);
    expect(bilan.rejetes, 1);
    expect(bilan.aReessayer, 1);

    final compte = await file.compter(7);
    expect(compte.enAttente, 1, reason: "l'élément en panne serveur reste en file");
    expect(compte.rejetes, 1, reason: "l'élément invalide est mis à part");

    // Un seul lot : l'élément à retenter n'est pas renvoyé dans la même seconde.
    expect(lots, hasLength(1));
    expect(lots.single.first['horodatage_action'], isNotNull);
    expect(lots.single.first['titre'], 'accepté');
  });

  test('ne perd rien sans réseau', () async {
    await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});

    final horsLigne = MockClient((_) async => throw http.ClientException('Network is unreachable'));
    final bilan = await moteur(horsLigne).envoyer(7);

    expect(bilan.issue, IssueEnvoi.horsLigne);
    expect((await file.compter(7)).enAttente, 1);
  });

  test('découpe la file à la taille de lot annoncée par le serveur', () async {
    for (var i = 0; i < 5; i++) {
      await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {'rang': i});
    }

    final lots = <List<Map<String, dynamic>>>[];

    final bilan = await moteur(serveur(
      tailleLot: 2,
      lotsRecus: lots,
      decider: (elements) => {
        'acceptes': [
          for (final (rang, element) in elements.indexed)
            {'rang': rang, 'uuid_client': element['uuid_client'], 'type': 'incident', 'id': rang, 'action': 'cree'},
        ],
        'rejetes': <Object>[],
      },
    )).envoyer(7);

    expect(bilan.issue, IssueEnvoi.termine);
    expect(bilan.acceptes, 5);
    expect(lots.map((lot) => lot.length), [2, 2, 1]);
    // L'ordre des gestes est respecté d'un lot à l'autre.
    expect(lots.expand((lot) => lot).map((e) => e['rang']), [0, 1, 2, 3, 4]);
    expect((await file.compter(7)).enAttente, 0);
  });

  test("ne touche pas au réseau quand il n'y a rien à envoyer", () async {
    var appels = 0;

    final bilan = await moteur(MockClient((_) async {
      appels++;

      return reponseServeur(200);
    })).envoyer(7);

    expect(bilan.issue, IssueEnvoi.rienAEnvoyer);
    expect(appels, 0);
  });

  test("n'envoie que la file du compte connecté", () async {
    await file.ajouter(utilisateurId: 8, type: 'incident', contenu: {});

    final bilan = await moteur(serveur(decider: (_) => fail('aucun lot ne doit partir'))).envoyer(7);

    expect(bilan.issue, IssueEnvoi.rienAEnvoyer);
    expect((await file.compter(8)).enAttente, 1);
  });

  test("s'arrête sur une session perdue, sans rien effacer", () async {
    await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});

    final bilan = await moteur(MockClient((_) async => reponseServeur(
          401,
          message: 'Vous devez être connecté pour faire cette action.',
        ))).envoyer(7);

    expect(bilan.issue, IssueEnvoi.sessionPerdue);
    expect((await file.compter(7)).enAttente, 1);
  });
}
