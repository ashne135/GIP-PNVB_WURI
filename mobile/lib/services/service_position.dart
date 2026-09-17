import 'dart:async';

import 'package:geolocator/geolocator.dart';

class PositionTelephone {
  const PositionTelephone({required this.latitude, required this.longitude, this.precisionMetres});

  final double latitude;
  final double longitude;
  final int? precisionMetres;
}

/// La position n'a pas pu être relevée : le message dit à l'agent quoi faire.
class PositionIndisponible implements Exception {
  const PositionIndisponible(this.message);

  final String message;

  @override
  String toString() => message;
}

/// LA POSITION DU TÉLÉPHONE, AU MOMENT D'UN GESTE.
///
/// Relevée UNIQUEMENT quand l'agent agit — il signale son arrivée, le
/// superviseur valide une feuille — et jamais en arrière-plan : les relevés de
/// rapprochement (section 8.4) sont une autre affaire, encadrée par la charte.
///
/// Le GPS fonctionne sans réseau : un site sans couverture n'empêche pas de
/// signaler son arrivée.
class ServicePosition {
  const ServicePosition();

  Future<PositionTelephone> relever() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const PositionIndisponible(
        'La localisation du téléphone est désactivée. Activez-la dans les réglages, puis réessayez.',
      );
    }

    var permission = await Geolocator.checkPermission();

    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      throw const PositionIndisponible(
        "L'application n'a pas l'autorisation d'utiliser la position. Autorisez-la dans les réglages du téléphone.",
      );
    }

    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 30),
        ),
      );

      return PositionTelephone(
        latitude: position.latitude,
        longitude: position.longitude,
        precisionMetres: position.accuracy.round(),
      );
    } on TimeoutException {
      throw const PositionIndisponible(
        'La position n’a pas pu être trouvée. Placez-vous à découvert quelques instants, puis réessayez.',
      );
    }
  }
}
