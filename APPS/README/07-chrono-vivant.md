# Le chrono qui vit hors de l'application

Écran verrouillé, Dynamic Island, notification permanente : le compteur continue
là où le coureur le regarde vraiment — sans ouvrir l'application.

## L'astuce qui rend tout ça possible

⚠️ **On n'envoie pas le temps chaque seconde.** La tentation est de rafraîchir
l'affichage depuis Dart : c'est ce qui vide une batterie en deux heures, et iOS
limite de toute façon le nombre de mises à jour d'une Live Activity.

On donne au système **l'instant de départ**, et c'est lui qui anime le compteur,
nativement, sans réveiller l'application :

| Plateforme | Mécanisme |
|---|---|
| Android | `usesChronometer: true` + `when` = instant de départ |
| iOS | `Text(timerInterval:)` dans la Live Activity |

Conséquence : **le chrono reste juste même application tuée par le système**, ou
téléphone en veille depuis une heure.

---

## Android — rien à faire

C'est déjà branché. `ChronoVivant` publie une notification permanente à
chronomètre au démarrage du suivi, et la retire à l'arrêt.

⚠️ La notification n'est **pas** décorative : sans notification de premier plan,
Android suspend l'application dès l'écran éteint et le suivi GPS s'arrête au
milieu de la course, sans prévenir. Elle est aussi le contrat vis-à-vis du
coureur — on suit sa position, et ça se voit.

---

## iPhone — une cible Xcode à ajouter

Flutter ne sait pas produire de Live Activity : ActivityKit est une API Swift,
utilisable seulement depuis une **Widget Extension**.

### 1. Créer la cible

Xcode, avec `ios/Runner.xcworkspace` ouvert :

1. **File → New → Target…** → **Widget Extension**
2. Nom : `FERLiveActivity`
3. **Cochez « Include Live Activity »**, décochez « Include Configuration Intent »
4. Activate le schéma quand Xcode le propose

### 2. Poser les sources

Depuis `mac/ios-liveactivity/` :

| Fichier | Cible Xcode |
|---|---|
| `FERActivityAttributes.swift` | **Runner ET FERLiveActivity** — les deux |
| `FERLiveActivity.swift` | FERLiveActivity |
| `PontLiveActivity.swift` | **Runner** uniquement |

⚠️ **`FERActivityAttributes.swift` doit appartenir aux DEUX cibles.**
L'application le lit pour démarrer l'activité, l'extension pour la dessiner. Une
seule cible cochée donne une erreur de compilation obscure du côté oublié, qui ne
mentionne jamais l'appartenance de cible.

⚠️ **`PontLiveActivity.swift` va dans Runner, pas dans l'extension.** Placé dans
l'extension, il compile parfaitement et Dart n'atteint jamais le canal.

### 3. Autoriser les Live Activities

Dans `ios/Runner/Info.plist` :

```xml
<key>NSSupportsLiveActivities</key>
<true/>
```

Sans cette clé, `Activity.request` échoue silencieusement.

### 4. Brancher le canal

Dans `ios/Runner/AppDelegate.swift`, avant `GeneratedPluginRegistrant` :

```swift
let controller = window?.rootViewController as! FlutterViewController
if #available(iOS 16.1, *) {
    PontLiveActivity.enregistrer(messager: controller.binaryMessenger)
}
```

### 5. Essayer

- **iPhone 14 Pro et plus** : Dynamic Island + écran verrouillé
- **Autres iPhone (iOS 16.1+)** : écran verrouillé seulement — **même code**
- **iOS < 16.1** : rien ne s'affiche, le suivi fonctionne normalement

⚠️ **Le simulateur affiche la Live Activity mais pas la Dynamic Island.** Pour
celle-ci, il faut un appareil réel.

### Si ça ne s'affiche pas

| Symptôme | Cause |
|---|---|
| `PlatformException(desactive)` | Live Activities désactivées : Réglages → l'app → Activités en direct |
| Aucune réaction, aucune erreur | `NSSupportsLiveActivities` absent de l'Info.plist |
| `FlutterMethodNotImplemented` | `PontLiveActivity.enregistrer` non appelé dans AppDelegate |
| Erreur de compilation sur `FERActivityAttributes` | Le fichier n'est pas dans les deux cibles |

---

## Apple Watch

Rien de plus à écrire : la Live Activity de l'iPhone s'affiche **automatiquement**
sur la montre appairée, dans la pile intelligente.

## Wear OS

La montre Android affiche déjà son propre chrono en plein écran. L'**Ongoing
Activity** *(androidx.wear)*, qui l'afficherait aussi sur le cadran, demande un
bout de Kotlin dans la coque — c'est le seul morceau de cet ensemble qui reste à
écrire, et il n'apporte rien tant qu'on regarde l'application.

---

## Ce qu'il faut savoir avant de promettre quoi que ce soit

**La Live Activity iOS tient 8 heures**, puis reste visible sur l'écran
verrouillé jusqu'à 12 h au total. Largement assez pour une marche de 7 km.

**La distance n'est poussée que de loin en loin** — une fois par minute au plus.
iOS limite les mises à jour et les dépasser fige l'activité jusqu'à la fin. Le
**chrono**, lui, n'a jamais besoin d'être mis à jour.
