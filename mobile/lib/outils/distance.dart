import 'dart:math';

/// LA DISTANCE ENTRE L'AGENT ET SON SITE, calculée sur le téléphone.
///
/// ATTENTION À CE QUE CE CALCUL EST, ET N'EST PAS. Il n'est PAS le contrôle :
/// le serveur recalcule la distance à la réception et c'est lui qui accepte ou
/// refuse — un calcul embarqué se contourne, et le cadrage interdit de
/// remplacer un contrôle serveur par un contrôle d'interface.
///
/// Il sert à ne pas laisser l'agent dans l'illusion. Hors ligne, un signal est
/// écrit dans la file PUIS envoyé : sans ce calcul, un agent hors zone croirait
/// avoir signalé son arrivée et ne l'apprendrait qu'au retour du réseau, dans
/// les éléments rejetés. C'est précisément ce qu'il ne faut pas.
///
/// Formule de Haversine, la même que celle du serveur, avec le même rayon
/// terrestre : deux formules différentes donneraient deux distances
/// différentes, et l'agent verrait « 480 m » là où le serveur refuserait.
int? distanceMetres({
  required double latitude,
  required double longitude,
  double? latitudeSite,
  double? longitudeSite,
}) {
  if (latitudeSite == null || longitudeSite == null) {
    return null;
  }

  const rayonTerre = 6371000.0;
  final dLat = _enRadians(latitude - latitudeSite);
  final dLon = _enRadians(longitude - longitudeSite);

  final a = pow(sin(dLat / 2), 2) +
      cos(_enRadians(latitudeSite)) * cos(_enRadians(latitude)) * pow(sin(dLon / 2), 2);

  return (rayonTerre * 2 * atan2(sqrt(a), sqrt(1 - a))).round();
}

/// Un nombre du serveur, qui peut arriver en texte comme en nombre.
double? nombreOuNul(Object? valeur) {
  if (valeur == null) {
    return null;
  }

  return valeur is num ? valeur.toDouble() : double.tryParse('$valeur');
}

double _enRadians(double degres) => degres * pi / 180;
