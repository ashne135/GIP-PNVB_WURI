import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../l10n/textes.dart';

/// L'ÉTAT DU RÉSEAU, VISIBLE EN PERMANENCE (cadrage, section 14).
///
/// Hors ligne, le bandeau ne dit pas « erreur » : il dit que le travail est
/// gardé. C'est la situation normale d'un site sans couverture, et l'agent
/// doit continuer sans s'inquiéter.
class BandeauReseau extends StatefulWidget {
  const BandeauReseau({super.key, this.connectivite});

  final Connectivity? connectivite;

  @override
  State<BandeauReseau> createState() => _EtatBandeauReseau();
}

class _EtatBandeauReseau extends State<BandeauReseau> {
  bool? _enLigne;
  StreamSubscription<List<ConnectivityResult>>? _abonnement;

  @override
  void initState() {
    super.initState();

    final connectivite = widget.connectivite ?? Connectivity();

    // Une panne du module de connectivité masque le bandeau ; elle ne fait
    // jamais tomber l'écran sur lequel l'agent travaille.
    try {
      connectivite.checkConnectivity().then(_mettreAJour, onError: (Object _) {});
      _abonnement = connectivite.onConnectivityChanged.listen(_mettreAJour, onError: (Object _) {});
    } catch (_) {
      _abonnement = null;
    }
  }

  void _mettreAJour(List<ConnectivityResult> resultats) {
    if (!mounted) {
      return;
    }

    setState(() => _enLigne = resultats.any((resultat) => resultat != ConnectivityResult.none));
  }

  @override
  void dispose() {
    _abonnement?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final enLigne = _enLigne;

    if (enLigne == null) {
      return const SizedBox.shrink();
    }

    final textes = Textes.of(context);

    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        color: enLigne ? const Color(0xFF1E6B3A) : const Color(0xFF8A4B00),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: [
            Icon(enLigne ? Icons.wifi : Icons.wifi_off, color: Colors.white),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                enLigne ? textes.reseauEnLigne : textes.reseauHorsLigne,
                style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
