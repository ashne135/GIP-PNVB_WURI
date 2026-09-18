import 'package:url_launcher/url_launcher.dart';

/// L'ITINÉRAIRE VERS UN SITE (demande du client, 18/09/2026).
///
/// Un site d'enregistrement se trouve le plus souvent dans un village sans
/// adresse : pour s'y rendre — une prise de poste, une visite de suivi — le
/// seul repère utilisable est le couple de coordonnées.
///
/// CE QU'ON N'ENVOIE PAS : la position de l'agent, ni son historique. On passe
/// une DESTINATION à l'application de cartes, qui calcule le trajet chez elle.
/// La plateforme, elle, n'enregistre rien de ce déplacement.
///
/// Sans coordonnées, aucun lien n'est fabriqué : un trajet vers un point
/// approximatif enverrait l'agent à des kilomètres du bon village.
class Itineraire {
  const Itineraire._();

  /// Les coordonnées d'un site telles que le profil les rend, ou null.
  static (double, double)? coordonnees(Map<String, dynamic>? site) {
    final latitude = _nombre(site?['latitude']);
    final longitude = _nombre(site?['longitude']);

    if (latitude == null || longitude == null) {
      return null;
    }

    // 0,0 est un point de l'Atlantique : jamais un site du Burkina Faso.
    if (latitude == 0 && longitude == 0) {
      return null;
    }

    return (latitude, longitude);
  }

  /// Ouvre la navigation vers le site. Rend false si rien n'a pu s'ouvrir.
  static Future<bool> ouvrir(double latitude, double longitude) async {
    // D'abord la navigation native d'Android : elle démarre le guidage tout
    // de suite, sans passer par une page.
    final navigation = Uri.parse('google.navigation:q=$latitude,$longitude');

    if (await canLaunchUrl(navigation)) {
      if (await launchUrl(navigation, mode: LaunchMode.externalApplication)) {
        return true;
      }
    }

    // Repli : l'adresse web des cartes, que le navigateur sait toujours
    // ouvrir — y compris sur un téléphone sans Google Maps installé.
    final web = Uri.parse(
      'https://www.google.com/maps/dir/?api=1&destination=$latitude,$longitude&travelmode=driving',
    );

    return launchUrl(web, mode: LaunchMode.externalApplication);
  }

  static double? _nombre(Object? valeur) {
    if (valeur is num) {
      return valeur.toDouble();
    }

    if (valeur is String) {
      return double.tryParse(valeur);
    }

    return null;
  }
}
