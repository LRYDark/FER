# docs/ — outils de développement

**Rien ici ne fait partie du site.** Ce dossier contient des bancs de test et des
notes de migration, exécutés **à la main, en local**, par la personne qui
développe. Aucune page du site ne les inclut, aucun visiteur ne les atteint.

Le fichier `.htaccess` de ce dossier contient `Require all denied` : même en cas
de déploiement complet, une URL vers `docs/` renvoie 403.

> **Faut-il l'envoyer en production ?** Non, ce n'est pas utile. Mais si votre
> outil de déploiement envoie tout le dépôt, ce n'est pas grave non plus : le
> `.htaccess` le rend inaccessible.

---

## Comment lancer un test

Les tests qui touchent à la base démarrent **une instance MySQL jetable sur le
port 3399**, séparée de celle du site. Ils ne se connectent **jamais** à votre
base réelle : ils créent leur propre base, la remplissent de fausses données,
vérifient, et repartent sans laisser de trace.

```bash
# 1. Démarrer une instance MySQL jetable (port 3399)
mysqld --initialize-insecure --datadir=/un/dossier/temporaire/data
mysqld --datadir=/un/dossier/temporaire/data --port=3399 --console

# 2. Lancer le test
php docs/test-api-v1.php
```

Chaque test affiche `OK` / `ECHEC` ligne par ligne, puis un bilan, et renvoie un
code de sortie 0 si tout est vert.

---

## Les bancs de test

| Fichier | Ce qu'il vérifie | Base 3399 |
|---|---|---|
| **`test-integrite.php`** | **Le contrôle d’ensemble.** Ne teste pas une fonctionnalité : vérifie que le tout se tient — compilation des 102 fichiers PHP, entrées de menu qui mènent quelque part, écrans admin n’employant que des classes réellement servies, permissions au catalogue, colonnes présentes dans install ET update, liens du chatbot valides, fichiers interdits intacts. 21 contrôles, aucune base requise. | non |
| **`audit-bdd.php`** | **Le plus important avant une mise à jour de production.** Rejoue les DEUX chemins d'installation sur des bases jetables : `install.php` sur une base vierge, et `update.php` sur une base garnie de fausses inscriptions (simulation d'un site en production). Vérifie que les inscriptions survivent, que `registrations` n'est pas modifiée structurellement, que les deux chemins convergent vers le même schéma, que rejouer la migration ne change rien, et que les collations permettent les jointures. | oui |
| **`test-api-classique.php`** | Non-régression de **`api.php`** (l'API partenaire) : ping, authentification, HTTPS, interrupteur, ajout d'inscrit, recherche, liste et filtres, statistiques, codes d'erreur. 25 tests. | oui |
| **`test-api-v1.php`** | L'**API mobile** de bout en bout : les trois barrières d'entrée, la connexion par code, la signature des jetons, la révocation immédiate, les modifications en libre-service, les transferts, les archives en lecture seule. 71 tests. | oui |
| **`test-chrono.php`** | Le chronométrage : réception des détections, arbitrage balise/GPS, calcul du temps. Vérifie surtout la REDONDANCE — balise en panne → le GPS sauve le chrono, et inversement — plus les garde-fous (temps aberrant, horodatage sans fuseau, doublons). 35 tests. | oui |
| **`test-lot6.php`** | Les intentions du chatbot (nouvelles ET anciennes, pour détecter les détournements), la section « app » du gabarit d'email, la page publique et les questions de FAQ. 61 tests. | non |
| **`test-lot7.php`** | Les purges de conservation — et surtout ce quelles ne doivent JAMAIS effacer (inscriptions, archives, comptes actifs, transferts en attente) — plus la revue de sécurité : isolation des sessions, `api.php` inchangé, fichiers interdits intacts. 33 tests. | oui |
| **`test-transferts.php`** | Le transfert d'une inscription d'un coureur à un autre, dans le scénario du terrain : une mère inscrit son fils sous sa propre adresse, le fils veut son espace. | oui |
| **`test-auth-coureur.php`** | La connexion des coureurs : code à 6 chiffres, expiration, tentatives, limitation de débit, cookie « se souvenir de moi ». | oui |
| **`test-espace-coureur.php`** | Les pages de l'espace coureur : contrôle d'accès, rattachement des inscriptions. | oui |
| **`test-config-enc.php`** | L'écriture **atomique** de `config/config.enc` : un fichier de configuration à moitié écrit rendrait le site inaccessible. | non |
| **`test-qrcode.php`** | Que le QR code du mail et celui de l'espace coureur sont **identiques** — un bénévole ne doit pas tomber sur un QR non reconnu le jour du retrait des t-shirts. | non |

Les fichiers **`*-appel.php`** ne se lancent pas seuls : ce sont les pilotes qui
exécutent une requête HTTP simulée pour le test correspondant. Une requête = un
processus, parce que les APIs se terminent par `exit()`.

---

## Les autres fichiers

| Fichier | À quoi il sert |
|---|---|
| **`test-mail-catchall.md`** | Procédure à suivre **à la main** pour vérifier le garde-fou des mails, celui qui empêche d'envoyer un mail de test à de vrais inscrits. Ne peut pas être automatisé : il faut regarder une vraie boîte mail. |
| **`navbar-modern.avant-bouton-espace-coureur.php`**<br>**`fer-modern.avant-bouton-espace-coureur.css`** | **Copies de sauvegarde** de la barre de navigation et de sa feuille de style, prises avant l'ajout du bouton « Espace coureur ». Conservées pour pouvoir revenir en arrière si le placement du bouton ne convient pas. À supprimer une fois le choix définitif. |

---

## Pourquoi ces tests existent

Une base de production contient les inscriptions réelles de la course. Une
migration ratée, et ce sont des gens qui se présentent au départ sans dossard.
Ces bancs permettent de **jouer la mise à jour pour de faux** autant de fois
qu'il le faut, jusqu'à ce que tout soit vert — avant d'y toucher pour de vrai.

C'est aussi ce qui a permis de trouver, avant la production :

- une différence de **collation** entre les nouvelles tables et les anciennes,
  qui aurait fait échouer la jointure centrale des lots 2 à 5 ;
- trois bugs de **fuseau horaire** de la même famille (une date produite par
  MySQL comparée en PHP) ;
- une validation de **sexe** qui acceptait silencieusement n'importe quelle
  valeur en la convertissant en « Autre ».
