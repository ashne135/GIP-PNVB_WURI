import 'dart:io';

import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as chemins;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

/// LA PRISE DE PHOTO SUR LE TERRAIN (cadrage, section 11.8).
///
/// La photo est compressée DÈS LA PRISE : 1 200 px de large, qualité réduite,
/// soit environ 200 Ko. Elle est rangée dans le dossier privé de l'application,
/// où elle attend son envoi, puis effacée une fois reçue par le serveur.
///
/// LIMITE CONNUE : la base locale est chiffrée, mais le fichier de la photo ne
/// l'est pas. Il est protégé par le cloisonnement d'Android — aucune autre
/// application ne le lit — et ne vit que le temps de l'envoi.
class ServicePhotos {
  ServicePhotos({ImagePicker? appareil}) : _appareil = appareil ?? ImagePicker();

  final ImagePicker _appareil;

  static const double largeurMaximale = 1200;
  static const int qualite = 70;

  /// Prend une photo ; rend son chemin sur le téléphone, ou null si l'agent a
  /// renoncé.
  Future<String?> prendre() async {
    final photo = await _appareil.pickImage(
      source: ImageSource.camera,
      maxWidth: largeurMaximale,
      imageQuality: qualite,
    );

    if (photo == null) {
      return null;
    }

    final dossier = Directory(chemins.join((await getApplicationDocumentsDirectory()).path, 'photos'));
    await dossier.create(recursive: true);

    final destination = chemins.join(dossier.path, '${const Uuid().v4()}.jpg');
    await File(photo.path).copy(destination);

    try {
      await File(photo.path).delete();
    } on FileSystemException {
      // Le fichier temporaire de l'appareil photo peut déjà avoir disparu.
    }

    return destination;
  }

  /// Efface une photo que l'agent a retirée avant d'enregistrer sa fiche.
  Future<void> effacer(String chemin) async {
    try {
      await File(chemin).delete();
    } on FileSystemException {
      // Rien à effacer.
    }
  }
}
