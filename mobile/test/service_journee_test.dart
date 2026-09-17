import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:volontaires/api/client_api.dart';
import 'package:volontaires/donnees/depot_travail.dart';
import 'package:volontaires/travail/service_journee.dart';

import 'doublures.dart';

/// PRÉPARER LA JOURNÉE QUAND LE RÉSEAU EST LÀ.
///
/// Ce que le compte a le droit de faire décide de ce qui est gardé ; un refus
/// sur un bloc n'empêche pas les autres ; sans réseau, rien n'est effacé.
void main() {
  late Database base;
  late DepotTravail depot;

  setUp(() async {
    base = await ouvrirBaseDeTest();
    depot = DepotTravail(base);
  });

  tearDown(() => base.close());

  ServiceJournee service(MockClient serveur) => ServiceJournee(
        api: ClientApi(urlBase: 'https://pnvb.test/api/v1', jeton: () async => 'jeton', client: serveur),
        depot: depot,
      );

  test("garde ce que le compte peut faire, et continue malgré un refus sur un bloc", () async {
    final demandes = <String>[];

    final bilan = await service(MockClient((requete) async {
      demandes.add('${requete.method} ${requete.url.path}');

      return switch (requete.url.path) {
        '/api/v1/incidents/canevas' => reponseServeur(200, donnees: {'natures': [{'id': 1, 'libelle': 'Panne'}]}),
        '/api/v1/rapports/ouvrir' => reponseServeur(422, message: "Vous n'avez pas d'affectation active : aucun rapport ne peut être ouvert."),
        '/api/v1/presence/carte' => reponseServeur(200, donnees: {
            'sites': [{'id': 5, 'code': 'GOU-FADA-C001-S01', 'nom': 'Site 1'}],
            'agents': [{'volontaire_id': 9, 'site_id': 5}, {'volontaire_id': 10, 'site_id': 5}],
          }),
        '/api/v1/sites/5/feuille' => reponseServeur(200, donnees: {
            'id': 3,
            'uuid_client': '6f0f2d7e-1c8b-4f0e-9d3a-8a1b2c3d4e5f',
            'lignes': [{'volontaire_id': 9, 'statut': 'absent'}],
            'date_envoyee': jsonDecode(requete.body)['date'],
          }),
        _ => reponseServeur(404),
      };
    })).preparer(7, {'incidents.declarer', 'rapports.saisir', 'presence.valider_feuille'});

    expect(bilan.horsLigne, isFalse);
    expect(bilan.prepares, containsAll([CleTravail.canevasIncident, CleTravail.feuillesDuJour]));
    expect(bilan.echecs[CleTravail.rapportDuJour], contains("pas d'affectation active"));

    // Ce que le compte ne peut pas faire n'est même pas demandé.
    expect(demandes.any((d) => d.contains('/alertes') || d.contains('/kits')), isFalse);

    // Une seule feuille pour un site qui attend deux agents, avec son site.
    final feuilles = await depot.liste(7, CleTravail.feuillesDuJour);
    expect(feuilles, hasLength(1));
    expect(feuilles.single['site']['code'], 'GOU-FADA-C001-S01');
  });

  test("n'efface rien sans réseau", () async {
    await depot.ecrire(7, CleTravail.canevasIncident, {'natures': []});

    final bilan = await service(MockClient((_) async => throw http.ClientException('Network is unreachable')))
        .preparer(7, {'incidents.declarer'});

    expect(bilan.horsLigne, isTrue);
    expect((await depot.lire(7, CleTravail.canevasIncident))?.contenu, {'natures': []});
  });
}
