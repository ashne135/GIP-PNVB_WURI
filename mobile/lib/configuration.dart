/// La configuration de l'application, fixée À LA COMPILATION.
///
/// L'adresse de l'API n'est jamais écrite en dur pour la production : elle est
/// passée par `--dart-define=URL_API=https://…` au moment du build (tâche 19).
/// La valeur par défaut vise le serveur de développement vu depuis l'émulateur
/// Android, où 10.0.2.2 désigne le poste qui l'héberge.
class Configuration {
  const Configuration._();

  static const String urlApi = String.fromEnvironment(
    'URL_API',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// Cadrage, section 11.4 : la file part toutes les 5 minutes tant que
  /// l'application est ouverte.
  static const Duration intervalleApplicationOuverte = Duration(minutes: 5);

  /// Application fermée : Android n'autorise pas une tâche de fond périodique
  /// plus fréquente que 15 minutes (décision du 14 septembre 2026).
  static const Duration intervalleApplicationFermee = Duration(minutes: 15);

  /// Taille d'un lot tant que le serveur ne l'a pas annoncée. Ce n'est pas un
  /// seuil métier : dès le premier contact, c'est la valeur du serveur
  /// (paramètre sync.max_elements_par_lot) qui s'applique.
  static const int tailleLotProvisoire = 50;

  /// Au-delà, le réseau est considéré comme absent : l'agent n'attend pas.
  static const Duration delaiReseau = Duration(seconds: 30);

  /// Le nom sous lequel ce téléphone apparaît dans les jetons du compte.
  static const String nomAppareil = 'mobile-android';
}
