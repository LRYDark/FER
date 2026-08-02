# Android — téléphone et tablette

## 1. Générer les dossiers de plateforme

Les dossiers `android/` et les fichiers Gradle **n'existent pas encore** : ce
sont des milliers de lignes générées, qui n'ont pas leur place dans un dépôt
écrit à la main. On les fabrique une fois :

```powershell
cd W:\FER\APPS\android
flutter create --platforms=android --org fr.forbachenrose --project-name fer_android .
```

Le `.` final compte : il dit « dans le dossier courant ».

⚠️ **`flutter create` ne touche pas à ce qui existe déjà** : `pubspec.yaml` et
`lib/` sont conservés. S'il vous demande d'écraser quoi que ce soit, **répondez
non** et vérifiez que vous êtes bien dans `APPS\android`.

Faites de même pour les deux autres paquets Dart, qui n'ont pas de plateforme
mais ont besoin de leurs dépendances :

```powershell
cd W:\FER\APPS\shared  ; flutter pub get
cd W:\FER\APPS\rappels ; flutter pub get
cd W:\FER\APPS\android ; flutter pub get
```

## 2. Fusionner le manifeste

`flutter create` a produit un `android/app/src/main/AndroidManifest.xml`
minimal. Il faut y **ajouter** ce que contient
[`../android/android-overlay/AndroidManifest.xml`](../android/android-overlay/AndroidManifest.xml)
— chaque permission y est expliquée.

**Ne remplacez pas le fichier** : vous perdriez l'activité principale, le thème
de démarrage et les requêtes générées par Flutter. Copiez seulement :

- les blocs `<uses-permission>` et `<uses-feature>`, **avant** `<application>` ;
- le `<receiver>` de redémarrage, **dans** `<application>` ;
- `android:launchMode="singleTop"` sur `<activity android:name=".MainActivity">`.

### Le point à ne pas rater

```xml
<uses-permission android:name="android.permission.SCHEDULE_EXACT_ALARM" />
```

Sans elle, Android regroupe les alarmes pour économiser la batterie et le rappel
peut arriver **après** le départ. Pour « départ dans 2 h », c'est un rappel qui
ne sert plus à rien.

Et à l'inverse, **n'ajoutez jamais** `ACCESS_BACKGROUND_LOCATION` : elle
permettrait de suivre quelqu'un application fermée, exige une justification
vidéo auprès de Google, et n'est pas nécessaire — le suivi tourne en service de
premier plan.

## 3. Régler le niveau minimal d'Android

Dans `android/app/build.gradle.kts` (ou `.gradle`) :

```kotlin
defaultConfig {
    minSdk = 26          // Android 8.0
    targetSdk = 34
}
```

`minSdk = 26` et pas moins : en dessous, `flutter_blue_plus` ne fonctionne pas,
et sans Bluetooth il n'y a plus qu'une source de chronométrage au lieu de deux.

## 4. Lancer

```powershell
cd W:\FER\APPS\android
flutter devices              # votre téléphone doit apparaître
flutter run
```

Le téléphone doit avoir le **débogage USB** activé (Paramètres → Options pour
développeurs).

Pas de téléphone sous la main ? Un émulateur suffit pour tout **sauf** le
Bluetooth et le GPS réel :

```powershell
flutter emulators --launch <nom>
```

## 5. Compiler pour publication

### Créer la clé de signature — une fois pour toutes

```powershell
keytool -genkey -v -keystore W:\FER\APPS\fer-release.jks `
        -keyalg RSA -keysize 2048 -validity 10000 -alias fer
```

⚠️ **Sauvegardez ce fichier et son mot de passe hors du dépôt et hors de
l'ordinateur.** Perdre cette clé signifie ne plus jamais pouvoir mettre à jour
l'application publiée : Google refuse une application resignée avec une autre
clé. Il faudrait la republier sous un nouveau nom, et chaque coureur devrait la
réinstaller à la main.

Puis `android/key.properties` — **à ajouter au `.gitignore`** :

```properties
storePassword=…
keyPassword=…
keyAlias=fer
storeFile=W:/FER/APPS/fer-release.jks
```

### Produire le paquet

```powershell
flutter build appbundle --release      # .aab, pour le Play Store
flutter build apk --release            # .apk, pour une installation directe
```

Le résultat est dans `build/app/outputs/`.

**`.aab` pour le Play Store, `.apk` pour tout le reste.** Le Play Store n'accepte
plus que les `.aab` ; à l'inverse, un `.aab` ne s'installe pas directement sur un
téléphone. Pour distribuer aux bénévoles sans passer par le magasin, c'est
l'`.apk` qu'il faut.

## 6. Avant chaque publication

- [ ] Relever la version dans `pubspec.yaml` (`1.0.0+1` → `1.0.1+2`), **et dans
      les deux autres coques**
- [ ] Vérifier `Réglages → API` : `app_version_minimale` ne doit **pas** être
      au-dessus de la version que vous publiez, sinon elle sera refusée dès
      l'installation
- [ ] Tester le rappel : régler l'heure de départ à ~10 min, fermer
      l'application, attendre
- [ ] Tester **sans réseau** : mode avion, déclarer un passage, réactiver le
      réseau, vérifier que la file part
