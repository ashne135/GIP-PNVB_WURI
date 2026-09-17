import '../api/client_api.dart';
import '../donnees/depot_file.dart';
import '../donnees/depot_travail.dart';
import '../outils/horodatage.dart';

/// Ce qu'a donné la préparation de la journée.
class BilanPreparation {
  const BilanPreparation({required this.prepares, required this.echecs, this.horsLigne = false});

  /// Les données rafraîchies.
  final List<String> prepares;

  /// Les données qui n'ont pas pu l'être, avec le message du serveur.
  final Map<String, String> echecs;

  /// Aucune donnée n'a pu être rafraîchie faute de réseau.
  final bool horsLigne;
}

/// PRÉPARER LA JOURNÉE QUAND LE RÉSEAU EST LÀ.
///
/// Le téléphone va chercher ce qu'il faudra pour travailler sans réseau, selon
/// les droits du compte :
///
///   - le canevas d'incident, pour déclarer même au milieu de nulle part ;
///   - le rapport du jour, ouvert et pré-rempli par le serveur ;
///   - les rapports qui attendent un visa ;
///   - les feuilles de présence des sites du jour, pour le superviseur ;
///   - les alertes, le kit détenu, les appréciations.
///
/// Chaque bloc est indépendant : un refus sur l'un — pas d'affectation active,
/// par exemple — n'empêche pas les autres d'être gardés. Seule l'absence de
/// réseau arrête tout, puisque rien ne passera.
///
/// UNE SAISIE QUI N'EST PAS ENCORE PARTIE N'EST JAMAIS ÉCRASÉE. Le rapport du
/// jour et les feuilles de présence se remplacent en entier : si la version du
/// serveur recouvrait celle du téléphone avant son envoi, l'agent reprendrait
/// sa saisie sur des chiffres anciens, et sa prochaine version enverrait ces
/// chiffres anciens.
class ServiceJournee {
  ServiceJournee({
    required ClientApi api,
    required DepotTravail depot,
    DepotFile? file,
    DateTime Function()? horloge,
  })  : _api = api,
        _depot = depot,
        _file = file,
        _horloge = horloge ?? DateTime.now;

  final ClientApi _api;
  final DepotTravail _depot;
  final DepotFile? _file;
  final DateTime Function() _horloge;

  Future<BilanPreparation> preparer(int utilisateurId, Set<String> permissions) async {
    final prepares = <String>[];
    final echecs = <String, String>{};
    final aujourdhui = horodatageIso(_horloge()).substring(0, 10);

    Future<void> bloc(String cle, bool utile, Future<Object?> Function() chercher) async {
      if (!utile) {
        return;
      }

      try {
        await _depot.ecrire(utilisateurId, cle, await chercher());
        prepares.add(cle);
      } on ErreurApi catch (erreur) {
        if (erreur.estHorsLigne || erreur.estSessionPerdue) {
          rethrow;
        }

        echecs[cle] = erreur.message;
      }
    }

    try {
      await bloc(CleTravail.canevasIncident, permissions.contains('incidents.declarer'),
          () => _api.lire('/incidents/canevas'));

      await bloc(CleTravail.alertes, permissions.contains('alertes.consulter'),
          () async => _lignes(await _api.lire('/alertes')));

      await bloc(CleTravail.kits, permissions.contains('kits.consulter'),
          () async => _lignes(await _api.lire('/kits')));

      await bloc(CleTravail.appreciations, permissions.contains('appreciations.consulter_les_miennes'),
          () async => _lignes(await _api.lire('/appreciations/mes-appreciations')));

      await bloc(CleTravail.rapportDuJour, permissions.contains('rapports.saisir'),
          () => _rapportDuJour(utilisateurId, aujourdhui));

      await bloc(CleTravail.rapportsAViser, permissions.contains('rapports.viser'),
          () async => _lignes(await _api.lire('/rapports/a-viser')));

      await bloc(CleTravail.feuillesDuJour, permissions.contains('presence.valider_feuille'),
          () => _feuillesDuJour(utilisateurId, aujourdhui));
    } on ErreurApi catch (erreur) {
      return BilanPreparation(prepares: prepares, echecs: {'reseau': erreur.message}, horsLigne: erreur.estHorsLigne);
    }

    return BilanPreparation(prepares: prepares, echecs: echecs);
  }

  /// Le rapport du jour, ouvert par le serveur — sauf si celui du téléphone
  /// attend encore son envoi.
  Future<Object?> _rapportDuJour(int utilisateurId, String date) async {
    final frais = (await _api.envoyer('/rapports/ouvrir', {'date': date})).donnees;
    final garde = (await _depot.lire(utilisateurId, CleTravail.rapportDuJour))?.contenu;

    if (garde is Map && frais is Map && garde['uuid_client'] == frais['uuid_client'] && await _enAttente(utilisateurId, garde['uuid_client'])) {
      return garde;
    }

    return frais;
  }

  /// Les feuilles de présence des sites où des agents sont attendus aujourd'hui.
  Future<List<Map<String, dynamic>>> _feuillesDuJour(int utilisateurId, String date) async {
    final carte = ((await _api.lire('/presence/carte')) as Map).cast<String, dynamic>();
    final sites = {
      for (final site in (carte['sites'] as List? ?? const []).whereType<Map>())
        site['id']: site.cast<String, dynamic>(),
    };
    final sitesDuJour = (carte['agents'] as List? ?? const [])
        .whereType<Map>()
        .map((agent) => agent['site_id'])
        .whereType<int>()
        .toSet();

    final gardees = {
      for (final feuille in await _depot.liste(utilisateurId, CleTravail.feuillesDuJour)) feuille['uuid_client']: feuille,
    };

    final feuilles = <Map<String, dynamic>>[];

    for (final siteId in sitesDuJour) {
      final reponse = await _api.envoyer('/sites/$siteId/feuille', {'date': date});
      final feuille = (reponse.donnees as Map).cast<String, dynamic>();
      final gardee = gardees[feuille['uuid_client']];

      feuilles.add(gardee != null && await _enAttente(utilisateurId, feuille['uuid_client'])
          ? gardee
          : {...feuille, 'site': sites[siteId]});
    }

    return feuilles;
  }

  /// La fiche est-elle encore dans la file du téléphone, en attente d'envoi ?
  Future<bool> _enAttente(int utilisateurId, Object? uuidClient) async =>
      uuidClient is String && _file != null && await _file.etatDe(utilisateurId, uuidClient) == DepotFile.enAttente;

  /// Une réponse paginée de Laravel, ou une liste simple.
  List<dynamic> _lignes(dynamic donnees) {
    if (donnees is Map && donnees['data'] is List) {
      return donnees['data'] as List;
    }

    return donnees is List ? donnees : const [];
  }
}
