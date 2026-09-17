import 'package:flutter/material.dart';

import '../theme.dart';

/// LE BOUTON DU TERRAIN (cadrage, section 14) : au moins 60 px de haut, toute
/// la largeur, une icône ET un texte. Pendant un envoi, il le montre et ne
/// se laisse pas appuyer deux fois.
class GrosBouton extends StatelessWidget {
  const GrosBouton({
    super.key,
    required this.icone,
    required this.libelle,
    required this.onPressed,
    this.secondaire = false,
    this.enCours = false,
  });

  final IconData icone;
  final String libelle;
  final VoidCallback? onPressed;
  final bool secondaire;
  final bool enCours;

  @override
  Widget build(BuildContext context) {
    final contenu = Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (enCours)
          const SizedBox.square(dimension: 24, child: CircularProgressIndicator(strokeWidth: 3))
        else
          Icon(icone, size: 28),
        const SizedBox(width: 12),
        Flexible(child: Text(libelle, textAlign: TextAlign.center)),
      ],
    );

    const style = ButtonStyle(minimumSize: WidgetStatePropertyAll(Size(64, hauteurBouton)));
    final action = enCours ? null : onPressed;

    return SizedBox(
      width: double.infinity,
      child: secondaire
          ? OutlinedButton(onPressed: action, style: style, child: contenu)
          : FilledButton(onPressed: action, style: style, child: contenu),
    );
  }
}
