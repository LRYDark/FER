# Faire sonner les téléphones (Firebase)

## Pourquoi Firebase, et rien d'autre

Android et iOS n'acceptent **aucun** autre moyen de réveiller une application
fermée. Ni un serveur qu'on appelle, ni une connexion maintenue ouverte : le
système coupe tout au bout de quelques minutes en arrière-plan.

Firebase Cloud Messaging est la seule voie, et il relaie lui-même vers APNs pour
les iPhone — **une seule intégration pour les deux plateformes**.

⚠️ **Ce que ça implique** : chaque installation de l'application est déclarée
auprès de Google. C'est le prix de la sonnerie ; il n'existe pas de version
« sans tiers » de cette fonction.

## 1. Créer le projet Firebase

1. [console.firebase.google.com](https://console.firebase.google.com) → **Ajouter un projet**
2. Nom : `forbach-en-rose`. Google Analytics : **inutile**, décochez
3. Aucune facturation à activer : l'envoi de notifications est gratuit, sans plafond réaliste pour une course annuelle

## 2. Android

Dans la console : **Ajouter une application** → Android

- Nom du package : **exactement** celui de `android/app/build.gradle.kts`
  (`fr.forbachenrose.fer_android`)
- Téléchargez **`google-services.json`**
- Placez-le dans `APPS/android/android/app/google-services.json`

Puis, dans `android/build.gradle.kts` :

```kotlin
plugins {
    id("com.google.gms.google-services") version "4.4.2" apply false
}
```

Et dans `android/app/build.gradle.kts` :

```kotlin
plugins {
    id("com.google.gms.google-services")
}
```

⚠️ **Sans ces deux blocs, `Firebase.initializeApp()` échoue au démarrage** —
l'application se lance quand même (c'est prévu), mais aucune notification
n'arrivera jamais, et rien à l'écran ne le dira.

## 3. iPhone

Vous avez le compte Apple Developer, donc tout est possible.

**Dans la console Firebase** : **Ajouter une application** → iOS

- Bundle ID : `fr.forbachenrose.coureur`
- Téléchargez **`GoogleService-Info.plist`**
- Ajoutez-le à la cible **Runner** dans Xcode *(glisser-déposer, « Copy items if needed »)*

**Dans le portail Apple Developer** : Certificates → Keys → **+**

- Cochez **Apple Push Notifications service (APNs)**
- Téléchargez le fichier **`.p8`** — ⚠️ **il ne se retélécharge jamais**, gardez-le
- Notez le **Key ID** et votre **Team ID**

**De retour dans Firebase** : Paramètres du projet → Cloud Messaging → section
Apple → **Importer la clé APNs** *(le `.p8`, le Key ID, le Team ID)*

**Dans Xcode**, cible Runner → Signing & Capabilities → **+ Capability** →
**Push Notifications**.

⚠️ **Le push iOS ne fonctionne JAMAIS sur simulateur.** Il faut un iPhone réel,
avec un profil de provisionnement valide.

## 4. Le compte de service, côté serveur

C'est ce qui permet au site d'envoyer.

Console Firebase → **Paramètres du projet** → **Comptes de service** →
**Générer une nouvelle clé privée**. Un fichier JSON se télécharge.

Dans l'administration du site : **Applications** → *Notifications sur les
téléphones* → collez le contenu du fichier → **Enregistrer la clé**.

⚠️ **C'est une clé privée.** Quiconque la lit peut envoyer des notifications au
nom de l'association. Le site la stocke **chiffrée**, ne la réaffiche jamais, et
ne la journalise pas. Ne la mettez pas dans le dépôt git.

Le badge passe à **configuré** et le nombre d'appareils joignables s'affiche.

## 5. Vérifier

1. Installez l'application sur un téléphone **réel**, connectez-vous
2. Administration → **Applications** : le compteur doit passer à ≥ 1
3. Créez un message, puis cliquez sur **✈** dans la liste
4. Le téléphone sonne, même application fermée

Si le compteur reste à 0 :

| Cause | Vérification |
|---|---|
| `google-services.json` absent ou mal placé | `android/app/` — et les deux blocs Gradle |
| Notifications refusées sur l'appareil | Réglages du téléphone |
| Le jeton n'est pas remonté | Journaux système → `logs_push.log` |

## 6. Ce que le site fait avec

- **Envoi par appareil** : FCM v1 n'a pas d'envoi groupé, le point d'entrée
  multicast ayant été retiré. Le site boucle, et compte les échecs.
- **Nettoyage automatique** : un jeton refusé (`UNREGISTERED`, application
  désinstallée) est effacé. Sans ça, la liste enflerait d'année en année et le
  compteur « envoyée à N appareils » mentirait de plus en plus.
- **Aucune dépendance nouvelle** : `vendor/google/auth` était déjà là pour Gmail.

## 7. Le bouton de départ envoie tout seul

Quand l'organisation appuie sur **DONNER LE DÉPART** *(tableau de bord)*, une
notification part automatiquement sur tous les téléphones. C'est annoncé dans la
confirmation — faire sonner mille téléphones sans le dire serait une mauvaise
surprise.
