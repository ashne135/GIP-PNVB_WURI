# Déploiement sur alwaysdata

Procédure pour mettre la plateforme en ligne sur un compte alwaysdata, en vue
d'une démonstration. Elle suppose un accès SSH et le panneau d'administration
`admin.alwaysdata.com`.

**Pas besoin d'un second hébergeur gratuit :** alwaysdata fournit PHP, MySQL,
SSH et des tâches planifiées. Tout tient sur le même compte.

---

## 0. Ce qu'il faut avant de commencer

| Élément | Valeur |
|---|---|
| PHP | **8.2 minimum** (`composer.json` exige `^8.2`) |
| Extensions | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, **`zip`**, **`gd`** |
| Base | **MariaDB 11.4** sur alwaysdata (le poste de développement, lui, tourne sous MySQL 8.4) |

> **MariaDB 10.10 au minimum**, pour deux raisons distinctes rencontrées en
> essayant réellement le schéma sur MariaDB 10.4 :
>
> | Obstacle | Version qui le lève |
> |---|---|
> | `add \`batch_uuid\` uuid null` → erreur 1064 : le pilote `mariadb` de Laravel écrit un type `uuid` **natif** | **10.7** |
> | `\`effectue_le\` timestamp not null` → erreur 1067 : sans `explicit_defaults_for_timestamp`, le moteur pose une date zéro que le mode strict refuse | **10.10** (le réglage y devient ON par défaut ; il est en lecture seule, donc non corrigeable après coup) |
>
> Le 11.4 d'alwaysdata couvre les deux — **et c'est désormais vérifié** : les
> 29 migrations sont passées sur **MariaDB 11.4.13** lors du déploiement réel,
> y compris `add_batch_uuid_column_to_activity_log_table` et
> `create_kit_mouvements_et_remplacements_tables`, les deux qui échouent en
> 10.4. Le succès avait d'abord été déduit de l'historique des versions ; il est
> maintenant constaté.
>
> Sur un hébergeur plus ancien, le repli reste `DB_CONNECTION=mysql`, dont la
> grammaire écrit `char(36)` et reste acceptée par toutes les versions.
>
> **Ce qui est vérifié, en revanche :** les quatre **colonnes générées** du
> schéma fonctionnent sur MariaDB, syntaxe et résultats compris.
> `cle_unicite_active` — l'index qui interdit en base deux affectations actives
> pour un même agent — a été créée par la migration réelle ; `ecart_enregistrements`,
> `taux_realisation` et `taux_conformite` ont été éprouvées séparément, y
> compris le cas où l'objectif est nul et où le taux doit rendre `NULL` plutôt
> que de diviser par zéro.

> **La base n'est pas la même qu'en développement, et cela se configure.**
> Laravel 11 fournit un pilote `mariadb` distinct du pilote `mysql` : c'est lui
> qu'il faut désigner, sinon le SQL émis vise MySQL.
>
> Deux points ont été vérifiés et ne posent pas de problème : la collation du
> projet est `utf8mb4_unicode_ci`, que MariaDB connaît — contrairement au
> `utf8mb4_0900_ai_ci` par défaut de MySQL 8 — et les quatre **colonnes
> générées** du schéma (`cle_unicite_active`, `ecart_enregistrements`,
> `taux_realisation`, `taux_conformite`) n'emploient que `if()`, `cast()`,
> `coalesce()` et `round()`, toutes présentes en MariaDB.
>
> C'est néanmoins là qu'une incompatibilité se manifesterait en premier : si
> `migrate` échoue, ce sera sur `create_affectations_tables` ou sur
> `creer_rapports_trois_niveaux`.

`zip` et `gd` ne sont pas facultatives : `maatwebsite/excel` produit les exports
tableur, et `dompdf` les feuilles de présence en PDF.

> **L'espace est la vraie contrainte.** 1 Go doit contenir l'application, la
> base **et les photos déposées par les agents** (incidents, mouvements de
> kits). C'est ce dernier poste qui saturera en premier : une centaine de
> photos à 200 Ko suffit à consommer 20 Mo. Pour une démonstration, prévoir de
> purger `storage/app` entre deux séances.

---

## 1. La base de données

Dans le panneau : **Bases de données → MySQL → Ajouter**.

Noter le nom (souvent préfixé par le compte, par exemple `moncompte_pnvb`),
l'utilisateur et le mot de passe : ils iront dans le `.env`.

---

## 2. Le code

```bash
ssh moncompte@ssh-moncompte.alwaysdata.net
cd ~/www
git clone https://github.com/ashne135/GIP-PNVB_WURI.git pnvb
cd pnvb
```

---

## 3. Les dépendances

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` est important : il écarte Pest, Faker et les outils de
développement, qui n'ont rien à faire en production et pèsent pour rien.

---

## 4. La configuration

```bash
cp .env.example .env
```

Puis éditer `.env`. **Les lignes à changer impérativement :**

```dotenv
APP_ENV=production
APP_DEBUG=false
# Le CHEMIN fait partie de l'adresse : sans lui, tous les liens generes
# (courriels, PDF, redirections) pointeraient a la racine du domaine.
APP_URL=https://moncompte.alwaysdata.net/pnvbwuri

# alwaysdata sert du MariaDB : ce pilote n'est PAS « mysql ».
DB_CONNECTION=mariadb
DB_HOST=mysql-moncompte.alwaysdata.net
DB_PORT=3306
DB_DATABASE=moncompte_pnvb
DB_USERNAME=moncompte_pnvb
DB_PASSWORD=le-mot-de-passe-choisi

# Pour une démonstration : pas de processus de file d'attente à maintenir.
QUEUE_CONNECTION=sync

# Aucun SMS réel n'est envoyé tant que ce réglage vaut « log ».
PNVB_SMS_PILOTE=log

# Le back-office et l'API partagent le même domaine : laisser vide.
CORS_ORIGINES=

# Chemin du fichier d'import du référentiel. Le .env.example porte un chemin
# Windows du poste de développement, sans aucun sens sur le serveur : le vider,
# ou le faire pointer vers un fichier réellement téléversé.
PNVB_FICHIER_REFERENTIEL=
```

`APP_DEBUG=false` n'est pas cosmétique : à `true`, la moindre erreur afficherait
la configuration de la base au visiteur.

**Le fichier `.env` n'est jamais versionné.** Il se crée sur le serveur, à la
main.

---

## 5. Clé, schéma et données de base

**D'abord, le référentiel territorial.** Sans lui, aucune région, commune ni
localité : le back-office s'affiche mais reste vide, et le chef d'antenne n'a
pas de région. Le classeur s'envoie **hors du répertoire web** — sinon il
serait téléchargeable — et sans espaces dans le nom :

```bash
# En local
scp "Liste des villages.xlsx" moncompte@ssh-moncompte.alwaysdata.net:pnvb-donnees/referentiel-villages.xlsx

# Sur le serveur
chmod 640 ~/pnvb-donnees/referentiel-villages.xlsx
```

Puis, dans `.env` :

```dotenv
PNVB_FICHIER_REFERENTIEL=/home/moncompte/pnvb-donnees/referentiel-villages.xlsx
```

La lecture d'un `.xlsx` exige les extensions **`zip`** et **`gd`**
(`php -m` pour vérifier). Ensuite :

```bash
php artisan key:generate
php artisan migrate --force
php -d memory_limit=1024M artisan db:seed --force
php artisan storage:link
```

`db:seed` enchaîne, dans l'ordre : rôles et permissions, paramètres,
nomenclatures d'incidents, **référentiel territorial**, comptes
d'administration. L'ordre compte — les comptes rattachent le chef d'antenne à
une région, qui doit donc exister. Les **données de démonstration sont
écartées d'office** hors environnement local ou de test : aucun risque de
déverser des milliers de volontaires fictifs en production.

`memory_limit=1024M` n'est pas superflu : lire 7 000 localités avec
PhpSpreadsheet dépasse la limite par défaut.

> **Les anomalies affichées pendant le chargement viennent du fichier source**,
> pas du déploiement : localités sans site faute de quota, écarts entre
> population déclarée et somme des localités, coquilles corrigées à la lecture.
> Elles sont à arbitrer avec le client ; elles n'empêchent rien.

`--force` est requis : Laravel refuse de migrer en production sans
confirmation explicite.

Les cinq seeders sont **idempotents** : les rejouer après une mise à jour
ajoute ce qui manque — nouveaux rôles, permissions, paramètres, localités —
sans dupliquer ni renuméroter. Les codes de commune déjà attribués ne sont
jamais régénérés : ils figurent sur des documents papier.

**Les comptes d'administration créés sont des comptes de démonstration.**
Quatre comptes, un par rôle web, tous marqués `est_fictif = true` :

| Téléphone | Rôle |
|---|---|
| `+22670000001` | Super administrateur |
| `+22670000002` | Administrateur national |
| `+22670000003` | Chef d'antenne régional |
| `+22670000004` | Observateur |

Mot de passe initial : `ChangerMoi#2026`, **à changer obligatoirement à la
première connexion** — le serveur l'impose avant toute autre action.

Ils suffisent pour une démonstration. **Avant une mise en service réelle**, il
faut créer le véritable super administrateur par une procédure dédiée, puis
purger ces comptes : leurs numéros et leur mot de passe initial sont publics,
puisqu'ils figurent dans ce dépôt.

---

## 6. Le back-office

Il se construit **sur votre machine**, pas sur le serveur : alwaysdata n'a pas
besoin de Node, et la compilation consommerait de l'espace pour rien.

```bash
# En local, si le site est servi à la RACINE d'un domaine
cd frontend
npm run build

# Si le site est servi sous un CHEMIN (le cas sur alwaysdata)
VITE_BASE=/pnvbwuri/ npm run build
```

> **Le chemin de base n'est pas optionnel, et il gouverne TROIS choses.**
> Vite inscrit les liens vers ses fichiers *à la compilation*. Compilé pour la
> racine puis servi sous `/pnvbwuri`, le back-office cherche ses scripts à la
> racine du domaine et n'affiche **qu'une page blanche, sans aucune erreur** —
> la panne la plus longue à diagnostiquer, parce qu'elle ne dit rien.
>
> Mais `VITE_BASE` ne suffisait pas : deux autres réglages en dépendent, et
> tous deux se déduisent désormais de la même valeur, pour qu'ils ne puissent
> pas diverger.
>
> | Ce qui dépend du chemin | Symptôme si on l'oublie |
> |---|---|
> | Les fichiers compilés (`base` de Vite) | Page blanche, aucune erreur |
> | Le routeur (`basename`) | L'adresse perd son préfixe ; recharger la page tombe sur une autre application |
> | L'adresse de l'API (`baseURL`) | L'écran s'affiche, mais toute connexion répond « Une erreur est survenue » |
>
> Les deux derniers sont insidieux : **l'interface paraît fonctionner**. C'est
> la seule panne de cette procédure qui ne ressemble pas à une panne.
>
> Pour vérifier avant d'envoyer : `dist/index.html` doit contenir
> `src="/pnvbwuri/assets/…"`. S'il contient `src="/assets/…"`, la compilation
> est à refaire. La barre oblique finale de `VITE_BASE` est obligatoire.

Puis envoyer le contenu de `frontend/dist/` dans le dossier `public/` du
serveur (SFTP ou `scp`) :

```bash
scp -r frontend/dist/* moncompte@ssh-moncompte.alwaysdata.net:~/www/pnvb/public/
```

Le fichier `index.html` se retrouve ainsi à la racine servie, et la route
« attrape‑tout » de `routes/web.php` prend en charge les adresses internes
(`/equipes`, `/journal`…) quand la page est rechargée.

---

## 7. Le site dans le panneau alwaysdata

**Sites → Ajouter un site :**

| Champ | Valeur |
|---|---|
| Adresse | `moncompte.alwaysdata.net/pnvbwuri` |
| Type | PHP |
| Répertoire racine | `/www/pnvb/public` |
| Version de PHP | **8.2 ou 8.3, choisie explicitement** |

> **On ne peut pas créer de sous-domaine sous `alwaysdata.net`.** Le compte
> reçoit `moncompte.alwaysdata.net` et rien en dessous : la zone appartient à
> alwaysdata. Tenter `pnvb.moncompte.alwaysdata.net` renvoie
> *« Le domaine alwaysdata.net ne vous appartient pas »*, et le nom ne résout
> jamais. Il faut donc **un chemin** — `moncompte.alwaysdata.net/pnvbwuri` —
> sauf à posséder son propre domaine.
>
> Un chemin plus précis l'emporte sur la racine : une autre application déjà
> installée à `moncompte.alwaysdata.net` continue de fonctionner normalement.
>
> **Ne laissez pas « Version par défaut » pour PHP.** `composer.json` exige
> `^8.2` ; si le défaut du compte est antérieur, `composer install` échoue sans
> que la cause saute aux yeux.

Le répertoire racine pointe sur **`public/`**, jamais sur la racine du projet :
sinon `.env`, `vendor/` et le code source deviendraient téléchargeables.

---

## 8. Les tâches planifiées

Six traitements tournent automatiquement (escalade des incidents, purge des
relevés de position, rapprochement des présences, recalcul des accès, agrégats,
exports). Ils dépendent tous d'un seul appel.

**Tâches planifiées → Ajouter :**

```
Commande : cd ~/www/pnvb && php artisan schedule:run
Fréquence : toutes les minutes
```

Sans cette tâche, les alertes d'escalade ne partent pas et les exports
quotidiens ne sont jamais produits.

---

## 9. L'application mobile

L'adresse de l'API **n'est pas écrite en dur** : elle est fixée à la
compilation (`mobile/lib/configuration.dart`). Sans la passer, l'APK garde sa
valeur par défaut `http://10.0.2.2:8000/api/v1` — le poste de développement vu
depuis un émulateur — et ne trouvera jamais le serveur.

```bash
cd mobile
flutter build apk --debug \
  --dart-define=URL_API=https://moncompte.alwaysdata.net/pnvbwuri/api/v1
```

L'adresse se compose de **trois morceaux**, et chacun est nécessaire : le
domaine, le **chemin du site** (`/pnvbwuri`), puis `/api/v1`. En omettre un
seul donne des 404 sur chaque appel — et l'application annoncera « serveur
injoignable », sans pouvoir dire pourquoi.

L'APK se trouve ensuite dans `mobile/build/app/outputs/flutter-apk/`.

Pour une remise en main propre, la version `--debug` suffit et s'installe sans
signature. Une version `--release` exige une clé de signature Android, qui
n'est pas configurée dans ce dépôt.

### Alléger l'APK pour les téléphones 32 bits

Sans précision, Flutter embarque **toutes** les architectures : l'APK pèse
alors plus du double (≈ 205 Mo contre ≈ 109 Mo). Il fonctionne partout, mais le
transfert par câble est deux fois plus long et l'espace occupé sur un téléphone
d'entrée de gamme devient gênant.

```bash
flutter build apk --debug --target-platform android-arm \
  --dart-define=URL_API=https://moncompte.alwaysdata.net/pnvbwuri/api/v1
```

`android-arm` vise `armeabi-v7a`, l'architecture des appareils 32 bits. À
n'utiliser que si le parc est homogène : un téléphone 64 bits accepte cet APK,
mais un APK compilé pour `android-arm64` ne s'installerait pas sur un 32 bits.

---

## 10. Vérifier que tout répond

Il n'existe pas de route « état de santé » publique : toute l'API est derrière
l'authentification. On vérifie donc par ce que le serveur **refuse**, ce qui
prouve tout autant que la chaîne fonctionne.

```bash
# Une route protegee, sans jeton : doit repondre 401, en JSON.
curl -i https://moncompte.alwaysdata.net/pnvbwuri/api/v1/alertes

# Une route d'API inexistante : doit repondre 404, en JSON egalement.
curl -i https://moncompte.alwaysdata.net/pnvbwuri/api/v1/inexistant
```

Le second appel est le plus instructif : s'il renvoie du **HTML avec un statut
200**, c'est que la règle « attrape‑tout » de `routes/web.php` avale les
adresses d'API. Le back-office continuerait de fonctionner, mais le téléphone
— qui attend du JSON — échouerait sans message compréhensible.

Puis, dans un navigateur, ouvrir l'adresse du site : l'écran de connexion du
back-office doit s'afficher, et **recharger une page interne ne doit pas
produire de 404**.

---

## En cas de page blanche

| Symptôme | Cause la plus fréquente |
|---|---|
| **403 sur la racine, alors que l'API répond** | Aucun `DirectoryIndex` : voir ci-dessous |
| **403 sur tout, y compris l'API** | Permissions du clone : voir ci-dessous |
| Erreur 500 sans détail | `APP_KEY` absente — relancer `php artisan key:generate` |
| 500 sur l'API seule, le back-office s'affichant | Identifiants de base : `storage/logs/laravel.log` donne le message exact |
| 404 sur toutes les pages sauf l'accueil | Répertoire racine mal placé, ou `dist/` non copié dans `public/` |
| Écran de connexion sans réponse | `APP_URL` ne correspond pas à l'adresse réelle |
| Erreur de base au premier appel | Hôte MySQL : sur alwaysdata c'est `mysql-moncompte.alwaysdata.net`, pas `127.0.0.1` |

### Les pièges rencontrés en conditions réelles

Chacun a été mesuré sur le serveur avant d'être corrigé. Ils sont listés dans
l'ordre où ils se présentent : réparer l'un révèle souvent le suivant.

**1. `git clone` produit des fichiers qu'Apache ne peut pas lire.**

Selon le masque du compte, le clone crée des dossiers en `770` et des fichiers
en `660` — donc **aucun droit pour « autres »**. Apache, qui ne tourne pas sous
votre compte, ne peut même pas traverser le dossier : tout renvoie **403**,
avant même que PHP soit appelé.

```bash
chmod o+x ~/www/pnvb
chmod -R o+rX ~/www/pnvb/public
chmod o+x ~/www/pnvb/storage ~/www/pnvb/storage/app
chmod -R o+rX ~/www/pnvb/storage/app/public
chmod 640 ~/www/pnvb/.env          # et surtout PAS o+r sur celui-ci
```

N'ouvrez **pas** en bloc avec un `chmod -R o+rX` sur tout le projet : sur un
hébergement mutualisé, cela rendrait votre `.env` — donc le mot de passe de la
base — lisible par les autres comptes du serveur.

**2. Laravel ne déclare aucun index — et la correction évidente est fausse.**

Sans `DirectoryIndex`, la racine du site renvoie **403**. Le réflexe consiste à
ajouter `DirectoryIndex index.html index.php` : **ne le faites pas**. Cette
directive fait servir `index.html` comme index de *tout* répertoire, y compris
sur les adresses d'API — qui répondent alors du **HTML en 200** au lieu de JSON.
Le back-office paraît sain ; le téléphone, lui, échoue sans explication.

La version du dépôt désactive l'index global et sert la racine par une règle
explicite, placée **dans le même bloc** que celle de Laravel et **avant** elle :

```apache
<IfModule mod_dir.c>
    DirectoryIndex disabled
</IfModule>
…
    RewriteRule ^$ index.html [L]
```

Un second bloc `RewriteEngine On` ajouté en fin de fichier produit la même
panne : il s'exécute dans une passe ultérieure et reprend la main après la
réécriture vers `index.php`.

> **Leçon de méthode.** Ce fichier a été corrigé trois fois de suite sans être
> relu en entier, et la deuxième correction annulait la première. En cas de
> doute, relisez le `.htaccess` complet avant d'y ajouter quoi que ce soit.

**3. Les sessions en base font échouer toutes les requêtes avant migration.**

`.env.example` porte `SESSION_DRIVER=database` et `CACHE_STORE=database`.
Chaque requête — même une 404 — ouvre alors une session en base : tant que les
identifiants sont faux ou les tables absentes, **tout** répond 500. Le journal
le montre sans ambiguïté :

```
select * from `sessions` where `id` = …
```

Cette API s'authentifie par jetons Sanctum ; les sessions n'y servent
quasiment pas. En production :

```dotenv
SESSION_DRIVER=file
CACHE_STORE=file
```

**4. alwaysdata ne transmet pas le sous-chemin à PHP.**

C'est le plus sournois. Mesuré sur une vraie requête :

```
REQUEST_URI : /pnvbwuri/api/v1/alertes
SCRIPT_NAME : /pnvb/public/index.php      ← chemin DISQUE, pas l'adresse publique
```

Symfony ne trouve aucun préfixe commun entre les deux, conserve `pnvbwuri/`
dans le chemin, et **aucune route d'API ne correspond plus**. La route de repli
sert alors la page du back-office — toujours en 200, toujours en HTML.

`public/index.php` retire désormais ce préfixe avant que Laravel ne lise
l'URL. Il suffit de le déclarer :

```dotenv
APP_PREFIXE_URL=/pnvbwuri
```

La variable est vide par défaut : en développement et sur un hébergement
classique, rien ne change.

**Comment savoir si tout est rentré dans l'ordre** — les quatre adresses doivent
se comporter *différemment* :

| Adresse | Réponse attendue |
|---|---|
| `/pnvbwuri/` | 200, HTML — le back-office |
| `/pnvbwuri/equipes` | 200, HTML — une page interne rechargée |
| `/pnvbwuri/api/v1/alertes` | 401 JSON, ou 500 tant que la base n'est pas jointe |
| `/pnvbwuri/api/v1/inexistant` | 404 |

Si elles répondent toutes pareil, un des pièges ci-dessus est encore actif.

Après toute modification du `.env` :

```bash
php artisan config:clear
```
