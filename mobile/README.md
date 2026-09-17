# PNVB Volontaires — application mobile de terrain

Application Flutter des volontaires du projet WURI (GIP-PNVB). Elle consomme l'API
Laravel du dossier parent. **Android uniquement** : les agents travaillent sur des
téléphones Android, et iOS n'est pas prévu par le cadrage.

- Identifiant d'application : `bf.gippnvb.volontaires` (définitif)
- Android 7.0 (API 24) minimum, imposé par Flutter 3.41

## Offline-first dès le premier écran

Le réseau est absent ou instable sur les sites. L'agent ne doit **jamais** attendre
le réseau pour continuer (cadrage, section 11).

| Mécanisme | Où |
|---|---|
| Base SQLite **chiffrée** (SQLCipher), clé tirée au hasard et gardée dans le coffre Android | `lib/donnees/base_locale.dart` |
| Toute action écrite **d'abord** dans la file, avec `uuid_client` et l'heure du geste (décalage compris) | `lib/donnees/depot_file.dart` |
| Envoi groupé `POST /sync` ; seuls les éléments **acceptés** quittent la file ; un refus définitif est mis à part, jamais renvoyé en boucle | `lib/sync/moteur_synchronisation.dart` |
| Déclenchement : au démarrage, au retour du réseau, toutes les **5 minutes** application ouverte, toutes les **15 minutes** application fermée | `lib/sync/planificateur_envoi.dart`, `lib/sync/tache_fond.dart` |
| Session rouverte depuis le téléphone, sans réseau ; profil affiché avec sa date | `lib/session/controleur_session.dart` |

**Pourquoi 15 minutes application fermée** : Android n'autorise pas une tâche de fond
périodique plus fréquente. Les 5 minutes du cadrage s'appliquent tant que
l'application est ouverte (décision du 14 septembre 2026).

**À la déconnexion**, le travail non envoyé reste sur le téléphone, pour ce compte : il
partira à sa prochaine connexion. L'agent en est prévenu avant de se déconnecter.

**Si l'accès est fermé** (assistant en fin de vague), le jeton ne sert plus qu'à
envoyer la file, pour des actions faites avant la fermeture, pendant le délai de
rattrapage (paramètre serveur `comptes.delai_rattrapage_jours`).

## Réseau exigé

Seules trois étapes demandent le réseau, parce que c'est le serveur qui les vérifie :
la première connexion, le changement du mot de passe initial, l'acceptation de la
charte. Le texte de la charte vient du serveur (`GET /charte`).

## Lancer en développement

Le serveur Laravel doit tourner (`php artisan serve`). Depuis l'émulateur Android,
`10.0.2.2` désigne le poste :

```bash
flutter run
```

Sur un téléphone réel, indiquez l'adresse du poste sur le réseau local :

```bash
flutter run --dart-define=URL_API=http://192.168.1.20:8000/api/v1
```

Le HTTP en clair n'est autorisé qu'en développement (`android/app/src/debug`). La
version de production n'accepte que HTTPS ; son adresse est fixée à la tâche 19 :

```bash
flutter build apk --release --dart-define=URL_API=https://…/api/v1
```

## Se connecter depuis un téléphone réel

Trois choses doivent tourner sur le poste **avant** d'ouvrir l'application :

| Quoi | Comment le démarrer | Comment vérifier |
|---|---|---|
| La base | MySQL 8.4 sur le port **3307**, démarrage manuel : `C:\Users\armel\mysql8\bin\mysqld.exe --defaults-file=C:\Users\armel\mysql8\my.ini` | le port 3307 répond |
| L'API | `php artisan serve` depuis la racine du projet | `http://127.0.0.1:8000/api/v1` répond dans un navigateur |
| Le pont vers le téléphone | `adb reverse tcp:8000 tcp:8000` | `adb devices` liste l'appareil |

Le `mysqld.exe` de XAMPP n'est **pas** la bonne base : c'est MariaDB sur le port 3306.

### Par USB — le chemin le plus simple

`adb reverse` fait que le `127.0.0.1:8000` **du téléphone** désigne le poste. Aucune
adresse IP à connaître, aucun pare-feu à ouvrir :

```bash
adb reverse tcp:8000 tcp:8000
flutter build apk --debug --target-platform android-arm --dart-define=URL_API=http://127.0.0.1:8000/api/v1
adb install -r build/app/outputs/flutter-apk/app-debug.apk
```

`outils/liaison-usb.ps1` surveille le câble et rejoue `adb reverse` à chaque
reconnexion : sur certains téléphones le lien USB lâche tout seul, et l'application
annonce alors « serveur injoignable » alors que rien n'est en panne.

### Par le Wi-Fi

Le téléphone et le poste doivent être sur le même réseau — le partage de connexion du
téléphone compte. Il faut alors l'adresse du poste sur ce réseau, et un serveur qui
écoute ailleurs que sur `127.0.0.1` :

```bash
php artisan serve --host=0.0.0.0
flutter build apk --debug --target-platform android-arm --dart-define=URL_API=http://192.168.43.6:8000/api/v1
```

Adresses du poste relevées le 16 septembre 2026 : `192.168.43.6` (partage de connexion),
`10.24.52.133`, `192.168.137.1`. Elles changent avec le réseau — `ipconfig` les redonne.
**Le pare-feu Windows bloque le port 8000 par défaut** : il faut une règle entrante, qui
demande les droits administrateur. C'est pourquoi l'USB reste préférable.

### « Le serveur est injoignable »

Ce message vient du téléphone : il dit qu'aucune réponse n'est arrivée, jamais que le
compte est refusé. À vérifier dans cet ordre : le port 3307 répond (base), le port 8000
répond (API), `adb devices` voit le téléphone, `adb reverse --list` montre la
redirection.

## Comptes de test (base de développement uniquement)

**Attention — développement uniquement.** Ces comptes sont fictifs (`est_fictif = true`) et
n'existent que dans la base locale de développement. Ils ne doivent jamais servir en
production : les comptes de démonstration y seront purgés, et le super administrateur réel
créé par une procédure dédiée. Ne diffusez pas ce fichier hors de l'équipe de développement.

Le numéro se saisit sur 8 chiffres (`71 00 05 57`) : le serveur ajoute l'indicatif +226.

### Application mobile (volontaires)

| Rôle | Numéro | Matricule | Mot de passe | État au 16 septembre 2026 |
|---|---|---|---|---|
| Opérateur de kit | 71 00 05 57 | PNVB-OPK000001 | **changé sur le téléphone** | Le provisoire ne marche plus. Voir « Remettre un mot de passe provisoire » ci-dessous. |
| Assistant (A-OPK) | 71 00 16 71 | PNVB-ASS000004 | `Essai2026Pnvb` | Provisoire valable |
| Superviseur | 71 00 00 01 | PNVB-SUP000001 | `Essai2026Pnvb` | Provisoire valable |

Les trois comptes sont **actifs et affectés** à la vague de démonstration : sans
affectation active, la plupart des écrans s'ouvriraient vides.

À la première connexion, l'application impose un nouveau mot de passe, puis l'acceptation
de la charte. Une fois changé, le mot de passe provisoire ne fonctionne plus — c'est ce
qui est arrivé au compte opérateur.

Chaque catégorie ouvre des écrans différents, puisque l'accueil suit les droits du compte :
l'opérateur a le kit et le visa des rapports de ses A-OPK, le superviseur a les feuilles de
présence, l'assistant a l'accueil du site et son propre rapport.

### Back-office web

| Rôle | Numéro | Mot de passe |
|---|---|---|
| Super administrateur | 70 00 00 01 | Déjà changé à la première connexion (non consigné ici) |
| Administrateur national | 70 00 00 02 | Déjà changé à la première connexion (non consigné ici) |
| Chef d'antenne régional (région de la vague de démonstration) | 70 00 00 03 | `ChangerMoi#2026` (provisoire) |
| Observateur | 70 00 00 04 | `ChangerMoi#2026` (provisoire) |

Mots de passe vérifiés dans la base de développement le **16 septembre 2026** : les deux
comptes d'administration marqués « déjà changé » ne répondent plus à `ChangerMoi#2026`,
les deux autres oui.

### Remettre un mot de passe provisoire

Après un `php artisan migrate:fresh --seed`, les comptes d'administration reviennent à
`ChangerMoi#2026`, mais les volontaires de démonstration reçoivent des mots de passe
aléatoires : c'est la cascade de remise des identifiants, déclenchée à l'ouverture de la
vague. Pour remettre un mot de passe provisoire sur un compte **fictif**, depuis la racine
du projet (`C:\xampp\php\php.exe` à la place de `php` sous XAMPP) :

```bash
php artisan tinker --execute='App\Models\User::where("telephone", "+22671000557")->where("est_fictif", true)->firstOrFail()->forceFill(["password" => "Essai2026Pnvb", "doit_changer_mot_de_passe" => true])->save();'
```

La condition `est_fictif` empêche de toucher par erreur un compte réel.

## Textes et langues

Les textes de l'écran sont dans `lib/l10n/app_fr.arb`. Le mooré et le dioula
s'ajouteront par un fichier `.arb` chacun, sans toucher au code.

## Tests

```bash
flutter analyze
flutter test
```

Les tests de la file, du moteur et de la session utilisent le vrai schéma de la base,
en mémoire et sans chiffrement (SQLCipher n'existe que sur le téléphone), et un
serveur simulé.

## Les écrans du terrain

Un écran par action, proposés à l'accueil **selon les droits du compte** rendus par le
serveur — qui revérifie tout à la réception. Chaque geste est écrit dans la file avant
d'être confirmé à l'agent.

| Écran | Ce qu'il met dans la file | Fichier |
|---|---|---|
| Je suis arrivé / Je pars | `signal_arrivee` — position et heure du téléphone | `lib/ecrans/ecran_signal_arrivee.dart` |
| Mon rapport du jour | `rapport_journalier`, enregistré sans signer ou signé (`soumettre`) | `lib/ecrans/ecran_rapport.dart` |
| Rapports à viser | `visa_rapport` — visa, ou renvoi avec motif obligatoire | `lib/ecrans/ecran_rapports_a_viser.dart` |
| Feuilles de présence | `feuille_presence` — chaque agent marqué, position du superviseur | `lib/ecrans/ecran_feuilles_presence.dart` |
| Déclarer un incident | `incident`, avec ses photos `preuve_incident` | `lib/ecrans/ecran_incident.dart` |
| Mon kit | `mouvement_kit` (panne, restitution, perte ou vol) et ses photos de constat | `lib/ecrans/ecran_kit.dart` |
| Alertes | `lecture_alerte` — l'accusé de lecture part même hors ligne | `lib/ecrans/ecran_alertes.dart` |
| Mes appréciations | `reponse_appreciation` — le droit de réponse, hors ligne aussi | `lib/ecrans/ecran_appreciations.dart` |

Le serveur **prépare la journée** tant que le réseau est là (`lib/travail/service_journee.dart`) :
canevas d'incident, rapport du jour, rapports à viser, feuilles du jour, alertes, kit,
appréciations. Les écrans s'ouvrent sur ces données gardées, avec leur date affichée.
Une saisie qui n'est pas encore partie n'est **jamais écrasée** par la version du serveur.

### Photos

Prises après les données, compressées à 1 200 px (environ 200 Ko), envoyées par
`POST /sync/fichiers` une fois leur fiche acceptée par le serveur. Une photo qui échoue
ne bloque jamais un enregistrement. Quand le téléphone annonce des photos de constat
(`photos_a_suivre`), le serveur attend leur arrivée avant d'alerter sur un mouvement de
kit sans photo.

### Position

Relevée **uniquement au moment d'un geste** : signal d'arrivée, signature d'un rapport,
visa, validation d'une feuille. Jamais en arrière-plan — les relevés de rapprochement
(cadrage, section 8.4) sont une tâche à part. Si le GPS reste muet, l'agent est prévenu
de ce que cela change et décide lui-même de continuer.

### Ce qui reste au back-office

La remise, le transfert et le changement de site d'un kit — ils désignent un autre agent
ou un autre site ; la correction d'un chiffre pré-rempli, qui exige un motif ; le
traitement et la clôture d'un incident (section J du canevas).
