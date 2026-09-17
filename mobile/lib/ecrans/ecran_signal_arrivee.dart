import 'package:flutter/material.dart';

import '../composants/cadre_ecran.dart';
import '../composants/gros_bouton.dart';
import '../l10n/textes.dart';
import '../outils/distance.dart';
import '../outils/horodatage.dart';
import '../services/service_position.dart';
import '../session/controleur_session.dart';

/// LE GROS BOUTON « JE SUIS ARRIVÉ » (cadrage, section 8.1).
///
/// L'agent ne pointe pas : il SIGNALE sa présence. À l'appui, le téléphone
/// relève la position et l'heure, l'écran confirme aussitôt — hors ligne
/// compris — et le serveur recalcule la distance au site. Ce signal n'a aucune
/// valeur administrative : seule la feuille validée par le superviseur fait foi.
///
/// LE PÉRIMÈTRE DU SITE, quand le dispositif est réglé pour l'imposer, est
/// vérifié ICI AUSSI — non pas pour contrôler, mais pour ne pas mentir. Un
/// signal part dans la file PUIS sur le réseau : sans cette vérification, un
/// agent hors zone verrait « arrivée enregistrée », puis découvrirait des
/// heures plus tard que le serveur l'a refusée. Le contrôle qui fait foi reste
/// celui du serveur, qui recalcule tout à la réception.
class EcranSignalArrivee extends StatefulWidget {
  const EcranSignalArrivee({super.key, required this.session, this.position = const ServicePosition()});

  final ControleurSession session;
  final ServicePosition position;

  @override
  State<EcranSignalArrivee> createState() => _EtatEcranSignalArrivee();
}

class _EtatEcranSignalArrivee extends State<EcranSignalArrivee> {
  bool _enCours = false;
  String? _erreur;
  String? _confirmation;

  Map<String, dynamic>? get _site {
    final site = (widget.session.profil?['perimetre'] as Map?)?['site_du_jour'];

    return site is Map ? site.cast<String, dynamic>() : null;
  }

  /// Le serveur annonce son réglage : le téléphone ne décide jamais seul de
  /// refuser, il applique la même règle que celle qui l'attend à l'arrivée.
  bool get _blocageActif =>
      (widget.session.profil?['reglages'] as Map?)?['bloquer_signal_hors_zone'] == true;

  int get _rayon => (nombreOuNul(_site?['rayon_zone_metres']) ?? 500).round();

  bool get _siteLocalise =>
      nombreOuNul(_site?['latitude']) != null && nombreOuNul(_site?['longitude']) != null;

  Future<void> _signaler(String typeSignal) async {
    final textes = Textes.of(context);

    setState(() {
      _enCours = true;
      _erreur = null;
      _confirmation = null;
    });

    try {
      final position = await widget.position.relever();
      final maintenant = DateTime.now();
      final site = _site;

      final distance = distanceMetres(
        latitude: position.latitude,
        longitude: position.longitude,
        latitudeSite: nombreOuNul(site?['latitude']),
        longitudeSite: nombreOuNul(site?['longitude']),
      );
      final horsZone = distance != null && distance > _rayon;

      // Refusé ici comme il le serait là-bas : rien n'entre dans la file, et
      // l'agent l'apprend tout de suite plutôt qu'au retour du réseau.
      if (horsZone && _blocageActif) {
        setState(() => _erreur = '${textes.signalHorsZone(distance, _rayon)}\n\n${textes.signalHorsZoneRefus}');

        return;
      }

      await widget.session.enregistrer(type: 'signal_arrivee', contenu: {
        'type_signal': typeSignal,
        'latitude': position.latitude,
        'longitude': position.longitude,
        'precision_gps': position.precisionMetres,
        'horodatage_telephone': horodatageIso(maintenant),
        if (site?['id'] is int) 'site_id': site!['id'],
      });

      final confirmation = typeSignal == 'arrivee'
          ? textes.signalArriveeEnregistree(heureLisible(maintenant))
          : textes.signalDepartEnregistre(heureLisible(maintenant));

      setState(() => _confirmation = horsZone
          // L'écart est constaté, pas caché : le superviseur le verra.
          ? '$confirmation ${textes.signalHorsZone(distance, _rayon)} ${textes.signalHorsZoneToleree}'
          : confirmation);
    } on PositionIndisponible catch (erreur) {
      setState(() => _erreur = erreur.message);
    } finally {
      if (mounted) {
        setState(() => _enCours = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);
    final site = _site;

    return CadreEcran(
      titre: textes.signalTitre,
      enfants: [
        Text(textes.signalExplication, style: theme.textTheme.bodyLarge),
        const SizedBox(height: 12),
        Text(
          site == null
              ? textes.siteDuJourInconnu
              : textes.accueilSiteDuJour('${site['code'] ?? ''} — ${site['nom'] ?? ''}'),
          style: theme.textTheme.titleMedium,
        ),
        if (site != null && !_siteLocalise)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(textes.signalSiteNonLocalise, style: theme.textTheme.bodySmall),
          ),
        const SizedBox(height: 24),
        if (_erreur != null) MessageErreur(_erreur!),
        if (_confirmation != null) _ConfirmationSignal(_confirmation!),
        if (_enCours) ...[
          const Center(child: CircularProgressIndicator()),
          const SizedBox(height: 8),
          Text(textes.positionRecherche, textAlign: TextAlign.center),
          const SizedBox(height: 16),
        ],
        SizedBox(
          height: 120,
          child: GrosBouton(
            icone: Icons.where_to_vote,
            libelle: textes.signalArrivee,
            enCours: _enCours,
            onPressed: () => _signaler('arrivee'),
          ),
        ),
        const SizedBox(height: 20),
        GrosBouton(
          icone: Icons.logout,
          libelle: textes.signalDepart,
          secondaire: true,
          enCours: _enCours,
          onPressed: () => _signaler('depart'),
        ),
      ],
    );
  }
}

/// La confirmation impossible à rater, qui reste affichée.
class _ConfirmationSignal extends StatelessWidget {
  const _ConfirmationSignal(this.texte);

  final String texte;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      child: Container(
        margin: const EdgeInsets.only(bottom: 20),
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: const Color(0xFF1E6B3A), borderRadius: BorderRadius.circular(12)),
        child: Row(
          children: [
            const Icon(Icons.check_circle, color: Colors.white, size: 44),
            const SizedBox(width: 16),
            Expanded(
              child: Text(
                '$texte\n${Textes.of(context).enregistre}',
                style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
