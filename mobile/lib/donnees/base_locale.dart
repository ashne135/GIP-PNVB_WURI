import 'dart:convert';
import 'dart:math';

import 'package:path/path.dart' as chemins;
import 'package:sqflite_sqlcipher/sqflite.dart';

import 'coffre.dart';

/// LA BASE LOCALE CHIFFRÉE (cadrage, section 11.1).
///
/// Toute action est écrite ici D'ABORD, et l'écran confirme aussitôt. La base
/// est chiffrée par SQLCipher avec une clé tirée au hasard au premier
/// lancement et gardée dans le coffre Android : un téléphone perdu ne livre ni
/// les rapports, ni les positions, ni le profil de l'agent.
///
/// Si la clé disparaît du coffre — application réinstallée, données effacées —
/// la base n'est plus lisible par personne. Elle est alors abandonnée et
/// recréée : ce qu'elle contenait ne peut plus être envoyé. C'est le prix du
/// chiffrement, et la raison pour laquelle la file part dès que le réseau le
/// permet.
class BaseLocale {
  const BaseLocale._();

  static const String nomFichier = 'pnvb_volontaires.db';

  /// 1 : file d'attente, profils, envois (tâche 16).
  /// 2 : données de travail gardées pour travailler sans réseau (tâche 17).
  static const int version = 2;

  static Future<Database> ouvrir(Coffre coffre) async {
    final chemin = chemins.join(await getDatabasesPath(), nomFichier);
    var cle = await coffre.lire(CleCoffre.cleBase);

    if (cle == null) {
      cle = _nouvelleCle();
      // Une base sans sa clé est illisible : on ne la garde pas en croyant la
      // rouvrir un jour.
      await deleteDatabase(chemin);
      await coffre.ecrire(CleCoffre.cleBase, cle);
    }

    return openDatabase(
      chemin,
      password: cle,
      version: version,
      onCreate: (base, _) => creerSchema(base),
      onUpgrade: (base, ancienne, _) => migrer(base, ancienne),
    );
  }

  /// 32 octets tirés par le générateur cryptographique du système.
  static String _nouvelleCle() {
    final aleatoire = Random.secure();

    return base64UrlEncode(List<int>.generate(32, (_) => aleatoire.nextInt(256)));
  }

  /// Le schéma complet, séparé de l'ouverture pour être éprouvé en test sur une
  /// base non chiffrée.
  static Future<void> creerSchema(DatabaseExecutor base) async {
    // LA FILE D'ATTENTE. Un élément appartient à UN compte : un autre compte
    // connecté sur le même téléphone ne l'envoie pas et ne le voit pas.
    await base.execute('''
      CREATE TABLE file_attente (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid_client TEXT NOT NULL UNIQUE,
        utilisateur_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        contenu TEXT NOT NULL,
        horodatage_action TEXT NOT NULL,
        nature TEXT NOT NULL DEFAULT 'donnees',
        depend_de TEXT,
        etat TEXT NOT NULL DEFAULT 'en_attente',
        tentatives INTEGER NOT NULL DEFAULT 0,
        derniere_tentative_le TEXT,
        code_rejet TEXT,
        motif_rejet TEXT,
        cree_le TEXT NOT NULL
      )
    ''');
    await base.execute(
      'CREATE INDEX idx_file_compte_etat ON file_attente (utilisateur_id, etat, nature, id)',
    );

    // LE PROFIL DU COMPTE, tel que /moi l'a rendu : c'est lui qui permet
    // d'ouvrir l'accueil sans réseau.
    await base.execute('''
      CREATE TABLE profils (
        utilisateur_id INTEGER PRIMARY KEY,
        profil TEXT NOT NULL,
        mis_a_jour_le TEXT NOT NULL
      )
    ''');

    // LA TRACE DES ENVOIS, pour dire à l'agent quand sa file est partie.
    await base.execute('''
      CREATE TABLE envois (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        utilisateur_id INTEGER NOT NULL,
        tente_le TEXT NOT NULL,
        issue TEXT NOT NULL,
        nb_acceptes INTEGER NOT NULL DEFAULT 0,
        nb_rejetes INTEGER NOT NULL DEFAULT 0,
        message TEXT
      )
    ''');

    await _creerDonneesTravail(base);
  }

  /// Fait évoluer la base d'un téléphone déjà installé, sans perdre sa file.
  static Future<void> migrer(DatabaseExecutor base, int ancienneVersion) async {
    if (ancienneVersion < 2) {
      await _creerDonneesTravail(base);
    }
  }

  /// LES DONNÉES DE TRAVAIL : ce que le serveur a préparé pour la journée —
  /// canevas d'incident, rapport du jour pré-rempli, feuilles de présence,
  /// alertes, kit, appréciations. Gardées pour travailler sans réseau, avec
  /// leur date : une information ancienne ne doit pas passer pour fraîche.
  static Future<void> _creerDonneesTravail(DatabaseExecutor base) async {
    await base.execute('''
      CREATE TABLE IF NOT EXISTS donnees_travail (
        utilisateur_id INTEGER NOT NULL,
        cle TEXT NOT NULL,
        contenu TEXT NOT NULL,
        mis_a_jour_le TEXT NOT NULL,
        PRIMARY KEY (utilisateur_id, cle)
      )
    ''');
  }
}
