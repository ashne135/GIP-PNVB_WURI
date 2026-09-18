import 'package:flutter/material.dart';

/// Hauteur minimale d'un bouton (cadrage, section 14).
const double hauteurBouton = 60;

/// LE THÈME DE L'APPLICATION DE TERRAIN.
///
/// Pensé pour un écran lu en plein soleil, par un utilisateur non
/// informaticien : boutons de 60 px au moins, texte généreux, champs larges.
/// LE VERT DU PROGRAMME, relevé sur le logo (`logo-pnvb.jpg`) et déjà porté
/// par le back-office. L'application partait d'un bleu-vert sans rapport :
/// deux outils du même dispositif n'ont aucune raison de ne pas se ressembler.
const Color vertPnvb = Color(0xFF028428);

ThemeData themePnvb() {
  final base = ThemeData(
    colorScheme: ColorScheme.fromSeed(seedColor: vertPnvb),
    useMaterial3: true,
  );

  const texteBouton = TextStyle(fontSize: 18, fontWeight: FontWeight.w600);
  const tailleBouton = Size(64, hauteurBouton);

  return base.copyWith(
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(minimumSize: tailleBouton, textStyle: texteBouton),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(minimumSize: tailleBouton, textStyle: texteBouton),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(minimumSize: tailleBouton, textStyle: texteBouton),
    ),
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(),
      contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 18),
    ),
    // Texte agrandi de 10 %. Dans un ThemeData, le thème de texte ne porte que
    // couleurs et polices : les TAILLES (la géométrie de la typographie) ne
    // sont ajoutées qu'à l'affichage. Appliquer un facteur à des tailles vides
    // fait échouer le premier écran — c'est ce qui s'est produit sur le
    // téléphone. On fusionne donc d'abord la géométrie, puis on agrandit ;
    // à l'affichage, les tailles déjà présentes l'emportent.
    textTheme: base.typography.englishLike.merge(base.textTheme).apply(fontSizeFactor: 1.1),
  );
}
