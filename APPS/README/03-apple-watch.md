# Apple Watch

## Pourquoi ce n'est pas du Dart

**Flutter ne compile pas pour watchOS.** Aucune version ne l'a jamais fait, et
rien n'annonce le contraire. Une application Apple Watch s'écrit en SwiftUI,
dans une cible Xcode distincte de l'application iPhone.

C'est la seule des six cibles qui ne partage pas le code Dart. Elle parle
**directement** à `/api/v1`, avec le même contrat : mêmes routes, même en-tête
`X-App-Version`, même jeton personnel.

⚠️ **Toute évolution de l'API doit donc être répercutée à deux endroits** :
`shared/lib/src/api/api_client.dart` et `mac/watchos/SessionMontre.swift`.

## 1. Ajouter la cible

Dans Xcode, avec `ios/Runner.xcworkspace` ouvert :

1. **File → New → Target…**
2. **watchOS → App**
3. Nom : `FERWatch` — Interface : **SwiftUI** — Langage : **Swift**
4. Cochez **Embed in Companion Application** et choisissez **Runner**

Xcode crée deux dossiers : `FERWatch` et `FERWatch Watch App`.

## 2. Poser les sources

Remplacez le contenu généré par les trois fichiers de `mac/watchos/` :

| Fichier | Rôle |
|---|---|
| `FERWatchApp.swift` | point d'entrée, aiguillage connecté / non connecté |
| `SessionMontre.swift` | état, appels API, file d'attente hors réseau |
| `VueCourse.swift` | l'écran : chrono, dossard, deux boutons |

Glissez-les dans le dossier `FERWatch Watch App` en cochant **Copy items if
needed** et en cochant la cible `FERWatch Watch App`.

Supprimez `ContentView.swift` généré par Xcode.

## 3. Le Bundle Identifier

Il doit être **exactement** celui de l'iPhone suivi de `.watchkitapp` :

```
iPhone : fr.forbachenrose.coureur
Montre : fr.forbachenrose.coureur.watchkitapp
```

⚠️ Sans ce préfixe exact, les deux applications ne se reconnaissent pas :
`WatchConnectivity` ne transmet rien, et la montre reste bloquée sur « Ouvrez
l'application sur votre iPhone » sans qu'aucune erreur n'apparaisse.

## 4. Ce qu'il reste à faire côté iPhone

La montre ne demande jamais l'adresse email — un clavier sur 40 mm serait
inutilisable. Elle reçoit le **jeton d'appareil** du téléphone.

Ce transfert est le **seul morceau non écrit** de cet ensemble : il demande un
canal de plateforme entre Dart et Swift, qui ne peut pas être testé sans un
Mac. Voici précisément ce qu'il faut ajouter.

Côté Swift, dans `ios/Runner/AppDelegate.swift` :

```swift
import WatchConnectivity

// Dans application(_:didFinishLaunchingWithOptions:)
if WCSession.isSupported() { WCSession.default.activate() }

let canal = FlutterMethodChannel(
    name: "fr.forbachenrose/montre",
    binaryMessenger: controller.binaryMessenger)

canal.setMethodCallHandler { appel, resultat in
    guard appel.method == "envoyerJeton",
          let jeton = appel.arguments as? String else {
        resultat(FlutterMethodNotImplemented); return
    }
    // `updateApplicationContext` et non `sendMessage` : il ne demande pas que
    // la montre soit joignable à l'instant. Le jeton l'attendra.
    try? WCSession.default.updateApplicationContext(["device_token": jeton])
    resultat(nil)
}
```

Côté Dart, après une connexion réussie :

```dart
const canal = MethodChannel('fr.forbachenrose/montre');
final jeton = await session.api.jetons.appareil();
if (jeton != null) await canal.invokeMethod('envoyerJeton', jeton);
```

⚠️ **Ne transmettez que le jeton d'appareil**, jamais l'adresse email ni le
jeton d'accès. Le premier est un secret de longue durée que la montre range dans
son propre trousseau ; le second se rachète en un appel et n'a pas à circuler.

## 5. Ce que fait la montre

Chrono, dossard, et surtout les deux boutons **« Je pars »** / **« J'arrive »**.
C'est le filet de sécurité du jour J : si la balise n'a rien vu et que le GPS a
dérivé, il reste ce geste.

⚠️ Le type envoyé est `geofence`, **jamais** `manuel` — que le serveur réserve à
l'organisation et refuse en 403. `manuel` prime sur toutes les autres sources :
un coureur qui pourrait l'émettre dicterait son propre temps.

Pas d'inscriptions, pas de transferts, pas de réglages : tout cela se fait sur le
téléphone, où l'on voit ce qu'on tape.

## 6. Tester

Un simulateur suffit pour l'interface, mais **pas** pour `WatchConnectivity` :
il faut une montre réelle appairée à un iPhone réel. Sur simulateur, la montre
restera sur l'écran « Ouvrez l'application sur votre iPhone ».
