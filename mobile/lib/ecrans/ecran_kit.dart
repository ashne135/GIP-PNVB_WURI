import 'package:flutter/material.dart';

import '../composants/cadre_ecran.dart';
import '../composants/formulaire.dart';
import '../composants/gros_bouton.dart';
import '../donnees/depot_travail.dart';
import '../l10n/textes.dart';
import '../outils/horodatage.dart';
import '../services/service_photos.dart';
import '../session/controleur_session.dart';

/// LE KIT DE L'AGENT (cadrage, section 13).
///
/// LE KIT SUIT LA PERSONNE, PAS LE SITE. Seul le kit que l'agent détient est
/// proposé ici, même si ses droits lui montrent d'autres kits de son périmètre.
/// Depuis son téléphone, il déclare ce qui arrive à ce kit : une panne, sa
/// restitution, une perte ou un vol. Le serveur retrouve le détenteur réel — le
/// téléphone ne dicte jamais la source d'un mouvement.
///
/// La remise, le transfert et le changement de site désignent un autre agent
/// ou un autre site : ils restent au back-office.
class EcranKit extends StatefulWidget {
  const EcranKit({super.key, required this.session});

  final ControleurSession session;

  /// Les kits gardés sur le téléphone que CE compte détient.
  static List<Map<String, dynamic>> kitsDetenus(ControleurSession session, List<Map<String, dynamic>> kits) {
    final volontaire = (session.profil?['volontaire'] as Map?)?['id'];

    return volontaire == null ? const [] : kits.where((kit) => kit['volontaire_detenteur_id'] == volontaire).toList();
  }

  @override
  State<EcranKit> createState() => _EtatEcranKit();
}

class _EtatEcranKit extends State<EcranKit> {
  List<Map<String, dynamic>> _kits = const [];
  DateTime? _du;
  bool _chargement = true;

  int get _utilisateur => widget.session.utilisateurId!;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    final donnee = await widget.session.travail.lire(_utilisateur, CleTravail.kits);
    final kits = await widget.session.travail.liste(_utilisateur, CleTravail.kits);

    if (mounted) {
      setState(() {
        _kits = EcranKit.kitsDetenus(widget.session, kits);
        _du = donnee?.misAJourLe;
        _chargement = false;
      });
    }
  }

  Future<void> _ouvrir(Map<String, dynamic> kit, String typeMouvement, String titre) async {
    final enregistre = await Navigator.of(context).push<bool>(MaterialPageRoute(
      builder: (_) => EcranMouvementKit(session: widget.session, kit: kit, typeMouvement: typeMouvement, titre: titre),
    ));

    if (enregistre != true) {
      return;
    }

    // La déclaration reste visible en attendant l'envoi : l'agent ne la refait pas.
    final kits = await widget.session.travail.liste(_utilisateur, CleTravail.kits);
    await widget.session.travail.ecrire(_utilisateur, CleTravail.kits, [
      for (final autre in kits) autre['reference'] == kit['reference'] ? {...autre, 'mouvement_en_attente': titre} : autre,
    ]);
    await _charger();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);

    return CadreEcran(
      titre: textes.actionKit,
      enfants: [
        if (_chargement) const Center(child: CircularProgressIndicator()),
        if (!_chargement) BandeauDonnees(misAJourLe: _du),
        if (!_chargement && _kits.isEmpty) Text(textes.kitAucun, style: theme.textTheme.bodyLarge),
        for (final kit in _kits)
          SectionFormulaire(
            titre: '${kit['reference'] ?? ''}',
            enfants: [
              Text(textes.kitEtat(Libelles.etatKit(textes, kit['etat']))),
              if (kit['mouvement_en_attente'] is String)
                Text(
                  textes.kitMouvementEnAttente(kit['mouvement_en_attente'] as String),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              const SizedBox(height: 12),
              GrosBouton(
                icone: Icons.build,
                libelle: textes.kitPanne,
                secondaire: true,
                onPressed: () => _ouvrir(kit, 'panne', textes.kitPanne),
              ),
              const SizedBox(height: 10),
              GrosBouton(
                icone: Icons.assignment_return,
                libelle: textes.kitRestitution,
                secondaire: true,
                onPressed: () => _ouvrir(kit, 'restitution', textes.kitRestitution),
              ),
              const SizedBox(height: 10),
              GrosBouton(
                icone: Icons.report,
                libelle: textes.kitPerteVol,
                secondaire: true,
                onPressed: () => _ouvrir(kit, 'perte_vol', textes.kitPerteVol),
              ),
            ],
          ),
        if (!_chargement && _kits.isNotEmpty) Text(textes.kitAutresMouvements, style: theme.textTheme.bodySmall),
      ],
    );
  }
}

/// UN MOUVEMENT DE KIT, déclaré sur place.
///
/// La restitution exige l'état constaté, et appelle les deux photos de constat :
/// elles partent après la fiche, et l'alerte « photos manquantes » attend
/// qu'elles aient eu le temps d'arriver.
class EcranMouvementKit extends StatefulWidget {
  const EcranMouvementKit({
    super.key,
    required this.session,
    required this.kit,
    required this.typeMouvement,
    required this.titre,
    ServicePhotos? photos,
  }) : _photos = photos;

  final ControleurSession session;
  final Map<String, dynamic> kit;
  final String typeMouvement;
  final String titre;
  final ServicePhotos? _photos;

  @override
  State<EcranMouvementKit> createState() => _EtatEcranMouvementKit();
}

class _EtatEcranMouvementKit extends State<EcranMouvementKit> {
  late final ServicePhotos _appareil = widget._photos ?? ServicePhotos();

  final _commentaire = TextEditingController();
  String? _etatConstate;
  String _circonstance = 'perte';
  String? _photoSource;
  String? _photoDestination;
  bool _tentative = false;
  bool _enregistrement = false;
  String? _erreurPhoto;

  bool get _avecConstat => widget.typeMouvement == 'restitution';

  @override
  void dispose() {
    _commentaire.dispose();
    super.dispose();
  }

  Future<void> _prendre({required bool source}) async {
    try {
      final chemin = await _appareil.prendre();

      if (chemin == null || !mounted) {
        return;
      }

      final ancienne = source ? _photoSource : _photoDestination;

      setState(() {
        if (source) {
          _photoSource = chemin;
        } else {
          _photoDestination = chemin;
        }
        _erreurPhoto = null;
      });

      if (ancienne != null) {
        await _appareil.effacer(ancienne);
      }
    } catch (erreur) {
      if (mounted) {
        setState(() => _erreurPhoto = Textes.of(context).photoImpossible('$erreur'));
      }
    }
  }

  Future<void> _retirer(String chemin) async {
    setState(() {
      if (_photoSource == chemin) {
        _photoSource = null;
      }
      if (_photoDestination == chemin) {
        _photoDestination = null;
      }
    });
    await _appareil.effacer(chemin);
  }

  Future<void> _enregistrer() async {
    setState(() => _tentative = true);

    if (_avecConstat && _etatConstate == null) {
      return;
    }

    setState(() => _enregistrement = true);

    final photos = [
      if (_photoSource != null) PhotoAJoindre(chemin: _photoSource!, role: 'constat_source'),
      if (_photoDestination != null) PhotoAJoindre(chemin: _photoDestination!, role: 'constat_destination'),
    ];

    await widget.session.enregistrer(
      type: 'mouvement_kit',
      contenu: {
        'kit_reference': widget.kit['reference'],
        'type_mouvement': widget.typeMouvement,
        'horodatage_telephone': horodatageIso(DateTime.now()),
        'etat_constate': ?_etatConstate,
        if (widget.typeMouvement == 'perte_vol') 'circonstance': _circonstance,
        if (_commentaire.text.trim().isNotEmpty) 'commentaire': _commentaire.text.trim(),
        // Les photos partent après la fiche : le serveur attend leur arrivée
        // avant de lever l'alerte « photos manquantes ».
        'photos_a_suivre': photos.isNotEmpty,
      },
      photos: photos,
    );

    if (!mounted) {
      return;
    }

    afficherConfirmation(context, Textes.of(context).enregistre);
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);

    return CadreEcran(
      titre: widget.titre,
      enfants: [
        Text('${widget.kit['reference'] ?? ''}', style: Theme.of(context).textTheme.headlineSmall),
        const SizedBox(height: 16),
        if (widget.typeMouvement == 'perte_vol')
          SectionFormulaire(titre: textes.kitCirconstance, enfants: [
            ChoixUnique<String>(
              options: {'perte': textes.circonstancePerte, 'vol': textes.circonstanceVol},
              valeur: _circonstance,
              onChange: (v) => setState(() => _circonstance = v),
            ),
          ]),
        if (_avecConstat || widget.typeMouvement == 'panne')
          SectionFormulaire(titre: textes.kitEtatConstate, enfants: [
            ChoixUnique<String>(
              options: Libelles.etatsConstates(textes),
              valeur: _etatConstate,
              onChange: (v) => setState(() => _etatConstate = v),
              erreur: _tentative && _avecConstat && _etatConstate == null ? textes.kitEtatObligatoire : null,
            ),
          ]),
        if (_avecConstat)
          SectionFormulaire(titre: textes.photosTitre, enfants: [
            Text(textes.kitPhotosConseil),
            const SizedBox(height: 8),
            if (_erreurPhoto != null) MessageErreur(_erreurPhoto!),
            SelecteurPhotos(
              photos: [?_photoSource],
              libelleBouton: textes.kitPhotoSource,
              onPrendre: () => _prendre(source: true),
              onRetirer: _retirer,
            ),
            const SizedBox(height: 12),
            SelecteurPhotos(
              photos: [?_photoDestination],
              libelleBouton: textes.kitPhotoDestination,
              onPrendre: () => _prendre(source: false),
              onRetirer: _retirer,
            ),
          ]),
        SectionFormulaire(titre: textes.kitCommentaire, enfants: [
          ChampTexte(libelle: textes.kitCommentaire, controleur: _commentaire, lignes: 3),
        ]),
        GrosBouton(icone: Icons.send, libelle: textes.kitEnregistrer, enCours: _enregistrement, onPressed: _enregistrer),
        const SizedBox(height: 24),
      ],
    );
  }
}
