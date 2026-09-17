import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:volontaires/composants/gros_bouton.dart';

/// Cadrage, section 14 : boutons d'au moins 60 px de haut, icône + texte.
void main() {
  testWidgets('le bouton du terrain fait au moins 60 px de haut, avec une icône et un texte', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: Center(
          child: GrosBouton(icone: Icons.cloud_upload, libelle: 'Envoyer maintenant', onPressed: () {}),
        ),
      ),
    ));

    expect(tester.getSize(find.byType(FilledButton)).height, greaterThanOrEqualTo(60));
    expect(find.byIcon(Icons.cloud_upload), findsOneWidget);
    expect(find.text('Envoyer maintenant'), findsOneWidget);
  });

  testWidgets("ne se laisse pas appuyer deux fois pendant un envoi", (tester) async {
    var appuis = 0;

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: GrosBouton(
          icone: Icons.cloud_upload,
          libelle: 'Envoyer maintenant',
          enCours: true,
          onPressed: () => appuis++,
        ),
      ),
    ));

    await tester.tap(find.byType(FilledButton));

    expect(appuis, 0);
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
  });
}
