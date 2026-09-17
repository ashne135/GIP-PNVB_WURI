import 'package:flutter/material.dart';

import '../api/client_api.dart';
import '../composants/cadre_ecran.dart';
import '../composants/gros_bouton.dart';
import '../l10n/textes.dart';
import '../session/controleur_session.dart';

/// L'ACCEPTATION DE LA CHARTE (cadrage, section 8.4).
///
/// Le texte vient du serveur : le même que sur le web, un seul par version.
/// La case doit être cochée pour accepter — un consentement qu'on peut donner
/// sans le vouloir n'en est pas un. Si la charte change pendant la lecture, le
/// serveur refuse : le nouveau texte est rechargé et la case décochée.
class EcranCharte extends StatefulWidget {
  const EcranCharte({super.key, required this.session});

  final ControleurSession session;

  @override
  State<EcranCharte> createState() => _EtatEcranCharte();
}

class _EtatEcranCharte extends State<EcranCharte> {
  Map<String, dynamic>? _texte;
  ErreurApi? _erreurChargement;
  ErreurApi? _erreur;
  bool _lue = false;
  bool _enCours = false;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    setState(() => _erreurChargement = null);

    try {
      final texte = await widget.session.lireCharte();

      if (mounted) {
        setState(() {
          _texte = texte;
          _lue = false;
        });
      }
    } on ErreurApi catch (erreur) {
      if (mounted) {
        setState(() => _erreurChargement = erreur);
      }
    }
  }

  Future<void> _accepter() async {
    final version = _texte?['version'] as String?;

    if (version == null) {
      return;
    }

    setState(() {
      _enCours = true;
      _erreur = null;
    });

    try {
      await widget.session.accepterCharte(version);
    } on ErreurApi catch (erreur) {
      if (mounted) {
        setState(() => _erreur = erreur);
      }

      if (erreur.statut == 409) {
        await _charger();
      }
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final texte = _texte;
    final theme = Theme.of(context);

    if (texte == null) {
      final erreur = _erreurChargement;

      return CadreEcran(
        titre: textes.charteTitre,
        enfants: [
          if (erreur == null) ...[
            const Center(child: CircularProgressIndicator()),
            const SizedBox(height: 16),
            Text(textes.charteChargement, textAlign: TextAlign.center),
          ] else ...[
            MessageErreur(erreur.message),
            Text(textes.obligationReseau),
            const SizedBox(height: 20),
            GrosBouton(icone: Icons.refresh, libelle: textes.reessayer, onPressed: _charger),
          ],
          const SizedBox(height: 16),
          GrosBouton(
            icone: Icons.logout,
            libelle: textes.charteRefuser,
            secondaire: true,
            onPressed: widget.session.deconnecter,
          ),
        ],
      );
    }

    final articles = (texte['articles'] as List? ?? const []).cast<Map>();

    return CadreEcran(
      titre: (texte['titre'] as String?) ?? textes.charteTitre,
      enfants: [
        if (texte['provisoire'] == true)
          Container(
            margin: const EdgeInsets.only(bottom: 20),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              border: Border.all(color: theme.colorScheme.error, width: 2),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  textes.charteProvisoireTitre.toUpperCase(),
                  style: TextStyle(color: theme.colorScheme.error, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 6),
                Text(textes.charteProvisoireTexte),
              ],
            ),
          ),
        Text((texte['preambule'] as String?) ?? '', style: theme.textTheme.bodyLarge),
        const SizedBox(height: 20),
        for (final article in articles) ...[
          Text((article['titre'] as String?) ?? '', style: theme.textTheme.titleMedium),
          const SizedBox(height: 6),
          Text((article['texte'] as String?) ?? '', style: theme.textTheme.bodyLarge),
          const SizedBox(height: 18),
        ],
        if (_erreur != null) MessageErreur(_erreur!.message),
        CheckboxListTile(
          value: _lue,
          onChanged: (valeur) => setState(() => _lue = valeur ?? false),
          controlAffinity: ListTileControlAffinity.leading,
          contentPadding: EdgeInsets.zero,
          title: Text((texte['engagement'] as String?) ?? ''),
        ),
        const SizedBox(height: 16),
        GrosBouton(
          icone: Icons.check_circle,
          libelle: _enCours ? textes.enCours : textes.charteAccepter,
          enCours: _enCours,
          onPressed: _lue ? _accepter : null,
        ),
        const SizedBox(height: 16),
        GrosBouton(
          icone: Icons.logout,
          libelle: textes.charteRefuser,
          secondaire: true,
          onPressed: widget.session.deconnecter,
        ),
        const SizedBox(height: 12),
        Text(textes.charteVersion((texte['version'] as String?) ?? ''), style: theme.textTheme.bodySmall),
      ],
    );
  }
}
