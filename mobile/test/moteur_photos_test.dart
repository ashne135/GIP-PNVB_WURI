import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:volontaires/api/client_api.dart';
import 'package:volontaires/donnees/depot_file.dart';
import 'package:volontaires/sync/moteur_synchronisation.dart';

import 'doublures.dart';

/// LES PHOTOS PARTENT APRÈS LEUR FICHE (cadrage, section 11.8).
///
/// Une photo ne part qu'une fois sa fiche acceptée ; elle est mise à part si sa
/// fiche est refusée ; elle reste en file si le serveur dit de réessayer ; et
/// elle quitte le téléphone une fois reçue.
void main() {
  late Database base;
  late DepotFile file;
  late Directory dossier;

  setUp(() async {
    base = await ouvrirBaseDeTest();
    file = DepotFile(base);
    dossier = await Directory.systemTemp.createTemp('pnvb_photos_');
  });

  tearDown(() async {
    await base.close();
    await dossier.delete(recursive: true);
  });

  Future<String> photoSurLeTelephone() async {
    final chemin = '${dossier.path}${Platform.pathSeparator}constat-${DateTime.now().microsecondsSinceEpoch}.jpg';
    await File(chemin).writeAsBytes(List<int>.filled(2048, 0xFF));

    return chemin;
  }

  Future<ElementFile> ficheAvecPhoto(String chemin) async {
    final incident = await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {'recit': 'Tablette tombée.'});
    await file.ajouter(
      utilisateurId: 7,
      type: 'photo',
      nature: DepotFile.fichier,
      dependDe: incident.uuidClient,
      contenu: {'chemin_local': chemin, 'role': 'preuve_incident'},
    );

    return incident;
  }

  MoteurSynchronisation moteur(MockClient serveur) => MoteurSynchronisation(
        api: ClientApi(urlBase: 'https://pnvb.test/api/v1', jeton: () async => 'jeton', client: serveur),
        file: file,
      );

  http.Response lotAccepte(http.Request requete) {
    final elements = ((jsonDecode(requete.body) as Map)['elements'] as List).cast<Map>();

    return reponseServeur(200, donnees: {
      'acceptes': [
        for (final element in elements) {'uuid_client': element['uuid_client'], 'action': 'cree'},
      ],
      'rejetes': <Object>[],
    });
  }

  test("envoie la photo après sa fiche, puis l'efface du téléphone", () async {
    final chemin = await photoSurLeTelephone();
    await ficheAvecPhoto(chemin);

    final appels = <String>[];

    final bilan = await moteur(MockClient((requete) async {
      appels.add(requete.url.path);

      if (requete.url.path.endsWith('/sync/types')) {
        return reponseServeur(200, donnees: {'max_elements_par_lot': 200});
      }

      if (requete.url.path.endsWith('/sync/fichiers')) {
        return reponseServeur(201, message: 'Photo enregistrée.', donnees: {'action': 'cree'});
      }

      return lotAccepte(requete);
    })).envoyer(7);

    expect(bilan.issue, IssueEnvoi.termine);
    expect(bilan.acceptes, 2);
    // La fiche d'abord, la photo ensuite.
    expect(appels.where((a) => !a.endsWith('/sync/types')), ['/api/v1/sync', '/api/v1/sync/fichiers']);
    expect((await file.compter(7)).enAttente, 0);
    expect(File(chemin).existsSync(), isFalse);
  });

  test("met la photo à part quand sa fiche a été refusée, sans l'envoyer", () async {
    final chemin = await photoSurLeTelephone();
    await ficheAvecPhoto(chemin);

    var photosEnvoyees = 0;

    final bilan = await moteur(MockClient((requete) async {
      if (requete.url.path.endsWith('/sync/types')) {
        return reponseServeur(200, donnees: {'max_elements_par_lot': 200});
      }

      if (requete.url.path.endsWith('/sync/fichiers')) {
        photosEnvoyees++;

        return reponseServeur(201);
      }

      final element = ((jsonDecode(requete.body) as Map)['elements'] as List).first as Map;

      return reponseServeur(200, donnees: {
        'acceptes': <Object>[],
        'rejetes': [
          {'uuid_client': element['uuid_client'], 'code': 'donnees_invalides', 'motif': 'Récit trop court.', 'reessayer': false},
        ],
      });
    })).envoyer(7);

    final rejetes = await file.rejetes(7);

    expect(photosEnvoyees, 0);
    expect(bilan.rejetes, 2);
    expect(rejetes.map((e) => e.codeRejet), containsAll(['donnees_invalides', 'fiche_refusee']));
  });

  test("garde la photo quand le serveur dit de réessayer, sans bloquer la fiche", () async {
    final chemin = await photoSurLeTelephone();
    await ficheAvecPhoto(chemin);

    final bilan = await moteur(MockClient((requete) async {
      if (requete.url.path.endsWith('/sync/types')) {
        return reponseServeur(200, donnees: {'max_elements_par_lot': 200});
      }

      if (requete.url.path.endsWith('/sync/fichiers')) {
        return reponseServeur(409, message: "L'incident n'est pas encore arrivé.", donnees: {'code': 'introuvable_serveur', 'reessayer': true});
      }

      return lotAccepte(requete);
    })).envoyer(7);

    final photos = await file.fichiersAEnvoyer(7, limite: 10);

    expect(bilan.issue, IssueEnvoi.partiel);
    expect(bilan.acceptes, 1, reason: 'la fiche est partie');
    expect(photos.single.tentatives, 1);
    expect(File(chemin).existsSync(), isTrue, reason: 'la photo reste sur le téléphone');
  });
}
