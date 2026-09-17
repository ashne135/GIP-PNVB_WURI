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
import '../theme.dart';
import 'ecran_rapports_a_viser.dart' show ResumeChiffresRapport;

/// LE RAPPORT DU JOUR, SAISI ET SIGNÉ SUR PLACE (cadrage, section 9).
///
/// Le serveur a OUVERT le rapport tant que le réseau était là : son type, son
/// bloc d'identification, l'objectif figé, les chiffres consolidés et les
/// agents suivis viennent de lui. Le téléphone n'apporte que ce que l'agent
/// saisit réellement.
///
/// TROIS RÈGLES DU CADRAGE, tenues ici comme au serveur :
///   - rien de calculé ne se saisit : écart, taux de réalisation, taux de
///     conformité restent au serveur ;
///   - rien de pré-rempli ne se ressaisit : l'évolution du superviseur est
///     montrée, jamais renvoyée — sa correction passe par le back-office, avec
///     un motif ;
///   - la présence d'un agent vient de la feuille validée, pas du rapport.
class EcranRapport extends StatefulWidget {
  const EcranRapport({super.key, required this.session, this.position = const ServicePosition()});

  final ControleurSession session;
  final ServicePosition position;

  @override
  State<EcranRapport> createState() => _EtatEcranRapport();
}

const _activitesComptees = ['justificatifs_recus', 'justificatifs_transmis', 'plaintes_enregistrees', 'plaintes_reversees'];

const _qualite = [
  'dossiers_controles',
  'dossiers_conformes',
  'dossiers_non_conformes',
  'doublons_detectes',
  'erreurs_saisie',
  'corrections_effectuees',
  'incidents_majeurs',
];

String _texte(Object? valeur) => valeur == null ? '' : '$valeur';

int? _entier(TextEditingController champ) => int.tryParse(champ.text.trim());

String? _ouNull(TextEditingController champ) => champ.text.trim().isEmpty ? null : champ.text.trim();

/// Une ressource de la situation logistique. Les ressources du canevas client
/// sont posées par le serveur ; l'agent peut en ajouter, et retirer celles qu'il
/// a ajoutées.
class _LigneLogistique {
  _LigneLogistique(Map<String, dynamic> ligne, {this.ajoutee = false})
      : ressource = TextEditingController(text: _texte(ligne['ressource'])),
        disponible = TextEditingController(text: _texte(ligne['disponible'])),
        fonctionnelle = TextEditingController(text: _texte(ligne['fonctionnelle'])),
        besoin = TextEditingController(text: _texte(ligne['besoin'])),
        observation = TextEditingController(text: _texte(ligne['observation']));

  final bool ajoutee;
  final TextEditingController ressource;
  final TextEditingController disponible;
  final TextEditingController fonctionnelle;
  final TextEditingController besoin;
  final TextEditingController observation;

  List<TextEditingController> get _champs => [ressource, disponible, fonctionnelle, besoin, observation];

  bool get estVide => _champs.every((champ) => champ.text.trim().isEmpty);

  bool get sansRessource => ressource.text.trim().isEmpty && !estVide;

  Map<String, dynamic> enDonnees() => {
        'ressource': ressource.text.trim(),
        'disponible': _entier(disponible),
        'fonctionnelle': _entier(fonctionnelle),
        'besoin': _entier(besoin),
        'observation': _ouNull(observation),
      };

  void liberer() {
    for (final champ in _champs) {
      champ.dispose();
    }
  }
}

class _LigneDifficulte {
  _LigneDifficulte(Map<String, dynamic> ligne)
      : difficulte = TextEditingController(text: _texte(ligne['difficulte'])),
        solution = TextEditingController(text: _texte(ligne['solution']));

  final TextEditingController difficulte;
  final TextEditingController solution;

  bool get estVide => difficulte.text.trim().isEmpty && solution.text.trim().isEmpty;

  /// Une solution sans la difficulté qu'elle résout ne veut rien dire.
  bool get sansDifficulte => difficulte.text.trim().isEmpty && solution.text.trim().isNotEmpty;

  Map<String, dynamic> enDonnees() => {'difficulte': difficulte.text.trim(), 'solution': _ouNull(solution)};

  void liberer() {
    difficulte.dispose();
    solution.dispose();
  }
}

class _Suivi {
  _Suivi(this.agent)
      : production = agent['production'] as String?,
        anomalies = (agent['anomalies'] as List? ?? const []).whereType<String>().toSet(),
        observation = TextEditingController(text: _texte(agent['observation']));

  final Map<String, dynamic> agent;
  String? production;
  Set<String> anomalies;
  final TextEditingController observation;
}

class _EtatEcranRapport extends State<EcranRapport> {
  Map<String, dynamic>? _rapport;
  DateTime? _du;
  bool _chargement = true;
  bool _tentative = false;
  bool _enCours = false;

  String? _heureArrivee;
  String? _heureDepart;

  String? _affluencePrevue;
  String? _affluenceRealisee;
  final Map<String, TextEditingController> _activites = {};

  final Map<String, TextEditingController> _production = {};
  String _etatKit = 'fonctionnel';

  final Map<String, TextEditingController> _controleQualite = {};
  final List<_LigneLogistique> _logistique = [];

  final List<_LigneDifficulte> _difficultes = [];
  final List<TextEditingController> _points = [];
  final List<_Suivi> _suivis = [];

  int get _utilisateur => widget.session.utilisateurId!;

  String? get _type => _rapport?['type'] as String?;

  /// Seuls un brouillon et un rapport renvoyé pour correction se modifient.
  bool get _modifiable => const ['brouillon', 'rejete'].contains(_rapport?['statut']);

  Map<String, dynamic> _bloc(String cle) {
    final valeur = _rapport?[cle];

    return valeur is Map ? valeur.cast<String, dynamic>() : <String, dynamic>{};
  }

  List<Map<String, dynamic>> _lignes(String cle) =>
      (_rapport?[cle] as List? ?? const []).whereType<Map>().map((ligne) => ligne.cast<String, dynamic>()).toList();

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    final donnee = await widget.session.travail.lire(_utilisateur, CleTravail.rapportDuJour);

    if (!mounted) {
      return;
    }

    setState(() {
      _rapport = donnee?.contenu is Map ? (donnee!.contenu as Map).cast<String, dynamic>() : null;
      _du = donnee?.misAJourLe;
      _chargement = false;

      if (_rapport != null) {
        _remplir();
      }
    });
  }

  void _remplir() {
    _heureArrivee = _heure(_rapport!['heure_arrivee']);
    _heureDepart = _heure(_rapport!['heure_depart']);

    final activites = _bloc('activites_aopk');
    _affluencePrevue = activites['affluence_prevue'] as String?;
    _affluenceRealisee = activites['affluence_realisee'] as String?;

    for (final activite in _activitesComptees) {
      for (final colonne in ['${activite}_prevu', '${activite}_realise']) {
        _activites[colonne] = TextEditingController(text: _texte(activites[colonne]));
      }
    }

    final production = _bloc('production_opk');

    for (final cle in ['enregistrements_realises', 'recepisses_transmis', 'enregistrements_non_valides', 'motif_non_valides']) {
      _production[cle] = TextEditingController(text: _texte(production[cle]));
    }

    _etatKit = (production['etat_kit'] as String?) ?? 'fonctionnel';

    final qualite = _bloc('qualite');

    for (final cle in _qualite) {
      _controleQualite[cle] = TextEditingController(text: _texte(qualite[cle]));
    }

    _logistique.addAll(_lignes('logistique').map(_LigneLogistique.new));
    _difficultes.addAll(_lignes('difficultes').map(_LigneDifficulte.new));
    _points.addAll(_lignes('points_amelioration').map((point) => TextEditingController(text: _texte(point['point']))));
    _suivis.addAll(_lignes('suivi_agents').map(_Suivi.new));
  }

  @override
  void dispose() {
    for (final champ in [..._activites.values, ..._production.values, ..._controleQualite.values, ..._points]) {
      champ.dispose();
    }
    for (final ligne in _logistique) {
      ligne.liberer();
    }
    for (final ligne in _difficultes) {
      ligne.liberer();
    }
    for (final suivi in _suivis) {
      suivi.observation.dispose();
    }
    super.dispose();
  }

  static String? _heure(Object? valeur) => valeur is String && valeur.length >= 5 ? valeur.substring(0, 5) : null;

  Future<void> _choisirHeure({required bool arrivee}) async {
    final actuelle = (arrivee ? _heureArrivee : _heureDepart)?.split(':');

    final choisie = await showTimePicker(
      context: context,
      initialTime: actuelle == null
          ? TimeOfDay.now()
          : TimeOfDay(hour: int.parse(actuelle[0]), minute: int.parse(actuelle[1])),
      builder: (context, enfant) => MediaQuery(
        data: MediaQuery.of(context).copyWith(alwaysUse24HourFormat: true),
        child: enfant!,
      ),
    );

    if (choisie == null || !mounted) {
      return;
    }

    final texte = '${choisie.hour.toString().padLeft(2, '0')}:${choisie.minute.toString().padLeft(2, '0')}';

    setState(() {
      if (arrivee) {
        _heureArrivee = texte;
      } else {
        _heureDepart = texte;
      }
    });
  }

  bool get _motifProductionManquant =>
      _type == 'opk' &&
      (_entier(_production['enregistrements_non_valides']!) ?? 0) > 0 &&
      _production['motif_non_valides']!.text.trim().isEmpty;

  bool get _valable =>
      !_motifProductionManquant &&
      !_logistique.any((ligne) => ligne.sansRessource) &&
      !_difficultes.any((ligne) => ligne.sansDifficulte);

  Map<String, dynamic> _contenu({required bool soumettre, required Map<String, Object> position}) => {
        'date_rapport': '${_rapport!['date_rapport']}'.substring(0, 10),
        'horodatage_telephone': horodatageIso(DateTime.now()),
        'heure_arrivee': ?_heureArrivee,
        'heure_depart': ?_heureDepart,
        if (_type == 'aopk')
          'activites': {
            'affluence_prevue': _affluencePrevue,
            'affluence_realisee': _affluenceRealisee,
            for (final colonne in _activites.entries) colonne.key: _entier(colonne.value),
          },
        if (_type == 'opk')
          'production': {
            'enregistrements_realises': _entier(_production['enregistrements_realises']!),
            'recepisses_transmis': _entier(_production['recepisses_transmis']!),
            'enregistrements_non_valides': _entier(_production['enregistrements_non_valides']!),
            'motif_non_valides': _ouNull(_production['motif_non_valides']!),
            'etat_kit': _etatKit,
          },
        if (_type == 'superviseur') ...{
          'qualite': {for (final champ in _controleQualite.entries) champ.key: _entier(champ.value)},
          'logistique': [
            for (final ligne in _logistique)
              if (!ligne.estVide) ligne.enDonnees(),
          ],
        },
        'difficultes': [
          for (final ligne in _difficultes)
            if (!ligne.estVide) ligne.enDonnees(),
        ],
        'points_amelioration': [
          for (final point in _points)
            if (point.text.trim().isNotEmpty) point.text.trim(),
        ],
        if (_suivis.isNotEmpty)
          'suivi_agents': [
            for (final suivi in _suivis)
              {
                'volontaire_id': suivi.agent['volontaire_id'],
                'production': suivi.production,
                // Une liste vide se lirait « avec anomalie » côté serveur.
                'anomalies': suivi.anomalies.isEmpty ? null : suivi.anomalies.toList(),
                'observation': _ouNull(suivi.observation),
              },
          ],
        'soumettre': soumettre,
        ...position,
      };

  Future<void> _enregistrer({required bool signer}) async {
    final textes = Textes.of(context);

    setState(() => _tentative = true);

    if (!_valable) {
      return;
    }

    var position = const <String, Object>{};

    if (signer) {
      final confirme = await confirmer(
        context,
        titre: textes.rapportSignerTitre,
        texte: textes.rapportSignerTexte,
        action: textes.rapportSigner,
      );

      if (!confirme || !mounted) {
        return;
      }

      setState(() => _enCours = true);

      final releve = await positionDeLActe(context, widget.position);

      if (releve == null) {
        if (mounted) {
          setState(() => _enCours = false);
        }

        return;
      }

      position = releve;
    } else {
      setState(() => _enCours = true);
    }

    final contenu = _contenu(soumettre: signer, position: position);

    // L'uuid du rapport ouvert par le serveur : un nouvel enregistrement
    // remplace dans la file celui qui n'était pas encore parti.
    await widget.session.enregistrer(
      type: 'rapport_journalier',
      uuidClient: _rapport!['uuid_client'] as String?,
      contenu: contenu,
    );
    await _memoriser(contenu, signe: signer);

    if (!mounted) {
      return;
    }

    afficherConfirmation(context, signer ? textes.rapportSigne : textes.enregistre);
    setState(() => _enCours = false);

    if (signer) {
      Navigator.of(context).pop();
    }
  }

  /// Le rapport gardé sur le téléphone reprend ce qui vient d'être saisi : il se
  /// rouvre tel que l'agent l'a laissé, même avant l'envoi.
  Future<void> _memoriser(Map<String, dynamic> contenu, {required bool signe}) async {
    Map<String, dynamic> fusion(String cle, String saisi) => {
          ..._bloc(cle),
          if (contenu[saisi] is Map) ...(contenu[saisi] as Map).cast<String, dynamic>(),
        };

    final rapport = <String, dynamic>{
      ..._rapport!,
      'heure_arrivee': contenu['heure_arrivee'] ?? _rapport!['heure_arrivee'],
      'heure_depart': contenu['heure_depart'] ?? _rapport!['heure_depart'],
      'activites_aopk': fusion('activites_aopk', 'activites'),
      'production_opk': fusion('production_opk', 'production'),
      'qualite': fusion('qualite', 'qualite'),
      if (contenu['logistique'] is List) 'logistique': contenu['logistique'],
      'difficultes': contenu['difficultes'],
      'points_amelioration': [
        for (final point in contenu['points_amelioration'] as List) {'point': point},
      ],
      'suivi_agents': [
        for (final suivi in _suivis)
          {
            ...suivi.agent,
            'production': suivi.production,
            'anomalies': suivi.anomalies.toList(),
            'observation': _ouNull(suivi.observation),
          },
      ],
      if (signe) 'statut': 'soumis',
      'en_attente_envoi': true,
    };

    await widget.session.travail.ecrire(_utilisateur, CleTravail.rapportDuJour, rapport);
    _rapport = rapport;
  }

  void _retirer<T>(List<T> lignes, T ligne, void Function(T) liberer) {
    setState(() => lignes.remove(ligne));
    // Le champ est encore affiché pendant cette image : il est libéré après.
    WidgetsBinding.instance.addPostFrameCallback((_) => liberer(ligne));
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);
    final rapport = _rapport;

    if (_chargement) {
      return CadreEcran(titre: textes.actionRapport, enfants: const [Center(child: CircularProgressIndicator())]);
    }

    if (rapport == null) {
      return CadreEcran(titre: textes.actionRapport, enfants: [MessageErreur(textes.donneesAbsentes)]);
    }

    final signeEnAttente = rapport['en_attente_envoi'] == true && rapport['statut'] == 'soumis';

    return CadreEcran(
      titre: Libelles.typeRapport(textes, rapport['type']),
      enfants: [
        BandeauDonnees(misAJourLe: _du),
        Text(textes.rapportJournee(jourLisible(rapport['date_rapport'])), style: theme.textTheme.titleLarge),
        Text(
          signeEnAttente ? textes.rapportSigneEnAttente : Libelles.statutRapport(textes, rapport['statut']),
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 12),
        if (rapport['statut'] == 'rejete' && (rapport['motif_rejet'] as String?)?.isNotEmpty == true)
          MessageErreur(textes.rapportMotifRejet(rapport['motif_rejet'] as String)),
        _identification(textes),
        if (!_modifiable) ...[
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: Text(textes.rapportNonModifiable, style: theme.textTheme.bodyLarge),
          ),
          ResumeChiffresRapport(rapport: rapport),
        ] else ...[
          _heures(textes),
          if (_type == 'aopk') _activitesAopk(textes),
          if (_type == 'opk') _productionOpk(textes),
          if (_type == 'superviseur') ..._contenuSuperviseur(textes),
          if (_suivis.isNotEmpty) _suiviAgents(textes),
          _blocDifficultes(textes),
          _blocPoints(textes),
          GrosBouton(
            icone: Icons.save,
            libelle: textes.rapportEnregistrer,
            secondaire: true,
            enCours: _enCours,
            onPressed: () => _enregistrer(signer: false),
          ),
          const SizedBox(height: 12),
          GrosBouton(
            icone: Icons.draw,
            libelle: textes.rapportSigner,
            enCours: _enCours,
            onPressed: () => _enregistrer(signer: true),
          ),
          const SizedBox(height: 24),
        ],
      ],
    );
  }

  Widget _identification(Textes textes) {
    final centre = _rapport!['centre'];
    final site = _rapport!['site'];
    final superieur = _rapport!['superieur'];

    return SectionFormulaire(titre: textes.rapportIdentification, enfants: [
      Text(textes.rapportIdentificationAide, style: Theme.of(context).textTheme.bodySmall),
      const SizedBox(height: 6),
      Text(textes.rapportLigne(textes.rapportCentre, centre is Map ? '${centre['code']} — ${centre['nom']}' : '—')),
      Text(textes.rapportLigne(textes.rapportSite, site is Map ? '${site['code']} — ${site['nom']}' : '—')),
      Text(textes.rapportLigne(
        textes.rapportSuperieur,
        superieur is Map ? '${superieur['matricule'] ?? ''} — ${Libelles.nomDe(superieur)}' : '—',
      )),
    ]);
  }

  Widget _heures(Textes textes) {
    return SectionFormulaire(titre: '${textes.rapportHeureArrivee} / ${textes.rapportHeureDepart}', enfants: [
      GrosBouton(
        icone: Icons.schedule,
        libelle: textes.rapportLigne(textes.rapportHeureArrivee, _heureArrivee ?? textes.rapportHeureNonSaisie),
        secondaire: true,
        onPressed: () => _choisirHeure(arrivee: true),
      ),
      const SizedBox(height: 10),
      GrosBouton(
        icone: Icons.schedule,
        libelle: textes.rapportLigne(textes.rapportHeureDepart, _heureDepart ?? textes.rapportHeureNonSaisie),
        secondaire: true,
        onPressed: () => _choisirHeure(arrivee: false),
      ),
    ]);
  }

  /// 9.1 — L'A-OPK n'enregistre personne : il compte ce qui passe à l'accueil,
  /// en PRÉVU et en RÉALISÉ.
  Widget _activitesAopk(Textes textes) {
    final sousTitre = Theme.of(context).textTheme.titleSmall;
    final affluences = {'faible': textes.affluenceFaible, 'moyen': textes.affluenceMoyen, 'eleve': textes.affluenceEleve};
    final libelles = {
      'justificatifs_recus': textes.activiteJustificatifsRecus,
      'justificatifs_transmis': textes.activiteJustificatifsTransmis,
      'plaintes_enregistrees': textes.activitePlaintesEnregistrees,
      'plaintes_reversees': textes.activitePlaintesReversees,
    };

    return SectionFormulaire(titre: textes.rapportActivites, enfants: [
      Text('${textes.activiteAffluence} — ${textes.rapportPrevu}', style: sousTitre),
      ChoixUnique<String>(
        options: affluences,
        valeur: _affluencePrevue,
        onChange: (valeur) => setState(() => _affluencePrevue = valeur),
      ),
      const SizedBox(height: 8),
      Text('${textes.activiteAffluence} — ${textes.rapportRealise}', style: sousTitre),
      ChoixUnique<String>(
        options: affluences,
        valeur: _affluenceRealisee,
        onChange: (valeur) => setState(() => _affluenceRealisee = valeur),
      ),
      for (final activite in libelles.entries) ...[
        const SizedBox(height: 12),
        Text(activite.value, style: sousTitre),
        ChampNombre(libelle: textes.rapportPrevu, controleur: _activites['${activite.key}_prevu']!),
        ChampNombre(libelle: textes.rapportRealise, controleur: _activites['${activite.key}_realise']!),
      ],
    ]);
  }

  /// 9.2 — La production de l'opérateur. L'objectif est celui figé à l'ouverture.
  Widget _productionOpk(Textes textes) {
    final theme = Theme.of(context);

    return SectionFormulaire(titre: textes.rapportProduction, enfants: [
      Text(
        textes.rapportLigne(textes.productionObjectif, Libelles.nombre(_bloc('production_opk')['objectif_enregistrements'])),
        style: theme.textTheme.titleMedium,
      ),
      Text(textes.productionObjectifAide, style: theme.textTheme.bodySmall),
      ChampNombre(libelle: textes.productionEnregistrements, controleur: _production['enregistrements_realises']!),
      ChampNombre(libelle: textes.productionRecepisses, controleur: _production['recepisses_transmis']!),
      ChampNombre(libelle: textes.productionNonValides, controleur: _production['enregistrements_non_valides']!),
      ChampTexte(
        libelle: textes.productionMotifNonValides,
        controleur: _production['motif_non_valides']!,
        lignes: 2,
        erreur: _tentative && _motifProductionManquant ? textes.productionMotifObligatoire : null,
      ),
      const SizedBox(height: 8),
      Text(textes.productionEtatKit, style: theme.textTheme.titleSmall),
      ChoixUnique<String>(
        options: {
          'fonctionnel': textes.etatKitFonctionnel,
          'panne_partielle': textes.etatKitPannePartielle,
          'panne_totale': textes.etatKitPanneTotale,
        },
        valeur: _etatKit,
        onChange: (valeur) => setState(() => _etatKit = valeur),
      ),
    ]);
  }

  /// 9.3 — Le superviseur : l'évolution consolidée en lecture, la qualité et la
  /// logistique en saisie.
  List<Widget> _contenuSuperviseur(Textes textes) {
    final theme = Theme.of(context);
    final evolution = _bloc('evolution');
    final libellesQualite = {
      'dossiers_controles': textes.qualiteControles,
      'dossiers_conformes': textes.qualiteConformes,
      'dossiers_non_conformes': textes.qualiteNonConformes,
      'doublons_detectes': textes.qualiteDoublons,
      'erreurs_saisie': textes.qualiteErreurs,
      'corrections_effectuees': textes.qualiteCorrections,
      'incidents_majeurs': textes.qualiteIncidents,
    };

    return [
      SectionFormulaire(titre: textes.rapportEvolution, enfants: [
        Text(textes.rapportEvolutionAide, style: theme.textTheme.bodySmall),
        const SizedBox(height: 6),
        Text(textes.rapportLigne(textes.evolutionPersonnes, Libelles.nombre(evolution['personnes_enregistrees']))),
        Text(textes.rapportLigne(textes.evolutionValides, Libelles.nombre(evolution['dossiers_valides']))),
        Text(textes.rapportLigne(textes.evolutionAReprendre, Libelles.nombre(evolution['dossiers_a_reprendre']))),
      ]),
      SectionFormulaire(titre: textes.rapportQualite, enfants: [
        Text(textes.rapportQualiteAide, style: theme.textTheme.bodySmall),
        for (final champ in libellesQualite.entries)
          ChampNombre(libelle: champ.value, controleur: _controleQualite[champ.key]!),
      ]),
      SectionFormulaire(titre: textes.rapportLogistique, enfants: [
        for (final ligne in _logistique) ...[
          if (ligne.ajoutee)
            ChampTexte(
              libelle: textes.logistiqueRessource,
              controleur: ligne.ressource,
              erreur: _tentative && ligne.sansRessource ? textes.champObligatoire : null,
            )
          else
            Text(ligne.ressource.text, style: theme.textTheme.titleSmall),
          ChampNombre(libelle: textes.logistiqueDisponible, controleur: ligne.disponible),
          ChampNombre(libelle: textes.logistiqueFonctionnelle, controleur: ligne.fonctionnelle),
          ChampNombre(libelle: textes.logistiqueBesoin, controleur: ligne.besoin),
          ChampTexte(libelle: textes.logistiqueObservation, controleur: ligne.observation),
          if (ligne.ajoutee) _BoutonRetirer(onPressed: () => _retirer(_logistique, ligne, (l) => l.liberer())),
          const Divider(height: 28),
        ],
        GrosBouton(
          icone: Icons.add,
          libelle: textes.logistiqueAjouter,
          secondaire: true,
          onPressed: () => setState(() => _logistique.add(_LigneLogistique(const {}, ajoutee: true))),
        ),
      ]),
    ];
  }

  Widget _suiviAgents(Textes textes) {
    final theme = Theme.of(context);
    final productions = {
      for (final valeur in ['passable', 'peu_satisfaisant', 'satisfaisant']) valeur: Libelles.production(textes, valeur),
    };
    final anomalies = {
      for (final valeur in ['retard', 'absenteisme', 'propos_discourtois', 'autre']) valeur: Libelles.anomalie(textes, valeur),
    };

    return SectionFormulaire(titre: textes.rapportSuiviAgents, enfants: [
      Text(textes.rapportSuiviAide, style: theme.textTheme.bodySmall),
      for (final suivi in _suivis) ...[
        const Divider(height: 28),
        Text(
          '${(suivi.agent['volontaire'] as Map?)?['matricule'] ?? ''} — ${Libelles.nomDe(suivi.agent['volontaire'])}',
          style: theme.textTheme.titleMedium,
        ),
        Text(
          '${Libelles.categorie(textes, suivi.agent['categorie_agent'])} · '
          '${textes.suiviPresence(Libelles.presences(textes)[suivi.agent['presence']] ?? textes.presenceInconnue)}',
        ),
        const SizedBox(height: 8),
        Text(textes.suiviProduction, style: theme.textTheme.titleSmall),
        ChoixUnique<String>(
          options: productions,
          valeur: suivi.production,
          onChange: (valeur) => setState(() => suivi.production = valeur),
        ),
        Text(textes.suiviAnomalies, style: theme.textTheme.titleSmall),
        ChoixMultiples<String>(
          options: anomalies,
          valeurs: suivi.anomalies,
          onChange: (valeurs) => setState(() => suivi.anomalies = valeurs),
        ),
        ChampTexte(libelle: textes.suiviObservation, controleur: suivi.observation, lignes: 2),
        // Une appréciation ne se lit jamais sans la réponse que l'agent y a faite.
        for (final reponse in (suivi.agent['reponses'] as List? ?? const []).whereType<Map>())
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              textes.suiviReponseAgent(
                reponse['repondu_le'] is String ? dateHeureLisible(DateTime.parse(reponse['repondu_le'] as String)) : '—',
                '${reponse['reponse'] ?? ''}',
              ),
              style: theme.textTheme.bodyMedium?.copyWith(fontStyle: FontStyle.italic),
            ),
          ),
      ],
    ]);
  }

  Widget _blocDifficultes(Textes textes) {
    return SectionFormulaire(titre: textes.rapportDifficultes, enfants: [
      for (final ligne in _difficultes) ...[
        ChampTexte(
          libelle: textes.rapportDifficulte,
          controleur: ligne.difficulte,
          lignes: 2,
          erreur: _tentative && ligne.sansDifficulte ? textes.champObligatoire : null,
        ),
        ChampTexte(libelle: textes.rapportSolution, controleur: ligne.solution, lignes: 2),
        _BoutonRetirer(onPressed: () => _retirer(_difficultes, ligne, (l) => l.liberer())),
        const Divider(height: 28),
      ],
      GrosBouton(
        icone: Icons.add,
        libelle: textes.rapportAjouterDifficulte,
        secondaire: true,
        onPressed: () => setState(() => _difficultes.add(_LigneDifficulte(const {}))),
      ),
    ]);
  }

  Widget _blocPoints(Textes textes) {
    return SectionFormulaire(titre: textes.rapportPoints, enfants: [
      for (final point in _points) ...[
        ChampTexte(libelle: textes.rapportPoint, controleur: point, lignes: 2),
        _BoutonRetirer(onPressed: () => _retirer(_points, point, (p) => p.dispose())),
      ],
      GrosBouton(
        icone: Icons.add,
        libelle: textes.rapportAjouterPoint,
        secondaire: true,
        onPressed: () => setState(() => _points.add(TextEditingController())),
      ),
    ]);
  }
}

class _BoutonRetirer extends StatelessWidget {
  const _BoutonRetirer({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerRight,
      child: TextButton.icon(
        style: TextButton.styleFrom(minimumSize: const Size(0, hauteurBouton)),
        onPressed: onPressed,
        icon: const Icon(Icons.delete_outline),
        label: Text(Textes.of(context).retirerLigne),
      ),
    );
  }
}
