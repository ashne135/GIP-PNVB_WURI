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

/// LES RAPPORTS QUI ATTENDENT MON VISA, MÊME SANS RÉSEAU.
///
/// Un opérateur qui vise le soir les rapports de ses A-OPK, sur un site sans
/// couverture, doit pouvoir le faire : sinon toute la chaîne de visas se bloque
/// au premier maillon. Le serveur n'a préparé ici que les rapports dont ce
/// compte est LE supérieur désigné.
class EcranRapportsAViser extends StatefulWidget {
  const EcranRapportsAViser({super.key, required this.session});

  final ControleurSession session;

  @override
  State<EcranRapportsAViser> createState() => _EtatEcranRapportsAViser();
}

class _EtatEcranRapportsAViser extends State<EcranRapportsAViser> {
  List<Map<String, dynamic>> _rapports = const [];
  DateTime? _du;
  bool _chargement = true;

  int get _utilisateur => widget.session.utilisateurId!;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    final donnee = await widget.session.travail.lire(_utilisateur, CleTravail.rapportsAViser);
    final rapports = await widget.session.travail.liste(_utilisateur, CleTravail.rapportsAViser);

    if (mounted) {
      setState(() {
        _rapports = rapports;
        _du = donnee?.misAJourLe;
        _chargement = false;
      });
    }
  }

  Future<void> _ouvrir(Map<String, dynamic> rapport) async {
    final tranche = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => EcranVisaRapport(session: widget.session, rapport: rapport),
    ));

    if (tranche != true) {
      return;
    }

    // Le rapport tranché quitte la liste du téléphone ; la prochaine
    // préparation de la journée la relira du serveur.
    await widget.session.travail.ecrire(
      _utilisateur,
      CleTravail.rapportsAViser,
      _rapports.where((r) => r['uuid_client'] != rapport['uuid_client']).toList(),
    );
    await _charger();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);

    return CadreEcran(
      titre: textes.actionVisas,
      enfants: [
        if (_chargement) const Center(child: CircularProgressIndicator()),
        if (!_chargement) BandeauDonnees(misAJourLe: _du),
        if (!_chargement) Padding(padding: const EdgeInsets.only(bottom: 16), child: Text(textes.visasAide)),
        if (!_chargement && _rapports.isEmpty) Text(textes.visasAucun, style: theme.textTheme.bodyLarge),
        for (final rapport in _rapports)
          SectionFormulaire(
            titre: '${Libelles.typeRapport(textes, rapport['type'])} — ${jourLisible(rapport['date_rapport'])}',
            enfants: [
              Text(_auteur(rapport), style: theme.textTheme.bodyLarge),
              if (rapport['site'] is Map) Text('${(rapport['site'] as Map)['code']} — ${(rapport['site'] as Map)['nom']}'),
              if (rapport['soumis_le'] is String)
                Text(textes.visaSigneLe(dateHeureLisible(DateTime.parse(rapport['soumis_le'] as String)))),
              const SizedBox(height: 12),
              GrosBouton(icone: Icons.fact_check, libelle: textes.visaOuvrir, onPressed: () => _ouvrir(rapport)),
            ],
          ),
      ],
    );
  }
}

String _auteur(Map<String, dynamic> rapport) {
  final auteur = rapport['auteur'];

  return auteur is Map ? '${auteur['matricule'] ?? ''} — ${Libelles.nomDe(auteur)}' : '—';
}

/// UN RAPPORT, LU PUIS VISÉ OU RENVOYÉ.
///
/// Le renvoi exige un motif : sans lui, l'auteur ne sait pas quoi corriger. Le
/// visa part avec la position et l'heure du téléphone, comme en ligne.
class EcranVisaRapport extends StatefulWidget {
  const EcranVisaRapport({
    super.key,
    required this.session,
    required this.rapport,
    this.position = const ServicePosition(),
  });

  final ControleurSession session;
  final Map<String, dynamic> rapport;
  final ServicePosition position;

  @override
  State<EcranVisaRapport> createState() => _EtatEcranVisaRapport();
}

class _EtatEcranVisaRapport extends State<EcranVisaRapport> {
  final _commentaire = TextEditingController();
  final _motif = TextEditingController();
  bool _tentativeRenvoi = false;
  bool _enCours = false;

  @override
  void dispose() {
    _commentaire.dispose();
    _motif.dispose();
    super.dispose();
  }

  Future<void> _trancher(String acte) async {
    final textes = Textes.of(context);

    if (acte == 'rejet') {
      setState(() => _tentativeRenvoi = true);

      if (_motif.text.trim().isEmpty) {
        return;
      }
    }

    setState(() => _enCours = true);

    final position = await positionDeLActe(context, widget.position);

    if (position == null) {
      if (mounted) {
        setState(() => _enCours = false);
      }

      return;
    }

    await widget.session.enregistrer(type: 'visa_rapport', contenu: {
      'rapport_uuid': widget.rapport['uuid_client'],
      'acte': acte,
      if (acte == 'visa' && _commentaire.text.trim().isNotEmpty) 'commentaire': _commentaire.text.trim(),
      if (acte == 'rejet') 'motif': _motif.text.trim(),
      ...position,
      'horodatage_telephone': horodatageIso(DateTime.now()),
    });

    if (!mounted) {
      return;
    }

    afficherConfirmation(context, acte == 'visa' ? textes.visaFait : textes.renvoiFait);
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final rapport = widget.rapport;

    return CadreEcran(
      titre: Libelles.typeRapport(textes, rapport['type']),
      enfants: [
        Text(textes.rapportJournee(jourLisible(rapport['date_rapport'])), style: Theme.of(context).textTheme.titleLarge),
        Text(_auteur(rapport), style: Theme.of(context).textTheme.bodyLarge),
        const SizedBox(height: 16),
        ResumeChiffresRapport(rapport: rapport),
        Padding(
          padding: const EdgeInsets.only(bottom: 16),
          child: Text(textes.visaContenuPartiel, style: Theme.of(context).textTheme.bodySmall),
        ),
        SectionFormulaire(titre: textes.visaViser, enfants: [
          ChampTexte(libelle: textes.visaCommentaire, controleur: _commentaire, lignes: 2),
          const SizedBox(height: 8),
          GrosBouton(icone: Icons.verified, libelle: textes.visaViser, enCours: _enCours, onPressed: () => _trancher('visa')),
        ]),
        SectionFormulaire(titre: textes.visaRenvoyerTitre, enfants: [
          ChampTexte(
            libelle: textes.visaMotif,
            controleur: _motif,
            lignes: 3,
            erreur: _tentativeRenvoi && _motif.text.trim().isEmpty ? textes.visaMotifObligatoire : null,
          ),
          const SizedBox(height: 8),
          GrosBouton(
            icone: Icons.undo,
            libelle: textes.visaRenvoyer,
            secondaire: true,
            enCours: _enCours,
            onPressed: () => _trancher('rejet'),
          ),
        ]),
        const SizedBox(height: 24),
      ],
    );
  }
}

/// Les chiffres d'un rapport, en lecture, tels que le serveur les a rendus.
/// Les écarts et les taux sont ceux calculés par la base : rien n'est refait ici.
class ResumeChiffresRapport extends StatelessWidget {
  const ResumeChiffresRapport({super.key, required this.rapport});

  final Map<String, dynamic> rapport;

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final ligne = textes.rapportLigne;

    Map<String, dynamic>? bloc(String cle) => rapport[cle] is Map ? (rapport[cle] as Map).cast<String, dynamic>() : null;

    final production = bloc('production_opk');
    final activites = bloc('activites_aopk');
    final evolution = bloc('evolution');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (production != null)
          SectionFormulaire(titre: textes.rapportProduction, enfants: [
            Text(ligne(textes.productionObjectif, Libelles.nombre(production['objectif_enregistrements']))),
            Text(ligne(textes.productionEnregistrements, Libelles.nombre(production['enregistrements_realises']))),
            Text(ligne(textes.productionEcart, Libelles.nombre(production['ecart_enregistrements']))),
            Text(ligne(textes.productionTaux, production['taux_realisation'] == null ? '—' : '${production['taux_realisation']} %')),
            Text(ligne(textes.productionRecepisses, Libelles.nombre(production['recepisses_transmis']))),
            Text(ligne(textes.productionNonValides, Libelles.nombre(production['enregistrements_non_valides']))),
            if ((production['motif_non_valides'] as String?)?.isNotEmpty == true)
              Text(production['motif_non_valides'] as String),
            Text(ligne(textes.productionEtatKit, _etatKit(textes, production['etat_kit']))),
          ]),
        if (activites != null)
          SectionFormulaire(titre: textes.rapportActivites, enfants: [
            Text(textes.rapportPrevuRealise(
              textes.activiteAffluence,
              _affluence(textes, activites['affluence_prevue']),
              _affluence(textes, activites['affluence_realisee']),
            )),
            for (final (libelle, cle) in [
              (textes.activiteJustificatifsRecus, 'justificatifs_recus'),
              (textes.activiteJustificatifsTransmis, 'justificatifs_transmis'),
              (textes.activitePlaintesEnregistrees, 'plaintes_enregistrees'),
              (textes.activitePlaintesReversees, 'plaintes_reversees'),
            ])
              Text(textes.rapportPrevuRealise(
                libelle,
                Libelles.nombre(activites['${cle}_prevu']),
                Libelles.nombre(activites['${cle}_realise']),
              )),
          ]),
        if (evolution != null)
          SectionFormulaire(titre: textes.rapportEvolution, enfants: [
            Text(ligne(textes.evolutionPersonnes, Libelles.nombre(evolution['personnes_enregistrees']))),
            Text(ligne(textes.evolutionValides, Libelles.nombre(evolution['dossiers_valides']))),
            Text(ligne(textes.evolutionAReprendre, Libelles.nombre(evolution['dossiers_a_reprendre']))),
          ]),
      ],
    );
  }

  static String _affluence(Textes textes, Object? valeur) => switch (valeur) {
        'faible' => textes.affluenceFaible,
        'moyen' => textes.affluenceMoyen,
        'eleve' => textes.affluenceEleve,
        _ => '—',
      };

  static String _etatKit(Textes textes, Object? valeur) => switch (valeur) {
        'fonctionnel' => textes.etatKitFonctionnel,
        'panne_partielle' => textes.etatKitPannePartielle,
        'panne_totale' => textes.etatKitPanneTotale,
        _ => '—',
      };
}
