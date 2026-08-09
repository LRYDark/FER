# Avant de commencer

## 1. Installer Flutter

Il n'est pas installé sur cette machine — seule l'extension VS Code l'est.

**Le plus simple**, depuis VS Code :

1. `Ctrl+Maj+P` → **Flutter: New Project**
2. VS Code répond « Flutter SDK not found » et propose **Download SDK**
3. Choisissez un dossier **sans espace ni accent** dans le chemin —
   `C:\flutter` va très bien. Un chemin comme
   `C:\Users\joris.reinert\Mes documents\flutter` casse la compilation Android,
   avec des messages qui ne parlent pas du chemin.

Puis, dans un terminal :

```powershell
flutter doctor
```

Tout doit être vert **sauf** « Xcode » (normal sous Windows) et éventuellement
« Chrome ». Si « Android toolchain » est rouge :

```powershell
flutter doctor --android-licenses
```

Android Studio est déjà présent sur cette machine
(`C:\Users\joris.reinert\AndroidStudioProjects` existe), le SDK Android devrait
donc être trouvé tout seul.

## 2. Vérifier que l'API répond

Inutile de compiler quoi que ce soit tant que le serveur n'est pas prêt.

```powershell
curl https://jr.zerobug-57.fr/FER/api/mobile/app/config
```

Réponse attendue :

```json
{"ok":true,"data":{"version_minimale":"1.0.0","chrono_actif":false, …},"error":null}
```

| Réponse | Ce qu'il faut faire |
|---|---|
| `503 api_disabled` | Activer l'API mobile dans **Réglages → API** |
| `503 not_installed` | Lancer `update.php` : la base n'est pas à jour |
| `403 https_required` | Vous avez appelé en `http://` — l'API exige HTTPS |
| Rien / erreur DNS | Vérifier l'adresse du serveur |

⚠️ **`chrono_actif: false` est normal** hors période de course. Les écrans de
course sont alors masqués dans l'application — c'est voulu.

### ⚠️ Le second test, celui qu'on oublie : l'en-tête `Authorization`

`/app/config` est **public**. Qu'il réponde ne prouve donc rien sur les routes
authentifiées, qui sont l'essentiel de l'application. Faites ce second appel,
avec un jeton volontairement faux :

```bash
curl -H "X-App-Version: 1.0.0" -H "Authorization: Bearer FAUX" \
     https://jr.zerobug-57.fr/FER/api/mobile/me
```

| Réponse | Ce que ça veut dire |
|---|---|
| `invalid_token` — « Jeton d'accès invalide ou expiré » | ✅ **Correct.** PHP a bien reçu l'en-tête, il a simplement rejeté ce jeton |
| `missing_token` — « Jeton d'accès absent » | ❌ **Apache retire l'en-tête `Authorization`** avant PHP |

**Le second cas produit la panne la plus trompeuse du projet.** La connexion
réussit (`/auth/verify-code` porte tout dans son corps JSON), les infos de la
course s'affichent (routes publiques) — l'application paraît parfaitement
connectée. Mais profil, inscriptions, appareils et résultats reviennent tous
vides, sans le moindre message. Et le site web, lui, fonctionne : il
s'authentifie par cookie de session, jamais par en-tête. On cherche alors le
défaut dans l'application ou dans les données, alors qu'il est dans Apache.

Le correctif tient en deux lignes dans `api/mobile/.htaccess`, avant la règle
de routage (il y est, commenté) :

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

C'est à refaire après toute réinstallation ou tout déplacement du site : un
`.htaccess` écrasé emporte la règle avec lui.

## 3. Renseigner les informations de course

Sans elles, l'application n'a rien à afficher et le chronométrage ne peut rien
calculer. Dans l'administration : **Réglages → Course**.

Le bandeau en haut de l'onglet dit exactement ce qui manque. Il faut au minimum :

- la **date** de la course ;
- l'**heure de départ** (saisie en heure locale, stockée en UTC — ne corrigez
  jamais « l'écart de deux heures » en décalant la saisie) ;
- les **coordonnées** des lignes de départ **et** d'arrivée.

Vérification :

```powershell
curl https://jr.zerobug-57.fr/FER/api/mobile/course
```

`"chrono_pret": true` signifie que tout y est.

## 4. L'ordre des opérations

```
1. flutter doctor              → vert
2. API qui répond              → /app/config
3. Réglages → Course rempli    → chrono_pret: true
4. README/01-android.md        → première compilation
5. README/02-apple.md          → sur le Mac
```

Commencez par **Android** même si votre cible est l'iPhone : la boucle
« modifier / relancer » y est plus rapide, et tout le code métier est commun. Ce
qui marche sur Android marche sur iOS, à l'habillage près.

## 5. Où changer l'adresse du serveur

Trois endroits, et ils doivent rester d'accord :

| Fichier | Rôle |
|---|---|
| `android/lib/main.dart` | repli au premier lancement |
| `mac/lib/main.dart` | idem |
| `mac/watchos/SessionMontre.swift` | la montre appelle l'API directement |

⚠️ Ce n'est qu'un **repli**. Une fois l'application lancée, c'est l'adresse
enregistrée sur l'appareil qui prime — c'est ce qui permet de changer de domaine
sans republier sur les magasins.
