# Où se trouve quoi, et pourquoi

## Le découpage

**Deux applications, une bibliothèque.** Le code commun n'existe qu'à un seul
endroit — corriger une fois suffit pour les quatre appareils.

```
bibliotheque/               LE CODE COMMUN — un seul exemplaire
├── fer_shared/               LE CŒUR
│   └── lib/src/
│       ├── api/                client de /api/mobile, erreurs, jetons
│       ├── models/             objets renvoyés par l'API
│       ├── course/             file d'attente, GPS, balises, calories
│       ├── ui/                 thème et écrans (téléphone + tablette)
│       ├── reveil.dart         le rappel, SANS greffon de notification
│       └── session.dart        l'état global
└── fer_rappels/             greffons : rappel local + réception des push

android/                    ANDROID : téléphone, tablette, Wear OS
├── lib/
│   ├── main.dart              point d'entrée téléphone et tablette
│   ├── main_montre.dart       point d'entrée Wear OS
│   └── ecran_montre.dart      l'écran unique de la montre
└── android-overlay/          permissions à fusionner dans le manifeste

mac/                        APPLE : iPhone, iPad, Apple Watch
├── lib/main.dart              point d'entrée iPhone et iPad
├── ios-overlay/               clés à fusionner dans Info.plist
├── ios-liveactivity/          Live Activity et Dynamic Island (SwiftUI)
└── watchos/                   Apple Watch (SwiftUI, pas du Dart)
```

```
bibliotheque ←── android
      ↑
      └───────── mac
```

⚠️ **`mac/` ne produit AUCUNE application macOS.** Le nom dit *où l'on compile*
— il faut un Mac et Xcode — pas ce qu'on obtient : iPhone, iPad et Apple Watch.

⚠️ **Sur le Mac, emportez `mac/` ET `bibliotheque/`**, côte à côte. C'est le seul
piège du transfert.

### Pourquoi un exemplaire unique, et pas une copie par application

Une version antérieure dupliquait le cœur dans chaque application, pour que
chaque dossier se transporte seul. C'était un mauvais échange : **deux copies
divergent toujours**. Un correctif du client d'API appliqué d'un seul côté ne se
remarque pas — les deux applications compilent, les deux fonctionnent, et l'une
envoie de mauvaises données. On s'en aperçoit après la course.

Un exemplaire unique règle le problème à la source. Le prix est une règle à
retenir : **la bibliothèque voyage avec l'application.**

### Pourquoi `fer_rappels` est un paquet à part

**`fer_shared` doit rester compilable sans les greffons de notification.** La
montre Wear OS ne pose aucun rappel *(c'est le téléphone qui le fait)*, et
watchOS est en Swift. Y mettre `firebase_messaging` imposerait Firebase à des
cibles qui n'en veulent pas.

Et **Android et iOS partagent exactement le même code de notification** —
`flutter_local_notifications` et `firebase_messaging` couvrent les deux
plateformes avec la même API. Un fichier, pas deux.

## Les six décisions qui structurent tout

### 1. Le serveur calcule, l'application observe

L'application n'envoie **jamais** un temps. Elle envoie des observations
horodatées : « balise vue à 9 h 42 min 17,3 s », « position à 3 m de la ligne ».
Le temps est calculé par `src/content/chrono.php`, côté serveur.

Une application qui enverrait « j'ai fait 42 minutes » ferait une déclaration,
pas une mesure. On la croit, ou on la truque — et le premier classement contesté
serait indéfendable.

### 2. Le disque avant le réseau

`course/file_attente.dart` écrit **toujours** sur le téléphone avant d'essayer
d'envoyer. Le réseau tombera pendant la course : trois mille personnes sur la
même antenne, un téléphone au fond d'une poche.

Si une détection d'arrivée n'existait que dans une requête HTTP qui échoue, elle
serait perdue — et quelqu'un franchirait la ligne sans chrono.

Les deux points d'entrée sont **idempotents côté serveur** (index unique sur la
détection, points GPS antérieurs ignorés). Dans le doute, on renvoie : le pire
est un doublon, que le serveur ignore.

### 3. Deux sources, toujours les deux

Balise Bluetooth **et** franchissement GPS, systématiquement. Si un boîtier lâche
le jour J, le GPS donne quand même un temps ; si les deux sont là, le serveur
retient la balise.

`course/balise.dart` ne publie pas la première lecture mais **le pic de signal** :
une balise se capte plusieurs secondes avant et après la ligne, et retenir la
première donnerait un temps systématiquement trop tôt — de quoi inverser un
classement.

### 4. Fermé en cas de doute

`chrono_actif`, `notifications`, `chrono_pret` : tous ces indicateurs valent
**false** quand l'information manque. Un serveur trop ancien pour connaître un
champ n'a pas décidé de l'activer ; l'inverse ouvrirait la collecte de positions
GPS sur un site que personne n'a configuré pour ça.

### 5. Le message et la sonnerie sont deux choses

Un **message** est du contenu : il vit dans la boîte de réception, avec une date
de publication et une date de fin, et se relit. L'application le récupère par
`GET /me/notifications`.

Un **push** est un événement : il sonne une fois, quand l'organisation appuie
sur « Envoyer sur les téléphones ». Il passe par Firebase.

⚠️ Une version antérieure de ce document affirmait *« aucun service de push, pas
de Firebase, pas d'APNs »* — c'était vrai avant l'ajout de l'envoi réel, et faux
depuis. **Ce qui est obligatoire n'est pas Firebase** : c'est FCM sur Android
*(incontournable en pratique)* et APNs sur iPhone *(celui d'Apple, que Firebase
se contente de relayer)*. Le détail et l'alternative sont dans
[06-notifications-push.md](06-notifications-push.md).

### 6. L'état décide de l'écran

Aucun `Navigator.push` vers la connexion ni vers l'accueil : c'est `EtatSession`
qui choisit (`src/app.dart`). Une déconnexion venue du serveur — appareil révoqué
depuis un autre téléphone — ramène donc à la connexion où qu'on soit, et le
bouton retour ne peut pas rouvrir une session fermée.

## Ce qui n'est volontairement pas là

**Aucune clé d'application globale.** Elle serait livrée dans le binaire installé
sur chaque téléphone, donc lisible par quiconque le décompile. Un secret publié
n'est pas un secret. Ce qui protège les données, c'est le jeton **personnel** de
chaque coureur.

**Aucun démarrage automatique.** Ni Android ni iOS ne l'autorisent. Le « réveil »
est une notification que le coureur touche.

**Aucun paquet d'injection de dépendances.** Six écrans, un utilisateur connecté
à la fois : un `ChangeNotifier` transmis par `InheritedNotifier` suffit et se
relit d'un trait. Une dépendance de moins à maintenir pendant les années où
l'application restera installée.

## Quand vous modifiez l'API

Tout se modifie dans `bibliotheque/fer_shared/` — une fois, pour les quatre
appareils.

| Vous changez | Fichier |
|---|---|
| une route, un champ | `bibliotheque/fer_shared/lib/src/api/api_client.dart` |
| un code d'erreur | `…/lib/src/api/api_erreur.dart` |
| un réglage de `/app/config` | `…/lib/src/models/modeles.dart` |
| … et si la **montre Apple** s'en sert | `mac/watchos/SessionMontre.swift` **aussi** |

⚠️ **`mac/watchos/SessionMontre.swift` EST LE SEUL ENDROIT QUI DUPLIQUE LE
CONTRAT.** C'est une réécriture en Swift, que rien ne peut synchroniser
automatiquement — le prix de watchOS, que Flutter ne sait pas compiler. Un oubli
s'y voit en 404 ou en champ vide, **sans message d'erreur**.

```bash
cd APPS/bibliotheque/fer_shared && flutter analyze   # après toute modification
```

## Relever une version

Deux `pubspec.yaml` (`android`, `mac`) **et**
`mac/watchos/SessionMontre.swift`. Le serveur compare une seule
`app_version_minimale` : un numéro divergent ferait refuser une plateforme et
pas l'autre, sans que rien ne l'explique.
