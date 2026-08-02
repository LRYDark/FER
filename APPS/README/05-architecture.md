# Où se trouve quoi, et pourquoi

## Le découpage

```
shared/          LE CŒUR — tout ce qui n'est pas de la plateforme
├── api/         client de /api/v1, erreurs, jetons
├── models/      objets renvoyés par l'API
├── course/      file d'attente, suivi GPS, écoute des balises
├── ui/          thème et écrans (téléphone + tablette)
├── reveil.dart  logique du rappel (SANS greffon de notification)
└── session.dart l'état global

rappels/         notification locale Android + iOS
android/         coque : permissions + point d'entrée
android_watch/   coque : deux écrans dédiés à la montre
mac/             coque iPhone/iPad + watchos/ en SwiftUI
```

## Les cinq décisions qui structurent tout

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

### 5. L'état décide de l'écran

Aucun `Navigator.push` vers la connexion ni vers l'accueil : c'est `EtatSession`
qui choisit (`src/app.dart`). Une déconnexion venue du serveur — appareil révoqué
depuis un autre téléphone — ramène donc à la connexion où qu'on soit, et le
bouton retour ne peut pas rouvrir une session fermée.

## Ce qui n'est volontairement pas là

**Aucun service de push.** Pas de Firebase, pas d'APNs. L'application interroge
`GET /me/notifications` à son ouverture. Conséquence : aucune liste d'appareils
n'est déclarée chez Google ou Apple, donc aucune liste de porteurs de
l'application n'est exportée. Contrepartie, dite dans l'administration : une
notification n'arrive pas dans la seconde.

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

| Vous changez | À reporter dans |
|---|---|
| une route, un champ | `shared/lib/src/api/api_client.dart` |
| … et si la montre Apple s'en sert | `mac/watchos/SessionMontre.swift` **aussi** |
| un code d'erreur | `shared/lib/src/api/api_erreur.dart` |
| un réglage de `/app/config` | `shared/lib/src/models/modeles.dart` |

⚠️ La montre Apple est le seul endroit qui duplique le contrat. C'est le prix de
watchOS, que Flutter ne sait pas compiler. Un oubli s'y voit en 404 ou en champ
vide, sans message d'erreur.

## Relever une version

Trois `pubspec.yaml` (`android`, `android_watch`, `mac`) **et**
`mac/watchos/SessionMontre.swift`. Le serveur compare une seule
`app_version_minimale` : un numéro divergent ferait refuser une plateforme et
pas l'autre, sans que rien ne l'explique.
