import 'package:workmanager/workmanager.dart';

import '../api/client_api.dart';
import '../configuration.dart';
import '../donnees/base_locale.dart';
import '../donnees/coffre.dart';
import '../donnees/depot_file.dart';
import 'moteur_synchronisation.dart';

class TacheDeFond {
  const TacheDeFond._();

  static const String nomUnique = 'pnvb.envoi_file';
  static const String nom = 'envoyer_la_file';
}

/// L'ENVOI DE LA FILE, APPLICATION FERMÉE.
///
/// Android lance ce point d'entrée dans un contexte à part, au plus toutes les
/// 15 minutes et seulement quand un réseau est disponible. Il n'a accès qu'à ce
/// que le téléphone garde : le coffre et la base chiffrée.
///
/// Sans compte connecté, il ne fait rien. Hors ligne ou session perdue, il ne
/// signale pas d'échec : Android le relancera au prochain créneau, et la file
/// n'a rien perdu.
@pragma('vm:entry-point')
void rappelTacheDeFond() {
  Workmanager().executeTask((tache, donnees) async {
    final coffre = CoffreSecurise();
    final jeton = await coffre.lire(CleCoffre.jeton);
    final utilisateurId = int.tryParse(await coffre.lire(CleCoffre.utilisateur) ?? '');

    if (jeton == null || utilisateurId == null) {
      return true;
    }

    final base = await BaseLocale.ouvrir(coffre);

    try {
      final moteur = MoteurSynchronisation(
        api: ClientApi(urlBase: Configuration.urlApi, jeton: () async => jeton),
        file: DepotFile(base),
      );

      final bilan = await moteur.envoyer(utilisateurId);

      return bilan.issue != IssueEnvoi.erreur;
    } finally {
      await base.close();
    }
  });
}
