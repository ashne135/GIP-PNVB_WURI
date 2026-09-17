import 'package:flutter/material.dart';

import '../composants/cadre_ecran.dart';
import '../composants/formulaire.dart';
import '../composants/gros_bouton.dart';
import '../donnees/depot_travail.dart';
import '../l10n/textes.dart';
import '../outils/horodatage.dart';
import '../session/controleur_session.dart';

/// MES APPRÉCIATIONS, ET MON DROIT DE RÉPONSE (cadrage, section 9).
///
/// Pas de notation invisible : l'agent voit ce que son supérieur a écrit sur
/// lui, et peut y répondre par une observation écrite, horodatée, que personne
/// ne pourra modifier. La réponse s'écrit même sans réseau.
class EcranAppreciations extends StatefulWidget {
  const EcranAppreciations({super.key, required this.session});

  final ControleurSession session;

  @override
  State<EcranAppreciations> createState() => _EtatEcranAppreciations();
}

class _EtatEcranAppreciations extends State<EcranAppreciations> {
  List<Map<String, dynamic>> _appreciations = const [];
  DateTime? _du;
  bool _chargement = true;

  int get _utilisateur => widget.session.utilisateurId!;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    final donnee = await widget.session.travail.lire(_utilisateur, CleTravail.appreciations);
    final appreciations = await widget.session.travail.liste(_utilisateur, CleTravail.appreciations);

    if (mounted) {
      setState(() {
        _appreciations = appreciations;
        _du = donnee?.misAJourLe;
        _chargement = false;
      });
    }
  }

  Future<void> _repondre(Map<String, dynamic> appreciation) async {
    final texte = await Navigator.of(context).push<String>(MaterialPageRoute(
      builder: (_) => const _EcranReponse(),
    ));

    if (texte == null || texte.trim().isEmpty) {
      return;
    }

    final maintenant = DateTime.now();

    await widget.session.enregistrer(type: 'reponse_appreciation', contenu: {
      'suivi_agent_id': appreciation['id'],
      'reponse': texte.trim(),
      'horodatage_telephone': horodatageIso(maintenant),
    });

    // La réponse apparaît tout de suite, marquée « en attente d'envoi ».
    final misesAJour = [
      for (final a in _appreciations)
        a['id'] == appreciation['id']
            ? {
                ...a,
                'reponses': [
                  ...(a['reponses'] as List? ?? const []),
                  {'reponse': texte.trim(), 'repondu_le': horodatageIso(maintenant), 'en_attente': true},
                ],
              }
            : a,
    ];
    await widget.session.travail.ecrire(_utilisateur, CleTravail.appreciations, misesAJour);

    if (mounted) {
      afficherConfirmation(context, Textes.of(context).enregistre);
      await _charger();
    }
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);

    return CadreEcran(
      titre: textes.actionAppreciations,
      enfants: [
        if (_chargement) const Center(child: CircularProgressIndicator()),
        if (!_chargement) BandeauDonnees(misAJourLe: _du),
        if (!_chargement && _appreciations.isEmpty) Text(textes.appreciationsAucune, style: theme.textTheme.bodyLarge),
        for (final appreciation in _appreciations)
          SectionFormulaire(
            titre: textes.appreciationDu(
              '${(appreciation['rapport'] as Map?)?['date_rapport'] ?? ''}'.split('T').first,
              Libelles.nomDe((appreciation['rapport'] as Map?)?['auteur']),
            ),
            enfants: [
              Text(textes.appreciationProduction(Libelles.production(textes, appreciation['production'] as String?))),
              if ((appreciation['anomalies'] as List? ?? const []).isNotEmpty)
                Text(textes.appreciationAnomalies(
                  (appreciation['anomalies'] as List).whereType<String>().map((a) => Libelles.anomalie(textes, a)).join(', '),
                )),
              if ((appreciation['observation'] as String?)?.isNotEmpty == true)
                Text(textes.appreciationObservation(appreciation['observation'] as String)),
              for (final reponse in (appreciation['reponses'] as List? ?? const []).whereType<Map>()) ...[
                const Divider(),
                Text(
                  reponse['en_attente'] == true
                      ? textes.appreciationReponseEnAttente
                      : textes.appreciationVotreReponseDu(
                          reponse['repondu_le'] is String ? dateHeureLisible(DateTime.parse(reponse['repondu_le'] as String)) : '—',
                        ),
                  style: theme.textTheme.labelLarge,
                ),
                Text('${reponse['reponse'] ?? ''}'),
              ],
              const SizedBox(height: 12),
              GrosBouton(
                icone: Icons.reply,
                libelle: textes.appreciationRepondre,
                secondaire: true,
                onPressed: () => _repondre(appreciation),
              ),
            ],
          ),
      ],
    );
  }
}

class _EcranReponse extends StatefulWidget {
  const _EcranReponse();

  @override
  State<_EcranReponse> createState() => _EtatEcranReponse();
}

class _EtatEcranReponse extends State<_EcranReponse> {
  final _texte = TextEditingController();
  bool _tentative = false;

  @override
  void dispose() {
    _texte.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);

    return CadreEcran(
      titre: textes.appreciationRepondre,
      enfants: [
        Text(textes.appreciationReponseNonModifiable, style: Theme.of(context).textTheme.bodyLarge),
        const SizedBox(height: 12),
        ChampTexte(
          libelle: textes.appreciationVotreReponse,
          controleur: _texte,
          lignes: 6,
          erreur: _tentative && _texte.text.trim().isEmpty ? textes.champObligatoire : null,
        ),
        const SizedBox(height: 16),
        GrosBouton(
          icone: Icons.send,
          libelle: textes.appreciationEnvoyerReponse,
          onPressed: () {
            setState(() => _tentative = true);

            if (_texte.text.trim().isNotEmpty) {
              Navigator.of(context).pop(_texte.text);
            }
          },
        ),
      ],
    );
  }
}
