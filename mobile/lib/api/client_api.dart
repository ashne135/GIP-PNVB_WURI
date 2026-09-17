import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../configuration.dart';

/// La réponse d'une action qui a réussi : le message du serveur, et ses données.
class ReponseApi {
  const ReponseApi({required this.message, this.donnees});

  final String message;
  final dynamic donnees;
}

/// UNE ERREUR D'API, NORMALISÉE.
///
/// Elle porte le message français du serveur — la seule chose lisible pour un
/// agent quand quelque chose refuse — et dit de quel refus il s'agit : pas de
/// réseau, session perdue, accès fermé, ou champ mal rempli.
class ErreurApi implements Exception {
  ErreurApi({
    required this.message,
    required this.statut,
    this.erreurs = const {},
    this.donnees,
  });

  final String message;

  /// 0 quand le serveur n'a pas répondu du tout.
  final int statut;
  final Map<String, List<String>> erreurs;
  final dynamic donnees;

  bool get estHorsLigne => statut == 0;

  /// Le jeton a expiré ou a été révoqué : c'est le seul cas où l'on déconnecte.
  bool get estSessionPerdue => statut == 401;

  bool get estValidation => statut == 422 && erreurs.isNotEmpty;

  String? get actionRequise => donnees is Map ? donnees['action_requise'] as String? : null;

  /// Le jeton d'un accès fermé n'envoie plus que la file.
  bool get estAccesFerme => statut == 403 && actionRequise == 'acces_ferme';

  /// Le serveur dit explicitement de réessayer plus tard.
  bool get estAReessayer => donnees is Map && donnees['reessayer'] == true;

  String? erreurDuChamp(String champ) => erreurs[champ]?.first;

  @override
  String toString() => message;
}

typedef LecteurJeton = Future<String?> Function();

/// LE CLIENT DE L'API.
///
/// Le serveur répond toujours la même enveloppe :
/// `{ success: bool, message: string, data: object|array|null }`.
/// Ce client la défait une fois pour toutes.
class ClientApi {
  ClientApi({
    required this.urlBase,
    required LecteurJeton jeton,
    http.Client? client,
    Duration delai = Configuration.delaiReseau,
  })  : _jeton = jeton,
        _client = client ?? http.Client(),
        _delai = delai;

  final String urlBase;
  final LecteurJeton _jeton;
  final http.Client _client;
  final Duration _delai;

  static const String messageHorsLigne =
      'Le serveur est injoignable. Vérifiez votre connexion, puis réessayez.';

  /// Rend directement `data`.
  Future<dynamic> lire(String chemin) async {
    final requete = http.Request('GET', Uri.parse('$urlBase$chemin'));

    return (await _executer(requete)).donnees;
  }

  /// Rend le message du serveur avec les données : il est rédigé pour l'agent.
  Future<ReponseApi> envoyer(String chemin, [Map<String, dynamic>? corps]) {
    final requete = http.Request('POST', Uri.parse('$urlBase$chemin'))
      ..headers['Content-Type'] = 'application/json; charset=utf-8';

    if (corps != null) {
      requete.body = jsonEncode(corps);
    }

    return _executer(requete);
  }

  /// Envoie un fichier — une photo — avec ses champs d'accompagnement.
  Future<ReponseApi> envoyerFichier(
    String chemin, {
    required Map<String, String> champs,
    required String cheminFichier,
    String nomChamp = 'fichier',
  }) async {
    final requete = http.MultipartRequest('POST', Uri.parse('$urlBase$chemin'))..fields.addAll(champs);
    requete.files.add(await http.MultipartFile.fromPath(nomChamp, cheminFichier));

    return _executer(requete);
  }

  Future<ReponseApi> _executer(http.BaseRequest requete) async {
    requete.headers['Accept'] = 'application/json';

    final jeton = await _jeton();

    if (jeton != null) {
      requete.headers['Authorization'] = 'Bearer $jeton';
    }

    final http.Response reponse;

    try {
      reponse = await Future(() async => http.Response.fromStream(await _client.send(requete)))
          .timeout(_delai);
    } on TimeoutException {
      throw ErreurApi(message: messageHorsLigne, statut: 0);
    } on SocketException {
      throw ErreurApi(message: messageHorsLigne, statut: 0);
    } on http.ClientException {
      throw ErreurApi(message: messageHorsLigne, statut: 0);
    }

    final enveloppe = _decoder(reponse);
    final message = (enveloppe['message'] as String?) ?? '';

    if (reponse.statusCode >= 200 && reponse.statusCode < 300) {
      return ReponseApi(message: message, donnees: enveloppe['data']);
    }

    final donnees = enveloppe['data'];

    throw ErreurApi(
      message: message.isEmpty ? 'Une erreur est survenue.' : message,
      statut: reponse.statusCode,
      erreurs: _erreursDeChamps(donnees),
      donnees: donnees,
    );
  }

  Map<String, dynamic> _decoder(http.Response reponse) {
    try {
      final corps = jsonDecode(utf8.decode(reponse.bodyBytes));

      return corps is Map<String, dynamic> ? corps : const {};
    } on FormatException {
      // Une page d'erreur d'un intermédiaire (proxy, portail Wi-Fi) n'est pas du JSON.
      return const {};
    }
  }

  Map<String, List<String>> _erreursDeChamps(dynamic donnees) {
    if (donnees is! Map || donnees['erreurs'] is! Map) {
      return const {};
    }

    return (donnees['erreurs'] as Map).map(
      (champ, messages) => MapEntry(
        champ.toString(),
        messages is List ? messages.map((m) => m.toString()).toList() : [messages.toString()],
      ),
    );
  }
}
