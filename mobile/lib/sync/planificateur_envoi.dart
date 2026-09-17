import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:workmanager/workmanager.dart';

import '../configuration.dart';
import 'tache_fond.dart';

/// QUAND LA FILE PART (cadrage, section 11.4, et décision du 14 septembre 2026) :
///
///   - au démarrage de l'application ;
///   - à chaque retour du réseau ;
///   - toutes les 5 minutes tant que l'application est ouverte ;
///   - toutes les 15 minutes quand elle est fermée — le minimum qu'Android
///     autorise pour une tâche de fond, sans notification permanente.
///
/// Deux envois qui se croisent ne font rien de mal : le moteur n'en laisse
/// tourner qu'un, et le serveur ne crée jamais de doublon.
class PlanificateurEnvoi with WidgetsBindingObserver {
  PlanificateurEnvoi({
    required this.envoyer,
    Stream<List<ConnectivityResult>>? changementsReseau,
    this.intervalle = Configuration.intervalleApplicationOuverte,
  }) : _changementsReseau = changementsReseau;

  final Future<void> Function() envoyer;
  final Duration intervalle;
  final Stream<List<ConnectivityResult>>? _changementsReseau;

  Timer? _minuterie;
  StreamSubscription<List<ConnectivityResult>>? _abonnement;
  bool _enLigne = false;

  void demarrer() {
    WidgetsBinding.instance.addObserver(this);

    _abonnement = (_changementsReseau ?? Connectivity().onConnectivityChanged).listen((resultats) {
      final enLigne = resultats.any((resultat) => resultat != ConnectivityResult.none);

      // C'est le RETOUR du réseau qui déclenche, pas chaque changement d'antenne.
      if (enLigne && !_enLigne) {
        unawaited(envoyer());
      }

      _enLigne = enLigne;
    });

    _relancerMinuterie();
    unawaited(envoyer());
  }

  void arreter() {
    WidgetsBinding.instance.removeObserver(this);
    _minuterie?.cancel();
    _abonnement?.cancel();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.resumed:
        _relancerMinuterie();
        unawaited(envoyer());
      case AppLifecycleState.paused:
      case AppLifecycleState.detached:
        // Application fermée : la tâche de fond prend le relais.
        _minuterie?.cancel();
      case AppLifecycleState.inactive:
      case AppLifecycleState.hidden:
        break;
    }
  }

  void _relancerMinuterie() {
    _minuterie?.cancel();
    _minuterie = Timer.periodic(intervalle, (_) => unawaited(envoyer()));
  }

  /// Programme l'envoi en tâche de fond, application fermée. Idempotent : un
  /// second appel ne crée pas une seconde tâche.
  static Future<void> programmerTacheDeFond() async {
    await Workmanager().initialize(rappelTacheDeFond);
    await Workmanager().registerPeriodicTask(
      TacheDeFond.nomUnique,
      TacheDeFond.nom,
      frequency: Configuration.intervalleApplicationFermee,
      constraints: Constraints(networkType: NetworkType.connected),
      existingWorkPolicy: ExistingPeriodicWorkPolicy.keep,
    );
  }
}
