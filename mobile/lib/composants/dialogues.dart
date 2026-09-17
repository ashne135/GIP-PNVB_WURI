import 'package:flutter/material.dart';

import '../l10n/textes.dart';
import '../services/service_position.dart';

/// Une confirmation avant un acte définitif : signer, viser, valider.
Future<bool> confirmer(
  BuildContext context, {
  required String titre,
  required String texte,
  required String action,
}) async {
  final textes = Textes.of(context);

  final reponse = await showDialog<bool>(
    context: context,
    builder: (dialogue) => AlertDialog(
      title: Text(titre),
      content: Text(texte, style: const TextStyle(fontSize: 17)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dialogue, false), child: Text(textes.annuler)),
        FilledButton(onPressed: () => Navigator.pop(dialogue, true), child: Text(action)),
      ],
    ),
  );

  return reponse == true;
}

/// LA POSITION D'UN ACTE SIGNÉ — signature d'un rapport, visa, validation d'une
/// feuille de présence.
///
/// Le serveur l'enregistre quand elle est fournie : c'est ce qui rend un acte
/// posé à distance vérifiable. Il ne l'exige pas, et un GPS défaillant ne doit
/// pas bloquer un superviseur au milieu d'un site : si elle reste introuvable,
/// l'agent est prévenu de la conséquence et décide lui-même de continuer.
///
/// Rend les coordonnées à joindre — vides si l'agent continue sans position —
/// ou null s'il renonce.
Future<Map<String, Object>?> positionDeLActe(
  BuildContext context,
  ServicePosition service, {
  String? consequence,
}) async {
  final textes = Textes.of(context);

  try {
    final position = await service.relever();

    return {'latitude': position.latitude, 'longitude': position.longitude};
  } on PositionIndisponible catch (erreur) {
    if (!context.mounted) {
      return null;
    }

    final continuer = await showDialog<bool>(
      context: context,
      builder: (dialogue) => AlertDialog(
        title: Text(textes.sansPositionTitre),
        content: Text([erreur.message, ?consequence].join('\n\n'), style: const TextStyle(fontSize: 17)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogue, false), child: Text(textes.annuler)),
          FilledButton(onPressed: () => Navigator.pop(dialogue, true), child: Text(textes.sansPositionContinuer)),
        ],
      ),
    );

    return continuer == true ? const {} : null;
  }
}
