import 'package:flutter/material.dart';

import 'bandeau_reseau.dart';

/// Le cadre commun à tous les écrans : un titre, l'état du réseau toujours
/// visible, et un contenu qui défile.
class CadreEcran extends StatelessWidget {
  const CadreEcran({super.key, required this.titre, required this.enfants});

  final String titre;
  final List<Widget> enfants;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(titre)),
      body: Column(
        children: [
          const BandeauReseau(),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: enfants,
            ),
          ),
        ],
      ),
    );
  }
}

/// Un message d'erreur du serveur, affiché tel quel : il est rédigé pour l'agent.
class MessageErreur extends StatelessWidget {
  const MessageErreur(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    final schema = Theme.of(context).colorScheme;

    return Semantics(
      liveRegion: true,
      child: Container(
        margin: const EdgeInsets.only(bottom: 16),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: schema.errorContainer,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.error_outline, color: schema.onErrorContainer),
            const SizedBox(width: 12),
            Expanded(
              child: Text(message, style: TextStyle(color: schema.onErrorContainer, fontSize: 16)),
            ),
          ],
        ),
      ),
    );
  }
}

/// LA CONFIRMATION IMPOSSIBLE À RATER (cadrage, section 14) : toute la
/// largeur, une grosse icône, une couleur franche, un texte lisible.
void afficherConfirmation(BuildContext context, String message, {bool succes = true}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        behavior: SnackBarBehavior.floating,
        duration: const Duration(seconds: 5),
        backgroundColor: succes ? const Color(0xFF1E6B3A) : const Color(0xFF8A4B00),
        content: Row(
          children: [
            Icon(succes ? Icons.check_circle : Icons.info, color: Colors.white, size: 36),
            const SizedBox(width: 16),
            Expanded(
              child: Text(
                message,
                style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
}
