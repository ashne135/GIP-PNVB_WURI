import 'package:flutter/material.dart';

import '../composants/cadre_ecran.dart';
import '../composants/dialogues.dart';
import '../composants/formulaire.dart';
import '../composants/gros_bouton.dart';
import '../donnees/depot_travail.dart';
import '../l10n/textes.dart';
import '../outils/horodatage.dart';
import '../services/service_position.dart';
import '../session/controleur_session.dart';

/// LES FEUILLES DE PRÉSENCE DU JOUR — la seule pièce qui fait foi (section 8.3).
///
/// Une feuille par site et par jour, préparée par le serveur tant que le réseau
/// était là, avec les agents attendus et l'état de leur signal d'arrivée. Le
/// superviseur marque et valide sur place, réseau ou pas.
class EcranFeuillesPresence extends StatefulWidget {
  const EcranFeuillesPresence({super.key, required this.session});

  final ControleurSession session;

  @override
  State<EcranFeuillesPresence> createState() => _EtatEcranFeuillesPresence();
}

class _EtatEcranFeuillesPresence extends State<EcranFeuillesPresence> {
  List<Map<String, dynamic>> _feuilles = const [];
  DateTime? _du;
  bool _chargement = true;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    final id = widget.session.utilisateurId!;
    final donnee = await widget.session.travail.lire(id, CleTravail.feuillesDuJour);
    final feuilles = await widget.session.travail.liste(id, CleTravail.feuillesDuJour);

    if (mounted) {
      setState(() {
        _feuilles = feuilles;
        _du = donnee?.misAJourLe;
        _chargement = false;
      });
    }
  }

  Future<void> _ouvrir(Map<String, dynamic> feuille) async {
    await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => EcranFeuillePresence(session: widget.session, feuille: feuille),
    ));
    await _charger();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);

    return CadreEcran(
      titre: textes.actionFeuilles,
      enfants: [
        if (_chargement) const Center(child: CircularProgressIndicator()),
        if (!_chargement) BandeauDonnees(misAJourLe: _du),
        if (!_chargement && _feuilles.isEmpty) Text(textes.feuillesAucune, style: Theme.of(context).textTheme.bodyLarge),
        for (final feuille in _feuilles)
          SectionFormulaire(
            titre: _nomDuSite(feuille),
            enfants: [
              Text(_etat(textes, feuille), style: const TextStyle(fontWeight: FontWeight.w700)),
              Text(textes.feuilleAgents((feuille['lignes'] as List? ?? const []).length)),
              const SizedBox(height: 12),
              GrosBouton(
                icone: Icons.fact_check,
                libelle: textes.feuilleOuvrir,
                secondaire: feuille['statut'] != 'brouillon',
                onPressed: () => _ouvrir(feuille),
              ),
            ],
          ),
      ],
    );
  }
}

String _nomDuSite(Map<String, dynamic> feuille) {
  final site = feuille['site'];

  return site is Map ? '${site['code'] ?? ''} — ${site['nom'] ?? ''}' : '—';
}

String _etat(Textes textes, Map<String, dynamic> feuille) {
  if (feuille['en_attente_envoi'] == true) {
    return textes.feuilleValideeEnAttente;
  }

  return feuille['statut'] == 'brouillon' ? textes.feuilleAValider : textes.feuilleValidee;
}

/// UNE FEUILLE, MARQUÉE PUIS VALIDÉE.
///
/// RIEN N'EST PRÉSUMÉ : sur une feuille à valider, aucun agent n'est coché
/// d'avance — le superviseur marque chacun. Le signal d'arrivée est affiché
/// pour l'aider, jamais pour décider à sa place.
class EcranFeuillePresence extends StatefulWidget {
  const EcranFeuillePresence({
    super.key,
    required this.session,
    required this.feuille,
    this.position = const ServicePosition(),
  });

  final ControleurSession session;
  final Map<String, dynamic> feuille;
  final ServicePosition position;

  @override
  State<EcranFeuillePresence> createState() => _EtatEcranFeuillePresence();
}

class _EtatEcranFeuillePresence extends State<EcranFeuillePresence> {
  late final List<Map<String, dynamic>> _lignes = (widget.feuille['lignes'] as List? ?? const [])
      .whereType<Map>()
      .map((ligne) => ligne.cast<String, dynamic>())
      .toList();

  late final bool _validee = widget.feuille['statut'] != 'brouillon';

  late final Map<int, String?> _statuts = {
    for (final ligne in _lignes) ligne['volontaire_id'] as int: _validee ? ligne['statut'] as String? : null,
  };

  late final Map<int, TextEditingController> _motifs = {
    for (final ligne in _lignes)
      ligne['volontaire_id'] as int: TextEditingController(text: (ligne['motif_absence'] as String?) ?? ''),
  };

  bool _tentative = false;
  bool _enCours = false;

  @override
  void dispose() {
    for (final motif in _motifs.values) {
      motif.dispose();
    }
    super.dispose();
  }

  bool get _toutMarque => _statuts.values.every((statut) => statut != null);

  bool _motifManquant(int agent) => _statuts[agent] == 'absent_justifie' && _motifs[agent]!.text.trim().isEmpty;

  String? _motifDe(int agent) => _statuts[agent] == 'absent_justifie' ? _motifs[agent]!.text.trim() : null;

  Future<void> _valider() async {
    final textes = Textes.of(context);

    setState(() => _tentative = true);

    if (!_toutMarque || _statuts.keys.any(_motifManquant)) {
      return;
    }

    final confirme = await confirmer(
      context,
      titre: textes.feuilleConfirmationTitre,
      texte: textes.feuilleConfirmationTexte,
      action: textes.feuilleValider,
    );

    if (!confirme || !mounted) {
      return;
    }

    setState(() => _enCours = true);

    final position = await positionDeLActe(context, widget.position, consequence: textes.sansPositionFeuille);

    if (position == null) {
      if (mounted) {
        setState(() => _enCours = false);
      }

      return;
    }

    await widget.session.enregistrer(
      type: 'feuille_presence',
      // L'uuid de la feuille préparée par le serveur : la validation hors ligne
      // retrouve la même feuille, sans en créer une seconde.
      uuidClient: widget.feuille['uuid_client'] as String?,
      contenu: {
        'site_id': widget.feuille['site_id'],
        'date_presence': '${widget.feuille['date_presence']}'.substring(0, 10),
        'lignes': [
          for (final agent in _statuts.keys)
            {
              'volontaire_id': agent,
              'statut': _statuts[agent],
              if (_motifDe(agent) != null) 'motif_absence': _motifDe(agent),
            },
        ],
        ...position,
      },
    );

    await _memoriserValidation();

    if (!mounted) {
      return;
    }

    afficherConfirmation(context, textes.feuilleValideeEnregistree);
    Navigator.of(context).pop(true);
  }

  /// La feuille reste marquée validée sur le téléphone, en attente d'envoi : le
  /// superviseur ne la revalide pas, et la voit telle qu'il l'a signée.
  Future<void> _memoriserValidation() async {
    final id = widget.session.utilisateurId!;
    final feuilles = await widget.session.travail.liste(id, CleTravail.feuillesDuJour);

    await widget.session.travail.ecrire(id, CleTravail.feuillesDuJour, [
      for (final feuille in feuilles)
        feuille['uuid_client'] == widget.feuille['uuid_client']
            ? {
                ...feuille,
                'statut': 'validee',
                'en_attente_envoi': true,
                'lignes': [
                  for (final ligne in _lignes)
                    {
                      ...ligne,
                      'statut': _statuts[ligne['volontaire_id']],
                      'motif_absence': _motifDe(ligne['volontaire_id'] as int),
                    },
                ],
              }
            : feuille,
    ]);
  }

  String _signal(Textes textes, Map<String, dynamic> ligne) {
    final heure = ligne['heure_arrivee_signalee'];

    if (heure is! String) {
      return textes.feuilleAucunSignal;
    }

    final zone = switch (ligne['dans_zone']) {
      true => textes.feuilleSignalDansZone,
      false => textes.feuilleSignalHorsZone,
      _ => '',
    };

    return textes.feuilleSignal(heureLisible(DateTime.parse(heure)), Libelles.nombre(ligne['distance_signalee']), zone);
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);
    final presences = Libelles.presences(textes);

    return CadreEcran(
      titre: _nomDuSite(widget.feuille),
      enfants: [
        Text(textes.rapportJournee(jourLisible(widget.feuille['date_presence'])), style: theme.textTheme.titleLarge),
        const SizedBox(height: 8),
        Text(_validee ? textes.feuilleDejaValidee : textes.feuilleAide, style: theme.textTheme.bodyLarge),
        const SizedBox(height: 16),
        if (_tentative && !_toutMarque) MessageErreur(textes.feuilleNonMarque),
        for (final ligne in _lignes)
          SectionFormulaire(
            titre: Libelles.nomDe(ligne['volontaire']),
            enfants: [
              Text(
                '${(ligne['volontaire'] as Map?)?['matricule'] ?? ''} · ${Libelles.categorie(textes, ligne['categorie'])}',
              ),
              Text(_signal(textes, ligne), style: theme.textTheme.bodySmall),
              const SizedBox(height: 8),
              if (_validee)
                Text(
                  presences[_statuts[ligne['volontaire_id']]] ?? '—',
                  style: theme.textTheme.titleMedium,
                )
              else ...[
                ChoixUnique<String>(
                  options: presences,
                  valeur: _statuts[ligne['volontaire_id']],
                  onChange: (statut) => setState(() => _statuts[ligne['volontaire_id'] as int] = statut),
                  erreur: _tentative && _statuts[ligne['volontaire_id']] == null ? textes.champObligatoire : null,
                ),
                if (_statuts[ligne['volontaire_id']] == 'absent_justifie')
                  ChampTexte(
                    libelle: textes.feuilleMotif,
                    controleur: _motifs[ligne['volontaire_id']]!,
                    erreur: _tentative && _motifManquant(ligne['volontaire_id'] as int)
                        ? textes.feuilleMotifObligatoire
                        : null,
                  ),
              ],
            ],
          ),
        if (!_validee)
          GrosBouton(icone: Icons.verified, libelle: textes.feuilleValider, enCours: _enCours, onPressed: _valider),
        const SizedBox(height: 24),
      ],
    );
  }
}
