import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:volontaires/api/client_api.dart';

import 'doublures.dart';

/// LE CLIENT DE L'API : l'enveloppe défaite, et chaque refus nommé.
void main() {
  ClientApi client(MockClient http, {String? jeton = 'jeton-de-test'}) =>
      ClientApi(urlBase: 'https://pnvb.test/api/v1', jeton: () async => jeton, client: http);

  test("rend le message du serveur et ses données, avec le jeton Bearer", () async {
    late http.Request recue;

    final api = client(MockClient((requete) async {
      recue = requete;

      return reponseServeur(200, message: 'Connexion réussie.', donnees: {'jeton': 'abc'});
    }));

    final reponse = await api.envoyer('/connexion', {'telephone': '70123456'});

    expect(reponse.message, 'Connexion réussie.');
    expect(reponse.donnees['jeton'], 'abc');
    expect(recue.headers['Authorization'], 'Bearer jeton-de-test');
    expect(jsonDecode(recue.body), {'telephone': '70123456'});
  });

  test('porte les erreurs de champ du serveur, sous le bon champ', () async {
    final api = client(MockClient((_) async => reponseServeur(
          422,
          message: 'Certaines informations sont incorrectes ou manquantes.',
          donnees: {
            'erreurs': {
              'telephone': ["Ce numéro de téléphone n'est pas valide. Exemple : 70 12 34 56."],
            },
          },
        )));

    final erreur = await api.envoyer('/connexion').then<ErreurApi?>((_) => null, onError: (e) => e as ErreurApi);

    expect(erreur!.estValidation, isTrue);
    expect(erreur.erreurDuChamp('telephone'), "Ce numéro de téléphone n'est pas valide. Exemple : 70 12 34 56.");
  });

  test("traite l'absence de réseau comme telle, pas comme une panne", () async {
    final api = client(MockClient((_) async => throw http.ClientException('Connection refused')));

    final erreur = await api.lire('/moi').then<ErreurApi?>((_) => null, onError: (e) => e as ErreurApi);

    expect(erreur!.estHorsLigne, isTrue);
    expect(erreur.message, ClientApi.messageHorsLigne);
  });

  test("reconnaît le refus d'un accès fermé, distinct d'un simple manque de droit", () async {
    final api = client(MockClient((_) async => reponseServeur(
          403,
          message: 'Votre accès est fermé.',
          donnees: {'action_requise': 'acces_ferme', 'rattrapage_jusqu_au': '2026-09-21T10:00:00+00:00'},
        )));

    final erreur = await api.lire('/referentiel/regions').then<ErreurApi?>((_) => null, onError: (e) => e as ErreurApi);

    expect(erreur!.estAccesFerme, isTrue);
    expect(erreur.estSessionPerdue, isFalse);
  });

  test("garde un message lisible quand la réponse n'est pas celle du serveur", () async {
    // Un portail Wi-Fi ou un proxy répond par une page HTML.
    final api = client(MockClient((_) async => http.Response('<html>Portail</html>', 502)));

    final erreur = await api.lire('/moi').then<ErreurApi?>((_) => null, onError: (e) => e as ErreurApi);

    expect(erreur!.statut, 502);
    expect(erreur.message, 'Une erreur est survenue.');
  });
}
