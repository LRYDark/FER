# Apple Watch

## Pourquoi ce n'est pas du Dart

**Flutter ne compile pas pour watchOS.** Aucune version ne l'a jamais fait, et
rien n'annonce le contraire. Une application Apple Watch s'écrit en SwiftUI,
dans une cible Xcode distincte de l'application iPhone.

C'est la seule des six cibles qui ne partage pas le code Dart. Elle parle
**directement** à `/api/mobile`, avec le même contrat : mêmes routes, même en-tête
`X-App-Version`, même jeton personnel.

⚠️ **Toute évolution de l'API doit donc être répercutée à deux endroits** :
`bibliotheque/fer_shared/lib/src/api/api_client.dart` et `mac/watchos/SessionMontre.swift`.

## 1. La cible existe déjà

Elle a été ajoutée au projet — plus rien à faire dans **File → New → Target**.
Le projet contient trois cibles :

| Cible | Ce qu'elle produit |
|---|---|
| `Runner` | l'application iPhone (Flutter) |
| `RunnerTests` | ses tests |
| `FERWatch Watch App` | l'application Apple Watch (SwiftUI) |

Elle a été créée par `mac/ios/montre_embarquee.rb` et un script `xcodeproj`,
et non à la main : un `project.pbxproj` édité au clavier casse en silence.

**Les sources ne sont pas copiées.** La cible référence directement
`mac/watchos/` en relatif. Il n'existe donc qu'une seule copie de chaque
fichier, et modifier `VueCourse.swift` suffit — rien à resynchroniser.

| Fichier | Rôle |
|---|---|
| `FERWatchApp.swift` | point d'entrée, aiguillage connecté / non connecté |
| `SessionMontre.swift` | état, appels API, file d'attente hors réseau |
| `VueCourse.swift` | l'écran : chrono, numéro, deux boutons |
| `Info.plist` | `WKApplication`, identifiant de l'application compagnon |

## 2. ⚠️ Pourquoi la montre n'est PAS embarquée par défaut

C'est le point le plus important de ce document, et le moins évident.

`flutter run` et `flutter build ios` appellent xcodebuild avec
`-sdk iphonesimulator` (ou `iphoneos`). **Un `-sdk` en ligne de commande écrase
le SDKROOT de toutes les cibles construites**, y compris celles qui déclarent
`SDKROOT = watchos`. C'est la précédence la plus haute d'Xcode : aucun réglage
par cible, aucun xcconfig ne peut gagner contre elle.

Dès que la montre devient une dépendance de `Runner`, ses sources SwiftUI sont
donc compilées avec le SDK iOS, et **le téléphone ne construit plus du tout** :

```
Swift Compiler Error: 'main()' is only available in iOS 14.0 or newer
    mac/watchos/FERWatchApp.swift:31
```

Rien dans ce message ne parle de la montre. On peut y perdre une soirée.

Les deux besoins sont réellement exclusifs, d'où un interrupteur :

```bash
cd mac/ios
ruby montre_embarquee.rb oui   # avant Product → Archive
ruby montre_embarquee.rb non   # pour revenir au travail quotidien
```

* **`non`** (état par défaut) — la montre est indépendante. `flutter run`,
  `flutter build ios` et le rechargement à chaud fonctionnent comme avant. La
  montre se lance depuis Xcode, avec son schéma `FERWatch Watch App`.
* **`oui`** — la montre est copiée dans l'application iPhone. Indispensable pour
  l'App Store : sans cela elle n'est ni transmise ni installée avec le
  téléphone. On archive alors **depuis Xcode** (Product → Archive), qui utilise
  `-destination` et respecte le SDK de chaque cible.

⚠️ **Repasser à `non` après l'archivage.**

Le script a besoin de la bibliothèque `xcodeproj`, qu'installe CocoaPods :

```bash
GEM_HOME=/opt/homebrew/Cellar/cocoapods/1.17.0/libexec \
  /opt/homebrew/opt/ruby/bin/ruby montre_embarquee.rb non
```

## 3. Le Bundle Identifier

Celui de la montre est **exactement** celui de l'iPhone suivi de
`.watchkitapp` :

```
iPhone : fr.forbachenrose.ferIos
Montre : fr.forbachenrose.ferIos.watchkitapp
```

⚠️ Sans ce préfixe exact, les deux applications ne se reconnaissent pas :
`WatchConnectivity` ne transmet rien, et la montre reste bloquée sur « Ouvrez
l'application sur votre iPhone » sans qu'aucune erreur n'apparaisse.

⚠️ Si l'identifiant de l'iPhone change un jour, **trois endroits** doivent
suivre : le réglage `PRODUCT_BUNDLE_IDENTIFIER` des deux cibles, et la clé
`WKCompanionAppBundleIdentifier` de `mac/watchos/Info.plist`.

## 4. Le transfert du jeton — écrit

Ce morceau manquait ; il est maintenant en place, des deux côtés.

**Côté Swift** — `mac/ios/Runner/AppDelegate.swift` active `WCSession` et
expose le canal `fr.forbachenrose/montre`.

⚠️ Il est écrit pour l'architecture de **Flutter 3.44** (moteur implicite +
`SceneDelegate`), et non pour celle des exemples qu'on trouve en ligne. Dans
`didFinishLaunchingWithOptions`, il n'y a plus de `window.rootViewController` à
interroger : à cet instant, le moteur n'existe pas encore. Le messager s'obtient
depuis `didInitializeImplicitFlutterEngine`, qui est appelé quand il est prêt.

⚠️ `updateApplicationContext` et non `sendMessage` : le second exige que la
montre soit joignable à l'instant même. Le premier dépose la valeur, que watchOS
remet dès la prochaine occasion.

**Côté Dart** — `bibliotheque/fer_shared/lib/src/pont_montre.dart`, appelé
depuis `session.dart` à trois endroits :

| Moment | Ce qui part |
|---|---|
| `Session.ouvrir()` | le jeton, à **chaque** ouverture |
| `verifierCode()` | le jeton, juste après la connexion |
| `deconnexion()` | `null`, **avant** que le jeton ne soit effacé |

⚠️ **À chaque ouverture, et pas seulement à la connexion.** Une montre appairée
des mois après coup n'aurait jamais vu passer le jeton, et resterait sur
« Ouvrez l'application sur votre iPhone ».

⚠️ **La déconnexion pousse `null` AVANT `api.deconnexion()`.** Après, il n'y
aurait plus rien à retirer et la montre garderait le sien : elle continuerait
d'envoyer des passages de ligne sous une identité qu'on croit fermée.

⚠️ **On ne transmet que le jeton d'appareil**, jamais l'adresse email ni le
jeton d'accès. Le premier est un secret de longue durée que la montre range dans
son propre trousseau ; le second se rachète en un appel et n'a pas à circuler.

Le pont ne fait jamais échouer quoi que ce soit : sur Android, sans montre
appairée, ou sur simulateur, l'appel ne fait rien du tout.

## 5. Ce que fait la montre

**Une seule application, quatre pages qu'on fait glisser latéralement :**

```
[ QR ]  ‹—›  [ Course ]  ‹—›  [ Résultat ]  ‹—›  [ Messages ]
```

⚠️ **UNE SEULE APPLICATION, UNE SEULE ICÔNE.** Ce ne sont pas quatre
applications : c'est un `TabView` dans une unique cible Xcode, `FERWatch Watch
App`. Rien n'est installé à part.

⚠️ **Chaque page n'existe que si elle a quelque chose à dire.** Le QR
apparaît si `fer_qrEligibilite()` l'autorise pour cette édition, le résultat si
une course est terminée, les messages s'il y en a. Une page vide qui dirait
« rien ici » coûterait un glissement pour rien, plusieurs fois par jour. Sur un
compte neuf, il n'y a donc qu'une seule page — ce n'est pas une panne.

⚠️ **La course est TOUJOURS la page ouverte au lancement**, et cela a demandé
trois tentatives. Les pages arrivent au rythme des réponses de l'API ; tant que
le `TabView` était construit dès le premier affichage puis complété, il ouvrait
la **dernière page apparue** — le QR, puis le résultat, puis les messages —
alors que la sélection valait « Course ». Ni un `onChange` ni un `.id()` ne
rattrapent cela : la page affichée est déjà décidée quand ils s'exécutent.

La solution est `session.chargementTermine` : on n'ouvre le `TabView` qu'une
fois **tout** chargé, en une seule construction. Pendant le chargement, la page
de course s'affiche seule et reste pleinement utilisable — le jour J, « Je
pars » doit répondre au premier coup d'œil, pas après une roue qui tourne.

⚠️ **Pas de titre de page sur la liste des messages.** watchOS réserve déjà une
zone sûre en haut pour l'heure du système ; ce n'est pas une marge, et
`contentMargins` ne l'entame pas. Un titre par-dessus repoussait le premier
message d'un tiers d'écran sur un boîtier de 40 mm. Pour la même raison, la
liste est un `List` et non un `ScrollView` : ce dernier ignore la zone sûre, et
le premier titre passait sous l'heure.

**Course** — chrono et les deux boutons **« Je pars »** / **« J'arrive »**.
C'est le filet de sécurité du jour J : si la balise n'a rien vu et que le GPS a
dérivé, il reste ce geste. Tant que l'heure de départ n'est pas publiée, le
chrono cède la place à la date : sinon la page n'afficherait qu'un tiret
364 jours sur 365.

⚠️ **Pas de numéro d'inscription sur cette page.** Personne ne le récite en
marchant. Il figure sous le QR, là où il sert — quand la douchette ne veut rien
savoir et qu'il faut le lire à voix haute.

**Résultat** — chrono officiel, distance, dénivelé, allure et calories.

⚠️ **Les calories sont calculées sur la montre**, par un portage fidèle de
`bibliotheque/fer_shared/lib/src/course/mesures.dart` (voir `Mesures.swift`).
Elles ne peuvent pas venir de l'API : l'équation part du poids, et **le poids et
la taille ne sont jamais envoyés au serveur**. Le téléphone les transmet
d'appareil à appareil par `WatchConnectivity`, et ils ne quittent pas la paire.
Toute correction des équations doit être reportée **aux deux endroits** : un
écart afficherait deux chiffres différents pour la même course selon qu'on
regarde son poignet ou son téléphone.

**Messages** — les annonces de l'organisation, épinglées d'abord, **dix au
maximum**. Au-delà, on glisserait une minute sur un écran de montre pour
retrouver ce qu'on lit mieux sur le téléphone. Le tri précède la coupe : le
faire après garderait dix messages au hasard, et l'épinglé du jour J pourrait en
tomber.

⚠️ **Pas de suppression depuis la montre.** Masquer un message est irréversible
côté serveur une fois confirmé, et une confirmation ne tient pas sur 40 mm — un
doigt qui dérape en marchant effacerait l'heure du rendez-vous.

⚠️ Le type envoyé est `geofence`, **jamais** `manuel` — que le serveur réserve à
l'organisation et refuse en 403. `manuel` prime sur toutes les autres sources :
un coureur qui pourrait l'émettre dicterait son propre temps.

Pas d'inscriptions, pas de transferts, pas de réglages : tout cela se fait sur le
téléphone, où l'on voit ce qu'on tape.

## 6. Tester

Dans Xcode, choisir le schéma **FERWatch Watch App** et un simulateur de montre.
En ligne de commande :

```bash
cd mac/ios
xcodebuild -project Runner.xcodeproj -target "FERWatch Watch App" \
           -sdk watchsimulator -configuration Debug build
```

⚠️ Bien `-project` et non `-workspace` : le workspace entraîne les Pods de
l'iPhone, qui n'ont rien à voir avec la montre et échouent sur un SDK watchOS.

Un simulateur suffit pour l'interface, mais **pas** pour `WatchConnectivity` :
il faut une montre réelle appairée à un iPhone réel. Sur simulateur, la montre
restera sur l'écran « Ouvrez l'application sur votre iPhone » — c'est le
comportement attendu, pas une panne. Apple n'émule pas l'appairage.

### Voir l'écran de course sur simulateur

On contourne en posant un vrai jeton d'appareil dans le conteneur de
l'application. ⚠️ Un faux jeton ne sert à rien : le serveur répond
`device_revoked` et la montre revient aussitôt à l'écran d'attente.

```bash
# 1. Obtenir un jeton — le code à 6 chiffres arrive par email.
curl -s -X POST https://jr.zerobug-57.fr/FER/api/mobile/auth/request-code \
     -H 'Content-Type: application/json' -H 'X-App-Version: 1.0.0' \
     -d '{"email":"…"}'

curl -s -X POST https://jr.zerobug-57.fr/FER/api/mobile/auth/verify-code \
     -H 'Content-Type: application/json' -H 'X-App-Version: 1.0.0' \
     -d '{"email":"…","code":"123456","device_info":{"libelle":"Simulateur"}}'

# 2. L'écrire dans le conteneur DE L'APPLICATION.
MONTRE=<identifiant du simulateur>   # xcrun simctl list devices | grep Watch
BID=fr.forbachenrose.ferIos.watchkitapp
C=$(xcrun simctl get_app_container $MONTRE $BID data)
defaults write "$C/Library/Preferences/$BID" fer_device_token "<le jeton>"
xcrun simctl spawn $MONTRE killall -9 cfprefsd

# 3. Lancer et regarder.
xcrun simctl launch $MONTRE $BID
xcrun simctl io $MONTRE screenshot /tmp/montre.png
```

⚠️ **`get_app_container`, et non `simctl spawn defaults write`.** Cette
dernière écrit dans le domaine global du simulateur, pas dans le bac à sable de
l'application : la valeur y est visible mais l'application ne la lit jamais.

⚠️ **Révoquer ce jeton après les essais**, depuis « Mes appareils » sur le
téléphone ou le site. Il n'expire pas.
