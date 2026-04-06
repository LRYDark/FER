# Release Notes

## 🔒 Sécurité

### ✨ Envoi AJAX + Base64
Les formulaires contenant du HTML riche (TinyMCE, AssoConnect, etc.) pouvaient être bloqués par le WAF avec une erreur **403**.

- Envoi des champs sensibles via **AJAX**
- Encodage des contenus en **Base64**
- Décodage côté **PHP** avant traitement

#### Fichiers concernés
- `inc/news.php`
  - sécurisation du champ `desc_article`
- `inc/setting.php`
  - sécurisation des champs `assoconnect_iframe`, `assoconnect_js`, `div_reglementation`, `titleAccueil`, `titleAccueil_mobile`, `title`, `title_mobile`
- `inc/partners.php`
  - sécurisation du champ `partners_desc`
- `inc/mail-settings.php`
- `inc/send-mail.php`
  - sécurisation du champ `description`
- `public/contact.php`
  - sécurisation du champ `message`

---

### 🛡 Sécurisation des requêtes SQL et des variables
Renforcement global de la robustesse du code côté serveur.

- Ajout de `try/catch (PDOException)` sur les requêtes SQL non protégées
- Validation plus sûre des variables `POST` / `GET` avec `??`
- Cast explicite en `(int)` lorsque nécessaire

#### Fichiers concernés
- `inc/news.php`
- `inc/albums.php`
- `inc/album-photos-handler.php`
- `inc/partners.php`
- `inc/stats.php`
- `inc/qr_code.php`
- `public/gallery.php`
- `public/register.php`
- `public/news_action.php`

#### Journalisation
- Toutes les erreurs SQL sont désormais enregistrées dans :
  - `config/logs/php-error.log`

---

### 🛡 Gestion CSRF pour les requêtes AJAX
Amélioration du comportement en cas d’erreur CSRF sur les appels AJAX.

- Retour en **JSON** au lieu d’un arrêt brutal avec `die('Invalid CSRF token');`
- Meilleure gestion côté interface utilisateur

#### Fichiers concernés
- `inc/news.php`
- `inc/setting.php`
- `inc/partners.php`

---

### ⚙️ Durcissement serveur via `.htaccess`
Amélioration des règles de sécurité côté Apache.

- Blocage de l’accès au dossier `.git/` via `RedirectMatch 404`
- Désactivation du listing des répertoires avec `Options -Indexes`
- Blocage de l’exécution de fichiers PHP dans `files/`
- Activation de `session.cookie_secure = 1` pour HTTPS uniquement

---

### 🧼 Assouplissement contrôlé du sanitizer HTML
Ajustement du sanitizer pour autoriser certains styles inline utiles sans compromettre la sécurité XSS.

#### Styles inline désormais autorisés
- `font-family`
- `font-size`
- `color`
- `background-color`
- `font-weight`
- `font-style`
- `text-decoration`
- `padding`

---

## ✨ Nouveau système de notifications "NEW" + envoi mail SMTP

### 🔔 Badges "NEW" et pastilles de notification
Mise en place d’un système de détection des contenus récents de moins de 7 jours.

- Détection des nouveautés sur :
  - les actualités
  - les albums photos
  - les partenaires
- Collecte des IDs pour le suivi de lecture côté client via `localStorage`
- Vérification résiliente de la colonne `created_at`

---

### 🧭 Navbar moderne avec notifications
Modernisation de la barre de navigation avec compteurs visuels.

- Ajout de pastilles rouges avec compteur sur :
  - **Actualités**
  - **Photos**
  - **Partenaires**
- Ajout d’un badge **NEW** rose sur les éléments récents dans les menus déroulants
- Ajout d’une pastille totale sur le bouton du menu mobile
- Masquage automatique de cette pastille lorsque le menu mobile est ouvert
- Mise à jour des compteurs en temps réel via JavaScript
- Mise en place d’un système **anti-flash** :
  - badges invisibles au chargement
  - affichage après calcul JavaScript

---

### 🎨 Styles associés
#### `css/fer-modern.css`
Ajout de nouvelles classes CSS pour gérer les badges et compteurs.

- `.badge-new`
  - badge rose **NEW**
  - animation `pulse`
- `.nav-notif-badge`
  - compteur rouge positionné sur l’icône
- `.mobile-icon-badge`
  - compteur sur les icônes du menu mobile
- `.mobile-menu-total-badge`
  - compteur total sur le bouton menu
- `.fer-badges-ready`
  - gestion de l’anti-flash

---

## 📰 Actualités

### `public/news.php`
Ajout du système de nouveauté sur les actualités récentes.

- Ajout du badge **NEW** sur les cartes d’actualités récentes
- Compatibilité avec le rendu serveur et AJAX
- Ajout des attributs :
  - `data-new-type`
  - `data-new-id`
- Ces attributs permettent le suivi de lecture côté client

---

## 📸 Photos

### `public/photos.php`
Amélioration visuelle et ajout du système de nouveauté.

- Ajout du badge **NEW** sur les albums créés depuis moins de 7 jours
- Suppression des box-shadows roses
- Remplacement par une légère ombre noire au survol
- Correction du mode sombre :
  - couleur forcée des `year-cards` à `#0f172a`

---

## 🤝 Partenaires

### `public/partenaires.php`
Ajout du système de nouveauté et amélioration visuelle.

- Ajout du badge **NEW** sur les cartes des partenaires récents
- Augmentation de l’opacité du texte **Info** à `0.35`
- Suppression des box-shadows roses
- Remplacement par une légère ombre noire au survol
- Correction du mode sombre identique à celle appliquée aux photos

---

## 🛠 Corrections de bugs

### `public/accueil.php`
Nettoyage et correction de l’affichage de la page d’accueil.

- Remplacement de la navbar hardcodée (~460 lignes) par un include vers `navbar-modern.php`
- Suppression des requêtes dupliquées sans filtre `status = 'published'`
- Correction d’un problème affichant des articles non publiés

---

### `inc/partners.php`
Correction de plusieurs problèmes côté partenaires.

- Correction d’une erreur **500**
- Ajout d’un `try/catch` sur `SELECT img FROM partners_years`
  - permet de gérer l’absence de la colonne en production

---

### `partners.php` — ajout d’album partenaire
Correction d’un bug lors de l’ajout d’un album partenaire sans image.

- Avant :
  - l’enregistrement n’était pas créé en base
- Maintenant :
  - l’album est toujours inséré, avec ou sans image

---

### `oauth2callback.php` / `config/googleMail.php`
Correction du flux de redirection OAuth Google.

- Le redirect est maintenant stocké en session via `$_SESSION['oauth_redirect']`
- Après connexion Google, redirection vers `mail-settings.php` au lieu de `setting.php`

---

### Pages d’erreur
Amélioration du bouton retour sur les pages d’erreur.

- Les pages **403**, **404**, **500** et **503** utilisent maintenant `history.back()`
- L’utilisateur revient à la page précédente au lieu d’être renvoyé vers l’accueil

---

## 🎨 Refonte admin & thème personnalisable

### 🗂 Réorganisation des onglets admin

#### `setting.php`
- Passage de **5 à 8 onglets**
- **Maintenance** renommé depuis **Général**
  - ne contient désormais plus que le **mode maintenance**
- Ajout de l’onglet **Personnalisation**
- Ajout de l’onglet **Inscription**
- Ajout de l’onglet **Import Excel**
- Onglets conservés :
  - **Accueil**
  - **Parcours**
  - **Réglementation**
  - **Formulaire**

#### `utilisateurs.php`
- Interface simplifiée
- Onglet renommé en **Utilisateurs & Droits**
- Déplacement de la fonction d’envoi de mail vers une page dédiée

#### `mail-settings.php`
- Création d’un onglet **Envoi de mail**
- Fonction déplacée depuis `utilisateurs.php`
- Réorganisation de la carte Gmail
- Déplacement de :
  - **Email de contact**
  - **Téléphone**
- Ces informations sont maintenant affichées dans une carte **Coordonnées** séparée
- Ajout de la configuration d’un mail **SMTP** en plus de Gmail

#### `partners.php`
- Ajout de deux nouveaux onglets :
  - **Description**
  - **Partenaires**

#### `logs.php`
- Réorganisation en **3 sous-onglets** :
  - **Erreurs PHP**
  - **Google Mails**
  - **Erreurs d'import**

---

### 🎨 Thème personnalisable

#### Réglages > Personnalisation
- Ajout d’un système complet de personnalisation du thème
- Paramètres disponibles :
  - **Couleur primaire**
  - **Couleur secondaire**
  - configuration distincte pour le **light mode** et le **dark mode**
- Ajout d’un réglage d’**arrondi des angles**
  - curseur de **0 à 32 px**
  - appliqué à l’ensemble des pages
- Ajout du choix de la **police d’écriture**
  - **20 polices Google Fonts** disponibles
- Personnalisation des couleurs du bandeau **Flash Info**
  - couleur de fond
  - couleur du texte
- Ajout d’un **aperçu en direct**
  - sous-onglet **Light**
  - sous-onglet **Dark**
- Ajout d’un bouton **Par défaut**
  - réinitialisation du thème
  - réinitialisation du bandeau Flash Info
- Mise en place d’un **auto-contraste**
  - texte noir ou blanc choisi automatiquement selon la luminosité du fond
- Application des variables CSS sur **toutes les pages publiques et admin**

---

### 🌙 Dark mode
- Support du dark mode via le toggle existant : `body.dark-theme`
- Couleurs primaire et secondaire du mode sombre désormais **configurables**
- Adaptation automatique des éléments suivants :
  - cards
  - inputs
  - footer
  - navbar
  - pills

---

## 🕒 Timeline

### Soft delete & filtres
- Ajout des filtres :
  - **Tous**
  - **Publiés**
  - **Brouillons**
  - **Corbeille**
- Mise en place du **soft delete** via `deleted_at`
- Support de :
  - la restauration
  - la suppression définitive

---

## 🧩 Uniformisation de l’interface

### Admin
- Ajout d’un **titre `h1` avec icône Bootstrap Icons** sur toutes les pages admin
- Suppression des wrappers `card-dashboard` sur plusieurs pages :
  - utilisateurs
  - dashboard
  - qr_code
  - partners
  - albums
  - timeline

### Boutons
- Harmonisation globale des boutons :
  - police uniforme
  - taille uniforme
  - hover uniforme
  - `border-radius` uniforme

#### Boutons concernés
- **Inscription**
- **Je m'inscris**
- **Devenir partenaire**
- **Contactez-nous**

### Flash banner
- Amélioration du bandeau **Flash banner**
- Couleurs désormais configurables
- Défilement continu amélioré
- Durée portée à **60 secondes**
- Suppression des coupures visuelles

---

## 💬 Commentaires

### Mentions et aide à la saisie
- Ajout du support des mentions `@`
- Texte d’aide lors de la saisie
- `@forbachenrose` toujours proposé en premier dans le dropdown
- Dropdown repositionné en dessous du textarea

---

### Modification / suppression des commentaires
- Possibilité de modifier ou supprimer ses propres commentaires pendant **10 minutes**
- Verrouillage automatique si le commentaire possède déjà des réponses
- Disparition automatique des actions après expiration du délai
- Vérification serveur basée sur :
  - l’IP
  - le délai autorisé

---

### Full AJAX
Le module commentaires fonctionne désormais entièrement en AJAX.

- Ajout d’un commentaire sans rechargement
- Réponse à un commentaire sans rechargement
- Modification sans rechargement
- Suppression sans rechargement
- Like sans rechargement

---

### Likes
- Les likes sont maintenant enregistrés en base de données
- Suivi par **IP**
- Remplacement du stockage précédent basé sur les cookies

---

### Rafraîchissement automatique
- Rafraîchissement automatique des commentaires toutes les **15 secondes**
- Rafraîchissement des timestamps toutes les **30 secondes**

---

### Messages inline
- Ajout de messages inline à côté du commentaire posté
- Retour utilisateur plus clair après action

---

## 🔔 Notifications admin

### Nouvel onglet Notifications
Ajout d’un onglet dédié **Notifications** dans les paramètres mail.

- Gestion centralisée des notifications admin
- QR Code déplacé dans cet onglet avec une description explicative

---

### Toggles individuels
Ajout de **5 toggles** indépendants pour activer ou désactiver les notifications suivantes :

- mention `@forbachenrose`
- demande partenariat
- ban IP
- échec 2FA
- verrouillage compte

---

### Destinataires personnalisables
- Sélecteur multiple via **Select2**
- Possibilité de choisir :
  - des administrateurs
  - des emails externes
- Si aucun destinataire n’est défini :
  - envoi à **tous les admins**

---

### Prévisualisations des mails
- Ajout de **5 prévisualisations**
- Chaque aperçu correspond exactement au mail réellement envoyé

---

### Mail de mention amélioré
Le mail de mention a été enrichi.

- Affichage de l’auteur
- Commentaire stylé
- Titre de l’article
- Bouton de lien direct vers le contenu

---

## 🏠 Page d’accueil

### Parcours
- La pastille **Parcours** est désormais cliquable
- Redirection vers la page parcours

---

### Contenu personnalisé
Ajout d’un contenu personnalisable sur la page d’accueil via TinyMCE.

- TinyMCE complet
- Position configurable :
  - entre inscrits et partenaires
  - entre partenaires et historique
  - désactivé
- Ajout d’un trait rose
- Mise en forme stylée des PDF
- Lightbox sur les images

---

### Nettoyage visuel
- Suppression du trait sous le formulaire partenaire

---

## 🔤 Polices personnalisées

### Gestion des polices
- Ajout d’un dossier `fonts/`
- Toute police déposée dans ce dossier apparaît automatiquement dans :
  - le thème
  - tous les TinyMCE
  - les pages publiques

---

### Sélecteur de police
- Menu déroulant personnalisé dans le thème
- Chaque police est affichée avec son propre rendu
- Aperçu en direct
- Google Fonts chargées dynamiquement

---

### Uniformisation
- La police du thème est appliquée par défaut dans tous les TinyMCE
- Liste de polices unifiée dans toute l’application

---

### Nouvelle police
- Ajout de la police **Brittany Signature**

---

## 📊 Statistiques

### Top pages
- Ajout du **Top 5 des pages**
- Affichage de l’URL complète
- Les paramètres `GET` sont inclus

---

### Référents
- Top 5 des référents regroupés par domaine
- Les visites directes sont comptabilisées
- Tooltip explicatif ajouté

---

### Interface
- Cards de même dimension
- Tooltips informatifs
- Ajout d’un bouton **Tout voir**
  - ouverture d’une modal
  - affichage de toutes les stats
  - barre de recherche intégrée

---

## ♻️ Refactoring

### Fonctions centralisées dans `config.php`
Centralisation de **11 fonctions** communes.

- `isAjaxRequest()`
- `decodeHtmlField()`
- `uploadImage()`
- `getTinyMceConfig()`
- `getTinyMceFontFormats()`
- `getTinyMceFontStyles()`
- `getTinyMceGoogleFontsUrl()`
- `getThemeFontStack()`
- `getCustomFonts()`
- `getNotifyRecipients()`
- `isNotifyEnabled()`

---

### TinyMCE unifié
Mise en place d’une configuration TinyMCE unique avec :

- `valid_styles`
- `color_map`
- `extended_valid_elements`
- `invalid_elements`

---

### Suppression du code dupliqué
Nettoyage de plusieurs blocs de code répétés dans le projet.

- `decodeHtmlField()` dupliqué supprimé
- détection AJAX dupliquée supprimée
- logique d’upload dupliquée supprimée
- configurations TinyMCE dupliquées supprimées

---

## 🗄 Base de données

### Nouvelles colonnes
Exécution de `update.php` nécessaire pour appliquer les migrations.

- `theme_primary_color`
- `theme_secondary_color`
- `theme_border_radius`
- `theme_font_family`
- `theme_dark_enabled`
- `theme_dark_primary_color`
- `theme_dark_secondary_color`
- `flash_bg_color`
- `flash_text_color`
- `timeline_items.deleted_at`
- `notify_recipients`
- `notify_toggles`
- `accueil_custom_content`
- `accueil_custom_position`

#### Détail des nouvelles colonnes métier
- `notify_recipients` (`TEXT`)
  - destinataires des notifications
- `notify_toggles` (`TEXT`)
  - états on/off des notifications
- `accueil_custom_content` (`MEDIUMTEXT`)
  - contenu personnalisé de la page d’accueil
- `accueil_custom_position` (`ENUM`)
  - position :
    - `off`
    - `after_inscrits`
    - `after_partners`

---

### Suppression de colonne
- Suppression de la colonne `footer` dans la table `setting`

---

## 🛠 Fichiers & maintenance

### Logs
- Renommage du fichier `logs_google_mails.txt` en `.log`

---

### `update.php`
- Refonte visuelle de la page de migration
- Ajout d’un avertissement dans la topbar admin tant que `update.php` n’est pas supprimé
- Ajout des nouvelles migrations nécessaires à cette release