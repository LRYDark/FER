<?php
/**
 * api-doc-mobile.php — Documentation de l'API des coureurs (/api/mobile).
 *
 * PAGE SÉPARÉE DE api-doc.php, ET C'EST VOLONTAIRE. Les deux APIs n'ont ni le
 * même public, ni le même mécanisme d'authentification :
 *   • api/v1     → UN jeton pour toute l'association, créé par un administrateur,
 *                  qui donne accès à TOUS les inscrits. Versionné, parce qu'un
 *                  partenaire ne met pas son logiciel à jour sur commande.
 *   • api/mobile → UN jeton PAR COUREUR, qu'il obtient lui-même avec son adresse
 *                  email et un code à 6 chiffres, et qui ne donne accès qu'à ses
 *                  propres données. Pas de version dans le chemin : c'est
 *                  `app_version_minimale` qui écarte les applications trop
 *                  anciennes.
 * Les documenter ensemble amenait à confondre les deux mécanismes — et un
 * développeur qui confond deux modèles d'authentification écrit une faille.
 *
 * Page d'administration, réservée aux administrateurs (comme api-doc.php).
 */
require '../src/core/config.php';

if (!isset($_SESSION['uid']) || currentRole() !== 'admin') {
    http_response_code(403);
    header('Location: ../login.php');
    exit;
}
$role = currentRole();
require __DIR__ . '/../src/partials/navbar-data.php';
require_once __DIR__ . '/../src/content/chrono.php';   // chrono_actif()

/* ── URL absolue de l'API mobile ──────────────────────────────────────────
 * getAppBaseUrl() ne rend que le schéma et le domaine : si le site vit dans un
 * sous-répertoire, il faut le retrouver, sinon toutes les URL de la page sont
 * fausses là où on en a le plus besoin — dans les exemples à recopier. */
$baseUrl     = getAppBaseUrl();
$projectRoot = realpath(__DIR__ . '/..');
$docRoot     = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($projectRoot === $docRoot || $projectRoot === false || $docRoot === false) {
    $baseDir = '';
} else {
    $baseDir = str_replace('\\', '/', substr($projectRoot, strlen($docRoot)));
}
$apiUrl = $baseUrl . $baseDir . '/api/mobile';

try {
    $cfg = $pdo->query('SELECT api_v1_enabled, app_version_minimale, app_access_token_ttl_min,
                               participant_code_ttl_min, participant_code_max_tentatives,
                               participant_code_max_par_email_15min, participant_code_max_par_ip_heure
                          FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $cfg = [];
}
$v1Enabled  = !empty($cfg['api_v1_enabled']);
$vMin       = $cfg['app_version_minimale']        ?? '1.0.0';
$ttlAcces   = (int) ($cfg['app_access_token_ttl_min'] ?? 60);
$ttlCode    = (int) ($cfg['participant_code_ttl_min'] ?? 15);
$maxEssais  = (int) ($cfg['participant_code_max_tentatives'] ?? 5);
$maxMail15  = (int) ($cfg['participant_code_max_par_email_15min'] ?? 3);
$maxIpHeure = (int) ($cfg['participant_code_max_par_ip_heure'] ?? 10);

/** Affiche un bloc de code échappé. */
function mCode(string $code): void
{
    echo '<pre class="api-code"><code>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></pre>';
}
/** Affiche un schéma de flux (même échappement, autre habillage). */
function mFlux(string $schema): void
{
    echo '<div class="api-flow">' . htmlspecialchars($schema, ENT_QUOTES, 'UTF-8') . '</div>';
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$A = $h($apiUrl);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Documentation de l'API mobile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<?php include __DIR__ . '/../src/partials/api-doc-styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<div class="container-fluid pb-5 api-doc">

  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
    <h1 class="fw-bold mb-0"><i class="bi bi-phone me-2"></i>API mobile — application des coureurs</h1>
    <div class="d-flex gap-2 flex-wrap">
      <a href="api-doc.php" class="btn btn-outline-secondary">
        <i class="bi bi-plug me-1"></i>API partenaire
      </a>
      <a href="setting.php?tab=api" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Retour aux réglages
      </a>
    </div>
  </div>
  <p class="text-muted">
    Comment connecter une application mobile à la base de données de Forbach en Rose,
    sans jamais lui confier les identifiants de cette base.
  </p>

  <?php if (!$v1Enabled): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>
    L'API mobile est actuellement <strong>désactivée</strong> : elle répond
    <code>503 api_disabled</code> à toutes les requêtes. Activez-la dans
    <a href="setting.php?tab=api">Réglages → onglet API</a>.
    <br><small>C'est l'état normal après une mise à jour : un service ne s'ouvre que
    lorsque quelqu'un décide de l'ouvrir.</small>
  </div>
  <?php endif; ?>

  <!-- ═══ Présentation ═══ -->
  <div class="api-card">
    <h2 class="mt-0"><i class="bi bi-info-circle me-2"></i>En résumé</h2>
    <p>Adresse de base : <span class="url-pill"><?= $A ?></span></p>

    <p><strong>Une application sur un téléphone ne peut pas parler directement à la base de
       données.</strong> Il faudrait lui confier les identifiants MySQL du site — donc les livrer
       à tous les coureurs, puisqu'ils sont dans l'application installée sur leur appareil.
       Cette API est l'intermédiaire : le téléphone lui parle en HTTPS, et <em>elle seule</em>
       touche la base.</p>

    <div class="alert alert-info"><i class="bi bi-signpost-split me-2"></i>
      <strong>Ce n'est pas l'API décrite dans l'autre page.</strong>
      <code>api/v1</code> parle au nom de l'<em>association</em> : un seul jeton, créé par un
      administrateur, qui voit tous les inscrits. Ici, chaque requête parle au nom d'<strong>un
      coureur</strong> et n'accède qu'à ses propres données. Les deux ne partagent aucun
      identifiant et ne se remplacent pas.
    </div>

    <p>Toutes les réponses ont la même forme, succès comme erreur — l'application peut donc
       traiter les deux cas avec le même code :</p>
    <?php mCode(
      "{ \"ok\": true,  \"data\": { … }, \"error\": null }\n"
    . "{ \"ok\": false, \"data\": null,  \"error\": { \"code\": \"invalid_code\", \"message\": \"Code incorrect.\" } }"
    ); ?>
  </div>

  <!-- ═══ Sommaire ═══ -->
  <div class="api-card api-toc">
    <h2 class="mt-0"><i class="bi bi-list-ul me-2"></i>Sommaire</h2>
    <ol class="mb-0">
      <li><a href="#principe">Le principe en un schéma</a></li>
      <li><a href="#barrieres">Les trois barrières d'entrée</a></li>
      <li><a href="#jetons">Les deux jetons, et pourquoi il y en a deux</a></li>
      <li><a href="#connexion">Se connecter — pas à pas</a></li>
      <li><a href="#stockage">Où l'application range le jeton</a></li>
      <li><a href="#endpoints">Liste des points d'entrée</a></li>
      <li><a href="#modif">Ce que le coureur peut modifier lui-même</a></li>
      <li><a href="#erreurs">Codes d'erreur</a></li>
      <li><a href="#course">Envoyer les données de course — balise et GPS</a></li>
      <li><a href="#regles">Règles de sécurité, et pourquoi elles sont là</a></li>
    </ol>
  </div>

  <!-- ═══ 1. Principe ═══ -->
  <div class="api-card" id="principe">
    <h2 class="mt-0">1. Le principe en un schéma</h2>
    <?php mFlux(
      "COUREUR                TÉLÉPHONE                        VOTRE SERVEUR              BASE\n"
    . "   │                       │                                  │                      │\n"
    . "   │  son adresse email    │                                  │                      │\n"
    . "   ├──────────────────────>│── POST /auth/request-code ──────>│                      │\n"
    . "   │                       │                                  │─ code à 6 chiffres ─>│\n"
    . "   │<══ mail avec le code ═╪══════════════════════════════════╡                      │\n"
    . "   │                       │                                  │                      │\n"
    . "   │  il saisit le code    │                                  │                      │\n"
    . "   ├──────────────────────>│── POST /auth/verify-code ───────>│  vérifie le code     │\n"
    . "   │                       │<── device_token + access_token ──│                      │\n"
    . "   │                       │                                  │                      │\n"
    . "   │                       │   ┌──────────────────────────┐   │                      │\n"
    . "   │                       │   │ device_token → trousseau │   │                      │\n"
    . "   │                       │   └──────────────────────────┘   │                      │\n"
    . "   │                       │                                  │                      │\n"
    . "   │  il ouvre l'app       │── GET /me/registrations ────────>│  QUI est-ce ?        │\n"
    . "   │                       │   Authorization: Bearer …        │  → ses données seules│\n"
    . "   │<══ ses inscriptions ══╡<─────────────────────────────────│                      │\n"
    );
    ?>
    <p class="mb-0">
      Le point important : <strong>le serveur sait toujours qui parle</strong>. Il ne renvoie
      jamais « les inscriptions », il renvoie « les inscriptions <em>de cette personne</em> ».
      C'est ce qui rend impossible de lire les données d'un autre coureur, même en trafiquant
      l'application.
    </p>
  </div>

  <!-- ═══ 2. Barrières ═══ -->
  <div class="api-card" id="barrieres">
    <h2 class="mt-0">2. Les trois barrières d'entrée</h2>
    <p>Elles s'appliquent <strong>avant</strong> tout traitement, à toutes les requêtes.</p>
    <table class="table table-sm api-params">
      <thead><tr><th>Barrière</th><th>Contre quoi</th><th>Refus</th></tr></thead>
      <tbody>
        <tr>
          <td><strong>HTTPS obligatoire</strong></td>
          <td>La <strong>fuite</strong> du jeton sur le réseau. C'est la seule des trois qui empêche
              réellement une divulgation : en clair, n'importe qui sur le même wifi lit le jeton
              du coureur et se fait passer pour lui.</td>
          <td><code>403 https_required</code></td>
        </tr>
        <tr>
          <td><strong>Interrupteur</strong></td>
          <td>L'imprévu. Réglages → API, un clic ferme tout. L'espace coureur du <em>site web</em>
              continue de fonctionner normalement : les deux sont indépendants.</td>
          <td><code>503 api_disabled</code></td>
        </tr>
        <tr>
          <td><strong>Version minimale</strong>
              <br><span class="badge bg-secondary">actuellement <?= $h($vMin) ?></span></td>
          <td>Les applications périmées. L'application annonce sa version dans
              <code>X-App-Version</code>&nbsp;; en dessous du minimum, le <strong>serveur</strong>
              refuse. Ce n'est pas un conseil que l'application serait libre d'ignorer.</td>
          <td><code>426 app_outdated</code></td>
        </tr>
      </tbody>
    </table>

    <?php mCode("X-App-Version: 1.2.0     ← obligatoire partout, sauf sur /app/config"); ?>

    <div class="alert alert-warning"><i class="bi bi-broadcast me-2"></i>
      <strong>Relever la version minimale met une application défectueuse hors service partout,
      d'un coup.</strong> C'est le seul moyen de rattraper une version publiée qui envoie de
      mauvaises données. <code>/app/config</code> reste toujours joignable, même pour une
      application refusée : c'est là qu'elle apprend qu'elle doit se mettre à jour et où trouver
      le lien du magasin d'applications. L'erreur <code>426</code> contient d'ailleurs déjà
      <code>version_minimale</code> et <code>config_url</code> — pas besoin d'un second appel.
    </div>

    <div class="alert alert-success mb-0"><i class="bi bi-key me-2"></i>
      <strong>Il n'y a volontairement AUCUNE clé d'application globale.</strong>
      Une clé unique valable pour tout le monde devrait être livrée dans l'application, donc
      présente sur le téléphone de chaque coureur — un fichier <code>.apk</code> se décompile en
      quelques minutes. <strong>Un secret publié n'est pas un secret</strong>, et croire le
      contraire fait baisser la garde là où elle compte. Ce qui protège les données, c'est le
      jeton <strong>personnel</strong> de chaque coureur.
    </div>
  </div>

  <!-- ═══ 3. Les deux jetons ═══ -->
  <div class="api-card" id="jetons">
    <h2 class="mt-0">3. Les deux jetons, et pourquoi il y en a deux</h2>
    <table class="table table-sm api-params">
      <thead><tr><th>Jeton</th><th>Durée</th><th>Où il vit</th><th>À quoi il sert</th></tr></thead>
      <tbody>
        <tr>
          <td><strong>device_token</strong><br><small class="text-muted">64 caractères hexadécimaux</small></td>
          <td>illimitée</td>
          <td>Trousseau sécurisé du téléphone</td>
          <td>Obtenir un jeton d'accès. Ne circule qu'au renouvellement.</td>
        </tr>
        <tr>
          <td><strong>access_token</strong><br><small class="text-muted">charge.signature</small></td>
          <td><?= $ttlAcces ?> min</td>
          <td>Mémoire vive de l'application</td>
          <td><code>Authorization: Bearer …</code> sur chaque appel.</td>
        </tr>
      </tbody>
    </table>

    <p><strong>Pourquoi ne pas en avoir qu'un seul ?</strong> Parce qu'un secret qui ne circule
       jamais ne peut pas être intercepté. Le <code>device_token</code> ne quitte le téléphone
       qu'une fois par heure ; c'est le jeton d'accès, à durée de vie courte, qui voyage à chaque
       requête. Si celui-ci fuit, il est périmé avant d'avoir beaucoup servi.</p>

    <div class="alert alert-info mb-0"><i class="bi bi-lightning me-2"></i>
      <strong>Le jeton d'accès est signé, pas stocké.</strong> Aucune table de sessions à purger.
      Mais <strong>chaque appel revérifie en base</strong> que l'appareil n'a pas été révoqué :
      sans ce contrôle, une révocation depuis « Mes appareils » resterait sans effet pendant
      <?= $ttlAcces ?> minutes. La révocation est donc immédiate — c'est exactement ce qu'on
      attend quand on se fait voler son téléphone.
    </div>
  </div>

  <!-- ═══ 4. Se connecter ═══ -->
  <div class="api-card" id="connexion">
    <h2 class="mt-0">4. Se connecter — pas à pas</h2>

    <h3><span class="endpoint-badge m-post">POST</span>Étape 1 — demander le code</h3>
    <?php mCode(
      "curl -X POST \"$apiUrl/auth/request-code\" \\\n"
    . "  -H \"Content-Type: application/json\" \\\n"
    . "  -H \"X-App-Version: 1.0.0\" \\\n"
    . "  -d '{\"email\": \"coureur@exemple.fr\"}'"
    ); ?>
    <div class="alert alert-warning">
      <i class="bi bi-eye-slash me-2"></i>
      <strong>La réponse est identique que l'adresse existe ou non.</strong> C'est délibéré :
      une réponse différente permettrait de deviner qui est inscrit à la course en essayant des
      adresses une par une. L'application affiche donc toujours « si un compte correspond,
      un code vient d'être envoyé ».
    </div>
    <p class="text-muted small">
      Le code est valable <strong><?= $ttlCode ?> minutes</strong>, ne sert qu'une fois, et toute
      nouvelle demande annule le précédent. Limites : <?= $maxMail15 ?> demandes par adresse
      toutes les 15 minutes, <?= $maxIpHeure ?> par adresse IP et par heure — au-delà,
      <code>429 rate_limited</code>. C'est ce qui empêche de se servir de ce point d'entrée
      pour inonder la boîte mail de quelqu'un.
    </p>

    <h3><span class="endpoint-badge m-post">POST</span>Étape 2 — valider le code</h3>
    <?php mCode(
      "curl -X POST \"$apiUrl/auth/verify-code\" \\\n"
    . "  -H \"Content-Type: application/json\" \\\n"
    . "  -H \"X-App-Version: 1.0.0\" \\\n"
    . "  -d '{\n"
    . "        \"email\": \"coureur@exemple.fr\",\n"
    . "        \"code\":  \"123456\",\n"
    . "        \"device_info\": {\n"
    . "          \"libelle\":    \"iPhone de Marie\",\n"
    . "          \"plateforme\": \"iOS 18\",\n"
    . "          \"modele\":     \"iPhone 14\"\n"
    . "        }\n"
    . "      }'"
    ); ?>
    <p>Réponse :</p>
    <?php mCode(
      "{\n"
    . "  \"ok\": true,\n"
    . "  \"data\": {\n"
    . "    \"device_token\":        \"a3f9…\",           ← à ranger dans le trousseau\n"
    . "    \"access_token\":        \"eyJk…\" + \".\" + signature,\n"
    . "    \"expires_at\":          \"2026-07-26T21:14:00+02:00\",\n"
    . "    \"rgpd_consent_requis\": false\n"
    . "  },\n"
    . "  \"error\": null\n"
    . "}"
    ); ?>
    <p class="text-muted small">
      <code>device_info</code> est facultatif mais fortement recommandé : c'est ce que le coureur
      verra dans « Mes appareils » pour décider lequel révoquer. « Appareil inconnu » ne l'aide
      pas à choisir. <code>rgpd_consent_requis</code> à <code>true</code> signifie que la personne
      n'a jamais accepté la politique de confidentialité : l'application doit la lui présenter.
    </p>
    <p class="text-muted small">
      Après <?= $maxEssais ?> codes faux, le code est invalidé définitivement : il faut en
      redemander un. Sans ce compteur, six chiffres se devinent par force brute.
    </p>

    <h3><span class="endpoint-badge m-post">POST</span>Étape 3 — renouveler le jeton d'accès</h3>
    <p>À faire quand un appel répond <code>401 invalid_token</code>, ou par anticipation avant
       l'expiration. L'utilisateur ne voit rien.</p>
    <?php mCode(
      "curl -X POST \"$apiUrl/auth/refresh\" \\\n"
    . "  -H \"Content-Type: application/json\" -H \"X-App-Version: 1.0.0\" \\\n"
    . "  -d '{\"device_token\": \"a3f9…\"}'"
    ); ?>
    <div class="alert alert-danger mb-0"><i class="bi bi-exclamation-octagon me-2"></i>
      Si <code>/auth/refresh</code> répond <code>401 device_revoked</code>, l'appareil a été
      révoqué (par le coureur ou par l'administration). <strong>L'application doit effacer le
      jeton du trousseau et revenir à l'écran de connexion</strong> — pas réessayer en boucle.
    </div>
  </div>

  <!-- ═══ 5. Stockage ═══ -->
  <div class="api-card" id="stockage">
    <h2 class="mt-0">5. Où l'application range le jeton</h2>
    <table class="table table-sm api-params">
      <thead><tr><th>Plateforme</th><th>À utiliser</th><th>À ne jamais utiliser</th></tr></thead>
      <tbody>
        <tr>
          <td>iOS</td>
          <td>Keychain (<code>kSecAttrAccessibleAfterFirstUnlock</code>)</td>
          <td><code>UserDefaults</code>, un fichier en clair</td>
        </tr>
        <tr>
          <td>Android</td>
          <td>Keystore / <code>EncryptedSharedPreferences</code></td>
          <td><code>SharedPreferences</code> simple, base SQLite en clair</td>
        </tr>
        <tr>
          <td>React Native / Flutter</td>
          <td><code>react-native-keychain</code>, <code>flutter_secure_storage</code></td>
          <td><code>AsyncStorage</code>, <code>localStorage</code></td>
        </tr>
      </tbody>
    </table>
    <div class="alert alert-warning mb-0"><i class="bi bi-journal-x me-2"></i>
      <strong>Ne journalisez jamais un jeton</strong>, même en développement : les journaux
      partent dans les outils de diagnostic, les rapports de plantage et les captures d'écran.
      Un jeton dans un rapport de bug est un jeton public.
    </div>
  </div>

  <!-- ═══ 6. Endpoints ═══ -->
  <div class="api-card" id="endpoints">
    <h2 class="mt-0">6. Liste des points d'entrée</h2>
    <p class="text-muted">
      Sauf mention contraire, tous exigent <code>Authorization: Bearer &lt;access_token&gt;</code>
      et <code>X-App-Version</code>.
    </p>
    <table class="table table-sm api-params">
      <thead><tr><th>Méthode</th><th>Chemin</th><th>Rôle</th></tr></thead>
      <tbody>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/app/config</code></td>
            <td><strong>Sans aucune authentification.</strong> Version minimale exigée, liens des
                magasins, URL de la politique de confidentialité, textes modifiables sans
                republier l'application, et <code>chrono_actif</code> — l'état du chronométrage.
                Une application trop ancienne doit pouvoir l'interroger — elle n'a justement pas
                de jeton valide.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/auth/request-code</code></td><td>Sans jeton. Envoie le code à 6 chiffres.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/auth/verify-code</code></td><td>Sans jeton. Valide le code, émet les deux jetons.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/auth/refresh</code></td><td>Avec le <code>device_token</code> dans le corps.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/auth/logout</code></td><td>Révoque l'appareil courant.</td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/editions</code><br><code>/editions/{id}</code></td>
            <td>Date, distance, heure de départ, points de départ et d'arrivée, date limite de transfert.</td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/me</code></td><td>Profil du compte.</td></tr>
        <tr><td><span class="endpoint-badge m-patch">PATCH</span></td><td><code>/me</code></td><td>Nom et prénom.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/me/email/request-change</code></td><td>Envoie un code à la <strong>nouvelle</strong> adresse.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/me/email/confirm</code></td><td>Vérifie ce code et applique le changement.</td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/me/registrations</code></td><td>Toutes les inscriptions du compte, éditions passées comprises.</td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/me/registrations/{annee}/{no}</code></td><td>Une inscription, par sa <strong>clé métier</strong>.</td></tr>
        <tr><td><span class="endpoint-badge m-patch">PATCH</span></td><td><code>/me/registrations/{annee}/{no}</code></td><td>Sexe et âge.</td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/me/registrations/{annee}/{no}/qrcode</code></td><td>QR code en PNG base64 — la même image que le mail de confirmation.</td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/me/devices</code></td><td>Appareils de confiance actifs.</td></tr>
        <tr><td><span class="endpoint-badge m-delete">DELETE</span></td><td><code>/me/devices/{id}</code></td><td>Révoque un appareil.</td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/me/transfers</code></td><td>Demandes de transfert émises.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/me/transfers</code></td><td>Nouvelle demande de transfert.</td></tr>
        <tr><td><span class="endpoint-badge m-delete">DELETE</span></td><td><code>/me/transfers/{id}</code></td><td>Annule une demande en attente.</td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/me/detections</code></td><td>Détections balise et GPS. Voir la section 9. <strong>Fermé si <code>chrono_actif</code> vaut <code>false</code>.</strong></td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/me/traces</code></td><td>Lot de points GPS. Exige le consentement. <strong>Fermé si <code>chrono_actif</code> vaut <code>false</code>.</strong></td></tr>
        <tr><td><span class="endpoint-badge m-post">POST</span></td><td><code>/me/traces/consent</code></td><td>Donner ou retirer l’accord au suivi GPS. <strong>Fermé si <code>chrono_actif</code> vaut <code>false</code>.</strong></td></tr>
        <tr><td><span class="endpoint-badge m-get">GET</span></td><td><code>/me/results</code></td>
            <td>Résultats calculés : temps, méthode, précision, statut. <strong>Fermé si <code>chrono_actif</code> vaut <code>false</code>.</strong></td></tr>
      </tbody>
    </table>

    <h3>Identifier une inscription : la clé métier</h3>
    <div class="alert alert-info mb-0"><i class="bi bi-key me-2"></i>
      Une inscription se désigne par <code>(annee, inscription_no)</code>, <strong>jamais par son
      identifiant technique</strong>. Le site archive chaque édition dans une table
      <code>registrations_AAAA</code> : les identifiants changent donc de table tous les ans, et une
      application qui les aurait mémorisés désignerait la mauvaise ligne l'année suivante.
      <code>2026/FER-00123</code>, lui, ne bouge jamais.
    </div>
  </div>

  <!-- ═══ 7. Modifications ═══ -->
  <div class="api-card" id="modif">
    <h2 class="mt-0">7. Ce que le coureur peut modifier lui-même</h2>
    <?php mCode(
      "PATCH $apiUrl/me\n"
    . "{ \"nom\": \"Durand\", \"prenom\": \"Marie\" }\n\n"
    . "PATCH $apiUrl/me/registrations/2026/FER-00123\n"
    . "{ \"sexe\": \"F\", \"age\": 34 }          ← sexe : H, F ou Autre\n\n"
    . "POST  $apiUrl/me/email/request-change\n"
    . "{ \"email\": \"nouvelle@exemple.fr\" }    ← un code part à cette adresse\n\n"
    . "POST  $apiUrl/me/email/confirm\n"
    . "{ \"email\": \"nouvelle@exemple.fr\", \"code\": \"123456\" }"
    ); ?>

    <table class="table table-sm api-params">
      <thead><tr><th>Règle</th><th>Pourquoi</th></tr></thead>
      <tbody>
        <tr>
          <td>Seules les <strong>éditions en cours</strong> sont modifiables</td>
          <td>Les tables <code>registrations_AAAA</code> sont la mémoire de l'association :
              elles ne bougent plus, jamais. Une demande sur une édition archivée est refusée
              avec un message clair, pas ignorée en silence.</td>
        </tr>
        <tr>
          <td>Sexe et âge se figent <strong>au départ de la course</strong></td>
          <td>Ils déterminent la catégorie de classement. Les changer après le départ
              reviendrait à changer de catégorie en pleine course.</td>
        </tr>
        <tr>
          <td>Seul l'<strong>âge</strong> est conservé, jamais la date de naissance</td>
          <td>C'est le modèle du site, et une donnée personnelle de moins à protéger.
              L'API accepte un âge, une année ou une date : elle n'en garde que l'âge.</td>
        </tr>
        <tr>
          <td>Changer d'adresse demande <strong>deux appels</strong></td>
          <td>Être connecté prouve qu'on est le titulaire ; le code reçu prouve qu'on possède
              la nouvelle boîte. Sans le second, une faute de frappe enfermerait le coureur
              dehors — son adresse est son seul moyen de se reconnecter.</td>
        </tr>
        <tr>
          <td>Une correction se répercute sur l'<strong>inscription</strong></td>
          <td>Sinon le coureur corrigerait une faute dans l'application et la retrouverait
              sur la liste de départ et sur son dossard.</td>
        </tr>
      </tbody>
    </table>

    <div class="alert alert-secondary mb-0"><i class="bi bi-diagram-3 me-2"></i>
      Ces règles vivent dans <code>src/auth/participant_profile.php</code>, <strong>partagé avec
      les pages web</strong>. L'API n'a pas sa propre copie des contrôles : sinon elle deviendrait
      le chemin qui les contourne. Chaque modification est tracée dans
      <code>storage/logs/logs_espace_coureur.log</code> et dans les journaux de contenu.
    </div>
  </div>

  <!-- ═══ 8. Erreurs ═══ -->
  <div class="api-card" id="erreurs">
    <h2 class="mt-0">8. Codes d'erreur</h2>
    <table class="table table-sm api-params">
      <thead><tr><th>HTTP</th><th>code</th><th>Signification</th><th>Que doit faire l'application</th></tr></thead>
      <tbody>
        <tr><td>400</td><td><code>invalid_json</code></td><td>Corps de requête illisible.</td><td>Bug côté application.</td></tr>
        <tr><td>400</td><td><code>missing_app_version</code></td><td>En-tête <code>X-App-Version</code> absent.</td><td>Bug côté application.</td></tr>
        <tr><td>401</td><td><code>missing_token</code><br><code>invalid_token</code></td><td>Jeton absent, signature invalide ou expiré.</td><td>Appeler <code>/auth/refresh</code>, puis réessayer <strong>une fois</strong>.</td></tr>
        <tr><td>401</td><td><code>device_revoked</code></td><td>L'appareil a été révoqué.</td><td>Effacer le trousseau, retour à l'écran de connexion.</td></tr>
        <tr><td>401</td><td><code>invalid_code</code></td><td>Code faux, expiré ou inexistant.</td><td>Afficher le message tel quel.</td></tr>
        <tr><td>403</td><td><code>https_required</code></td><td>Connexion non chiffrée.</td><td>Corriger l'URL de base.</td></tr>
        <tr><td>403</td><td><code>account_disabled</code></td><td>Compte désactivé par l'administration.</td><td>Inviter à contacter l'organisation.</td></tr>
        <tr><td>403</td><td><code>forbidden</code></td><td>Ressource valide, mais qui n'appartient pas à ce compte.</td><td>Ne devrait pas arriver — bug de navigation.</td></tr>
        <tr><td>403</td><td><code>no_registration</code></td><td>Aucune inscription pour cette adresse.</td><td>Expliquer qu'il faut d'abord s'inscrire à la course.</td></tr>
        <tr><td>403</td><td><code>chrono_disabled</code></td><td>Le chronométrage n'est pas ouvert : <code>/me/detections</code>, <code>/me/traces</code> et <code>/me/results</code> sont fermés.</td><td><strong>Masquer les écrans de course</strong> — l'état est donné à l'avance par <code>chrono_actif</code> dans <code>/app/config</code>. Ne pas réessayer.</td></tr>
        <tr><td>404</td><td><code>not_found</code><br><code>unknown_endpoint</code></td><td>Ressource ou chemin inconnu.</td><td>—</td></tr>
        <tr><td>405</td><td><code>method_not_allowed</code></td><td>Mauvaise méthode HTTP sur un chemin valide.</td><td>Bug côté application.</td></tr>
        <tr><td>422</td><td><code>invalid_email</code>, <code>missing_fields</code>,<br><code>invalid_key</code>, <code>invalid_input</code></td><td>Données de la requête incorrectes.</td><td>Afficher le message à l'utilisateur.</td></tr>
        <tr><td>422</td><td><code>transfer_refused</code><br><code>email_change_refused</code></td><td>Refusé par les règles métier.</td><td>Afficher le message : il est écrit pour être lu.</td></tr>
        <tr><td>426</td><td><code>app_outdated</code></td><td>Version trop ancienne.</td><td><strong>Écran bloquant</strong> « mettez à jour », avec le lien du magasin.</td></tr>
        <tr><td>429</td><td><code>rate_limited</code></td><td>Trop de demandes de code.</td><td>Afficher un délai, <strong>ne pas réessayer automatiquement</strong>.</td></tr>
        <tr><td>503</td><td><code>api_disabled</code><br><code>not_installed</code></td><td>API désactivée, ou migration non jouée.</td><td>Message « service momentanément indisponible ».</td></tr>
      </tbody>
    </table>
    <p class="mb-0 text-muted small"><i class="bi bi-clock-history me-1"></i>
      Les appels à <code>/auth/*</code> — succès comme échecs — sont journalisés dans
      <code>storage/logs/logs_api_mobile.log</code>, consultable depuis
      <a href="logs.php">Journaux système</a>.
    </p>
  </div>

  <!-- ═══ 9. Données de course ═══ -->
  <div class="api-card" id="course">
    <h2 class="mt-0">9. Envoyer les données de course</h2>

    <?php /* Placé EN TÊTE de la section : c'est la condition d'existence de tout
             ce qui suit. Le découvrir après avoir codé l'écran de course serait
             le découvrir trop tard. */ ?>
    <div class="alert <?= chrono_actif($pdo) ? 'alert-success' : 'alert-secondary' ?>">
      <i class="bi bi-toggles me-2"></i>
      <strong>Toute cette section dépend d'un interrupteur :
        <?= chrono_actif($pdo)
              ? 'le chronométrage est actuellement <span class="badge bg-success">ouvert</span>.'
              : 'le chronométrage est actuellement <span class="badge bg-secondary">fermé</span>.' ?>
      </strong>
      Fermé, <code>/me/detections</code>, <code>/me/traces</code>, <code>/me/traces/consent</code>
      et <code>/me/results</code> répondent <code>403 chrono_disabled</code>, et l'espace coureur
      du site masque les mêmes écrans. L'application doit lire <code>chrono_actif</code> dans
      <code>/app/config</code> et masquer ses écrans de course en conséquence — plutôt que de
      découvrir le refus sur la ligne d'arrivée. Réglage&nbsp;: administration →
      <a href="resultats.php">Résultats</a>.
      <br><strong>Fermer ne supprime rien</strong> : les temps et les traces déjà enregistrés
      restent en base et redeviennent lisibles dès la réouverture.
    </div>

    <div class="alert alert-info"><i class="bi bi-broadcast-pin me-2"></i>
      <strong>Deux sources, toujours les deux.</strong> Chaque passage est détecté par la
      <strong>balise Bluetooth</strong> posée sur la ligne <em>et</em> par le
      <strong>franchissement de la zone GPS</strong>. Si l'une manque, l'autre donne le temps ;
      si les deux sont là, c'est la balise qui fait foi. C'est le seul moyen de ne pas se
      retrouver, le jour de la course, avec des participants sans chrono parce qu'un boîtier
      a lâché. <strong>Envoyez systématiquement les deux</strong> — le serveur trie.
    </div>

    <h3><span class="endpoint-badge m-post">POST</span>/me/detections</h3>
    <?php mCode(
      "curl -X POST \"$apiUrl/me/detections\" \\\n"
    . "  -H \"Content-Type: application/json\" -H \"X-App-Version: 1.0.0\" \\\n"
    . "  -H \"Authorization: Bearer <access_token>\" \\\n"
    . "  -d '{\n"
    . "        \"annee\": 2026,\n"
    . "        \"inscription_no\": \"FER-00123\",\n"
    . "        \"detections\": [\n"
    . "          { \"type\": \"beacon\",   \"point\": \"depart\",  \"detecte_at\": \"2026-10-04T10:00:01.250+02:00\", \"rssi_pic\": -52 },\n"
    . "          { \"type\": \"geofence\", \"point\": \"depart\",  \"detecte_at\": \"2026-10-04T10:00:12.000+02:00\" },\n"
    . "          { \"type\": \"beacon\",   \"point\": \"arrivee\", \"detecte_at\": \"2026-10-04T10:40:03.100+02:00\", \"rssi_pic\": -54 },\n"
    . "          { \"type\": \"geofence\", \"point\": \"arrivee\", \"detecte_at\": \"2026-10-04T10:40:18.000+02:00\" }\n"
    . "        ]\n"
    . "      }'\n\n"
    . "→ { \"ajoutees\": 4, \"connues\": 0, \"refusees\": [],\n"
    . "     \"statut\": \"termine\", \"temps_s\": 2402, \"methode\": \"beacon\" }"
    ); ?>
    <table class="table table-sm api-params">
      <thead><tr><th>Champ</th><th>Valeurs</th><th>Remarque</th></tr></thead>
      <tbody>
        <tr><td><code>type</code></td><td><code>beacon</code>, <code>geofence</code>, <code>gps_ligne</code></td>
            <td><code>manuel</code> est <strong>refusé</strong> (403) : il est réservé à l'organisation et prime sur tout le reste. Le laisser passer permettrait de dicter son temps.</td></tr>
        <tr><td><code>point</code></td><td><code>depart</code>, <code>arrivee</code></td><td>—</td></tr>
        <tr><td><code>detecte_at</code></td><td>ISO-8601 <strong>avec décalage</strong></td>
            <td>L'instant vu par le <em>téléphone</em>. Millisecondes acceptées. Une date sans fuseau est refusée : elle serait lue dans le fuseau du serveur, soit deux heures d'écart sur le chrono.</td></tr>
        <tr><td><code>rssi_pic</code></td><td>entier négatif (dBm)</td>
            <td>Balise uniquement. Sert à départager deux détections de balise : −50 dBm, on est passé à côté ; −95 dBm, on l'a captée de loin.</td></tr>
      </tbody>
    </table>
    <p class="text-muted small">
      200 détections par appel au maximum. Le champ <code>connues</code> compte celles déjà
      reçues : c'est normal après un renvoi, ce n'est pas une erreur.
    </p>

    <h3><span class="endpoint-badge m-post">POST</span>/me/traces/consent</h3>
    <p>Une trace GPS dit où quelqu'un se trouvait <strong>minute par minute</strong>. Elle ne
       s'enregistre pas parce que l'application l'a décidé : sans consentement,
       <code>/me/traces</code> répond <code>403 consent_required</code>.</p>
    <?php mCode("POST $apiUrl/me/traces/consent\n{ \"consent\": true }     ← ou false pour le retirer"); ?>

    <h3><span class="endpoint-badge m-post">POST</span>/me/traces</h3>
    <?php mCode(
      "POST $apiUrl/me/traces\n"
    . "{\n"
    . "  \"annee\": 2026, \"inscription_no\": \"FER-00123\",\n"
    . "  \"points\": [\n"
    . "    { \"lat\": 49.1903, \"lon\": 6.9002, \"at\": \"2026-10-04T10:00:03+02:00\", \"alt\": 210, \"precision_m\": 8 },\n"
    . "    { \"lat\": 49.1904, \"lon\": 6.9004, \"at\": \"2026-10-04T10:00:06+02:00\" }\n"
    . "  ]\n"
    . "}\n\n"
    . "→ { \"ajoutes\": 2, \"ignores\": 0 }"
    ); ?>
    <div class="alert alert-success"><i class="bi bi-arrow-repeat me-2"></i>
      <strong>Idempotent par construction.</strong> Seuls les points <em>postérieurs</em> au dernier
      point déjà connu sont conservés. Renvoyer un lot déjà reçu n'ajoute rien et répond
      <code>ajoutes: 0</code> — l'application peut donc réémettre sans risque après une coupure,
      sans tenir de comptabilité de ce qui est passé. 5000 points par appel au maximum.
    </div>

    <h3>Ce que le serveur fait de tout ça</h3>
    <?php mFlux(
      "détections reçues                    arbitrage                  résultat\n"
    . "───────────────────                  ─────────                  ────────\n"
    . " balise   départ  10:00:01  ┐\n"
    . " zone GPS départ  10:00:12  ┤── 1. le type le plus fiable gagne :\n"
    . " balise   arrivée 10:40:03  ┤      manuel > balise > zone GPS > GPS ligne\n"
    . " zone GPS arrivée 10:40:18  ┘\n"
    . "                               2. à type égal :\n"
    . "                                  départ  → la plus TARDIVE\n"
    . "                                            (on part en quittant la ligne)\n"
    . "                                  arrivée → la plus PRÉCOCE\n"
    . "                                            (on finit en la franchissant)\n"
    . "                                                              ↓\n"
    . "                                          temps_s     = 2402\n"
    . "                                          methode     = beacon\n"
    . "                                          precision_s = ±2 s\n"
    . "                                          statut      = termine"
    ); ?>
    <table class="table table-sm api-params">
      <thead><tr><th>Source retenue</th><th><code>methode</code></th><th><code>precision_s</code></th></tr></thead>
      <tbody>
        <tr><td>Saisie par un officiel</td><td><code>manuel</code></td><td>±1 s</td></tr>
        <tr><td>Balise Bluetooth</td><td><code>beacon</code></td><td>±2 s</td></tr>
        <tr><td>Zone GPS franchie</td><td><code>gps_ligne</code></td><td>±15 s</td></tr>
        <tr><td>Reconstruction GPS</td><td><code>gps_ligne</code></td><td>±30 s</td></tr>
      </tbody>
    </table>
    <p class="text-muted small">
      <code>precision_s</code> est celle de la source la <strong>moins bonne des deux</strong> :
      un temps ne peut pas être plus précis que sa borne la plus floue. <strong>Affichez-la
      toujours à côté du temps</strong> — un temps GPS montré nu passerait pour une mesure à
      la seconde.
    </p>

    <h3>Les cas particuliers, et ce que renvoie l'API</h3>
    <table class="table table-sm api-params">
      <thead><tr><th>Situation</th><th>Résultat</th></tr></thead>
      <tbody>
        <tr><td>Aucune détection de départ (départ en masse)</td>
            <td><code>termine</code>, calculé sur <code>editions.heure_depart</code>. Le champ <code>commentaire</code> l'indique.</td></tr>
        <tr><td>Arrivée détectée sans aucun départ possible</td><td><code>invalide</code></td></tr>
        <tr><td>Arrivée antérieure au départ</td><td><code>invalide</code></td></tr>
        <tr><td>Temps sous <code>temps_min_plausible_s</code></td><td><code>invalide</code> — quelqu'un n'a pas fait 7 km en 4 minutes</td></tr>
        <tr><td>Résultat validé par un officiel</td><td>Inchangé. Une détection tardive ne défait pas la décision d'un humain.</td></tr>
      </tbody>
    </table>
    <p class="text-muted small mb-0">
      Un résultat <code>invalide</code> n'est <strong>jamais présenté comme un temps</strong> au
      coureur : son espace affiche « à vérifier ». L'écran d'administration
      <em>Résultats</em> les met en tête de liste, avec le détail de toutes les détections reçues.
    </p>
  </div>

  <!-- ═══ 9 bis. Règles de conception ═══ -->
  <div class="api-card">
    <h2 class="mt-0">9 bis. Quatre règles à respecter dans l'application</h2>
    <p>Elles sont inscrites dans la structure de la base, et le serveur les applique :</p>
    <table class="table table-sm api-params">
      <thead><tr><th>Règle</th><th>Ce que ça implique</th></tr></thead>
      <tbody>
        <tr>
          <td><strong>Le serveur calcule le temps, jamais le téléphone</strong></td>
          <td>Une application qui envoie « j'ai fait 42 min » fait une déclaration, pas une mesure :
              on la croit ou on la truque. Le serveur recalcule à partir des points bruts. C'est
              pourquoi <code>resultats.methode</code> distingue <code>beacon</code> de
              <code>gps_extrapole</code> — un temps mesuré et un temps estimé ne valent pas la même
              chose et ne doivent jamais s'afficher comme équivalents.</td>
        </tr>
        <tr>
          <td><strong>Le réseau tombera pendant la course</strong></td>
          <td>C'est certain, pas probable. Le téléphone garde ses points et les envoie quand il peut :
              d'où <code>detections.detecte_at</code> (l'instant vu par le téléphone) distinct de
              <code>recu_at</code> (l'instant reçu par le serveur). <strong>L'heure qui compte est
              celle du coureur</strong>, pas celle de l'envoi.</td>
        </tr>
        <tr>
          <td><strong>Les mêmes points seront envoyés deux fois</strong></td>
          <td>Renvoi après échec, reprise après plantage. La réception doit être idempotente,
              sinon un trajet se retrouve compté deux fois.</td>
        </tr>
        <tr>
          <td><strong>On n'envoie que pour son propre dossard</strong></td>
          <td>Le contrôle existe déjà (<code>pauth_owns</code>) : il suffira de le réutiliser.</td>
        </tr>
      </tbody>
    </table>

    <p class="mb-0 text-muted">
      <code>GET /me/results</code> renvoie les résultats calculés — <code>temps_s</code>, <code>methode</code>,
      <code>precision_s</code>, <code>statut</code>. La liste reste vide tant qu'aucune détection n'a été reçue.
    </p>
  </div>

  <!-- ═══ 10. Règles de sécurité ═══ -->
  <div class="api-card" id="regles">
    <h2 class="mt-0">10. Règles de sécurité, et pourquoi elles sont là</h2>
    <table class="table table-sm api-params">
      <thead><tr><th>Règle</th><th>Raison</th></tr></thead>
      <tbody>
        <tr>
          <td>Aucune <strong>donnée personnelle en paramètre d'URL</strong></td>
          <td>Les URL sont journalisées par les serveurs, les proxys et les pare-feux, et
              conservées longtemps. Les adresses email passent donc dans le corps de la requête.</td>
        </tr>
        <tr>
          <td>Aucune en-tête <strong>CORS</strong></td>
          <td>Une application native n'en a pas besoin. Un <code>Access-Control-Allow-Origin: *</code>
              ouvrirait l'API à toutes les pages web du monde.</td>
        </tr>
        <tr>
          <td><code>403</code> et non <code>404</code> sur l'inscription d'un autre</td>
          <td>Le message ne dit pas laquelle des deux raisons s'applique — ni « elle n'existe pas »,
              ni « elle existe mais n'est pas à vous ».</td>
        </tr>
        <tr>
          <td>Réponse <strong>identique</strong> pour une adresse inconnue</td>
          <td>Sinon l'API devient un annuaire : on essaie des adresses une par une et on apprend
              qui est inscrit à la course.</td>
        </tr>
        <tr>
          <td>Le code à 6 chiffres est haché avec <code>password_hash()</code></td>
          <td>Six chiffres, c'est un million de combinaisons : il faut un hachage <strong>lent</strong>.
              Les jetons d'appareil, eux, sont hachés en SHA-256 — ce sont des secrets longs et
              aléatoires, recherchés par leur empreinte à chaque appel.</td>
        </tr>
        <tr>
          <td>Toutes les dates sont en <strong>ISO-8601 avec décalage</strong></td>
          <td><code>2026-10-04T09:30:00+02:00</code>, jamais une date nue. Une heure de départ sans
              fuseau, c'est deux heures d'écart sur tous les chronos.</td>
        </tr>
        <tr>
          <td>Aucune session PHP n'est ouverte</td>
          <td>L'API s'authentifie par jeton. Sans ce garde-fou, chaque appel créerait un fichier de
              session jamais relu et renverrait un cookie à un client qui n'en fera rien.</td>
        </tr>
      </tbody>
    </table>

    <div class="alert alert-secondary mb-0"><i class="bi bi-diagram-2 me-2"></i>
      <strong>Séparation stricte avec l'administration.</strong> Un compte coureur et un compte
      administrateur ne se croisent jamais : deux tables, deux sessions, deux cookies, deux
      systèmes de jetons. Un coureur ne peut pas atteindre l'espace d'administration, même en cas
      de faille dans cette API — l'isolation est structurelle, elle ne repose pas sur un test
      qu'on pourrait oublier quelque part.
    </div>
  </div>

</div>
<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
