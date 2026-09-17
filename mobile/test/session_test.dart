import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:volontaires/donnees/coffre.dart';
import 'package:volontaires/donnees/depot_file.dart';
import 'package:volontaires/donnees/depot_profil.dart';
import 'package:volontaires/session/controleur_session.dart';

import 'doublures.dart';

/// LA SESSION : offline-first dès le premier écran, et rien de perdu à la
/// déconnexion.
void main() {
  late Database base;
  late CoffreMemoire coffre;

  final horsLigne = MockClient((_) async => throw http.ClientException('Network is unreachable'));

  const profil = {
    'utilisateur': {'id': 7, 'nom_complet': 'Awa Ouédraogo', 'statut_compte': 'actif'},
    'actions_requises': {'changer_mot_de_passe': false, 'accepter_charte': false},
  };

  setUp(() async {
    base = await ouvrirBaseDeTest();
    coffre = CoffreMemoire();
  });

  tearDown(() => base.close());

  ControleurSession session(http.Client client) =>
      ControleurSession(coffre: coffre, base: base, urlApi: 'https://pnvb.test/api/v1', client: client);

  test("rouvre la session et l'accueil depuis le téléphone, sans réseau", () async {
    coffre.valeurs[CleCoffre.jeton] = 'jeton';
    coffre.valeurs[CleCoffre.utilisateur] = '7';
    await DepotProfil(base).enregistrer(7, profil);
    await DepotFile(base).ajouter(utilisateurId: 7, type: 'incident', contenu: {});

    final controleur = session(horsLigne);
    await controleur.demarrer();

    expect(controleur.etat, EtatSession.connecte);
    expect(controleur.profil!['utilisateur']['nom_complet'], 'Awa Ouédraogo');
    expect(controleur.profilMisAJourLe, isNotNull);
    expect(controleur.compteFile.enAttente, 1);
    expect(controleur.doitChangerMotDePasse, isFalse);
  });

  test('demande la connexion quand aucun compte ne s’est jamais connecté', () async {
    final controleur = session(horsLigne);
    await controleur.demarrer();

    expect(controleur.etat, EtatSession.deconnecte);
  });

  test('garde le travail non envoyé à la déconnexion, même sans réseau', () async {
    coffre.valeurs[CleCoffre.jeton] = 'jeton';
    coffre.valeurs[CleCoffre.utilisateur] = '7';
    await DepotProfil(base).enregistrer(7, profil);
    await DepotFile(base).ajouter(utilisateurId: 7, type: 'incident', contenu: {});

    final controleur = session(horsLigne);
    await controleur.demarrer();
    await controleur.deconnecter();

    expect(controleur.etat, EtatSession.deconnecte);
    expect(coffre.valeurs.containsKey(CleCoffre.jeton), isFalse);
    // Le travail reste, pour ce compte : il partira à sa prochaine connexion.
    expect((await DepotFile(base).compter(7)).enAttente, 1);
  });
}
