# Faire sonner les téléphones (Firebase)

## Pourquoi Firebase — et ce qui est vraiment obligatoire

Aucune application ne peut se réveiller seule : ni un serveur qu'on appelle, ni
une connexion maintenue ouverte ne survivent à quelques minutes d'arrière-plan.
Il faut passer par le service de notification du système.

**Mais ce service n'est pas le même des deux côtés :**

| | Service obligatoire | Firebase est-il nécessaire ? |
|---|---|---|
| **Android** | FCM *(Google)* | **Oui, en pratique.** Google a supprimé l'ancien GCM, et les solutions tierces *(OneSignal, Leanplum…)* repassent toutes par FCM |
| **iPhone** | **APNs** *(Apple)* | **Non.** Firebase ne fait que **relayer** vers APNs |

⚠️ **Une version antérieure de ce guide affirmait que Firebase était « la seule
voie » pour les deux plateformes. C'est faux pour l'iPhone.**

On peut s'adresser **directement** à APNs, en HTTP/2, avec un jeton JWT signé
par la clé `.p8` — celle qu'il faut de toute façon. La bibliothèque
`firebase/php-jwt`, déjà présente dans `vendor/`, signe en ES256, et des clients
PHP dédiés existent *(voir [edamov/pushok](https://github.com/edamov/pushok))*.

### Pourquoi on ne l'a pas fait

**Un seul chemin d'envoi plutôt que deux.** Le coût d'un second chemin n'est pas
le code — c'est la dépendance à un **curl compilé avec HTTP/2** sur
l'hébergement mutualisé. S'il manque, les iPhone cessent de recevoir **sans que
rien ne le signale**.

Pour savoir si c'est jouable chez vous, déposez ce fichier à la racine et
ouvrez-le :

```php
<?php $v = curl_version();
echo (CURL_VERSION_HTTP2 & $v['features']) ? 'HTTP/2 : OUI' : 'HTTP/2 : NON';
```

**OUI** → l'option « APNs direct pour iOS » devient réaliste : aucun iPhone ne
serait alors déclaré chez Google. Il faut en contrepartie un second chemin
d'envoi côté serveur et une vingtaine de lignes de Swift pour récupérer le jeton
APNs.

⚠️ **Ce que le choix actuel implique, et qu'il faut assumer** : chaque
installation — iPhone compris — est déclarée auprès de Google. C'est le prix du
chemin unique, **pas une fatalité technique**.

### Une chose ne change pas

Dans les deux cas, il vous faut la **clé APNs `.p8`** de votre compte Apple
Developer. Avec Firebase vous la lui confiez ; en direct vous la gardez. Passer
en direct ne vous demanderait **rien de plus** — cela retirerait seulement le
projet Firebase du chemin iPhone.

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
