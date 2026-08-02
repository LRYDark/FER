# APPS — les applications mobiles Forbach en Rose

Six cibles, **un seul code métier**. Tout ce qui parle à l'API, tout ce qui
calcule, tout ce qui s'affiche sur téléphone et tablette vit dans `shared/`.
Les autres dossiers sont des coques.

```
APPS/
├── README/              ← les guides de compilation (commencez ici)
├── shared/              ← LE CŒUR : API, modèles, file d'attente, écrans
├── rappels/             ← notification de rappel avant la course (Android + iOS)
├── android/             ← coque Android : téléphone + tablette
├── android_watch/       ← coque Wear OS : montre Android
└── mac/                 ← coque Apple : iPhone + iPad
    ├── ios-overlay/     ← clés à fusionner dans Info.plist
    └── watchos/         ← Apple Watch, en SwiftUI (pas du Dart)
```

## Ce qu'il faut savoir avant d'ouvrir quoi que ce soit

**Flutter ne compile pas pour watchOS.** Ce n'est pas une limite de ce projet :
aucune version de Flutter ne l'a jamais fait. L'application Apple Watch est
écrite en SwiftUI, dans `mac/watchos/`, et s'ajoute comme cible dans Xcode. Wear
OS, en revanche, est de l'Android — `android_watch/` est bien du Dart.

**Le SDK Flutter n'est pas installé sur cette machine.** Seule l'extension VS
Code l'est ; elle propose de télécharger le SDK au premier « Flutter: New
Project ». Les dossiers de plateforme (`android/app`, `ios/Runner`) n'existent
donc pas encore : ils se génèrent avec `flutter create`, comme expliqué dans
[README/01-android.md](README/01-android.md).

**Un seul numéro de version pour les trois coques.** Le serveur compare une
unique `app_version_minimale` (Réglages → API). Si `android/pubspec.yaml` dit
`1.2.0` et `mac/pubspec.yaml` dit `1.1.0`, l'iPhone est refusé et l'Android
passe — sans que rien ne l'explique. **Relevez les trois ensemble.**

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
