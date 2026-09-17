import 'package:flutter/material.dart';

import '../composants/cadre_ecran.dart';
import '../composants/formulaire.dart';
import '../composants/gros_bouton.dart';
import '../donnees/depot_travail.dart';
import '../l10n/textes.dart';
import '../outils/horodatage.dart';
import '../services/service_photos.dart';
import '../session/controleur_session.dart';

/// LA DÉCLARATION D'INCIDENT, SUR PLACE ET SANS RÉSEAU (cadrage, section 12).
///
/// Le canevas A à I du client, gardé sur le téléphone : les natures, les
/// impacts, les mesures et les destinataires viennent du serveur, jamais d'une
/// liste écrite dans l'application. La section J — traitement et clôture — ne
/// figure pas ici : elle est réservée aux responsables habilités.
///
/// Seuls la nature, le récit et la gravité sont exigés. Tout le reste est
/// vraiment facultatif : rien ne doit pousser un agent à inventer une donnée
/// pour enregistrer une déclaration.
class EcranIncident extends StatefulWidget {
  const EcranIncident({super.key, required this.session, ServicePhotos? photos}) : _photos = photos;

  final ControleurSession session;
  final ServicePhotos? _photos;

  @override
  State<EcranIncident> createState() => _EtatEcranIncident();
}

class _EtatEcranIncident extends State<EcranIncident> {
  late final ServicePhotos _appareil = widget._photos ?? ServicePhotos();

  Map<String, dynamic>? _canevas;
  DateTime? _canevasDu;
  bool _chargement = true;

  final _recit = TextEditingController();
  final _lieuPrecision = TextEditingController();
  final _personnesAffectees = TextEditingController();
  final _mesuresPrecisions = TextEditingController();

  Set<int> _natures = {};
  Set<int> _impacts = {};
  Set<int> _mesures = {};
  Set<int> _informes = {};
  int? _gravite;
  String _typeLieu = 'site';
  String _enCours = 'inconnu';
  bool _danger = false;
  final List<String> _photos = [];

  bool _tentative = false;
  bool _enregistrement = false;
  String? _erreurPhoto;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    final donnee = await widget.session.travail.lire(widget.session.utilisateurId!, CleTravail.canevasIncident);

    if (!mounted) {
      return;
    }

    setState(() {
      _canevas = (donnee?.contenu as Map?)?.cast<String, dynamic>();
      _canevasDu = donnee?.misAJourLe;
      _chargement = false;
    });
  }

  @override
  void dispose() {
    _recit.dispose();
    _lieuPrecision.dispose();
    _personnesAffectees.dispose();
    _mesuresPrecisions.dispose();
    super.dispose();
  }

  Map<int, String> _options(String cle) => {
        for (final option in (_canevas?[cle] as List? ?? const []).whereType<Map>())
          if (option['id'] is int) option['id'] as int: (option['libelle'] as String?) ?? '',
      };

  Future<void> _prendrePhoto() async {
    try {
      final chemin = await _appareil.prendre();

      if (chemin != null && mounted) {
        setState(() {
          _photos.add(chemin);
          _erreurPhoto = null;
        });
      }
    } catch (erreur) {
      if (mounted) {
        setState(() => _erreurPhoto = Textes.of(context).photoImpossible('$erreur'));
      }
    }
  }

  Future<void> _retirerPhoto(String chemin) async {
    setState(() => _photos.remove(chemin));
    await _appareil.effacer(chemin);
  }

  bool get _recitValable => _recit.text.trim().length >= 10;

  Future<void> _enregistrer() async {
    setState(() => _tentative = true);

    if (_natures.isEmpty || !_recitValable || _gravite == null) {
      return;
    }

    setState(() => _enregistrement = true);

    final maintenant = horodatageIso(DateTime.now());
    final site = (widget.session.profil?['perimetre'] as Map?)?['site_du_jour'] as Map?;

    await widget.session.enregistrer(
      type: 'incident',
      contenu: {
        if (site?['id'] is int && _typeLieu == 'site') 'site_id': site!['id'],
        'horodatage_telephone': maintenant,
        'survenu_le': maintenant,
        'type_lieu': _typeLieu,
        if (_lieuPrecision.text.trim().isNotEmpty) 'lieu_precision': _lieuPrecision.text.trim(),
        'natures': _natures.toList(),
        'recit': _recit.text.trim(),
        'toujours_en_cours': _enCours,
        'danger_immediat': _danger,
        if (_impacts.isNotEmpty) 'impacts': _impacts.toList(),
        if (_personnesAffectees.text.isNotEmpty) 'nb_personnes_affectees': int.parse(_personnesAffectees.text),
        'gravite': _gravite,
        'preuves': _photos.isEmpty ? ['aucun'] : ['photo'],
        if (_mesures.isNotEmpty) 'mesures': _mesures.toList(),
        if (_mesuresPrecisions.text.trim().isNotEmpty) 'mesures_precisions': _mesuresPrecisions.text.trim(),
        if (_informes.isNotEmpty) 'personnes_informees': _informes.toList(),
      },
      photos: [for (final photo in _photos) PhotoAJoindre(chemin: photo, role: 'preuve_incident')],
    );

    if (!mounted) {
      return;
    }

    afficherConfirmation(context, Textes.of(context).enregistre);
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);

    if (_chargement) {
      return CadreEcran(titre: textes.actionIncident, enfants: const [Center(child: CircularProgressIndicator())]);
    }

    if (_canevas == null) {
      return CadreEcran(titre: textes.actionIncident, enfants: [MessageErreur(textes.donneesAbsentes)]);
    }

    final gravites = {
      for (final g in (_canevas!['gravites'] as List? ?? const []).whereType<Map>())
        if (g['valeur'] is int) g['valeur'] as int: '${g['libelle']} — ${g['description'] ?? ''}',
    };

    return CadreEcran(
      titre: textes.actionIncident,
      enfants: [
        BandeauDonnees(misAJourLe: _canevasDu),
        SectionFormulaire(titre: textes.incidentNature, enfants: [
          ChoixMultiples<int>(
            options: _options('natures'),
            valeurs: _natures,
            onChange: (v) => setState(() => _natures = v),
            erreur: _tentative && _natures.isEmpty ? textes.incidentNatureObligatoire : null,
          ),
        ]),
        SectionFormulaire(titre: textes.incidentRecit, enfants: [
          ChampTexte(
            libelle: textes.incidentRecit,
            controleur: _recit,
            lignes: 4,
            erreur: _tentative && !_recitValable ? textes.incidentRecitCourt : null,
          ),
        ]),
        SectionFormulaire(titre: textes.incidentGravite, enfants: [
          ChoixUnique<int>(
            options: gravites,
            valeur: _gravite,
            onChange: (v) => setState(() => _gravite = v),
            erreur: _tentative && _gravite == null ? textes.incidentGraviteObligatoire : null,
          ),
          SwitchListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 8),
            value: _danger,
            title: Text(textes.incidentDanger),
            onChanged: (v) => setState(() => _danger = v),
          ),
        ]),
        SectionFormulaire(titre: textes.incidentEnCours, enfants: [
          ChoixUnique<String>(
            options: {'oui': textes.oui, 'non': textes.non, 'inconnu': textes.jeNeSaisPas},
            valeur: _enCours,
            onChange: (v) => setState(() => _enCours = v),
          ),
        ]),
        SectionFormulaire(titre: textes.incidentLieu, enfants: [
          ChoixUnique<String>(
            options: {
              'site': textes.lieuSite,
              'trajet_aller': textes.lieuTrajetAller,
              'trajet_retour': textes.lieuTrajetRetour,
              'autre': textes.lieuAutre,
            },
            valeur: _typeLieu,
            onChange: (v) => setState(() => _typeLieu = v),
          ),
          ChampTexte(libelle: textes.incidentLieuPrecision, controleur: _lieuPrecision),
        ]),
        SectionFormulaire(titre: textes.photosTitre, enfants: [
          if (_erreurPhoto != null) MessageErreur(_erreurPhoto!),
          SelecteurPhotos(photos: _photos, onPrendre: _prendrePhoto, onRetirer: _retirerPhoto),
        ]),
        SectionFormulaire(titre: textes.incidentImpacts, enfants: [
          ChoixMultiples<int>(options: _options('impacts'), valeurs: _impacts, onChange: (v) => setState(() => _impacts = v)),
          ChampNombre(libelle: textes.incidentPersonnesAffectees, controleur: _personnesAffectees),
        ]),
        SectionFormulaire(titre: textes.incidentMesures, enfants: [
          ChoixMultiples<int>(options: _options('mesures'), valeurs: _mesures, onChange: (v) => setState(() => _mesures = v)),
          ChampTexte(libelle: textes.incidentMesuresPrecisions, controleur: _mesuresPrecisions, lignes: 2),
        ]),
        SectionFormulaire(titre: textes.incidentInformes, enfants: [
          ChoixMultiples<int>(options: _options('destinataires'), valeurs: _informes, onChange: (v) => setState(() => _informes = v)),
        ]),
        GrosBouton(
          icone: Icons.send,
          libelle: textes.incidentEnregistrer,
          enCours: _enregistrement,
          onPressed: _enregistrer,
        ),
        const SizedBox(height: 24),
      ],
    );
  }
}
