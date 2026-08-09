# Apple — iPhone et iPad

**Tout ce qui suit se fait sur le Mac.** Xcode n'existe pas sous Windows, et
aucun contournement ne produit un binaire iOS signé.

## 1. Emporter le code

⚠️ **DEUX DOSSIERS, PAS UN.**

```
mac/  +  bibliotheque/
```

```
mac/
├── lib/main.dart          iPhone et iPad
├── ios-overlay/           clés Info.plist
├── ios-liveactivity/      Live Activity (SwiftUI)
└── watchos/               Apple Watch (SwiftUI)

bibliotheque/
├── fer_shared/            le cœur — API, modèles, écrans
└── fer_rappels/           notifications
```

`mac/pubspec.yaml` déclare `path: ../bibliotheque/fer_shared`. Sans le dossier
voisin, `flutter pub get` échoue sur *« path does not exist »* — c'est le seul
piège de ce transfert.

Rangez-les côte à côte sur le Mac, comme ici :

```
Quelque part/
├── mac/
└── bibliotheque/
```

Clé USB, `scp`, dépôt git : peu importe, tant que les deux voyagent ensemble.

### ⚠️ OÙ les poser : ni le Bureau, ni un partage réseau

Rangez le projet dans un dossier **ordinaire et local** — `~/Developer/FER/` par
exemple. Deux endroits font échouer la compilation, chacun à sa manière, et
aucun des deux messages d'erreur ne dit ce qui se passe réellement.

**Le Bureau et Documents, si OneDrive est installé.** OneDrive les gère par
*FileProvider* : le chemin reste `~/Desktop`, le dossier reste un vrai dossier —
rien ne se voit. Mais tout répertoire en `.bundle` créé là reçoit l'attribut
`com.apple.FinderInfo` dans la seconde qui suit, et `codesign` le refuse :

```
Flutter.framework/Flutter: resource fork, Finder information,
                           or similar detritus not allowed
```

Purger l'attribut ne sert à rien : il est reposé pendant la compilation. Pour
vérifier si un dossier est concerné :

```bash
mkdir -p ~/Desktop/essai/x.bundle && sleep 2 && xattr -lr ~/Desktop/essai
# une ligne `com.apple.fileprovider.fpfs#P` = OneDrive gère ce dossier
rm -rf ~/Desktop/essai
```

**Un partage réseau (SMB, NFS).** Xcode et CocoaPods n'y travaillent pas de
façon fiable : liens symboliques, permissions, attributs étendus. C'est aussi
la raison pour laquelle `APPS/` se copie sur le Mac au lieu de se compiler
depuis le lecteur réseau.

Si le doute persiste, `ditto --noextattr --norsrc` recopie proprement :

```bash
ditto --noextattr --norsrc /Volumes/html/FER/APPS/mac ~/Developer/FER/mac
ditto --noextattr --norsrc /Volumes/html/FER/APPS/bibliotheque ~/Developer/FER/bibliotheque
```

## 2. Préparer le Mac

```bash
# Homebrew d'abord — il demande votre mot de passe une fois.
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Flutter. Le .zip depuis flutter.dev convient aussi ; voir l'encadré plus bas.
brew install --cask flutter

# Xcode depuis l'App Store, puis :
sudo xcode-select --switch /Applications/Xcode.app/Contents/Developer
sudo xcodebuild -license accept

# CocoaPods — PAR HOMEBREW, pas par `sudo gem install`.
brew install cocoapods

flutter doctor
```

`flutter doctor` doit être vert sur « Xcode » et « CocoaPods ». Les lignes
« Android toolchain » et « Chrome » peuvent rester rouges : elles ne concernent
pas la compilation iOS.

### ⚠️ Pourquoi `brew install cocoapods` et pas `sudo gem install cocoapods`

Le Ruby livré avec macOS est un **2.6**, et il le restera. Les dépendances
actuelles de CocoaPods exigent bien davantage — `zeitwerk` réclame Ruby ≥ 3.2,
`securerandom` ≥ 3.1 — et aucune combinaison de versions épinglées n'en sort :
descendre assez bas pour Ruby 2.6 donne un CocoaPods trop ancien pour Xcode 26.

La formule Homebrew embarque son propre Ruby. C'est la seule voie qui tient.

### Si vous installez Flutter par le `.zip` plutôt que par Homebrew

Purgez les attributs étendus après décompression, sinon la signature du
framework échoue avec le message « resource fork… » vu plus haut :

```bash
xattr -cr ~/Developer/flutter
```

(Les erreurs `Permission denied` sur `flutter/.git/objects/` sont sans
conséquence : ces fichiers sont en lecture seule et n'entrent pas dans le
binaire.)

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

## 5. Fusionner le Podfile

Ajoutez au bloc `post_install` de `ios/Podfile` ce que décrit
[`../mac/ios-overlay/Podfile-additions.rb`](../mac/ios-overlay/Podfile-additions.rb),
puis :

```bash
cd ios && pod install && cd ..
```

**Sans cette étape, la compilation échoue** sur `VerifyModule` pour
`flutter_local_notifications` et `device_info_plus` — le contrôle de modules
d'Xcode 15+ refuse leurs en-têtes, écrits à une époque plus permissive. Le
fichier d'overlay explique quoi ajouter, pourquoi, et quand le retirer.

## 6. Vérifier avant d'ouvrir Xcode

```bash
flutter build ios --simulator
```

Cette commande **ne demande aucune signature** : elle valide tout le reste —
Dart, greffons, pods, ressources — sans compte Apple. C'est le contrôle à passer
en premier ; `flutter build ios` échouera de toute façon sur l'absence d'équipe
de développement tant que l'étape suivante n'est pas faite.

⚠️ **`flutter analyze` dans `mac/` ne suffit pas.** Il n'analyse QUE le paquet
courant, jamais les dépendances `path` : des erreurs de compilation dans
`bibliotheque/` passent inaperçues. Analysez le cœur séparément :

```bash
cd APPS/bibliotheque/fer_shared && flutter analyze
cd ../fer_rappels              && flutter analyze
```

## 7. Signer

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
| `path does not exist: ../bibliotheque/fer_shared` | Vous n'avez copié que `mac/` — il faut `bibliotheque/` à côté |
| `Undefined symbols … _FlutterLocalNotifications` | Ouvert `.xcodeproj` au lieu de `.xcworkspace` |
| `No profiles for 'fr.forbachenrose…' were found` | Bundle ID déjà pris, ou Team non sélectionnée |
| `CocoaPods not installed` | `sudo gem install cocoapods` |
| Le suivi s'arrête écran éteint | Background Modes → Location updates non coché |

## 8. Et la montre ?

L'Apple Watch est une **cible Xcode séparée**, en SwiftUI :
[03-apple-watch.md](03-apple-watch.md).
