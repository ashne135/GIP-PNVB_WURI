import 'dart:convert';

import 'package:sqflite_sqlcipher/sqflite.dart';
import 'package:uuid/uuid.dart';

import '../outils/horodatage.dart';

/// Un élément de la file, tel qu'il attend son envoi.
class ElementFile {
  const ElementFile({
    required this.id,
    required this.uuidClient,
    required this.utilisateurId,
    required this.type,
    required this.contenu,
    required this.horodatageAction,
    required this.nature,
    required this.etat,
    required this.tentatives,
    this.dependDe,
    this.codeRejet,
    this.motifRejet,
  });

  factory ElementFile.depuisLigne(Map<String, Object?> ligne) => ElementFile(
        id: ligne['id']! as int,
        uuidClient: ligne['uuid_client']! as String,
        utilisateurId: ligne['utilisateur_id']! as int,
        type: ligne['type']! as String,
        contenu: (jsonDecode(ligne['contenu']! as String) as Map).cast<String, dynamic>(),
        horodatageAction: ligne['horodatage_action']! as String,
        nature: ligne['nature']! as String,
        etat: ligne['etat']! as String,
        tentatives: ligne['tentatives']! as int,
        dependDe: ligne['depend_de'] as String?,
        codeRejet: ligne['code_rejet'] as String?,
        motifRejet: ligne['motif_rejet'] as String?,
      );

  final int id;
  final String uuidClient;
  final int utilisateurId;

  /// Le type synchronisable : signal_arrivee, rapport_journalier, incident…
  /// Pour une photo : « photo ».
  final String type;
  final Map<String, dynamic> contenu;

  /// L'heure du geste, avec son décalage (cadrage, section 11.7).
  final String horodatageAction;

  /// « donnees » part d'abord ; « fichier » — les photos — après (section 11.8).
  final String nature;

  /// L'élément dont celui-ci dépend : une photo attend que sa fiche soit acceptée.
  final String? dependDe;
  final String etat;
  final int tentatives;
  final String? codeRejet;
  final String? motifRejet;

  /// L'élément tel que POST /sync l'attend : son contenu, plus ce que le moteur
  /// du serveur exige. Le type synchronisable passe en dernier : aucune clé du
  /// contenu ne peut l'écraser.
  Map<String, dynamic> versLot() => {
        ...contenu,
        'uuid_client': uuidClient,
        'horodatage_action': horodatageAction,
        'type': type,
      };
}

/// Ce que contient la file d'un compte.
class CompteFile {
  const CompteFile({required this.enAttente, required this.rejetes});

  static const CompteFile vide = CompteFile(enAttente: 0, rejetes: 0);

  final int enAttente;
  final int rejetes;
}

/// LA FILE D'ATTENTE LOCALE (cadrage, section 11, points 1 à 3 et 6).
///
/// ÉCRIRE D'ABORD, ENVOYER ENSUITE. Un élément ajouté est déjà en sécurité sur
/// le téléphone : l'écran le confirme sans attendre le réseau, et l'agent
/// continue son travail.
///
/// Seuls les éléments ACCEPTÉS par le serveur quittent la file. Un refus
/// définitif est mis à part — gardé et visible, jamais renvoyé en boucle.
class DepotFile {
  DepotFile(this._base, {Uuid? uuid, DateTime Function()? horloge})
      : _uuid = uuid ?? const Uuid(),
        _horloge = horloge ?? DateTime.now;

  final Database _base;
  final Uuid _uuid;
  final DateTime Function() _horloge;

  static const String enAttente = 'en_attente';
  static const String rejete = 'rejete';
  static const String donnees = 'donnees';
  static const String fichier = 'fichier';

  /// Ajoute un élément à la file.
  ///
  /// [uuidClient] est fourni quand l'élément désigne une fiche qui existe déjà
  /// — le rapport du jour, une feuille de présence : il porte alors l'uuid de
  /// cette fiche. Une nouvelle saisie de la même fiche REMPLACE la version qui
  /// attendait encore : c'est la dernière saisie qui part.
  Future<ElementFile> ajouter({
    required int utilisateurId,
    required String type,
    required Map<String, dynamic> contenu,
    String nature = donnees,
    String? dependDe,
    String? uuidClient,
  }) async {
    final maintenant = horodatageIso(_horloge());
    final uuid = uuidClient ?? _uuid.v4();

    if (uuidClient != null) {
      await _base.delete(
        'file_attente',
        where: 'uuid_client = ? AND utilisateur_id = ?',
        whereArgs: [uuid, utilisateurId],
      );
    }

    final id = await _base.insert('file_attente', {
      'uuid_client': uuid,
      'utilisateur_id': utilisateurId,
      'type': type,
      'contenu': jsonEncode(contenu),
      'horodatage_action': maintenant,
      'nature': nature,
      'depend_de': dependDe,
      'etat': enAttente,
      'tentatives': 0,
      'cree_le': maintenant,
    });

    return ElementFile(
      id: id,
      uuidClient: uuid,
      utilisateurId: utilisateurId,
      type: type,
      contenu: contenu,
      horodatageAction: maintenant,
      nature: nature,
      etat: enAttente,
      tentatives: 0,
      dependDe: dependDe,
    );
  }

  /// Les données à envoyer, DANS L'ORDRE OÙ ELLES ONT ÉTÉ FAITES : un rapport
  /// est saisi avant d'être visé. Les photos ne sont pas prises ici — elles
  /// partent après les données texte (section 11.8).
  Future<List<ElementFile>> aEnvoyer(int utilisateurId, {required int limite, Set<String> sauf = const {}}) =>
      _enAttente(utilisateurId, donnees, limite: limite, sauf: sauf);

  /// Les photos à envoyer, dans l'ordre où elles ont été prises.
  Future<List<ElementFile>> fichiersAEnvoyer(int utilisateurId, {required int limite, Set<String> sauf = const {}}) =>
      _enAttente(utilisateurId, fichier, limite: limite, sauf: sauf);

  Future<List<ElementFile>> _enAttente(int utilisateurId, String nature, {required int limite, required Set<String> sauf}) async {
    final lignes = await _base.query(
      'file_attente',
      where: 'utilisateur_id = ? AND etat = ? AND nature = ?',
      whereArgs: [utilisateurId, enAttente, nature],
      orderBy: 'id',
      limit: limite + sauf.length,
    );

    return lignes
        .map(ElementFile.depuisLigne)
        .where((element) => !sauf.contains(element.uuidClient))
        .take(limite)
        .toList();
  }

  /// L'état d'une fiche dans la file : « en_attente », « rejete », ou null si
  /// elle n'y est plus — c'est-à-dire acceptée par le serveur.
  Future<String?> etatDe(int utilisateurId, String uuidClient) async {
    final lignes = await _base.query(
      'file_attente',
      columns: ['etat'],
      where: 'utilisateur_id = ? AND uuid_client = ?',
      whereArgs: [utilisateurId, uuidClient],
      limit: 1,
    );

    return lignes.isEmpty ? null : lignes.first['etat'] as String?;
  }

  /// Les éléments acceptés quittent la file, désignés par leur uuid.
  Future<void> retirer(Iterable<String> uuids) => _supprimerOu('uuid_client', uuids.toList());

  /// Les éléments acceptés quittent la file, désignés par leur ligne.
  ///
  /// Le moteur retire par LIGNE, pas par uuid : si l'agent a enregistré une
  /// nouvelle version du rapport pendant l'envoi de l'ancienne, cette nouvelle
  /// version porte le même uuid, et elle ne doit pas disparaître avec l'accusé
  /// de l'ancienne.
  Future<void> retirerIds(Iterable<int> ids) => _supprimerOu('id', ids.toList());

  Future<void> _supprimerOu(String colonne, List<Object> valeurs) async {
    if (valeurs.isEmpty) {
      return;
    }

    await _base.delete(
      'file_attente',
      where: '$colonne IN (${List.filled(valeurs.length, '?').join(', ')})',
      whereArgs: valeurs,
    );
  }

  /// Une tentative sans succès, qui vaut la peine d'être refaite plus tard.
  Future<void> noterTentative(int id) async {
    await _base.rawUpdate(
      'UPDATE file_attente SET tentatives = tentatives + 1, derniere_tentative_le = ? WHERE id = ?',
      [horodatageIso(_horloge()), id],
    );
  }

  /// Un refus que le temps ne résoudra pas : mis à part, avec son motif.
  Future<void> marquerRejete(int id, {required String code, required String motif}) async {
    await _base.rawUpdate(
      'UPDATE file_attente SET etat = ?, code_rejet = ?, motif_rejet = ?, tentatives = tentatives + 1, '
      'derniere_tentative_le = ? WHERE id = ?',
      [rejete, code, motif, horodatageIso(_horloge()), id],
    );
  }

  Future<CompteFile> compter(int utilisateurId) async {
    final lignes = await _base.rawQuery(
      'SELECT etat, COUNT(*) AS nombre FROM file_attente WHERE utilisateur_id = ? GROUP BY etat',
      [utilisateurId],
    );

    int nombre(String etat) =>
        (lignes.where((ligne) => ligne['etat'] == etat).firstOrNull?['nombre'] as int?) ?? 0;

    return CompteFile(enAttente: nombre(enAttente), rejetes: nombre(rejete));
  }

  Future<List<ElementFile>> rejetes(int utilisateurId) async {
    final lignes = await _base.query(
      'file_attente',
      where: 'utilisateur_id = ? AND etat = ?',
      whereArgs: [utilisateurId, rejete],
      orderBy: 'id',
    );

    return lignes.map(ElementFile.depuisLigne).toList();
  }

  /// La trace d'un envoi, pour dire à l'agent quand sa file est partie.
  Future<void> noterEnvoi(
    int utilisateurId, {
    required String issue,
    required int acceptes,
    required int rejetes,
    String? message,
  }) async {
    await _base.insert('envois', {
      'utilisateur_id': utilisateurId,
      'tente_le': horodatageIso(_horloge()),
      'issue': issue,
      'nb_acceptes': acceptes,
      'nb_rejetes': rejetes,
      'message': message,
    });
  }

  /// Le dernier envoi qui a réellement joint le serveur.
  Future<DateTime?> dernierEnvoiReussi(int utilisateurId) async {
    final lignes = await _base.query(
      'envois',
      columns: ['tente_le'],
      where: "utilisateur_id = ? AND issue IN ('termine', 'partiel')",
      whereArgs: [utilisateurId],
      orderBy: 'id DESC',
      limit: 1,
    );

    return lignes.isEmpty ? null : DateTime.parse(lignes.first['tente_le']! as String);
  }
}
