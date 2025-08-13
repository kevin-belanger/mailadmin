 # EVOMAIL — Admin courriel avec expiration

Application web légère permettant de **créer des boîtes courriel cPanel** et de **définir une date d’expiration** pour chacune.  
Une fois expirées, les boîtes sont **supprimées automatiquement** par une tâche cron.

> L’application détecte aussi les boîtes créées **directement dans cPanel** : vous pouvez leur attribuer une date d’expiration depuis l’interface.

---

## Sommaire

- [Pour les utilisateurs](#pour-les-utilisateurs)
  - [Créer une boîte](#créer-une-boîte)
  - [Voir / modifier les expirations](#voir--modifier-les-expirations)
  - [Suppression automatique](#suppression-automatique)
- [Installation (technique)](#installation-technique)
  - [Prérequis](#prérequis)
  - [Arborescence](#arborescence)
  - [Configuration](#configuration)
  - [Mot de passe administrateur](#mot-de-passe-administrateur)
  - [Déploiement](#déploiement)
  - [Cron : installation & fonctionnement](#cron--installation--fonctionnement)
- [Sécurité](#sécurité)
- [Dépannage (FAQ)](#dépannage-faq)
- [Notes techniques](#notes-techniques)

---

## Pour les utilisateurs

### Créer une boîte

1. Ouvrez l’interface d’administration (ex. `https://votre-domaine.tld/mailadmin/`), connectez-vous.
2. **Nom d’utilisateur** (avant le `@`) — ex. `prenom.nom`.
3. **Domaine** — choisir dans la liste autorisée (ex. `@evomail.ca`).
4. **Quota** en MiB — `0` = illimité.
5. **Mot de passe** — laissez vide pour en **générer un automatiquement** (affiché une seule fois).
6. **Expiration** :
   - **Expire dans … jours** (valeur par défaut définie par l’admin), ou
   - **Jamais** (pas d’expiration).
7. Validez. La boîte est créée côté cPanel et l’expiration est enregistrée.

### Voir / modifier les expirations

- En bas de la page, la **liste** affiche toutes les boîtes connues + leur **date d’expiration** (ou « jamais »).
- Pour **modifier** : choisissez une nouvelle date via le **sélecteur**, ou cochez **« jamais »**, puis **Enregistrer**.
- La liste est **synchronisée automatiquement** à l’ouverture (détection des boîtes présentes dans cPanel et ajout des manquantes avec expiration « jamais »).

> Vous pouvez créer des boîtes **dans cPanel** ou **dans l’application** : si vous souhaitez leur donner une date d’expiration, faites-le **dans l’application**.

### Suppression automatique

- Les boîtes **arrivées à expiration** sont **supprimées automatiquement** côté cPanel par une **tâche cron** (créée si absente).
- L’entrée correspondante est ensuite **retirée** de la liste dans l’application.

---

## Installation (technique)

### Prérequis

- **PHP** 8.0+ recommandé : extensions `curl`, `pdo_sqlite`, `json`, `mbstring`, `openssl`.
- Accès **cPanel** (même utilisateur que celui qui possède les boîtes) + **API Token** (cPanel → *Security* → *API Tokens*).
- HTTPS fonctionnel ; l’appli contacte cPanel en **TLS sur le port 2083**.

### Arborescence

```
/config.php
/app/
  app.php         # bootstrap unique (charge config + modules + auto-cron check)
  auth.php        # auth appli (login, CSRF, helpers)
  cpanel.php      # appels UAPI/JSON API (Email, Cron)
  expiry.php      # SQLite + logique expiration/sync/purge
/bin/
  cron_cleanup.php # script appelé par la cron (sync + suppression expirés)
/data/
  app.sqlite       # base SQLite (créée auto)
  .htaccess        # "Require all denied" (créé auto)
/index.php        # vue unique : création + liste/édition des expirations
/login.php        # page de connexion
```

> Si possible, placez `/data` **hors** du webroot ; sinon, le `.htaccess` bloque l’accès HTTP.

### Configuration

Éditez `config.php` et complétez les constantes :

```php
<?php
// --- cPanel / UAPI ---
define('CPANEL_HOST',  'cpanel.votre-domaine.tld'); // hôte cPanel
define('CPANEL_USER',  'votre_user_cpanel');        // utilisateur cPanel
define('CPANEL_TOKEN', 'VOTRE_JETON_API');          // Security > API Tokens

// --- Auth appli ---
define('ADMIN_USERNAME',      'admin');
define('ADMIN_PASSWORD_HASH', '$2y$10$...'); // voir section suivante

// --- Domaines & quotas ---
define('ALLOWED_DOMAINS',       ['evomail.ca']);
define('RESTRICTED_LOCALPARTS', ['admin','postmaster','abuse','root','mail','webmaster']);
define('DEFAULT_QUOTA_MIB',     1024);  // 0 = illimité
define('DEFAULT_EXPIRY_DAYS',   60);    // expiration par défaut (jours)

// --- SQLite ---
define('SQLITE_PATH', __DIR__ . '/data/app.sqlite'); // chemin absolu recommandé

// --- Cron (auto-suppression) ---
define('CRON_ENABLED', true);
define('CRON_MINUTE',  '*/30'); // exécution toutes les 30 min (ajustez selon besoin)
define('CRON_HOUR',    '*');
define('CRON_DAY',     '*');
define('CRON_MONTH',   '*');
define('CRON_WEEKDAY', '*');

// Binaire PHP CLI et script appelé
define('CRON_PHP_BIN', '/usr/local/bin/php');             // adapter selon l’hébergeur
define('CRON_SCRIPT',  __DIR__ . '/bin/cron_cleanup.php'); // chemin absolu
```
> **Important** : sur cPanel, le binaire PHP CLI peut se trouver ailleurs (ex. `/opt/cpanel/ea-php82/root/usr/bin/php`). Vérifiez-le dans votre environnement.

### Mot de passe administrateur

Générez un **hash** sécurisé et placez-le dans `ADMIN_PASSWORD_HASH` :

```bash
php -r 'echo password_hash("votre-mot-de-passe", PASSWORD_DEFAULT), PHP_EOL;'
```

> Ne stockez jamais le mot de passe **en clair** dans `config.php`.

### Déploiement

1. Déposez les fichiers sur le serveur web (ex. dans `/mailadmin`).
2. Vérifiez les droits du dossier `/data` (créé automatiquement) et la présence du `.htaccess` `Require all denied`.
3. Accédez à `/mailadmin/login.php`, connectez-vous avec `ADMIN_USERNAME` + votre mot de passe.
4. La page `index.php` est unique : **formulaire** de création en haut, **liste/édition** des expirations en bas.

### Cron : installation & fonctionnement

- À chaque chargement d’une page admin authentifiée, l’appli **vérifie** via l’API cPanel que la cron existe ; sinon, elle la **crée** (*commande exacte* : `CRON_PHP_BIN CRON_SCRIPT`).
- Le script `bin/cron_cleanup.php` exécute :
  1) **Synchronisation** cPanel → SQLite (ajoute les boîtes manquantes en « jamais », retire celles qui n’existent plus).
  2) **Suppression** des boîtes **expirées** via UAPI `Email::delete_pop`, puis **nettoyage** de la table `mailbox_expiry`.

> Vous pouvez aussi créer/modifier la cron **manuellement** dans cPanel si vous préférez.

---

## Sécurité

- **TLS** : ne désactivez pas la vérification du certificat (les appels API exigent un cert valide).
- **Jeton API** : tenez `CPANEL_TOKEN` secret ; régénérez-le en cas de doute.
- **Répertoire admin** : possibilité d’ajouter une **auth HTTP** ou une **liste d’IP autorisées** en plus du login appli.
- **SQLite** : si `/data` est sous webroot, le `.htaccess` empêche tout accès HTTP.
- **Mots de passe** : le mot de passe administrateur n’est jamais stocké en clair (seulement le hash).

---

## Dépannage (FAQ)

**Je vois “API2 HTTP 404” en gestion de cron.**  
→ Vérifiez l’URL d’API2 : `https://CPANEL_HOST:2083/json-api/cpanel` (le **port 2083** est requis).

**UAPI renvoie 403 / 401.**  
→ Mauvais `CPANEL_USER` / `CPANEL_TOKEN`, jeton invalide/expiré, ou droits insuffisants. Regénérez un token côté cPanel.

**Une boîte “système” sans `@` apparaît.**  
→ L’appli **ignore** les entrées **sans `@`** lors de la synchronisation.

**“database is locked”**.  
→ L’appli active WAL + `busy_timeout`. Si le problème persiste, vérifier droits/quotas et scripts concurrents.

**Cron ne s’exécute pas.**  
→ Confirmez le chemin de `CRON_PHP_BIN` et celui de `CRON_SCRIPT`. Vérifiez dans cPanel → *Cron Jobs* que la ligne est créée, et consultez les logs cron si disponibles.

**Je veux tester la purge sans supprimer réellement.**  
→ Ajoutez un mode **dry-run** dans `bin/cron_cleanup.php` (facile à intégrer) pour tracer ce qui serait supprimé.

---

## Notes techniques

- **Appels cPanel utilisés**
  - UAPI `Email::add_pop` : création d’une boîte.
  - UAPI `Email::delete_pop` : suppression d’une boîte.
  - UAPI `Email::list_pops` : liste des boîtes par domaine.
  - JSON API v2 `Cron::listcron` / `Cron::add_line` : gestion de la tâche cron.
- **Synchronisation**
  - À l’ouverture d’`index.php`, l’appli synchronise cPanel ↔ SQLite (avec filtrage des entrées sans `@`).
- **Base de données**
  - Table unique `mailbox_expiry(email PRIMARY KEY, expires_at TEXT|NULL)`.
  - `NULL` = jamais ; format ISO8601 UTC pour les dates (ex. `2025-08-12T10:00:00+00:00`).
- **UI**
  - Une seule vue (`index.php`) : formulaire de création + tableau d’expirations (édition inline : datepicker + “jamais”).

---

Bon usage !  
Pour toute amélioration (grâce avant suppression, dry-run, notifications e-mail de purge, multi-profils cPanel), ces options peuvent être ajoutées facilement aux scripts existants.
