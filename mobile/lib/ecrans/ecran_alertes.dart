import 'package:flutter/material.dart';

import '../composants/cadre_ecran.dart';
import '../composants/formulaire.dart';
import '../composants/gros_bouton.dart';
import '../donnees/depot_travail.dart';
import '../l10n/textes.dart';
import '../outils/horodatage.dart';
import '../session/controleur_session.dart';

/// LES ALERTES DESCENDANTES, LUES MÊME SANS RÉSEAU.
///
/// Savoir qui a lu une alerte, et quand, fait partie de ce qu'on doit pouvoir
/// établir après coup : l'accusé de lecture part avec la file, à l'heure où
/// l'agent a lu.
/// Le serveur calcule « lue » par une sous-requête d'existence : selon la base,
/// elle revient en booléen ou en 1. Les deux veulent dire lue — comparer à
/// `true` seul laisserait une alerte déjà lue s'annoncer non lue.
bool alerteEstLue(Map<String, dynamic> alerte) => alerte['lue'] == true || alerte['lue'] == 1;

class EcranAlertes extends StatefulWidget {
  const EcranAlertes({super.key, required this.session});

  final ControleurSession session;

  @override
  State<EcranAlertes> createState() => _EtatEcranAlertes();
}

class _EtatEcranAlertes extends State<EcranAlertes> {
  List<Map<String, dynamic>> _alertes = const [];
  DateTime? _du;
  bool _chargement = true;

  int get _utilisateur => widget.session.utilisateurId!;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    final donnee = await widget.session.travail.lire(_utilisateur, CleTravail.alertes);
    final alertes = await widget.session.travail.liste(_utilisateur, CleTravail.alertes);

    // Les non lues d'abord, puis les plus récentes.
    alertes.sort((a, b) {
      final lecture = (alerteEstLue(a) ? 1 : 0).compareTo(alerteEstLue(b) ? 1 : 0);

      return lecture != 0 ? lecture : '${b['publiee_le']}'.compareTo('${a['publiee_le']}');
    });

    if (mounted) {
      setState(() {
        _alertes = alertes;
        _du = donnee?.misAJourLe;
        _chargement = false;
      });
    }
  }

  Future<void> _marquerLue(Map<String, dynamic> alerte) async {
    await widget.session.enregistrer(type: 'lecture_alerte', contenu: {
      'alerte_id': alerte['id'],
      'horodatage_telephone': horodatageIso(DateTime.now()),
    });

    // Le téléphone s'en souvient tout de suite : l'alerte ne reste pas « non lue »
    // en attendant le réseau.
    final misesAJour = [
      for (final a in _alertes) a['id'] == alerte['id'] ? {...a, 'lue': true} : a,
    ];
    await widget.session.travail.ecrire(_utilisateur, CleTravail.alertes, misesAJour);

    if (mounted) {
      afficherConfirmation(context, Textes.of(context).enregistre);
      await _charger();
    }
  }

  Color _couleur(String? niveau) => switch (niveau) {
        'critique' => const Color(0xFFB3261E),
        'important' => const Color(0xFF8A4B00),
        _ => const Color(0xFF1F6F78),
      };

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);

    return CadreEcran(
      titre: textes.actionAlertes,
      enfants: [
        if (_chargement) const Center(child: CircularProgressIndicator()),
        if (!_chargement) BandeauDonnees(misAJourLe: _du),
        if (!_chargement && _alertes.isEmpty) Text(textes.alertesAucune, style: theme.textTheme.bodyLarge),
        for (final alerte in _alertes)
          Card(
            margin: const EdgeInsets.only(bottom: 12),
            shape: RoundedRectangleBorder(
              side: BorderSide(color: _couleur(alerte['niveau'] as String?), width: alerteEstLue(alerte) ? 1 : 3),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    alerteEstLue(alerte) ? textes.alerteLue : textes.alerteNonLue,
                    style: TextStyle(color: _couleur(alerte['niveau'] as String?), fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 4),
                  Text('${alerte['titre'] ?? ''}', style: theme.textTheme.titleMedium),
                  const SizedBox(height: 6),
                  Text('${alerte['message'] ?? ''}', style: theme.textTheme.bodyLarge),
                  if (alerte['publiee_le'] is String) ...[
                    const SizedBox(height: 6),
                    Text(
                      dateHeureLisible(DateTime.parse(alerte['publiee_le'] as String)),
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                  if (!alerteEstLue(alerte)) ...[
                    const SizedBox(height: 12),
                    GrosBouton(icone: Icons.done, libelle: textes.alerteMarquerLue, onPressed: () => _marquerLue(alerte)),
                  ],
                ],
              ),
            ),
          ),
      ],
    );
  }
}
