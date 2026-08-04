# docs/ — outils de vérification

**Rien ici ne fait partie du site.** Deux bancs de vérification, exécutés **à la
main, en local**, par la personne qui développe. Aucune page ne les inclut,
aucun visiteur ne les atteint.

> **Ce dossier ne part JAMAIS en production.** Il est marqué `export-ignore`
> dans `.gitattributes` : `git archive` — et les outils de déploiement qui s'en
> servent — l'excluent du paquet. Et si votre outil copie malgré tout le dépôt
> entier, le `.htaccess` (`Require all denied`) le rend inaccessible par URL.
> Deux barrières.

---

## `audit-bdd.php` — à lancer AVANT toute mise à jour de production

C'est le plus important des deux. Il rejoue **les deux chemins d'installation**
sur des bases MySQL jetables :

* `install.php` sur une base vierge — le cas d'un nouveau serveur ;
* `update.php` sur une base garnie de fausses inscriptions et d'une archive —
  la simulation de **votre site en production**.

Puis il vérifie, dans cet ordre :

1. les inscriptions existantes **survivent** à la migration ;
2. `registrations` reste **structurellement inchangée** ;
3. les deux chemins produisent **le même schéma** — une divergence signifierait
   qu'une installation neuve et une installation migrée ne se comportent pas
   pareil ;
4. rejouer la migration **ne change rien** (idempotence) ;
5. les éditions sont peuplées correctement ;
6. les valeurs par défaut des réglages sont appliquées ;
7. les **collations** permettent les jointures entre nouvelles et anciennes
   tables ;
8. le gabarit d'email personnalisé et la FAQ de l'association **ne sont pas
   écrasés** ;
9. un texte de politique de confidentialité déjà rédigé **n'est pas remplacé** ;
10. l'index d'unicité des détections est bien posé.

C'est lui qui a trouvé, **avant la production**, une différence de collation qui
aurait fait échouer la jointure centrale de tout l'espace coureur.

**Nécessite MySQL sur le port 3399** (voir plus bas).

---

## `test-integrite.php` — la cohérence de l'ensemble

Il ne teste pas une fonctionnalité : il vérifie que le tout se tient. **Aucune
base de données nécessaire.** 27 contrôles, dont chacun a été ajouté après un
bug réel rencontré sur ce projet :

* les fichiers PHP **compilent** tous ;
* chaque entrée du menu d'administration **mène à un fichier existant**, et a un
  titre déclaré — sinon la page s'affiche « Administration » ;
* aucun écran d'administration n'emploie les **classes CSS de l'espace coureur**
  (elles n'y sont pas servies, et `.row` y casse la mise en page) ;
* **toute fonction appelée a son fichier chargé** — attrape le « Call to
  undefined function » avant qu'il n'arrive à l'écran ;
* **aucun gestionnaire d'événement en ligne** : la CSP du site les bloque tous,
  et ils échouent en silence — un « êtes-vous sûr ? » qui ne s'affiche jamais ;
* les retours passent par les **toasts** du site, pas par des blocs `.alert` ;
* toute **permission** utilisée figure au catalogue ;
* les colonnes des derniers lots sont dans **`install.php` ET `update.php`** ;
* les **liens du chatbot** mènent à des fichiers existants ;
* les fichiers que la consigne interdit de modifier (`login.php`,
  `change-password.php`, `reset-password.php`, `totp.php`, `webauthn.php`) sont
  **restés intacts** ;
* l'ancien `api.php`, devenu **`api/v1.php`**, n'a rien changé à son
  comportement : le banc compare son corps ligne à ligne à la version d'origine
  et n'accepte que l'en-tête et les chemins `__DIR__` remontés d'un cran ;
* l'API mobile ne lit **aucune adresse email depuis l'URL**.

---

## Comment les lancer

```bash
# test-integrite.php : rien à préparer
php docs/test-integrite.php

# audit-bdd.php : une instance MySQL jetable, séparée de celle du site
mysqld --initialize-insecure --datadir=/un/dossier/temporaire/data
mysqld --datadir=/un/dossier/temporaire/data --port=3399 --console
php docs/audit-bdd.php
```

L'instance du port **3399** est jetable et **ne touche jamais votre base
réelle** : l'audit crée ses propres bases, les remplit de fausses données,
vérifie, et repart sans rien laisser.

Chaque banc affiche `OK` / `ECHEC` ligne par ligne, puis un bilan, et renvoie un
code de sortie 0 si tout est vert.

---

## `test-mail-catchall.md`

Procédure à suivre **à la main** pour vérifier le garde-fou des mails — celui
qui empêche d'envoyer un mail de test à de vrais inscrits. Ne peut pas être
automatisé : il faut regarder une vraie boîte mail.

---

## Les bancs supprimés — et comment les retrouver

Treize bancs par fonctionnalité (API mobile, API partenaire, chronométrage,
chatbot, purges, transferts, authentification coureur…) ont été retirés : ~350
tests qui servaient au **développement** de ces parties et qui dormaient une
fois celles-ci terminées.

**Ils restent dans l'historique git**, et se restaurent un par un :

```bash
git show d0f908ca:docs/test-api-v1.php > docs/test-api-v1.php
```

À faire si l'on reprend le développement de l'**API mobile** ou du
**chronométrage** — deux chantiers encore ouverts. Sans eux, une régression sur
ces parties passerait inaperçue : c'est ainsi qu'une réponse du chatbot était
restée fausse pendant trois lots, jusqu'à ce qu'un test la démasque.
