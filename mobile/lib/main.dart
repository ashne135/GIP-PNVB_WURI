import 'dart:async';

import 'package:flutter/material.dart';

import 'application.dart';
import 'donnees/base_locale.dart';
import 'donnees/coffre.dart';
import 'session/controleur_session.dart';
import 'sync/planificateur_envoi.dart';

/// LE DÉMARRAGE.
///
/// L'écran s'affiche d'abord ; les données du téléphone s'ouvrent ensuite, et
/// seulement alors la file tente de partir. Rien de ce qui précède l'affichage
/// ne dépend du réseau, ni même de la base chiffrée.
void main() {
  WidgetsFlutterBinding.ensureInitialized();

  runApp(const ApplicationPnvb(ouvrirSession: ouvrirSessionDuTelephone));
}

Future<ControleurSession> ouvrirSessionDuTelephone() async {
  try {
    final coffre = CoffreSecurise();
    final base = await BaseLocale.ouvrir(coffre);
    final session = ControleurSession(coffre: coffre, base: base);

    await session.demarrer();

    PlanificateurEnvoi(envoyer: () async {
      await session.envoyerFile();
    }).demarrer();

    unawaited(_programmerTacheDeFond());

    return session;
  } catch (erreur, pile) {
    // Le détail va au journal du téléphone ; l'agent, lui, voit un message
    // clair et un bouton pour réessayer.
    debugPrint('Démarrage impossible : $erreur\n$pile');
    rethrow;
  }
}

Future<void> _programmerTacheDeFond() async {
  try {
    await PlanificateurEnvoi.programmerTacheDeFond();
  } catch (erreur) {
    // Sans tâche de fond, la file part encore à l'ouverture et au retour du
    // réseau : l'échec ne doit pas empêcher l'agent de travailler.
    debugPrint('Tâche de fond non programmée : $erreur');
  }
}
