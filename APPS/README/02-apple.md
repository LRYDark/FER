# Apple — iPhone et iPad

**Tout ce qui suit se fait sur le Mac.** Xcode n'existe pas sous Windows, et
aucun contournement ne produit un binaire iOS signé.

## 1. Emporter le code

Copiez **le dossier `APPS` entier**, pas seulement `mac/`.

```
APPS/
├── shared/     ← indispensable : mac/ en dépend par `path: ../shared`
├── rappels/    ← indispensable aussi
└── mac/
```

`mac/pubspec.yaml` déclare `fer_shared: path: ../shared`. Sans le dossier
parent, `flutter pub get` échoue avec « path does not exist » — et c'est le
premier écueil de ce transfert.

Clé USB, `scp`, dépôt git : peu importe, tant que l'arborescence reste entière.

## 2. Préparer le Mac

```bash
# Flutter, via Homebrew
brew install --cask flutter

# Xcode depuis l'App Store, puis :
sudo xcode-select --switch /Applications/Xcode.app/Contents/Developer
sudo xcodebuild -license accept

# CocoaPods, dont Flutter a besoin pour les greffons iOS
sudo gem install cocoapods

flutter doctor
```

`flutter doctor` doit être vert sur « Xcode » et « CocoaPods ».

## 3. Générer le dossier iOS

```bash
cd APPS/mac
flutter create --platforms=ios --org fr.forbachenrose --project-name fer_ios .
flutter pub get
```

`flutter create` **conserve** `pubspec.yaml` et `lib/`. S'il propose de les
écraser, arrêtez : vous n'êtes pas dans le bon dossier.

## 4. Fusionner l'Info.plist

Ouvrez `ios/Runner/Info.plist` et **ajoutez** les clés de
[`../mac/ios-overlay/Info-additions.plist`](../mac/ios-overlay/Info-additions.plist).

⚠️ **Ne remplacez pas le fichier** : vous perdriez le nom du bundle, les
orientations et l'écran de lancement générés par Flutter.

### Les textes d'autorisation sont lus par un humain chez Apple

`NSLocationWhenInUseUsageDescription` et les deux clés Bluetooth sont examinées à
chaque soumission. Une phrase vague — « cette app utilise votre position » — est
un **motif de rejet documenté**. Celles fournies disent ce qu'on fait et
pourquoi ; gardez-les telles quelles, ou gardez-en le niveau de précision.

### Ce qu'il ne faut pas ajouter

`NSLocationAlwaysAndWhenInUseUsageDescription` — la position « toujours »
permettrait de suivre quelqu'un application fermée. On ne la demande pas, et
Apple demanderait de justifier pourquoi.

`NSAllowsArbitraryLoads` — le serveur refuse le HTTP en 403 `https_required` :
l'autoriser côté application n'ouvre rien et affaiblit la protection du jeton
personnel de chaque coureur.

## 5. Signer

```bash
open ios/Runner.xcworkspace
```

⚠️ **`.xcworkspace`, pas `.xcodeproj`.** Avec le second, les greffons
(CocoaPods) ne sont pas liés et la compilation échoue sur des symboles
introuvables — un message qui ne dit jamais « vous avez ouvert le mauvais
fichier ».

Dans Xcode :

1. Sélectionnez la cible **Runner** → onglet **Signing & Capabilities**
2. Cochez **Automatically manage signing**
3. Choisissez votre **Team** (un compte Apple gratuit suffit pour tester sur
   votre propre appareil ; il faut le programme développeur à 99 €/an pour
   l'App Store)
4. Le **Bundle Identifier** doit être unique au monde :
   `fr.forbachenrose.coureur`

Puis, toujours dans **Signing & Capabilities**, ajoutez la capacité
**Background Modes** et cochez :

- **Location updates** — sans elle, iOS suspend l'application écran éteint et
  le suivi s'arrête au milieu de la marche, sans prévenir ;
- **Uses Bluetooth LE accessories** — pour l'écoute de la balise.

## 6. Lancer et compiler

```bash
flutter devices
flutter run                    # sur un iPhone branché
flutter build ios --release    # binaire à archiver
```

Pour l'App Store : dans Xcode, **Product → Archive**, puis **Distribute App**.

### Sur iPad

Rien de particulier : la même application. La disposition passe automatiquement
en rail latéral au-delà de 720 px de large, et **aucune fonction n'est retirée**
d'un format à l'autre.

## 7. Les erreurs qui reviennent

| Message | Cause |
|---|---|
| `path does not exist: ../shared` | Vous n'avez copié que `mac/` |
| `Undefined symbols … _FlutterLocalNotifications` | Ouvert `.xcodeproj` au lieu de `.xcworkspace` |
| `No profiles for 'fr.forbachenrose…' were found` | Bundle ID déjà pris, ou Team non sélectionnée |
| `CocoaPods not installed` | `sudo gem install cocoapods` |
| Le suivi s'arrête écran éteint | Background Modes → Location updates non coché |

## 8. Et la montre ?

L'Apple Watch est une **cible Xcode séparée**, en SwiftUI :
[03-apple-watch.md](03-apple-watch.md).
