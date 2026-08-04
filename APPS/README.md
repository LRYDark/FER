# APPS — les applications mobiles Forbach en Rose

**Deux applications, une bibliothèque.** Chaque application contient **le
téléphone ET la montre** ; le code commun ne vit qu'à **un seul endroit**.

```
APPS/
├── INSTALLATION.html  ← COMMENCEZ ICI : tout installer, de zéro, Mac et Windows
│                        (ouvrir dans un navigateur, Ctrl+P → Enregistrer en PDF)
├── README/            les guides détaillés, par sujet
│
├── bibliotheque/      ══ LE CODE COMMUN — un seul exemplaire ══
│   ├── fer_shared/      LE CŒUR : API, modèles, file d'attente, suivi de
│   │                    course, calories, TOUS les écrans
│   └── fer_rappels/     notifications : rappel avant la course, réception
│                        des push (Android et iOS, même code)
│
├── android/           ══ ANDROID : téléphone, tablette, Wear OS ══
│   ├── lib/
│   │   ├── main.dart          → téléphone et tablette
│   │   ├── main_montre.dart   → Wear OS
│   │   └── ecran_montre.dart
│   └── android-overlay/       permissions à fusionner dans le manifeste
│
└── mac/               ══ APPLE : iPhone, iPad, Apple Watch ══
    ├── lib/main.dart          → iPhone et iPad
    ├── ios-overlay/           clés à fusionner dans Info.plist
    ├── ios-liveactivity/      Live Activity et Dynamic Island (SwiftUI)
    └── watchos/               Apple Watch (SwiftUI, pas du Dart)
```

## ⚠️ Sur le Mac : DEUX dossiers

```
mac/  +  bibliotheque/
```

`mac/pubspec.yaml` déclare `path: ../bibliotheque/fer_shared`. Sans le dossier
voisin, `flutter pub get` échoue sur *« path does not exist »*. C'est le seul
piège du transfert, et il n'a qu'une règle : **la bibliothèque voyage avec.**

En contrepartie, **il n'y a rien à synchroniser** : une correction du client
d'API vaut immédiatement pour Android, iPhone et les deux montres. Une version
antérieure dupliquait ce code dans chaque application — deux copies divergent
toujours, et un correctif appliqué d'un seul côté ne se remarque qu'après une
course.

## Téléphone et montre dans le même dossier

**Apple le fait nativement** : l'Apple Watch est une **cible Xcode** dans le
projet de l'iPhone. Un dossier, un projet, deux applications.

**Android ne le fait pas** — mais un seul projet Flutter y arrive, avec **deux
points d'entrée** :

```bash
flutter run                                # téléphone
flutter run -t lib/main_montre.dart        # montre
```

⚠️ **Deux paquets distincts en revanche, pas un seul.** Une application Wear OS
autonome doit déclarer `android.hardware.type.watch` comme *requis*, ce qui
l'**exclut** des téléphones. Un unique `.aab` ne peut donc pas servir les deux —
d'où la variante `wear` décrite dans [README/04-wear-os.md](README/04-wear-os.md).

## Vérifier avant de compiler

```bash
cd APPS/bibliotheque/fer_shared && flutter analyze
cd ../../android                && flutter analyze
```

`flutter analyze` voit tout ce qu'un contrôle maison ne peut pas voir : les
types, les signatures, les variables inutilisées. Lancez-le **avant d'emporter
quoi que ce soit sur le Mac** — corriger une erreur de compilation est bien plus
rapide côté Android.

⚠️ **`docs/test-integrite.php` ne contrôle QUE le site.** `APPS/` n'est pas
déployé sur le serveur : y mêler les applications ferait échouer le banc en
production, pour des fichiers qui n'y sont pas.

⚠️ **`mac/` ne produit AUCUNE application macOS.** Le nom dit *où l'on compile*
— il faut un Mac et Xcode — pas ce qu'on obtient : iPhone, iPad, Apple Watch.

## Ce qu'il faut savoir avant d'ouvrir quoi que ce soit

**Flutter ne compile pas pour watchOS.** Ce n'est pas une limite de ce projet :
aucune version de Flutter ne l'a jamais fait. L'application Apple Watch est
écrite en SwiftUI, dans `mac/watchos/`, et s'ajoute comme cible dans Xcode. Wear
OS, en revanche, est de l'Android : c'est `android/lib/main_montre.dart`, du Dart.

**Le SDK Flutter n'est pas installé sur cette machine.** Seule l'extension VS
Code l'est ; elle propose de télécharger le SDK au premier « Flutter: New
Project ». Les dossiers de plateforme (`android/app`, `ios/Runner`) n'existent
donc pas encore : ils se génèrent avec `flutter create`, comme expliqué dans
[README/01-android.md](README/01-android.md).

**Un seul numéro de version pour les deux applications.** Le serveur compare une
unique `app_version_minimale` (Réglages → API). Si `android/pubspec.yaml` dit
`1.2.0` et `mac/pubspec.yaml` dit `1.1.0`, l'iPhone est refusé et l'Android
passe — sans que rien ne l'explique. **Relevez les deux ensemble.**

## Les guides

| Guide | Pour quoi |
|---|---|
| [README/00-avant-de-commencer.md](README/00-avant-de-commencer.md) | Installer Flutter, vérifier l'API, l'ordre des opérations |
| [README/01-android.md](README/01-android.md) | Téléphone et tablette : compiler, signer, publier |
| [README/02-apple.md](README/02-apple.md) | iPhone et iPad, sur votre Mac |
| [README/03-apple-watch.md](README/03-apple-watch.md) | La cible watchOS dans Xcode |
| [README/04-wear-os.md](README/04-wear-os.md) | La montre Android |
| [README/05-architecture.md](README/05-architecture.md) | Où se trouve quoi, et pourquoi |
| [README/06-notifications-push.md](README/06-notifications-push.md) | Firebase : faire sonner les téléphones |
| [README/07-chrono-vivant.md](README/07-chrono-vivant.md) | Dynamic Island, écran verrouillé, notification permanente |

## Ce que l'application fait

- **Connexion** par code à 6 chiffres reçu par mail — aucun mot de passe, aucun
  lien cliquable dans le mail.
- **Mes inscriptions** : toutes éditions confondues, avec le **QR code** du
  dossard (le même que le mail de confirmation).
- **Transfert d'inscription** à quelqu'un d'autre, avec double accord.
- **Ma course** : infos pratiques, chrono, **suivi GPS + balise Bluetooth**,
  distance, dénivelé, allure, temps au kilomètre, boutons de passage de ligne.
- **Chrono hors application** : Dynamic Island et écran verrouillé sur iPhone,
  notification permanente sur Android.
- **Calories estimées**, calculées sur l'appareil — le poids n'en sort jamais.
- **Mes résultats** : temps, méthode de mesure et précision — toujours ensemble.
- **Carte de partage** pour les réseaux, fabriquée sur le téléphone.
- **Messages de l'organisation** en boîte de réception, et **notifications
  poussées** qui font sonner le téléphone.
- **Rappel avant la course**, à l'heure réglée par l'organisation.

## Ce qu'elle ne fait pas, et pourquoi

**Elle ne calcule aucun temps officiel.** Elle envoie des observations
horodatées ; le serveur arbitre entre la détection du coureur, le top de départ
donné par l'organisation, et l'heure prévue. Une application qui enverrait
« j'ai fait 42 minutes » ferait une déclaration, pas une mesure — et le premier
classement contesté serait indéfendable.

**Elle ne se lance pas toute seule.** Ni Android ni iOS ne l'autorisent. Le
« réveil » est une notification que le coureur touche pour ouvrir l'application.

**Elle ne présente aucune estimation comme une mesure.** Les calories sont
annoncées « ~450 kcal (estimation ±20 %) », un temps GPS n'est jamais donné pour
un temps balise, et la méthode accompagne toujours le chrono — y compris sur la
carte partagée.

**Elle n'envoie jamais votre poids.** Il reste sur le téléphone, et le serveur
n'a aucun moyen de le demander. Le calcul se fait sur l'appareil.
