import 'dart:io';

import 'package:uuid/uuid.dart';

import '../api/client_api.dart';
import '../configuration.dart';
import '../donnees/depot_file.dart';

enum IssueEnvoi {
  /// La file était vide : aucun appel réseau n'a été fait.
  rienAEnvoyer,

  /// Tout ce qui pouvait partir est parti.
  termine,

  /// Des éléments restent à retenter plus tard (panne serveur, cible pas encore arrivée).
  partiel,
  horsLigne,
  sessionPerdue,
  erreur,

  /// Un autre envoi tournait déjà : on ne double pas les appels.
  dejaEnCours,
}

class BilanEnvoi {
  const BilanEnvoi(
    this.issue, {
    this.acceptes = 0,
    this.rejetes = 0,
    this.aReessayer = 0,
    this.message,
  });

  final IssueEnvoi issue;
  final int acceptes;
  final int rejetes;
  final int aReessayer;
  final String? message;
}

/// LE MOTEUR D'ENVOI DE LA FILE (cadrage, section 11, points 4 à 6 et 8).
///
/// Il applique au pied de la lettre ce que le serveur répond, élément par
/// élément :
///
///   - ACCEPTÉ          → l'élément quitte la file ;
///   - REFUSÉ, à retenter (panne serveur, cible pas encore remontée)
///                      → il reste en file, mais n'est pas renvoyé dans le même
///                        passage : réessayer à la seconde ne changerait rien ;
///   - REFUSÉ, définitif → il est mis à part avec son motif, et n'est plus
///                        jamais renvoyé. Le renvoyer bloquerait la file sans fin.
///
/// LES PHOTOS PARTENT APRÈS LES DONNÉES TEXTE, une par une, et une photo ne
/// part qu'une fois sa fiche acceptée. Une photo qui échoue ne bloque jamais une
/// fiche : elle reste en file, ou elle est mise à part si sa fiche a été refusée.
class MoteurSynchronisation {
  MoteurSynchronisation({required ClientApi api, required DepotFile file, Uuid? uuid})
      : _api = api,
        _file = file,
        _uuid = uuid ?? const Uuid();

  final ClientApi _api;
  final DepotFile _file;
  final Uuid _uuid;

  bool _enCours = false;

  /// Taille maximale d'un lot annoncée par le serveur (GET /sync/types).
  int? _tailleLot;

  bool get enCours => _enCours;

  Future<BilanEnvoi> envoyer(int utilisateurId) async {
    if (_enCours) {
      return const BilanEnvoi(IssueEnvoi.dejaEnCours);
    }

    _enCours = true;

    try {
      return await _envoyer(utilisateurId);
    } finally {
      _enCours = false;
    }
  }

  Future<BilanEnvoi> _envoyer(int utilisateurId) async {
    // File vide : pas un octet sur le réseau. La batterie et le forfait de
    // l'agent comptent autant que la fraîcheur des données.
    if ((await _file.compter(utilisateurId)).enAttente == 0) {
      return const BilanEnvoi(IssueEnvoi.rienAEnvoyer);
    }

    final compteur = _Compteur();

    try {
      await _envoyerDonnees(utilisateurId, compteur);
      await _envoyerPhotos(utilisateurId, compteur);
    } on ErreurApi catch (erreur) {
      final issue = erreur.estHorsLigne
          ? IssueEnvoi.horsLigne
          : erreur.estSessionPerdue
              ? IssueEnvoi.sessionPerdue
              : IssueEnvoi.erreur;

      // Un lot refusé en bloc (422) : la taille maximale a peut-être baissé
      // côté serveur. Elle sera relue au prochain passage.
      if (erreur.statut == 422) {
        _tailleLot = null;
      }

      return _conclure(utilisateurId, compteur.bilan(issue, message: erreur.message));
    }

    return _conclure(
      utilisateurId,
      compteur.bilan(compteur.aRetenter.isEmpty ? IssueEnvoi.termine : IssueEnvoi.partiel),
    );
  }

  Future<void> _envoyerDonnees(int utilisateurId, _Compteur compteur) async {
    final elementsEnFile = await _file.aEnvoyer(utilisateurId, limite: 1);

    if (elementsEnFile.isEmpty) {
      return;
    }

    final taille = await _tailleDuLot();

    while (true) {
      final elements = await _file.aEnvoyer(utilisateurId, limite: taille, sauf: compteur.aRetenter);

      if (elements.isEmpty) {
        break;
      }

      final reponse = await _api.envoyer('/sync', {
        'uuid_lot': _uuid.v4(),
        'elements': elements.map((element) => element.versLot()).toList(),
      });

      final detail = (reponse.donnees as Map).cast<String, dynamic>();
      final traites = <String>{};

      final acceptes = [
        for (final accepte in (detail['acceptes'] as List? ?? const []))
          _elementDe(accepte as Map, elements),
      ].whereType<ElementFile>().toList();

      await _file.retirerIds(acceptes.map((element) => element.id));
      compteur.acceptes += acceptes.length;
      traites.addAll(acceptes.map((element) => element.uuidClient));

      for (final rejet in (detail['rejetes'] as List? ?? const [])) {
        final refus = (rejet as Map).cast<String, dynamic>();
        final element = _elementDe(refus, elements);

        if (element == null) {
          continue;
        }

        traites.add(element.uuidClient);

        if (refus['reessayer'] == true) {
          compteur.aRetenter.add(element.uuidClient);
          await _file.noterTentative(element.id);
        } else {
          compteur.rejetes++;
          await _file.marquerRejete(
            element.id,
            code: (refus['code'] as String?) ?? 'inconnu',
            motif: (refus['motif'] as String?) ?? 'Refusé par le serveur.',
          );
        }
      }

      // Un élément dont la réponse ne parle pas n'est pas perdu : il reste en
      // file, et le prochain passage le reprendra.
      for (final element in elements) {
        if (!traites.contains(element.uuidClient)) {
          compteur.aRetenter.add(element.uuidClient);
        }
      }
    }
  }

  Future<void> _envoyerPhotos(int utilisateurId, _Compteur compteur) async {
    while (true) {
      final photos = await _file.fichiersAEnvoyer(utilisateurId, limite: 10, sauf: compteur.aRetenter);

      if (photos.isEmpty) {
        break;
      }

      for (final photo in photos) {
        await _envoyerPhoto(utilisateurId, photo, compteur);
      }
    }
  }

  Future<void> _envoyerPhoto(int utilisateurId, ElementFile photo, _Compteur compteur) async {
    final fiche = photo.dependDe;
    final etatFiche = fiche == null ? null : await _file.etatDe(utilisateurId, fiche);

    // La fiche attend encore : la photo partira après elle.
    if (etatFiche == DepotFile.enAttente) {
      compteur.aRetenter.add(photo.uuidClient);

      return;
    }

    if (etatFiche == DepotFile.rejete) {
      compteur.rejetes++;
      await _file.marquerRejete(
        photo.id,
        code: 'fiche_refusee',
        motif: 'La fiche de cette photo a été refusée par le serveur : la photo ne peut pas être envoyée.',
      );

      return;
    }

    final chemin = photo.contenu['chemin_local'] as String?;

    if (chemin == null || !File(chemin).existsSync()) {
      compteur.rejetes++;
      await _file.marquerRejete(photo.id, code: 'fichier_absent', motif: "La photo n'est plus sur le téléphone.");

      return;
    }

    try {
      await _api.envoyerFichier(
        '/sync/fichiers',
        champs: {
          'uuid_fichier': photo.uuidClient,
          'element_uuid': fiche ?? '',
          'role': (photo.contenu['role'] as String?) ?? '',
          'horodatage_telephone': photo.horodatageAction,
        },
        cheminFichier: chemin,
      );
    } on ErreurApi catch (erreur) {
      // Sans réseau, ou session perdue : on arrête tout, la file n'a rien perdu.
      if (erreur.estHorsLigne || erreur.estSessionPerdue) {
        rethrow;
      }

      // Fiche pas encore arrivée, ou panne du serveur : on retentera.
      if (erreur.estAReessayer || erreur.statut >= 500) {
        compteur.aRetenter.add(photo.uuidClient);
        await _file.noterTentative(photo.id);

        return;
      }

      compteur.rejetes++;
      await _file.marquerRejete(photo.id, code: 'photo_refusee', motif: erreur.message);

      return;
    }

    await _file.retirerIds([photo.id]);
    compteur.acceptes++;

    // La photo est sur le serveur : elle n'a plus rien à faire sur le téléphone.
    try {
      await File(chemin).delete();
    } on FileSystemException {
      // Un fichier déjà effacé n'empêche pas l'accusé.
    }
  }

  Future<int> _tailleDuLot() async {
    final connue = _tailleLot;

    if (connue != null) {
      return connue;
    }

    final types = (await _api.lire('/sync/types') as Map).cast<String, dynamic>();
    final annoncee = types['max_elements_par_lot'];

    return _tailleLot = annoncee is int && annoncee > 0 ? annoncee : Configuration.tailleLotProvisoire;
  }

  /// L'élément désigné par la réponse : par son uuid, ou à défaut par son rang.
  ElementFile? _elementDe(Map resultat, List<ElementFile> elements) {
    final uuid = resultat['uuid_client'];

    if (uuid is String) {
      return elements.where((element) => element.uuidClient == uuid).firstOrNull;
    }

    final rang = resultat['rang'];

    return rang is int && rang >= 0 && rang < elements.length ? elements[rang] : null;
  }

  Future<BilanEnvoi> _conclure(int utilisateurId, BilanEnvoi bilan) async {
    await _file.noterEnvoi(
      utilisateurId,
      issue: bilan.issue.name,
      acceptes: bilan.acceptes,
      rejetes: bilan.rejetes,
      message: bilan.message,
    );

    return bilan;
  }
}

class _Compteur {
  int acceptes = 0;
  int rejetes = 0;
  final Set<String> aRetenter = {};

  BilanEnvoi bilan(IssueEnvoi issue, {String? message}) => BilanEnvoi(
        issue,
        acceptes: acceptes,
        rejetes: rejetes,
        aReessayer: aRetenter.length,
        message: message,
      );
}
