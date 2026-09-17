import 'package:flutter/material.dart';

import '../composants/cadre_ecran.dart';
import '../composants/gros_bouton.dart';
import '../donnees/depot_travail.dart';
import '../l10n/textes.dart';
import '../outils/horodatage.dart';
import '../session/controleur_session.dart';
import '../sync/moteur_synchronisation.dart';
import '../travail/service_journee.dart';
import 'ecran_alertes.dart';
import 'ecran_appreciations.dart';
import 'ecran_feuilles_presence.dart';
import 'ecran_incident.dart';
import 'ecran_kit.dart';
import 'ecran_rapport.dart';
import 'ecran_rapports_a_viser.dart';
import 'ecran_signal_arrivee.dart';

/// L'ACCUEIL, QUI S'OUVRE SANS RÉSEAU.
///
/// Tout ce qu'il montre vient du téléphone : le profil gardé — avec sa date,
/// pour qu'une information ancienne ne passe pas pour fraîche — et la file
/// d'attente. L'agent y voit d'un coup d'œil ce qui attend d'être envoyé.
class EcranAccueil extends StatelessWidget {
  const EcranAccueil({super.key, required this.session});

  final ControleurSession session;

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final theme = Theme.of(context);

    final profil = session.profil ?? const <String, dynamic>{};
    final utilisateur = _carte(profil['utilisateur']);
    final volontaire = profil['volontaire'] == null ? null : _carte(profil['volontaire']);
    final perimetre = _carte(profil['perimetre']);
    final affectation = perimetre['affectation_courante'] == null ? null : _carte(perimetre['affectation_courante']);
    final site = perimetre['site_du_jour'] == null ? null : _carte(perimetre['site_du_jour']);
    final compte = session.compteFile;

    return CadreEcran(
      titre: textes.titreApplication,
      enfants: [
        if (session.sessionExpiree) ...[
          MessageErreur(textes.sessionExpiree),
          GrosBouton(icone: Icons.login, libelle: textes.seReconnecter, onPressed: session.deconnecter),
          const SizedBox(height: 20),
        ],
        if (utilisateur['statut_compte'] == 'ferme') MessageErreur(textes.accesFerme),
        Text(
          textes.accueilBonjour((utilisateur['nom_complet'] as String?) ?? ''),
          style: theme.textTheme.headlineSmall,
        ),
        if (volontaire != null)
          Text('${volontaire['matricule'] ?? ''} · ${volontaire['categorie_libelle'] ?? ''}'),
        if (utilisateur['statut_compte_libelle'] != null) Text(utilisateur['statut_compte_libelle'] as String),
        const SizedBox(height: 20),
        _Rubrique(
          titre: textes.accueilMission,
          enfants: affectation == null
              ? [Text(textes.accueilAucuneMission)]
              : [
                  if (affectation['vague'] != null) Text((_carte(affectation['vague'])['libelle'] as String?) ?? ''),
                  if (affectation['centre'] != null)
                    Text('${_carte(affectation['centre'])['code'] ?? ''} — ${_carte(affectation['centre'])['nom'] ?? ''}'),
                  if (site != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      textes.accueilSiteDuJour('${site['code'] ?? ''} — ${site['nom'] ?? ''}'),
                      style: theme.textTheme.titleMedium,
                    ),
                    if (site['kit_present_aujourdhui'] == false) Text(textes.accueilKitAbsent),
                  ],
                ],
        ),
        if (session.profilMisAJourLe != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              textes.accueilInformationsDu(dateHeureLisible(session.profilMisAJourLe!)),
              style: theme.textTheme.bodySmall,
            ),
          ),
        const SizedBox(height: 20),
        _MonTravail(session: session),
        _Rubrique(
          titre: textes.fileTitre,
          enfants: [
            Text(textes.fileEnAttente(compte.enAttente), style: theme.textTheme.titleMedium),
            if (compte.rejetes > 0)
              Text(textes.fileRejetes(compte.rejetes), style: TextStyle(color: theme.colorScheme.error)),
            const SizedBox(height: 4),
            Text(
              session.dernierEnvoi == null
                  ? textes.fileJamaisEnvoye
                  : textes.fileDernierEnvoi(dateHeureLisible(session.dernierEnvoi!)),
            ),
            const SizedBox(height: 16),
            GrosBouton(
              icone: Icons.cloud_upload,
              libelle: textes.fileEnvoyer,
              enCours: session.envoiEnCours,
              onPressed: () => _envoyer(context),
            ),
          ],
        ),
        const SizedBox(height: 32),
        GrosBouton(
          icone: Icons.logout,
          libelle: textes.deconnexion,
          secondaire: true,
          onPressed: () => _deconnecter(context),
        ),
      ],
    );
  }

  Future<void> _envoyer(BuildContext context) async {
    final textes = Textes.of(context);
    final bilan = await session.envoyerFile();

    if (bilan == null || !context.mounted) {
      return;
    }

    switch (bilan.issue) {
      case IssueEnvoi.termine || IssueEnvoi.rienAEnvoyer:
        afficherConfirmation(context, textes.envoiTermine(bilan.acceptes));
      case IssueEnvoi.partiel:
        afficherConfirmation(context, textes.envoiPartiel, succes: false);
      case IssueEnvoi.horsLigne:
        afficherConfirmation(context, textes.envoiHorsLigne, succes: false);
      case IssueEnvoi.sessionPerdue:
        afficherConfirmation(context, textes.sessionExpiree, succes: false);
      case IssueEnvoi.erreur:
        afficherConfirmation(context, bilan.message ?? textes.envoiPartiel, succes: false);
      case IssueEnvoi.dejaEnCours:
        break;
    }
  }

  /// Le travail non envoyé reste sur le téléphone ; l'agent en est prévenu
  /// avant de se déconnecter, pour ne pas croire qu'il est parti.
  Future<void> _deconnecter(BuildContext context) async {
    final textes = Textes.of(context);
    final enAttente = session.compteFile.enAttente;

    if (enAttente > 0) {
      final confirme = await showDialog<bool>(
        context: context,
        builder: (dialogue) => AlertDialog(
          title: Text(textes.deconnexionAvertissementTitre),
          content: Text('${textes.deconnexionNonEnvoyes(enAttente)} ${textes.deconnexionGarde}'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dialogue, false), child: Text(textes.annuler)),
            FilledButton(onPressed: () => Navigator.pop(dialogue, true), child: Text(textes.deconnexion)),
          ],
        ),
      );

      if (confirme != true) {
        return;
      }
    }

    await session.deconnecter();
  }

  static Map<String, dynamic> _carte(Object? valeur) =>
      valeur is Map ? valeur.cast<String, dynamic>() : const <String, dynamic>{};
}

/// MON TRAVAIL : UN BOUTON PAR ACTION, SELON LES DROITS DU COMPTE.
///
/// Les droits viennent du profil rendu par le serveur ; ils décident de ce qui
/// est proposé, et le serveur revérifie tout à la réception. Les compteurs —
/// alertes non lues, rapports à viser, feuilles à valider — se lisent dans les
/// données gardées sur le téléphone.
class _MonTravail extends StatefulWidget {
  const _MonTravail({required this.session});

  final ControleurSession session;

  @override
  State<_MonTravail> createState() => _EtatMonTravail();
}

class _EtatMonTravail extends State<_MonTravail> {
  int _alertesNonLues = 0;
  int _visas = 0;
  int _feuilles = 0;
  bool _kitDetenu = false;
  BilanPreparation? _preparationComptee;

  @override
  void initState() {
    super.initState();
    _compter();
  }

  @override
  void didUpdateWidget(covariant _MonTravail ancien) {
    super.didUpdateWidget(ancien);

    // L'accueil se reconstruit à chaque changement de la session : une
    // préparation terminée entre-temps a pu changer les compteurs.
    if (!identical(widget.session.dernierePreparation, _preparationComptee)) {
      _compter();
    }
  }

  Future<void> _compter() async {
    final session = widget.session;
    final id = session.utilisateurId;
    _preparationComptee = session.dernierePreparation;

    if (id == null) {
      return;
    }

    final alertes = await session.travail.liste(id, CleTravail.alertes);
    final visas = await session.travail.liste(id, CleTravail.rapportsAViser);
    final feuilles = await session.travail.liste(id, CleTravail.feuillesDuJour);
    final kits = await session.travail.liste(id, CleTravail.kits);

    if (!mounted) {
      return;
    }

    setState(() {
      _alertesNonLues = alertes.where((alerte) => !alerteEstLue(alerte)).length;
      _visas = visas.length;
      _feuilles = feuilles.where((f) => f['statut'] == 'brouillon' && f['en_attente_envoi'] != true).length;
      _kitDetenu = EcranKit.kitsDetenus(session, kits).isNotEmpty;
    });
  }

  Future<void> _ouvrir(Widget Function() ecran) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => ecran()));
    await _compter();
  }

  Future<void> _actualiser() async {
    final textes = Textes.of(context);
    final bilan = await widget.session.preparerJournee();

    if (bilan == null || !mounted) {
      return;
    }

    if (bilan.horsLigne) {
      afficherConfirmation(context, textes.preparationHorsLigne, succes: false);
    } else if (bilan.echecs.isNotEmpty) {
      afficherConfirmation(context, textes.preparationEchecs(bilan.echecs.values.join(' ')), succes: false);
    } else {
      afficherConfirmation(context, textes.preparationTerminee);
    }

    await _compter();
  }

  @override
  Widget build(BuildContext context) {
    final textes = Textes.of(context);
    final session = widget.session;

    final actions = [
      if (session.peut('presence.signaler_arrivee'))
        _action(Icons.where_to_vote, textes.actionSignal, () => EcranSignalArrivee(session: session)),
      if (session.peut('rapports.saisir'))
        _action(Icons.edit_note, textes.actionRapport, () => EcranRapport(session: session)),
      if (session.peut('rapports.viser'))
        _action(Icons.fact_check, textes.actionVisas, () => EcranRapportsAViser(session: session),
            precision: _visas > 0 ? textes.visasEnAttente(_visas) : null),
      if (session.peut('presence.valider_feuille'))
        _action(Icons.how_to_reg, textes.actionFeuilles, () => EcranFeuillesPresence(session: session),
            precision: _feuilles > 0 ? textes.feuillesAValider(_feuilles) : null),
      if (session.peut('incidents.declarer'))
        _action(Icons.report_problem, textes.actionIncident, () => EcranIncident(session: session)),
      if (session.peut('kits.declarer_mouvement') && _kitDetenu)
        _action(Icons.work, textes.actionKit, () => EcranKit(session: session)),
      if (session.peut('alertes.consulter'))
        _action(Icons.campaign, textes.actionAlertes, () => EcranAlertes(session: session),
            precision: _alertesNonLues > 0 ? textes.alertesNonLues(_alertesNonLues) : null),
      if (session.peut('appreciations.consulter_les_miennes'))
        _action(Icons.forum, textes.actionAppreciations, () => EcranAppreciations(session: session)),
    ];

    if (actions.isEmpty) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: _Rubrique(
        titre: textes.actionsTitre,
        enfants: [
          ...actions,
          GrosBouton(
            icone: Icons.sync,
            libelle: textes.actualiserDonnees,
            secondaire: true,
            enCours: session.preparationEnCours,
            onPressed: _actualiser,
          ),
        ],
      ),
    );
  }

  Widget _action(IconData icone, String libelle, Widget Function() ecran, {String? precision}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          GrosBouton(icone: icone, libelle: libelle, onPressed: () => _ouvrir(ecran)),
          if (precision != null)
            Padding(
              padding: const EdgeInsets.only(top: 4, left: 4),
              child: Text(
                precision,
                style: TextStyle(color: Theme.of(context).colorScheme.error, fontWeight: FontWeight.w700),
              ),
            ),
        ],
      ),
    );
  }
}

class _Rubrique extends StatelessWidget {
  const _Rubrique({required this.titre, required this.enfants});

  final String titre;
  final List<Widget> enfants;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(titre, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 10),
            ...enfants,
          ],
        ),
      ),
    );
  }
}
