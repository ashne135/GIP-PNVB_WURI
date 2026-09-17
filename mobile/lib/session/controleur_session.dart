import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:sqflite_sqlcipher/sqflite.dart';

import '../api/client_api.dart';
import '../configuration.dart';
import '../donnees/coffre.dart';
import '../donnees/depot_file.dart';
import '../donnees/depot_profil.dart';
import '../donnees/depot_travail.dart';
import '../sync/moteur_synchronisation.dart';
import '../travail/service_journee.dart';

enum EtatSession { demarrage, deconnecte, connecte }

/// Une photo prise pour une fiche, avec son rôle côté serveur.
class PhotoAJoindre {
  const PhotoAJoindre({required this.chemin, required this.role});

  final String chemin;

  /// preuve_incident, constat_source ou constat_destination.
  final String role;
}

/// LA SESSION DE L'AGENT SUR CE TÉLÉPHONE.
///
/// OFFLINE-FIRST DÈS LE PREMIER ÉCRAN : une session ouverte une fois se rouvre
/// depuis le téléphone — jeton dans le coffre, profil dans la base chiffrée —
/// sans attendre le réseau. Le profil et les données de travail sont
/// rafraîchis en arrière-plan quand le réseau le permet, et leur date est
/// toujours montrée.
///
/// Le réseau n'est exigé que pour ce qui ne peut pas se faire autrement : la
/// première connexion, le changement du mot de passe initial, l'acceptation de
/// la charte. Ce sont des vérifications du serveur, pas du téléphone.
///
/// À LA DÉCONNEXION, LE TRAVAIL NON ENVOYÉ EST GARDÉ pour ce compte (décision
/// du 14 septembre 2026) : il partira à sa prochaine connexion, et un autre
/// compte connecté sur ce téléphone ne le voit pas.
class ControleurSession extends ChangeNotifier {
  ControleurSession({
    required this.coffre,
    required Database base,
    String urlApi = Configuration.urlApi,
    http.Client? client,
  })  : file = DepotFile(base),
        profils = DepotProfil(base),
        travail = DepotTravail(base) {
    api = ClientApi(urlBase: urlApi, jeton: () => coffre.lire(CleCoffre.jeton), client: client);
    moteur = MoteurSynchronisation(api: api, file: file);
    journee = ServiceJournee(api: api, depot: travail, file: file);
  }

  final Coffre coffre;
  final DepotFile file;
  final DepotProfil profils;
  final DepotTravail travail;
  late final ClientApi api;
  late final MoteurSynchronisation moteur;
  late final ServiceJournee journee;

  EtatSession etat = EtatSession.demarrage;
  int? utilisateurId;
  Map<String, dynamic>? profil;
  DateTime? profilMisAJourLe;

  /// Le serveur a refusé le jeton : il faut se reconnecter pour envoyer.
  bool sessionExpiree = false;
  bool envoiEnCours = false;
  bool preparationEnCours = false;
  BilanPreparation? dernierePreparation;
  CompteFile compteFile = CompteFile.vide;
  DateTime? dernierEnvoi;

  Map<String, dynamic> get _actions =>
      (profil?['actions_requises'] as Map?)?.cast<String, dynamic>() ?? const {};

  bool get doitChangerMotDePasse => _actions['changer_mot_de_passe'] == true;

  bool get doitAccepterCharte => _actions['accepter_charte'] == true;

  /// Les droits du compte, tels que le serveur les a rendus. Ils décident des
  /// écrans proposés ; le serveur, lui, revérifie tout à la réception.
  Set<String> get permissions =>
      (profil?['permissions'] as List?)?.whereType<String>().toSet() ?? const <String>{};

  bool peut(String permission) => permissions.contains(permission);

  Future<void> demarrer() async {
    final jeton = await coffre.lire(CleCoffre.jeton);
    final id = int.tryParse(await coffre.lire(CleCoffre.utilisateur) ?? '');
    final enCache = id == null ? null : await profils.lire(id);

    if (jeton == null || id == null || enCache == null) {
      etat = EtatSession.deconnecte;
      notifyListeners();

      return;
    }

    utilisateurId = id;
    profil = enCache.profil;
    profilMisAJourLe = enCache.misAJourLe;
    etat = EtatSession.connecte;
    await _relireFile();
    notifyListeners();

    unawaited(rafraichirProfil().then((_) => preparerJournee()));
  }

  /// Relit /moi quand le réseau le permet. Hors ligne, le profil gardé suffit.
  Future<void> rafraichirProfil() async {
    try {
      final donnees = (await api.lire('/moi') as Map).cast<String, dynamic>();
      await _memoriserProfil(donnees);
      sessionExpiree = false;
    } on ErreurApi catch (erreur) {
      if (!erreur.estSessionPerdue) {
        return;
      }

      sessionExpiree = true;
    }

    notifyListeners();
  }

  /// Va chercher ce qu'il faudra pour travailler sans réseau. Sans réseau, rien
  /// n'est effacé : les données gardées restent disponibles.
  Future<BilanPreparation?> preparerJournee() async {
    final id = utilisateurId;

    if (id == null || etat != EtatSession.connecte || preparationEnCours || doitChangerMotDePasse || doitAccepterCharte) {
      return null;
    }

    preparationEnCours = true;
    notifyListeners();

    try {
      final bilan = await journee.preparer(id, permissions);
      dernierePreparation = bilan;

      return bilan;
    } finally {
      preparationEnCours = false;
      notifyListeners();
    }
  }

  /// ENREGISTRE UN GESTE DU TERRAIN : écrit d'abord sur le téléphone, avec ses
  /// photos, puis tente l'envoi. L'écran peut confirmer dès le retour de cette
  /// méthode : le travail est en sécurité, réseau ou pas.
  Future<ElementFile> enregistrer({
    required String type,
    required Map<String, dynamic> contenu,
    String? uuidClient,
    List<PhotoAJoindre> photos = const [],
  }) async {
    final id = utilisateurId;

    if (id == null) {
      throw StateError('Aucun compte connecté.');
    }

    final element = await file.ajouter(utilisateurId: id, type: type, contenu: contenu, uuidClient: uuidClient);

    for (final photo in photos) {
      await file.ajouter(
        utilisateurId: id,
        type: 'photo',
        nature: DepotFile.fichier,
        dependDe: element.uuidClient,
        contenu: {'chemin_local': photo.chemin, 'role': photo.role},
      );
    }

    await _relireFile();
    notifyListeners();

    unawaited(envoyerFile());

    return element;
  }

  Future<void> connecter(String telephone, String motDePasse) async {
    final reponse = await api.envoyer('/connexion', {
      'telephone': telephone,
      'mot_de_passe': motDePasse,
      'nom_appareil': Configuration.nomAppareil,
    });

    final donnees = (reponse.donnees as Map).cast<String, dynamic>();
    final id = ((donnees['utilisateur'] as Map)['id'] as num).toInt();

    await coffre.ecrire(CleCoffre.jeton, donnees['jeton'] as String);
    await coffre.ecrire(CleCoffre.utilisateur, '$id');

    utilisateurId = id;
    sessionExpiree = false;
    // Le jeton reste dans le coffre : il n'est jamais écrit dans la base.
    await _memoriserProfil(Map.of(donnees)..remove('jeton'));
    etat = EtatSession.connecte;
    await _relireFile();
    notifyListeners();

    // Du travail gardé d'une session précédente de ce compte part tout de suite,
    // et la journée se prépare tant que le réseau est là.
    unawaited(envoyerFile().then((_) => preparerJournee()));
  }

  Future<void> changerMotDePasse(String actuel, String nouveau, String confirmation) async {
    await api.envoyer('/mot-de-passe/changer', {
      'mot_de_passe_actuel': actuel,
      'nouveau_mot_de_passe': nouveau,
      'nouveau_mot_de_passe_confirmation': confirmation,
    });

    // Les obligations restantes viennent du serveur, pas d'une supposition.
    await _exigerProfilFrais();
  }

  Future<Map<String, dynamic>> lireCharte() async =>
      (await api.lire('/charte') as Map).cast<String, dynamic>();

  /// La version envoyée est celle DU TEXTE AFFICHÉ : si la charte a changé
  /// pendant la lecture, le serveur refuse (409).
  Future<void> accepterCharte(String versionAffichee) async {
    await api.envoyer('/charte/accepter', {'version_charte': versionAffichee});
    await _exigerProfilFrais();
    unawaited(preparerJournee());
  }

  Future<BilanEnvoi?> envoyerFile() async {
    final id = utilisateurId;

    if (id == null || etat != EtatSession.connecte) {
      return null;
    }

    envoiEnCours = true;
    notifyListeners();

    final bilan = await moteur.envoyer(id);

    if (bilan.issue == IssueEnvoi.sessionPerdue) {
      sessionExpiree = true;
    }

    envoiEnCours = false;
    await _relireFile();
    notifyListeners();

    return bilan;
  }

  /// Le travail non envoyé RESTE sur le téléphone : seuls le jeton et le compte
  /// courant sont oubliés. L'écran prévient l'agent avant d'appeler ceci.
  Future<void> deconnecter() async {
    try {
      await api.envoyer('/deconnexion');
    } on ErreurApi {
      // Hors ligne : le jeton est oublié ici, et ne sera plus jamais présenté.
    }

    await coffre.effacer(CleCoffre.jeton);
    await coffre.effacer(CleCoffre.utilisateur);

    utilisateurId = null;
    profil = null;
    profilMisAJourLe = null;
    sessionExpiree = false;
    dernierePreparation = null;
    compteFile = CompteFile.vide;
    dernierEnvoi = null;
    etat = EtatSession.deconnecte;
    notifyListeners();
  }

  Future<void> _memoriserProfil(Map<String, dynamic> donnees) async {
    final id = ((donnees['utilisateur'] as Map)['id'] as num).toInt();

    await profils.enregistrer(id, donnees);
    final enCache = await profils.lire(id);

    profil = enCache?.profil ?? donnees;
    profilMisAJourLe = enCache?.misAJourLe;
  }

  Future<void> _exigerProfilFrais() async {
    final donnees = (await api.lire('/moi') as Map).cast<String, dynamic>();
    await _memoriserProfil(donnees);
    notifyListeners();
  }

  Future<void> _relireFile() async {
    final id = utilisateurId;

    if (id == null) {
      return;
    }

    compteFile = await file.compter(id);
    dernierEnvoi = await file.dernierEnvoiReussi(id);
  }
}
