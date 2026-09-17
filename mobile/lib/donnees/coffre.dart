import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// LE COFFRE DU TÉLÉPHONE : la clé de la base et le jeton de session.
///
/// Ni l'un ni l'autre ne va dans la base SQLite — une clé ne se range pas dans
/// ce qu'elle chiffre — ni dans des préférences en clair. Le coffre Android
/// (Keystore) les garde, et ils ne quittent jamais l'appareil.
abstract class Coffre {
  Future<String?> lire(String cle);

  Future<void> ecrire(String cle, String valeur);

  Future<void> effacer(String cle);
}

/// Les noms rangés dans le coffre.
class CleCoffre {
  const CleCoffre._();

  static const String cleBase = 'pnvb.cle_base';
  static const String jeton = 'pnvb.jeton';
  static const String utilisateur = 'pnvb.utilisateur_id';
}

class CoffreSecurise implements Coffre {
  CoffreSecurise([FlutterSecureStorage? stockage])
      : _stockage = stockage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _stockage;

  @override
  Future<String?> lire(String cle) => _stockage.read(key: cle);

  @override
  Future<void> ecrire(String cle, String valeur) => _stockage.write(key: cle, value: valeur);

  @override
  Future<void> effacer(String cle) => _stockage.delete(key: cle);
}
