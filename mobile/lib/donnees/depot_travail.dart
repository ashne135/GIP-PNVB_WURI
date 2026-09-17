import 'dart:convert';

import 'package:sqflite_sqlcipher/sqflite.dart';

import '../outils/horodatage.dart';

/// Une donnée de travail gardée sur le téléphone, avec sa date.
class DonneeTravail {
  const DonneeTravail(this.contenu, this.misAJourLe);

  final dynamic contenu;
  final DateTime misAJourLe;
}

/// Les clés des données de travail.
class CleTravail {
  const CleTravail._();

  static const String canevasIncident = 'canevas_incident';
  static const String alertes = 'alertes';
  static const String kits = 'kits';
  static const String appreciations = 'appreciations';
  static const String rapportDuJour = 'rapport_du_jour';
  static const String rapportsAViser = 'rapports_a_viser';
  static const String feuillesDuJour = 'feuilles_du_jour';
}

/// LES DONNÉES DE TRAVAIL DU COMPTE, GARDÉES POUR TRAVAILLER SANS RÉSEAU.
///
/// Le serveur prépare la journée — rapport pré-rempli, feuilles de présence,
/// canevas d'incident. Le téléphone les garde, chiffrées, et les écrans
/// s'ouvrent dessus même au milieu d'un site sans couverture.
class DepotTravail {
  DepotTravail(this._base, {DateTime Function()? horloge}) : _horloge = horloge ?? DateTime.now;

  final Database _base;
  final DateTime Function() _horloge;

  Future<void> ecrire(int utilisateurId, String cle, Object? contenu) async {
    await _base.insert(
      'donnees_travail',
      {
        'utilisateur_id': utilisateurId,
        'cle': cle,
        'contenu': jsonEncode(contenu),
        'mis_a_jour_le': horodatageIso(_horloge()),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<DonneeTravail?> lire(int utilisateurId, String cle) async {
    final lignes = await _base.query(
      'donnees_travail',
      where: 'utilisateur_id = ? AND cle = ?',
      whereArgs: [utilisateurId, cle],
      limit: 1,
    );

    if (lignes.isEmpty) {
      return null;
    }

    return DonneeTravail(
      jsonDecode(lignes.first['contenu']! as String),
      DateTime.parse(lignes.first['mis_a_jour_le']! as String),
    );
  }

  /// Une liste gardée, ou une liste vide si rien n'a encore été préparé.
  Future<List<Map<String, dynamic>>> liste(int utilisateurId, String cle) async {
    final donnee = await lire(utilisateurId, cle);
    final contenu = donnee?.contenu;

    return contenu is List ? contenu.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList() : [];
  }
}
