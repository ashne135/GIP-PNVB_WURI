import 'package:flutter/material.dart';

import 'composants/gros_bouton.dart';
import 'ecrans/ecran_accueil.dart';
import 'ecrans/ecran_charte.dart';
import 'ecrans/ecran_connexion.dart';
import 'ecrans/ecran_mot_de_passe.dart';
import 'l10n/textes.dart';
import 'session/controleur_session.dart';
import 'theme.dart';

/// Ouvre les données du téléphone et rouvre la session.
typedef OuvertureSession = Future<ControleurSession> Function();

/// L'APPLICATION, et l'ordre de ses écrans.
///
/// L'ÉCRAN S'AFFICHE AVANT L'OUVERTURE DES DONNÉES. Sur un téléphone d'entrée
/// de gamme, ouvrir la base chiffrée prend quelques secondes : l'agent voit que
/// l'application démarre, et un échec s'affiche en clair au lieu d'un écran
/// noir.
///
/// Les obligations suivent ensuite l'ordre du serveur : connexion, mot de passe
/// initial, charte. Tant qu'elles ne sont pas levées, l'accueil n'est pas
/// proposé — le serveur refuserait de toute façon ce qu'il promettrait.
class ApplicationPnvb extends StatefulWidget {
  const ApplicationPnvb({super.key, required this.ouvrirSession});

  final OuvertureSession ouvrirSession;

  @override
  State<ApplicationPnvb> createState() => _EtatApplicationPnvb();
}

class _EtatApplicationPnvb extends State<ApplicationPnvb> {
  late Future<ControleurSession> _session = _ouvrir();

  /// L'échec est affiché par l'écran dédié. Le Future peut échouer avant que
  /// l'écran ne l'écoute — entre le bouton « Réessayer » et l'image suivante :
  /// ignore() le marque comme pris en charge, sans le cacher à l'écran.
  Future<ControleurSession> _ouvrir() => widget.ouvrirSession()..ignore();

  void _reessayer() {
    // Le corps de setState ne doit rien rendre : une fonction fléchée rendrait
    // ici le Future de l'ouverture, et Flutter refuserait la mise à jour.
    setState(() {
      _session = _ouvrir();
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      onGenerateTitle: (context) => Textes.of(context).titreApplication,
      debugShowCheckedModeBanner: false,
      theme: themePnvb(),
      locale: const Locale('fr'),
      supportedLocales: Textes.supportedLocales,
      localizationsDelegates: Textes.localizationsDelegates,
      home: FutureBuilder<ControleurSession>(
        future: _session,
        builder: (context, ouverture) {
          if (ouverture.hasError) {
            return _EcranDemarrageImpossible(onReessayer: _reessayer);
          }

          final session = ouverture.data;

          if (session == null) {
            return const _EcranChargement();
          }

          return ListenableBuilder(
            listenable: session,
            builder: (context, _) => switch (session.etat) {
              EtatSession.demarrage => const _EcranChargement(),
              EtatSession.deconnecte => EcranConnexion(session: session),
              EtatSession.connecte when session.doitChangerMotDePasse => EcranMotDePasse(session: session),
              EtatSession.connecte when session.doitAccepterCharte => EcranCharte(session: session),
              EtatSession.connecte => EcranAccueil(session: session),
            },
          );
        },
      ),
    );
  }
}

class _EcranChargement extends StatelessWidget {
  const _EcranChargement();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(Textes.of(context).titreApplication, style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 24),
            const CircularProgressIndicator(),
          ],
        ),
      ),
    );
  }
}

class _EcranDemarrageImpossible extends StatelessWidget {
  const _EcranDemarrageImpossible({required this.onReessayer});

  final VoidCallback onReessayer;

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.error_outline, size: 56, color: Theme.of(context).colorScheme.error),
              const SizedBox(height: 16),
              Text(
                textes.demarrageImpossibleTitre,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 12),
              Text(textes.demarrageImpossibleTexte, textAlign: TextAlign.center),
              const SizedBox(height: 28),
              GrosBouton(icone: Icons.refresh, libelle: textes.reessayer, onPressed: onReessayer),
            ],
          ),
        ),
      ),
    );
  }
}
