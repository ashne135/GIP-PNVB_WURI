import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:volontaires/donnees/base_locale.dart';
import 'package:volontaires/donnees/coffre.dart';

/// Un coffre en mémoire : le vrai n'existe que sur un téléphone.
class CoffreMemoire implements Coffre {
  final Map<String, String> valeurs = {};

  @override
  Future<String?> lire(String cle) async => valeurs[cle];

  @override
  Future<void> ecrire(String cle, String valeur) async => valeurs[cle] = valeur;

  @override
  Future<void> effacer(String cle) async => valeurs.remove(cle);
}

/// La base de l'application, avec son vrai schéma, en mémoire et sans
/// chiffrement : SQLCipher n'existe que sur le téléphone.
Future<Database> ouvrirBaseDeTest() async {
  sqfliteFfiInit();

  return databaseFactoryFfi.openDatabase(
    inMemoryDatabasePath,
    options: OpenDatabaseOptions(
      version: BaseLocale.version,
      singleInstance: false,
      onCreate: (base, _) => BaseLocale.creerSchema(base),
    ),
  );
}

/// Une réponse du serveur, dans son enveloppe constante.
http.Response reponseServeur(int statut, {String message = '', Object? donnees}) => http.Response(
      jsonEncode({'success': statut < 400, 'message': message, 'data': donnees}),
      statut,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
