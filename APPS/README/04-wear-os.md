# Wear OS — la montre Android

Contrairement à watchOS, **Wear OS est de l'Android** : Flutter y compile
normalement. Cette coque réutilise donc tout le cœur — API, file d'attente,
suivi de course — et n'en change que l'affichage.

## 1. Générer et lancer

```powershell
cd W:\FER\APPS\android_watch
flutter create --platforms=android --org fr.forbachenrose --project-name fer_wear .
flutter pub get
```

Dans `android/app/src/main/AndroidManifest.xml`, ajoutez **dans**
`<application>` :

```xml
<meta-data android:name="com.google.android.wearable.standalone"
           android:value="true" />
```

`standalone = true` : l'application fonctionne **sans** le téléphone. C'est le
cas ici — la montre a son propre jeton et parle directement à l'API. Déclarer
`false` obligerait le Play Store à exiger l'installation du téléphone d'abord.

Ajoutez aussi, **avant** `<application>`, les mêmes permissions que la coque
téléphone (voir [`../android/android-overlay/AndroidManifest.xml`](../android/android-overlay/AndroidManifest.xml)) —
à l'exception des alarmes exactes : **le rappel est posé par le téléphone**, pas
par la montre. En poser un second ferait sonner deux fois pour la même chose.

Puis :

```powershell
flutter devices     # la montre doit apparaître
flutter run
```

## 2. Appairer la montre en débogage

**Montre physique** — Paramètres → Système → À propos → toucher 7 fois « Numéro
de build », puis Paramètres → Options pour développeurs → **Débogage ADB** et
**Débogage via Wi-Fi**. Notez l'adresse IP, puis :

```powershell
adb connect 192.168.1.42:5555
```

**Émulateur** — dans Android Studio, Device Manager → Create Device →
catégorie **Wear OS**. Suffisant pour l'interface, mais **pas** pour le GPS ni
le Bluetooth.

## 3. Ce que la montre affiche

Un seul écran : le chrono, le dossard, et les deux boutons de passage de ligne.
Pas de navigation, pas d'onglets, aucun réglage — ils sont sur le téléphone.

Trois décisions à ne pas défaire :

**Thème sombre imposé.** Les écrans OLED n'allument que les pixels non noirs :
un fond clair vide la batterie bien avant l'arrivée. Ce n'est pas une préférence
esthétique.

**Marge de 22 px sur les cadrans ronds.** `WatchShape` détecte la forme. Sans
cette marge, le premier et le dernier caractère de chaque ligne passent hors
écran — et on ne s'en aperçoit pas sur un émulateur carré.

**Chiffres à chasse fixe pour le chrono.** Sinon le compteur change de largeur à
chaque seconde et l'œil suit un texte qui bouge au lieu de lire l'heure.

## 4. Publier

Wear OS se publie dans la **même fiche Play Store** que l'application
téléphone : un seul dépôt, deux `.aab`.

```powershell
flutter build appbundle --release
```

⚠️ Le **Bundle ID doit être identique** à celui de la coque téléphone
(`fr.forbachenrose.fer_android`), avec un **code de version différent**. Deux
identifiants distincts créeraient deux applications séparées dans le magasin, et
les coureurs devraient chercher laquelle installer.

## 5. Limite connue

Le suivi GPS sur Wear OS **consomme beaucoup**. Sur une montre d'entrée de
gamme, comptez 2 à 3 heures d'autonomie en suivi actif — assez pour une marche
de 7 km, pas pour une journée entière. Prévenez-en les bénévoles avant le jour J
plutôt qu'après.
