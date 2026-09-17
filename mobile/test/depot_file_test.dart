import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:volontaires/donnees/depot_file.dart';

import 'doublures.dart';

/// LA FILE D'ATTENTE LOCALE.
///
/// Écrire d'abord, envoyer ensuite ; ne retirer que ce que le serveur a
/// accepté ; ne jamais mélanger la file de deux comptes.
void main() {
  late Database base;
  late DepotFile file;

  setUp(() async {
    base = await ouvrirBaseDeTest();
    file = DepotFile(base);
  });

  tearDown(() => base.close());

  test("garde l'élément sur le téléphone dès l'ajout, avec son uuid et l'heure du geste", () async {
    final element = await file.ajouter(
      utilisateurId: 7,
      type: 'signal_arrivee',
      contenu: {'type_signal': 'arrivee', 'latitude': 12.37},
    );

    expect(element.uuidClient, matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$')));
    expect(element.horodatageAction, matches(RegExp(r'[+-]\d\d:\d\d$')));
    expect((await file.compter(7)).enAttente, 1);
  });

  test("rend les éléments dans l'ordre où ils ont été faits, et seulement ceux du compte", () async {
    final rapport = await file.ajouter(utilisateurId: 7, type: 'rapport_journalier', contenu: {});
    await file.ajouter(utilisateurId: 8, type: 'incident', contenu: {});
    final visa = await file.ajouter(utilisateurId: 7, type: 'visa_rapport', contenu: {});

    final aEnvoyer = await file.aEnvoyer(7, limite: 10);

    expect(aEnvoyer.map((e) => e.uuidClient), [rapport.uuidClient, visa.uuidClient]);
  });

  test("met l'élément au format du lot : son contenu, son uuid, l'heure du geste et son type", () async {
    final element = await file.ajouter(
      utilisateurId: 7,
      type: 'mouvement_kit',
      // Une clé « type » dans le contenu ne doit jamais écraser le type synchronisable.
      contenu: {'type_mouvement': 'restitution', 'type': 'piege'},
    );

    final lot = element.versLot();

    expect(lot['type'], 'mouvement_kit');
    expect(lot['type_mouvement'], 'restitution');
    expect(lot['uuid_client'], element.uuidClient);
    expect(lot['horodatage_action'], element.horodatageAction);
  });

  test('retire les acceptés et met à part les refus définitifs, sans les renvoyer', () async {
    final accepte = await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});
    final refuse = await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});
    final aRetenter = await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});

    await file.retirer([accepte.uuidClient]);
    await file.marquerRejete(refuse.id, code: 'donnees_invalides', motif: 'Incomplet');
    await file.noterTentative(aRetenter.id);

    final compte = await file.compter(7);
    final restants = await file.aEnvoyer(7, limite: 10);
    final rejetes = await file.rejetes(7);

    expect(compte.enAttente, 1);
    expect(compte.rejetes, 1);
    expect(restants.single.uuidClient, aRetenter.uuidClient);
    expect(restants.single.tentatives, 1);
    expect(rejetes.single.motifRejet, 'Incomplet');
  });

  test("laisse de côté ce qu'on lui demande d'écarter, sans réduire la taille du lot", () async {
    final premier = await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});
    await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});
    await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});

    final lot = await file.aEnvoyer(7, limite: 2, sauf: {premier.uuidClient});

    expect(lot, hasLength(2));
    expect(lot.map((e) => e.uuidClient), isNot(contains(premier.uuidClient)));
  });

  test('une nouvelle saisie de la même fiche remplace la version qui attendait encore', () async {
    const uuidRapport = '0b7c3c1e-5b1a-4f7e-9a51-2d6f0e8c1a11';

    await file.ajouter(utilisateurId: 7, type: 'rapport_journalier', uuidClient: uuidRapport, contenu: {'heure_arrivee': '07:40'});
    await file.ajouter(utilisateurId: 7, type: 'rapport_journalier', uuidClient: uuidRapport, contenu: {'heure_arrivee': '07:45', 'soumettre': true});

    final enFile = await file.aEnvoyer(7, limite: 10);

    expect(enFile, hasLength(1));
    expect(enFile.single.contenu['heure_arrivee'], '07:45');
  });

  test("sépare les photos des données, et dit si la fiche d'une photo attend encore", () async {
    final incident = await file.ajouter(utilisateurId: 7, type: 'incident', contenu: {});
    await file.ajouter(
      utilisateurId: 7,
      type: 'photo',
      nature: DepotFile.fichier,
      dependDe: incident.uuidClient,
      contenu: {'chemin_local': '/photos/a.jpg', 'role': 'preuve_incident'},
    );

    expect((await file.aEnvoyer(7, limite: 10)).map((e) => e.type), ['incident']);
    expect((await file.fichiersAEnvoyer(7, limite: 10)).map((e) => e.type), ['photo']);
    expect(await file.etatDe(7, incident.uuidClient), DepotFile.enAttente);

    await file.retirerIds([incident.id]);

    expect(await file.etatDe(7, incident.uuidClient), isNull);
  });
}
