import 'dart:convert';

import 'package:sqflite_sqlcipher/sqflite.dart';

import '../outils/horodatage.dart';

class ProfilEnCache {
  const ProfilEnCache(this.profil, this.misAJourLe);

  final Map<String, dynamic> profil;
  final DateTime misAJourLe;
}

/// LE PROFIL DU COMPTE GARDÉ SUR LE TÉLÉPHONE.
///
/// C'est ce qui permet d'ouvrir l'accueil SANS RÉSEAU : nom, catégorie, mission
/// et site du jour, tels que le serveur les a rendus la dernière fois. La date
/// de mise à jour est toujours affichée avec : une information ancienne ne
/// doit pas passer pour fraîche.
class DepotProfil {
  DepotProfil(this._base, {DateTime Function()? horloge}) : _horloge = horloge ?? DateTime.now;

  final Database _base;
  final DateTime Function() _horloge;

  Future<void> enregistrer(int utilisateurId, Map<String, dynamic> profil) async {
    await _base.insert(
      'profils',
      {
        'utilisateur_id': utilisateurId,
        'profil': jsonEncode(profil),
        'mis_a_jour_le': horodatageIso(_horloge()),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<ProfilEnCache?> lire(int utilisateurId) async {
    final lignes = await _base.query(
      'profils',
      where: 'utilisateur_id = ?',
      whereArgs: [utilisateurId],
      limit: 1,
    );

    if (lignes.isEmpty) {
      return null;
    }

    return ProfilEnCache(
      (jsonDecode(lignes.first['profil']! as String) as Map).cast<String, dynamic>(),
      DateTime.parse(lignes.first['mis_a_jour_le']! as String),
    );
  }
}
