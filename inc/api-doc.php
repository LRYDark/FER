<?php
/**
 * api-doc.php — Documentation de l'API publique de Forbach en Rose.
 * Page d'administration accessible depuis Réglages → onglet API.
 */
require '../src/core/config.php';

// Accès réservé aux administrateurs
if (!isset($_SESSION['uid']) || currentRole() !== 'admin') {
    http_response_code(403);
    header('Location: ../login.php');
    exit;
}
$role = currentRole();
require __DIR__ . '/../src/partials/navbar-data.php';

// ── URL absolue de l'API (api.php est à la racine du projet) ──────────────
$baseUrl     = getAppBaseUrl();
$projectRoot = realpath(__DIR__ . '/..');
$docRoot     = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($projectRoot === $docRoot || $projectRoot === false || $docRoot === false) {
    $baseDir = '';
} else {
    $baseDir = str_replace('\\', '/', substr($projectRoot, strlen($docRoot)));
}
$apiUrl = $baseUrl . $baseDir . '/api.php';

// Identifiant API actuel (le token n'est jamais affiché ici, voir l'onglet API)
try {
    $apiRow     = $pdo->query('SELECT api_enabled, api_user FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $apiRow = [];
}
$apiEnabled = !empty($apiRow['api_enabled']);
$apiUser    = $apiRow['api_user'] ?? 'votre_identifiant';

/** Affiche un bloc de code échappé. */
function codeBlock(string $code): void
{
    echo '<pre class="api-code"><code>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></pre>';
}
$U = htmlspecialchars($apiUser, ENT_QUOTES, 'UTF-8');
$A = htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Documentation de l'API</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<style>
  body { background:var(--surface-2); }
  .api-doc h2 { color:#880e4f; font-weight:700; margin-top:2.5rem; }
  .api-doc h3 { color:#c4577a; font-weight:600; margin-top:1.75rem; font-size:1.2rem; }
  .api-code {
    background:#1e1e2e; color:#e4e4ef; padding:1rem 1.15rem; border-radius:10px;
    font-size:.85rem; overflow-x:auto; margin:.6rem 0 1rem;
  }
  .api-code code { color:inherit; background:none; white-space:pre; }
  .api-card {
    background: var(--surface); border:1px solid var(--border); border-radius:14px;
    padding:1.5rem; margin-bottom:1.5rem;
  }
  .endpoint-badge {
    display:inline-block; font-weight:700; font-size:.78rem; padding:.2rem .6rem;
    border-radius:6px; margin-right:.5rem; font-family:monospace;
  }
  .m-get  { background:#d1fae5; color: var(--ok); }
  .m-post { background: color-mix(in srgb, var(--info) 15%, var(--surface)); color: var(--info); }
  .m-patch  { background: color-mix(in srgb, var(--warn) 18%, var(--surface));   color: var(--warn); }
  .m-delete { background: color-mix(in srgb, var(--danger) 15%, var(--surface)); color: var(--danger); }
  .api-toc a { color:#880e4f; text-decoration:none; }
  .api-toc a:hover { text-decoration:underline; }
  table.api-params td, table.api-params th { font-size:.88rem; vertical-align:top; }
  .url-pill { background: var(--accent-soft); color:#880e4f; padding:.15rem .5rem; border-radius:6px; font-family:monospace; font-size:.85rem; }
  /* navbar-admin.php applique « display:flex » à toutes les alertes
     (#oc-content .alert) : ici les alertes contiennent du texte riche
     multi-lignes, on rétablit donc un affichage en flux normal. */
  #oc-content .api-doc .alert { display:block; }
  /* Les <code> dans les alertes : puce blanche lisible sur tout fond
     (y compris le fond rouge de alert-danger). */
  #oc-content .api-doc .alert code {
    background: var(--surface); color: var(--ink);
    padding:.05rem .35rem; border-radius:4px;
    font-size:.85em; word-break:break-word;
  }
  #oc-content .api-doc .alert a { color:inherit; font-weight:600; }
  /* Les tableaux restent lisibles sur mobile (défilement horizontal si besoin) */
  .api-card .table-responsive { margin-bottom:0; }
  table.api-params { width:100%; }
  /* Adaptation mobile */
  @media (max-width: 576px) {
    .api-card { padding:1.1rem; }
    .api-doc h1 { font-size:1.5rem; }
    .api-doc h2 { font-size:1.25rem; }
    .api-doc h3 { font-size:1.05rem; }
    .api-code { font-size:.76rem; padding:.8rem .9rem; }
    .api-card table { display:block; overflow-x:auto; white-space:normal; }
  }
</style>
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<div class="container-fluid pb-5 api-doc">

  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
    <h1 class="fw-bold mb-0"><i class="bi bi-plug me-2"></i>Documentation de l'API</h1>
    <a href="setting.php?tab=api" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Retour aux réglages</a>
  </div>
  <p class="text-muted">Connectez vos applications externes au site Forbach en Rose, en toute sécurité.</p>

  <?php if (!$apiEnabled): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>
    L'API est actuellement <strong>désactivée</strong>. Activez-la dans
    <a href="setting.php?tab=api">Réglages → onglet API</a> pour qu'elle réponde aux requêtes.
  </div>
  <?php endif; ?>

  <!-- ═══ Présentation ═══ -->
  <div class="api-card">
    <h2 class="mt-0"><i class="bi bi-info-circle me-2"></i>En résumé</h2>
    <p>L'API permet à d'autres logiciels de <strong>dialoguer avec le site</strong> sans passer
       par le tableau de bord. Tout se fait en envoyant des requêtes web à une seule adresse :</p>
    <p><span class="url-pill"><?= $A ?></span></p>
    <p>Une requête = un <em>endpoint</em> (l'action voulue) + vos <em>identifiants</em> (pour prouver
       que vous avez le droit). La réponse est toujours au format <strong>JSON</strong>.</p>
    <ul>
      <li><strong>Phase 1</strong> — importer un fichier Excel d'inscrits (avec ou sans envoi de mails).</li>
      <li><strong>Phase 2</strong> — ajouter un seul inscrit.</li>
      <li><strong>Phase 3</strong> — consulter les inscrits et les statistiques.</li>
    </ul>
  </div>

  <!-- ═══ Sommaire ═══ -->
  <div class="api-card api-toc">
    <h2 class="mt-0"><i class="bi bi-list-ul me-2"></i>Sommaire</h2>
    <ol class="mb-0">
      <li><a href="#auth">Authentification &amp; sécurité (HTTPS, identifiant + token)</a></li>
      <li><a href="#ping">Tester la connexion — <code>ping</code></a></li>
      <li><a href="#import">Phase 1 — Importer un fichier Excel — <code>import</code></a></li>
      <li><a href="#add">Phase 2 — Ajouter un inscrit — <code>registration</code> (POST)</a></li>
      <li><a href="#search">Phase 3 — Rechercher un inscrit — <code>registration</code> (GET)</a></li>
      <li><a href="#list">Phase 3 — Lister les inscrits — <code>registrations</code></a></li>
      <li><a href="#stats">Phase 3 — Statistiques — <code>stats</code> &amp; <code>years</code></a></li>
      <li><a href="#errors">Codes d'erreur</a></li>
      <li><a href="#modele">Modèle de données — clé métier, fuseaux horaires, traces GPS</a></li>
      <li><a href="#mobile">API mobile — <code>/api/v1</code> (espace coureur, application)</a></li>
      <li><a href="#mobile-errors">Codes d'erreur de l'API mobile</a></li>
    </ol>
  </div>

  <!-- ═══ 1. Authentification ═══ -->
  <div class="api-card" id="auth">
    <h2 class="mt-0">1. Authentification &amp; sécurité</h2>

    <div class="alert alert-danger"><i class="bi bi-lock-fill me-2"></i>
      <strong>HTTPS obligatoire.</strong> L'API refuse toute connexion non sécurisée :
      les requêtes en <code>http://</code> sont bloquées (erreur <code>https_required</code>).
      Utilisez toujours une URL commençant par <code>https://</code>.
    </div>

    <p>Chaque requête doit contenir vos identifiants (créés dans l'onglet API des réglages) :
       un <strong>identifiant</strong> et un <strong>token</strong>. Deux méthodes sont acceptées,
       choisissez la plus simple pour votre application — elles sont aussi sûres l'une que
       l'autre dès lors que la connexion est en HTTPS.</p>

    <h3>Méthode 1 — En-têtes dédiés (recommandée, la plus simple)</h3>
    <?php codeBlock("X-Api-User: $apiUser\nX-Api-Token: VOTRE_TOKEN"); ?>

    <h3>Méthode 2 — Bearer (standard, token seul)</h3>
    <?php codeBlock("Authorization: Bearer VOTRE_TOKEN"); ?>

    <div class="alert alert-info mt-3 mb-0"><i class="bi bi-shield-lock me-2"></i>
      <strong>Bonnes pratiques :</strong> ne partagez jamais votre token, conservez-le
      côté serveur (évitez de l'exposer dans une application accessible au public), et
      régénérez-le depuis l'onglet API s'il a été exposé.
    </div>
  </div>

  <!-- ═══ 2. Ping ═══ -->
  <div class="api-card" id="ping">
    <h2 class="mt-0"><span class="endpoint-badge m-get">GET</span>2. Tester la connexion — <code>ping</code></h2>
    <p>Vérifie que l'API est activée et que vos identifiants sont valides. À utiliser en premier.</p>
    <h3>Exemple (cURL)</h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=ping\" \\\n  -H \"X-Api-User: $apiUser\" \\\n  -H \"X-Api-Token: VOTRE_TOKEN\""); ?>
    <h3>Réponse</h3>
    <?php codeBlock("{\n  \"ok\": true,\n  \"message\": \"API opérationnelle. Authentification réussie.\",\n  \"api_user\": \"$apiUser\",\n  \"time\": \"2026-05-19T10:30:00+02:00\"\n}"); ?>
  </div>

  <!-- ═══ 3. Import Excel ═══ -->
  <div class="api-card" id="import">
    <h2 class="mt-0"><span class="endpoint-badge m-post">POST</span>3. Phase 1 — Importer un fichier Excel — <code>import</code></h2>
    <p>Fait <strong>exactement la même chose que l'import Excel du tableau de bord</strong> :
       lecture du fichier, correspondance des colonnes, <strong>gestion automatique des doublons</strong>,
       chiffrement des données personnelles, et — si demandé — envoi des mails d'inscription
       (avec QR Code selon la configuration du site).</p>

    <h3>Paramètres</h3>
    <table class="table table-sm api-params">
      <thead><tr><th>Nom</th><th>Type</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><code>file</code></td><td>fichier</td><td><strong>Obligatoire.</strong> Le fichier Excel (<code>.xlsx</code> ou <code>.xls</code>), envoyé en <code>multipart/form-data</code>.</td></tr>
        <tr><td><code>send_mails</code></td><td>0 / 1</td><td>Facultatif. Mettre <code>1</code> pour envoyer un mail de confirmation à chaque nouvel inscrit (équivaut à cocher la case du dashboard). Absent ou <code>0</code> = aucun mail.</td></tr>
      </tbody>
    </table>

    <h3>Exemple — sans envoi de mails (cURL)</h3>
    <?php codeBlock("curl -X POST \"$apiUrl?endpoint=import\" \\\n  -H \"X-Api-User: $apiUser\" \\\n  -H \"X-Api-Token: VOTRE_TOKEN\" \\\n  -F \"file=@inscrits.xlsx\""); ?>

    <h3>Exemple — avec envoi de mails</h3>
    <?php codeBlock("curl -X POST \"$apiUrl?endpoint=import\" \\\n  -H \"X-Api-User: $apiUser\" \\\n  -H \"X-Api-Token: VOTRE_TOKEN\" \\\n  -F \"file=@inscrits.xlsx\" \\\n  -F \"send_mails=1\""); ?>

    <h3>Réponse</h3>
    <?php codeBlock("{\n  \"ok\": true,\n  \"message\": \"Import terminé.\",\n  \"mails_demandes\": true,\n  \"added\": 42,        // inscrits ajoutés\n  \"skipped\": 5,       // lignes ignorées (doublons ou données manquantes)\n  \"duplicates\": 3,    // doublons détectés\n  \"mails_sent\": 42,   // mails envoyés\n  \"mail_errors\": 0\n}"); ?>
  </div>

  <!-- ═══ 4. Ajouter un inscrit ═══ -->
  <div class="api-card" id="add">
    <h2 class="mt-0"><span class="endpoint-badge m-post">POST</span>4. Phase 2 — Ajouter un inscrit — <code>registration</code></h2>
    <p>Ajoute un seul inscrit, <strong>comme le bouton « Nouvel inscrit » du dashboard</strong> :
       attribution automatique du numéro d'inscription, chiffrement des données, et — sauf
       indication contraire — envoi du mail de confirmation (avec QR Code selon la configuration).</p>
    <p>Le corps de la requête est au format <strong>JSON</strong>.</p>

    <h3>Champs</h3>
    <table class="table table-sm api-params">
      <thead><tr><th>Champ</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><code>nom</code></td><td><strong>Obligatoire.</strong> Nom de l'inscrit.</td></tr>
        <tr><td><code>prenom</code></td><td><strong>Obligatoire.</strong> Prénom de l'inscrit.</td></tr>
        <tr><td><code>email</code></td><td>Email — nécessaire pour recevoir le mail de confirmation.</td></tr>
        <tr><td><code>tel</code></td><td>Téléphone.</td></tr>
        <tr><td><code>naissance</code></td><td><strong>Âge</strong> (ex. <code>34</code>). Une année (<code>1990</code>) ou une date (<code>09/05/1990</code>) sont acceptées et automatiquement converties en âge.</td></tr>
        <tr><td><code>sexe</code></td><td><code>H</code>, <code>F</code> ou <code>Autre</code>.</td></tr>
        <tr><td><code>ville</code>, <code>entreprise</code></td><td>Informations complémentaires.</td></tr>
        <tr><td><code>tshirt_size</code></td><td><code>XS</code>, <code>S</code>, <code>M</code>, <code>L</code>, <code>XL</code>, <code>XXL</code> ou <code>-</code>.</td></tr>
        <tr><td><code>send_mails</code></td><td>Facultatif. <strong>Le mail de confirmation est envoyé par défaut</strong> (comme le bouton « Nouvel inscrit »). Mettez <code>send_mails=0</code> pour <strong>ne PAS</strong> envoyer de mail. Valeurs acceptées : <code>1</code>/<code>0</code>, <code>true</code>/<code>false</code>. (Le nom <code>send_mail</code> au singulier fonctionne aussi.)</td></tr>
      </tbody>
    </table>

    <div class="alert alert-info"><i class="bi bi-envelope me-2"></i>
      <strong>Envoi du mail :</strong> oui, par défaut l'ajout d'un inscrit déclenche
      automatiquement le mail de confirmation (avec QR Code selon la configuration), exactement
      comme le bouton du dashboard. Ajoutez <code>send_mails=0</code> dans le corps JSON ou dans
      l'URL pour le désactiver ponctuellement.
    </div>

    <h3>Exemple — AVEC envoi du mail (comportement par défaut)</h3>
    <?php codeBlock("curl -X POST \"$apiUrl?endpoint=registration\" \\\n  -H \"X-Api-User: $apiUser\" \\\n  -H \"X-Api-Token: VOTRE_TOKEN\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\n    \"nom\": \"Dupont\",\n    \"prenom\": \"Marie\",\n    \"email\": \"marie.dupont@example.com\",\n    \"tel\": \"0612345678\",\n    \"naissance\": \"1990\",\n    \"sexe\": \"F\",\n    \"ville\": \"Forbach\",\n    \"tshirt_size\": \"M\"\n  }'"); ?>

    <h3>Exemple — SANS envoi du mail (<code>send_mails=0</code>)</h3>
    <?php codeBlock("curl -X POST \"$apiUrl?endpoint=registration\" \\\n  -H \"X-Api-User: $apiUser\" \\\n  -H \"X-Api-Token: VOTRE_TOKEN\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\n    \"nom\": \"Dupont\",\n    \"prenom\": \"Marie\",\n    \"email\": \"marie.dupont@example.com\",\n    \"sexe\": \"F\",\n    \"send_mails\": 0\n  }'"); ?>

    <h3>Exemple (PHP)</h3>
    <?php codeBlock("<?php\n\$data = [\n  'nom'    => 'Dupont',\n  'prenom' => 'Marie',\n  'email'  => 'marie.dupont@example.com',\n  'sexe'   => 'F',\n];\n\n\$ch = curl_init('$apiUrl?endpoint=registration');\ncurl_setopt_array(\$ch, [\n  CURLOPT_RETURNTRANSFER => true,\n  CURLOPT_POST           => true,\n  CURLOPT_POSTFIELDS     => json_encode(\$data),\n  CURLOPT_HTTPHEADER     => [\n    'Content-Type: application/json',\n    'X-Api-User: $apiUser',\n    'X-Api-Token: VOTRE_TOKEN',\n  ],\n]);\n\$reponse = json_decode(curl_exec(\$ch), true);\ncurl_close(\$ch);\nprint_r(\$reponse);"); ?>

    <h3>Réponse</h3>
    <?php codeBlock("{\n  \"ok\": true,\n  \"message\": \"Inscrit ajouté avec succès.\",\n  \"mail_demande\": true,      // le mail a-t-il été demandé\n  \"inscription_no\": \"S123\",\n  \"mail_sent\": true,         // le mail a-t-il réellement été envoyé\n  \"qrcode_included\": true    // QR Code joint au mail (selon configuration)\n}"); ?>
  </div>

  <!-- ═══ 5. Rechercher un inscrit ═══ -->
  <div class="api-card" id="search">
    <h2 class="mt-0"><span class="endpoint-badge m-get">GET</span>5. Phase 3 — Rechercher un inscrit — <code>registration</code></h2>
    <p>Recherche un ou plusieurs inscrits à partir du <strong>nom</strong>, du <strong>prénom</strong>
       et/ou de l'<strong>email</strong>, et renvoie toutes leurs informations.</p>

    <h3>Paramètres (au moins un obligatoire)</h3>
    <table class="table table-sm api-params">
      <thead><tr><th>Nom</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><code>nom</code></td><td>Nom recherché.</td></tr>
        <tr><td><code>prenom</code></td><td>Prénom recherché.</td></tr>
        <tr><td><code>email</code></td><td>Email recherché.</td></tr>
        <tr><td><code>year</code></td><td>Facultatif — pour chercher dans une année archivée (ex. <code>2024</code>).</td></tr>
      </tbody>
    </table>

    <h3>Exemple — rechercher une personne (année en cours)</h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=registration&nom=Dupont&prenom=Marie\" \\\n  -H \"X-Api-User: $apiUser\" \\\n  -H \"X-Api-Token: VOTRE_TOKEN\""); ?>

    <h3>Exemple — rechercher une personne DANS une année archivée</h3>
    <p>Ajoutez simplement <code>&year=2024</code> pour chercher dans l'archive de l'année concernée
       (par email, nom ou prénom).</p>
    <?php codeBlock("curl \"$apiUrl?endpoint=registration&year=2024&email=marie.dupont@example.com\" \\\n  -H \"X-Api-User: $apiUser\" \\\n  -H \"X-Api-Token: VOTRE_TOKEN\""); ?>

    <h3>Réponse</h3>
    <?php codeBlock("{\n  \"ok\": true,\n  \"message\": \"1 inscrit(s) trouvé(s).\",\n  \"count\": 1,\n  \"results\": [\n    {\n      \"inscription_no\": \"S123\",\n      \"nom\": \"Dupont\", \"prenom\": \"Marie\",\n      \"email\": \"marie.dupont@example.com\",\n      \"tel\": \"0612345678\", \"naissance\": \"1990\",\n      \"sexe\": \"F\", \"ville\": \"Forbach\", \"tshirt_size\": \"M\"\n    }\n  ]\n}"); ?>
  </div>

  <!-- ═══ 6. Lister les inscrits ═══ -->
  <div class="api-card" id="list">
    <h2 class="mt-0"><span class="endpoint-badge m-get">GET</span>6. Phase 3 — Lister les inscrits — <code>registrations</code></h2>
    <p>Renvoie <strong>tous les inscrits</strong> de l'année en cours, ou d'une année archivée.</p>

    <h3>Paramètres</h3>
    <table class="table table-sm api-params">
      <thead><tr><th>Nom</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><code>year</code></td><td>Facultatif. Année archivée (ex. <code>2024</code>). Absent = année en cours.</td></tr>
      </tbody>
    </table>

    <h3>Filtres (tous facultatifs et cumulables)</h3>
    <p>Permettent d'extraire uniquement les inscrits correspondant à un ou plusieurs critères.
       Pour accepter plusieurs valeurs d'un même champ, séparez-les par une virgule
       (ex. <code>tshirt_size=M,L</code>). La comparaison est exacte et insensible à la casse.</p>
    <table class="table table-sm api-params">
      <thead><tr><th>Filtre</th><th>Exemple</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><code>tshirt_size</code></td><td><code>M</code></td><td>Taille de T-shirt (<code>XS</code>, <code>S</code>, <code>M</code>, <code>L</code>, <code>XL</code>, <code>XXL</code>, <code>-</code>).</td></tr>
        <tr><td><code>sexe</code></td><td><code>F</code></td><td>Sexe (<code>H</code>, <code>F</code> ou <code>Autre</code>).</td></tr>
        <tr><td><code>ville</code></td><td><code>Forbach</code></td><td>Ville de l'inscrit.</td></tr>
        <tr><td><code>entreprise</code></td><td>—</td><td>Entreprise de l'inscrit.</td></tr>
        <tr><td><code>origine</code></td><td><code>API</code></td><td>Origine de l'inscription.</td></tr>
        <tr><td><code>paiement_mode</code></td><td>—</td><td>Moyen de paiement.</td></tr>
        <tr><td><code>nom</code>, <code>prenom</code>, <code>email</code>, <code>tel</code>, <code>naissance</code>, <code>inscription_no</code></td><td>—</td><td>Autres champs filtrables.</td></tr>
      </tbody>
    </table>

    <h3>Exemple — année en cours</h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=registrations\" \\\n  -H \"X-Api-User: $apiUser\" -H \"X-Api-Token: VOTRE_TOKEN\""); ?>

    <h3>Exemple — tous les inscrits en T-shirt taille M</h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=registrations&tshirt_size=M\" \\\n  -H \"X-Api-User: $apiUser\" -H \"X-Api-Token: VOTRE_TOKEN\""); ?>

    <h3>Exemple — toutes les femmes (sexe F) en taille M ou L</h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=registrations&sexe=F&tshirt_size=M,L\" \\\n  -H \"X-Api-User: $apiUser\" -H \"X-Api-Token: VOTRE_TOKEN\""); ?>

    <h3>Exemple — année archivée 2024, hommes uniquement</h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=registrations&year=2024&sexe=H\" \\\n  -H \"X-Api-User: $apiUser\" -H \"X-Api-Token: VOTRE_TOKEN\""); ?>

    <h3>Réponse</h3>
    <?php codeBlock("{\n  \"ok\": true,\n  \"message\": \"24 inscrit(s) correspondant aux filtres.\",\n  \"year\": 2024,\n  \"archived\": true,\n  \"filters\": { \"sexe\": \"F\", \"tshirt_size\": \"M,L\" },\n  \"count\": 24,\n  \"results\": [ { \"inscription_no\": \"S1\", \"nom\": \"...\", ... }, ... ]\n}"); ?>
  </div>

  <!-- ═══ 7. Statistiques ═══ -->
  <div class="api-card" id="stats">
    <h2 class="mt-0"><span class="endpoint-badge m-get">GET</span>7. Phase 3 — Statistiques — <code>stats</code> &amp; <code>years</code></h2>

    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      <strong>À retenir :</strong> l'endpoint <code>stats</code> renvoie uniquement des
      <strong>chiffres agrégés</strong> (totaux, moyennes, comptage par taille) — il ne renvoie
      <strong>pas la liste des personnes</strong>.<br>
      Pour obtenir <strong>la liste des inscrits d'une année archivée</strong> ou
      <strong>filtrer par taille de T-shirt / sexe</strong>, utilisez
      <a href="#list"><code>registrations</code> (section 6)</a> avec <code>&amp;year=</code>.<br>
      Pour <strong>rechercher une personne précise</strong> dans une année archivée (par email,
      nom ou prénom), utilisez <a href="#search"><code>registration</code> (section 5)</a> avec
      <code>&amp;year=</code>.
    </div>

    <h3>Vue d'ensemble — <code>stats</code> (sans paramètre)</h3>
    <p>Renvoie l'année en cours, les moyennes globales (inscrits/an, âge moyen) et le détail
       de chaque année archivée.</p>
    <?php codeBlock("curl \"$apiUrl?endpoint=stats\" \\\n  -H \"X-Api-User: $apiUser\" -H \"X-Api-Token: VOTRE_TOKEN\""); ?>
    <?php codeBlock("{\n  \"ok\": true,\n  \"annee_en_cours\": { \"year\": 2026, \"total_inscrits\": 87, \"tshirt_recuperes\": 60 },\n  \"moyennes\": {\n    \"inscrits_par_an\": 305.4,\n    \"age_moyen_global\": 41.2,\n    \"annees_archivees\": 5\n  },\n  \"detail_par_annee\": [\n    { \"year\": 2024, \"total_inscrits\": 320, \"age_moyen\": 42.1,\n      \"ville_top\": \"Forbach\", \"entreprise_top\": \"...\",\n      \"plus_vieux_h\": \"...\", \"plus_vieille_f\": \"...\",\n      \"tshirt_xs\": 10, \"tshirt_s\": 80, ... }, ...\n  ]\n}"); ?>

    <h3>Une année archivée précise — <code>stats&amp;year=2024</code></h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=stats&year=2024\" \\\n  -H \"X-Api-User: $apiUser\" -H \"X-Api-Token: VOTRE_TOKEN\""); ?>
    <p>Renvoie la ligne complète de statistiques de l'année (total, tailles de T-shirt,
       âge moyen, ville et entreprise les plus représentées, doyens, etc.).</p>

    <h3>Années disponibles — <code>years</code></h3>
    <?php codeBlock("curl \"$apiUrl?endpoint=years\" \\\n  -H \"X-Api-User: $apiUser\" -H \"X-Api-Token: VOTRE_TOKEN\""); ?>
    <?php codeBlock("{ \"ok\": true, \"message\": \"5 année(s) archivée(s).\", \"years\": [2024, 2023, 2022, 2021, 2020] }"); ?>
  </div>

  <!-- ═══ 8. Codes d'erreur ═══ -->
  <div class="api-card" id="errors">
    <h2 class="mt-0">8. Codes d'erreur</h2>
    <p>En cas de problème, la réponse contient <code>"ok": false</code>, un code <code>error</code>
       et un <code>message</code> explicatif. Le code HTTP indique la nature du souci :</p>
    <table class="table table-sm api-params">
      <thead><tr><th>HTTP</th><th>error</th><th>Signification</th></tr></thead>
      <tbody>
        <tr><td>401</td><td><code>unauthorized</code></td><td>Identifiant ou token manquant / invalide.</td></tr>
        <tr><td>403</td><td><code>https_required</code></td><td>Connexion non sécurisée : l'API exige le HTTPS.</td></tr>
        <tr><td>403</td><td><code>api_disabled</code></td><td>L'API est désactivée dans les réglages.</td></tr>
        <tr><td>400</td><td><code>missing_endpoint</code> / <code>missing_criteria</code> / <code>missing_fields</code></td><td>Paramètre ou champ obligatoire absent.</td></tr>
        <tr><td>404</td><td><code>unknown_endpoint</code> / <code>not_found</code> / <code>year_not_found</code></td><td>Endpoint, inscrit ou année introuvable.</td></tr>
        <tr><td>405</td><td><code>method_not_allowed</code></td><td>Mauvaise méthode HTTP (GET au lieu de POST, etc.).</td></tr>
        <tr><td>422</td><td><code>invalid_format</code> / <code>missing_columns</code> / <code>import_error</code></td><td>Fichier Excel invalide ou colonnes manquantes.</td></tr>
        <tr><td>503</td><td><code>not_installed</code></td><td>Mise à jour BDD requise (exécuter update.php).</td></tr>
        <tr><td>500</td><td><code>server_error</code></td><td>Erreur interne du serveur.</td></tr>
      </tbody>
    </table>
    <p class="mb-0 text-muted"><i class="bi bi-clock-history me-1"></i>Tous les appels à l'API sont
       journalisés dans le fichier <code>storage/logs/api.log</code>, consultable et videable
       depuis la page <a href="logs.php?log=api">Journaux système</a> (onglet « API »).</p>
  </div>

  <!-- ═══ 9. Modèle de données (espace coureur / application mobile) ═══ -->
  <div class="api-card" id="modele">
    <h2 class="mt-0">9. Modèle de données — clé métier, fuseaux horaires, traces GPS</h2>

    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      <strong>Cette section documente le schéma, pas de nouveaux endpoints.</strong>
      L'API décrite ci-dessus (<code>api.php</code>) est <strong>inchangée</strong>, et la table
      <code>registrations</code> <strong>n'a pas été modifiée</strong> : ni colonne, ni index, ni
      contrainte. L'archivage annuel fonctionne exactement comme avant.
    </div>

    <h3>La clé métier <code>(annee, inscription_no)</code></h3>
    <p>Le site archive chaque année : la routine d'archivage crée
       <code>registrations_&lt;année&gt;</code>, y recopie les lignes, puis vide
       <code>registrations</code>. Les identifiants techniques <code>id</code> changent donc de
       table tous les ans — <strong>une clé étrangère vers <code>registrations.id</code> casserait
       à chaque archivage</strong>.</p>
    <p>Les tables de l'espace coureur désignent donc un coureur par le couple
       <strong><code>(annee, inscription_no)</code></strong> : « l'inscrit n°142 de l'édition 2026 »
       désigne la même personne, que sa ligne vive dans <code>registrations</code> ou dans
       <code>registrations_2026</code>.</p>
    <table class="table table-sm api-params">
      <thead><tr><th>Table</th><th>Rôle</th><th>Clé</th></tr></thead>
      <tbody>
        <tr><td><code>editions</code></td><td>Configuration d'une édition : date, distance, heure et coordonnées de départ/arrivée, date limite des transferts</td><td><code>annee</code> (unique)</td></tr>
        <tr><td><code>participants</code></td><td>Comptes coureurs. <strong>Aucune colonne de mot de passe</strong> : connexion par code à 6 chiffres. Table strictement distincte de <code>users</code></td><td><code>email_hmac</code> (unique)</td></tr>
        <tr><td><code>participant_registrations</code></td><td>Le lien compte ↔ inscription. Son index unique garantit qu'<strong>une inscription appartient à un compte au maximum</strong></td><td><code>(annee, inscription_no)</code></td></tr>
        <tr><td><code>participant_auth_codes</code></td><td>Codes de connexion à 6 chiffres, hachés</td><td><code>email_hmac</code></td></tr>
        <tr><td><code>participant_devices</code></td><td>Appareils de confiance (navigateur / application)</td><td><code>token_hash</code> (unique)</td></tr>
        <tr><td><code>registration_transfers</code></td><td>Transferts d'inscription en double opt-in, limités à l'édition active</td><td><code>(annee, inscription_no)</code></td></tr>
        <tr><td><code>resultats</code></td><td>Chronométrage</td><td><code>(annee, inscription_no)</code></td></tr>
        <tr><td><code>traces_gps</code></td><td>Traces enregistrées par l'application</td><td><code>(annee, inscription_no)</code></td></tr>
        <tr><td><code>detections</code></td><td>Détections brutes (beacon, geofence, GPS)</td><td><code>(annee, inscription_no)</code></td></tr>
      </tbody>
    </table>

    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      <strong>Contrepartie assumée.</strong> MySQL ne peut pas garantir qu'un résultat pointe vers
      une inscription existante : il n'y a aucune clé étrangère vers <code>registrations</code>.
      C'est le rôle de <code>update.php?tool=check-integrity</code>, qui liste en lecture seule les
      références orphelines, les inscriptions sans numéro et la dérive de schéma entre archives.
    </div>

    <p>Toute résolution passe par <code>src/core/registrations_resolver.php</code> (fonctions
       <code>regres_*</code>), seule couche qui sait dans quelle table chercher. Les tables
       d'archive y sont en <strong>lecture seule</strong> : toute écriture d'inscription se fait
       exclusivement dans <code>registrations</code>.</p>
    <p>Deux précautions y sont prises. D'abord, jamais de <code>SELECT *</code> sur une archive :
       elles ont été créées par <code>CREATE TABLE … LIKE registrations</code> à des dates
       différentes et leurs colonnes divergent — l'aiguilleur renvoie <code>null</code> pour les
       colonnes absentes. Ensuite, la recherche par email <strong>déchiffre en PHP</strong> :
       <code>registrations.email</code> étant chiffré avec un vecteur d'initialisation aléatoire, un
       <code>WHERE LOWER(TRIM(email)) = …</code> comparerait le paramètre à du chiffré et ne
       renverrait jamais rien. Le coût reste négligeable, l'archivage gardant chaque table à une
       seule année.</p>

    <h3>Contrat de fuseau horaire</h3>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      <strong>Une erreur ici coûte deux heures de chrono.</strong> Le schéma historique est mixte et
      ne change pas : <code>registrations.created_at</code> est un <code>TIMESTAMP</code> (converti
      par MySQL), <code>registrations.date_inscription</code> un <code>DATETIME</code> (heure
      serveur, stockée telle quelle).
    </div>
    <p>Pour <strong>tout ce qui a été ajouté</strong>, le contrat est le suivant :</p>
    <ul>
      <li>les colonnes <code>DATETIME(3)</code> — <code>resultats.depart_at</code> et
          <code>arrivee_at</code>, <code>detections.detecte_at</code> et <code>recu_at</code>,
          <code>traces_gps.debut_at</code> et <code>fin_at</code> — sont stockées
          <strong>en UTC</strong>, sans exception ;</li>
      <li><code>editions.heure_depart</code> est également <strong>en UTC</strong> : c'est l'heure
          du coup de feu, donc la référence de tous les temps calculés. Renseignée en heure locale
          face à des arrivées en UTC, <strong>tous les chronos seraient faux de deux heures</strong>,
          silencieusement ;</li>
      <li>l'API n'accepte et ne renvoie que de l'<strong>ISO-8601 avec décalage explicite</strong>
          (<code>2027-07-04T09:32:15.123+02:00</code>), jamais une date nue ;</li>
      <li>la conversion vers <code>Europe/Paris</code> se fait <strong>uniquement à l'affichage</strong>.</li>
    </ul>

    <h3>Format de <code>traces_gps.points</code></h3>
    <p>Colonne <code>LONGBLOB</code> : le JSON des points est stocké <strong>compressé</strong> par
       <code>gzencode()</code>. À 1000 coureurs et ~3600 points chacun, le non-compressé
       représenterait plusieurs centaines de Mo et alourdirait les sauvegardes.</p>
    <?php codeBlock("// Écriture\n\$points = [\n  ['t' => '2027-07-04T09:32:15.123+02:00', 'lat' => 49.1889, 'lon' => 6.9004, 'alt' => 218, 'acc' => 5],\n  // …\n];\n\$blob = gzencode(json_encode(\$points), 9);\n\n// Lecture\n\$points = json_decode(gzdecode(\$blob), true);"); ?>
    <p><code>nb_points</code> conserve le nombre de points sans avoir à décompresser, et
       <code>purge_at</code> porte la date de suppression automatique (RGPD).</p>

    <h3>Résultats : <code>methode</code> et <code>precision_s</code></h3>
    <p>Tout résultat exposé porte <strong>obligatoirement</strong> sa méthode d'obtention
       (<code>beacon</code>, <code>gps_ligne</code>, <code>gps_extrapole</code>,
       <code>gps_distance</code>, <code>manuel</code>, <code>declaratif</code>) et sa précision en
       secondes. Un temps issu d'une extrapolation GPS ne doit <strong>jamais</strong> être présenté
       comme équivalent à un temps beacon.</p>
    <p>La table <code>detections</code> conserve <strong>toutes</strong> les détections brutes ;
       la colonne <code>retenue</code> marque celle qui a produit le résultat. On n'en supprime
       jamais.</p>
    <p><code>resultats.valide_par</code> référence <code>users.id</code> : l'<strong>administrateur</strong>
       qui a validé ou corrigé un temps. C'est la seule référence vers <code>users</code> de tout ce
       modèle, et elle ne désigne jamais un coureur.</p>

    <h3>Adresse email d'un compte coureur</h3>
    <p>La table <code>participants</code> stocke l'adresse <strong>deux fois</strong>, et c'est
       nécessaire :</p>
    <ul>
      <li><code>email_hmac</code> — HMAC-SHA256 de l'adresse en minuscules. Déterministe, donc
          <strong>indexable et unique</strong> : c'est par lui qu'on retrouve un compte à la
          connexion. Un HMAC seul ne suffirait pas — irréversible, on ne pourrait plus envoyer le
          code à 6 chiffres.</li>
      <li><code>email_chiffre</code> — chiffré par le même mécanisme que
          <code>registrations.email</code> (AES-256-GCM, vecteur d'initialisation aléatoire) :
          il permet de retrouver l'adresse en clair au moment de l'envoi. Un chiffrement seul ne
          suffirait pas — l'IV aléatoire rend toute recherche par égalité impossible.</li>
    </ul>
    <p><code>participant_auth_codes</code> ne porte que <code>email_hmac</code> : cette table
       journalise les tentatives d'authentification, y compris pour des adresses ne correspondant à
       aucun compte, et ne doit contenir aucune adresse lisible.</p>
    <div class="alert alert-warning">
      <i class="bi bi-key me-2"></i>
      <strong>La clé HMAC (<code>EMAIL_HMAC_KEY</code>) vit dans <code>config/config.enc</code>,
      jamais en base.</strong> Un dump compromis livrerait sinon à la fois les empreintes et le
      moyen de les recalculer. Sauvegardez-la avec <code>ENCRYPTION_KEY</code> : sa perte rend les
      comptes coureurs introuvables. Une rotation invaliderait toutes les recherches par email et
      exigerait de recalculer chaque <code>email_hmac</code> depuis <code>email_chiffre</code>.
    </div>

    <h3>Âge et catégorie</h3>
    <p>Aucune migration de la colonne <code>naissance</code> n'a été faite : l'âge est calculé
       <strong>à la lecture</strong> par <code>regcore_ageFromNaissance()</code>, qui rapporte la
       valeur à l'année de l'édition de la ligne — l'âge d'une archive 2023 se calcule sur 2023 et
       non sur l'année courante.</p>
  </div>

  <!-- ═══ 10. API mobile ═══ -->
  <div class="api-card" id="mobile">
    <h2 class="mt-0">10. API mobile — <code>/api/v1</code></h2>

    <div class="alert alert-info"><i class="bi bi-phone me-2"></i>
      <strong>C'est une API différente de celle décrite ci-dessus.</strong>
      <code>api.php</code> parle au nom de l'<em>association</em> (un token global, un logiciel
      partenaire). <code>/api/v1</code> parle au nom d'<em>un coureur</em> : chaque requête n'accède
      qu'aux données de son compte. Les deux ne partagent aucun identifiant et ne se remplacent pas.
    </div>

    <p>Adresse de base : <span class="url-pill"><?= htmlspecialchars($baseUrl . $baseDir . '/api/v1', ENT_QUOTES, 'UTF-8') ?></span></p>
    <p>Toutes les réponses ont la même forme, succès comme erreur :</p>
    <?php codeBlock("{ \"ok\": true,  \"data\": { … }, \"error\": null }\n{ \"ok\": false, \"data\": null,  \"error\": { \"code\": \"invalid_code\", \"message\": \"Code incorrect.\" } }"); ?>

    <h3>Deux niveaux de jeton</h3>
    <table class="table table-sm api-params">
      <thead><tr><th>Jeton</th><th>Durée</th><th>Où il vit</th><th>À quoi il sert</th></tr></thead>
      <tbody>
        <tr>
          <td><strong>device_token</strong></td><td>illimitée</td>
          <td>trousseau du téléphone</td>
          <td>Obtenir un jeton d'accès. Ne circule qu'au renouvellement.</td>
        </tr>
        <tr>
          <td><strong>access_token</strong></td><td>1 h (réglable)</td>
          <td>mémoire de l'application</td>
          <td><code>Authorization: Bearer …</code> sur chaque appel.</td>
        </tr>
      </tbody>
    </table>
    <div class="alert alert-warning"><i class="bi bi-shield-lock me-2"></i>
      Le jeton d'accès est <strong>signé, pas stocké</strong> — aucune table de sessions à purger.
      Mais chaque appel <strong>revérifie en base</strong> que l'appareil n'a pas été révoqué : sans
      ce contrôle, une révocation depuis « Mes appareils » resterait sans effet pendant une heure.
      La révocation est donc immédiate, dans l'application comme sur le web.
    </div>

    <h3>Se connecter</h3>
    <?php codeBlock(
      "POST /api/v1/auth/request-code\n"
    . "{ \"email\": \"coureur@exemple.fr\" }\n"
    . "→ réponse IDENTIQUE que l'adresse existe ou non (anti-énumération)\n\n"
    . "POST /api/v1/auth/verify-code\n"
    . "{ \"email\": \"coureur@exemple.fr\", \"code\": \"123456\",\n"
    . "  \"device_info\": { \"libelle\": \"iPhone de Marie\", \"plateforme\": \"iOS 18\", \"modele\": \"iPhone 14\" } }\n"
    . "→ { \"device_token\": \"…\", \"access_token\": \"…\", \"expires_at\": \"…\", \"rgpd_consent_requis\": false }\n\n"
    . "POST /api/v1/auth/refresh\n"
    . "{ \"device_token\": \"…\" }   → nouveau access_token\n\n"
    . "POST /api/v1/auth/logout     (Bearer)  → révoque l'appareil courant"
    ); ?>

    <h3>Points d'entrée</h3>
    <table class="table table-sm api-params">
      <thead><tr><th>Méthode</th><th>Chemin</th><th>Rôle</th></tr></thead>
      <tbody>
        <tr><td><code>GET</code></td><td><code>/app/config</code></td><td><strong>Sans authentification.</strong> Version minimale exigée, liens des stores, textes. Une application trop ancienne doit pouvoir l'apprendre — et elle n'a justement pas de jeton valide.</td></tr>
        <tr><td><code>GET</code></td><td><code>/editions</code>, <code>/editions/{id}</code></td><td>Éditions : date, distance, heure de départ, départ/arrivée.</td></tr>
        <tr><td><code>GET</code></td><td><code>/me</code></td><td>Profil du compte.</td></tr>
        <tr><td><code>PATCH</code></td><td><code>/me</code></td><td>Nom et prénom. Répercutés sur l'inscription de l'édition en cours.</td></tr>
        <tr><td><code>POST</code></td><td><code>/me/email/request-change</code></td><td>Envoie un code à la <strong>nouvelle</strong> adresse.</td></tr>
        <tr><td><code>POST</code></td><td><code>/me/email/confirm</code></td><td>Vérifie le code et applique le changement.</td></tr>
        <tr><td><code>GET</code></td><td><code>/me/registrations</code></td><td>Toutes les inscriptions rattachées au compte.</td></tr>
        <tr><td><code>GET</code></td><td><code>/me/registrations/{annee}/{no}</code></td><td>Une inscription, par sa <strong>clé métier</strong>.</td></tr>
        <tr><td><code>PATCH</code></td><td><code>/me/registrations/{annee}/{no}</code></td><td>Sexe et âge. Refusé sur une édition archivée ou après le départ.</td></tr>
        <tr><td><code>GET</code></td><td><code>/me/registrations/{annee}/{no}/qrcode</code></td><td>QR code en PNG base64 — la même image que le mail.</td></tr>
        <tr><td><code>GET</code></td><td><code>/me/devices</code></td><td>Appareils de confiance actifs.</td></tr>
        <tr><td><code>DELETE</code></td><td><code>/me/devices/{id}</code></td><td>Révoque un appareil.</td></tr>
        <tr><td><code>GET</code></td><td><code>/me/transfers</code></td><td>Demandes de transfert émises.</td></tr>
        <tr><td><code>POST</code></td><td><code>/me/transfers</code></td><td>Nouvelle demande.</td></tr>
        <tr><td><code>DELETE</code></td><td><code>/me/transfers/{id}</code></td><td>Annule une demande en attente.</td></tr>
        <tr><td><code>GET</code></td><td><code>/me/results</code></td><td>Résultats. Liste vide tant que le chronométrage n'existe pas — le contrat est figé dès maintenant pour ne pas le casser plus tard.</td></tr>
      </tbody>
    </table>

    <h3>Ce que le coureur peut modifier lui-même</h3>
    <?php codeBlock(
      "PATCH /api/v1/me\n"
    . "{ \"nom\": \"Durand\", \"prenom\": \"Marie\" }\n\n"
    . "PATCH /api/v1/me/registrations/2026/FER-00123\n"
    . "{ \"sexe\": \"F\", \"age\": 34 }\n\n"
    . "POST  /api/v1/me/email/request-change   { \"email\": \"nouvelle@exemple.fr\" }\n"
    . "POST  /api/v1/me/email/confirm          { \"email\": \"nouvelle@exemple.fr\", \"code\": \"123456\" }"
    ); ?>
    <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>
      Ces règles vivent dans <code>src/auth/participant_profile.php</code>, <strong>partagé avec les
      pages web</strong>. L'API n'a pas sa propre copie des contrôles : sinon elle deviendrait le
      chemin qui les contourne. Concrètement : seules les <strong>éditions en cours</strong> sont
      modifiables (les tables <code>registrations_AAAA</code> restent en lecture seule), le sexe et
      l'âge se figent au départ de la course, et un changement d'adresse exige un code reçu sur la
      nouvelle boîte. Chaque modification est tracée dans
      <code>storage/logs/logs_espace_coureur.log</code> et dans les journaux de contenu.
    </div>
  </div>

  <!-- ═══ 11. Codes d'erreur de l'API mobile ═══ -->
  <div class="api-card" id="mobile-errors">
    <h2 class="mt-0">11. Codes d'erreur de l'API mobile</h2>
    <table class="table table-sm api-params">
      <thead><tr><th>HTTP</th><th>code</th><th>Signification</th></tr></thead>
      <tbody>
        <tr><td>400</td><td><code>invalid_json</code></td><td>Corps de requête illisible.</td></tr>
        <tr><td>401</td><td><code>missing_token</code> / <code>invalid_token</code></td><td>En-tête <code>Authorization</code> absent, signature invalide ou jeton expiré.</td></tr>
        <tr><td>401</td><td><code>device_revoked</code></td><td>L'appareil a été révoqué : il faut se reconnecter.</td></tr>
        <tr><td>401</td><td><code>invalid_code</code></td><td>Code à 6 chiffres faux, expiré ou inexistant.</td></tr>
        <tr><td>403</td><td><code>account_disabled</code></td><td>Compte désactivé par l'administration.</td></tr>
        <tr><td>403</td><td><code>forbidden</code></td><td>Ressource valide, mais qui n'appartient pas à ce compte.</td></tr>
        <tr><td>403</td><td><code>no_registration</code></td><td>Aucune inscription associée à cette adresse.</td></tr>
        <tr><td>404</td><td><code>not_found</code> / <code>unknown_endpoint</code></td><td>Ressource ou chemin inconnu.</td></tr>
        <tr><td>405</td><td><code>method_not_allowed</code></td><td>Mauvaise méthode HTTP sur un chemin valide.</td></tr>
        <tr><td>422</td><td><code>invalid_email</code> / <code>missing_fields</code> / <code>invalid_key</code> / <code>invalid_input</code></td><td>Données de la requête incorrectes.</td></tr>
        <tr><td>422</td><td><code>transfer_refused</code> / <code>email_change_refused</code></td><td>Demande valide en la forme, mais refusée par les règles métier (message explicite).</td></tr>
        <tr><td>429</td><td><code>rate_limited</code></td><td>Trop de demandes de code. Attendre quelques minutes.</td></tr>
      </tbody>
    </table>

    <div class="alert alert-secondary mb-0"><i class="bi bi-shield-check me-2"></i>
      <strong>Choix de sécurité assumés.</strong>
      Aucune en-tête <strong>CORS</strong> n'est émise : une application native n'en a pas besoin, et
      un <code>*</code> ouvrirait l'API à toutes les pages web du monde.
      <strong>Aucune donnée personnelle en paramètre d'URL</strong> — les adresses passent par le
      corps de la requête, car les URL sont journalisées par les serveurs et les proxys.
      Un <code>403 forbidden</code> plutôt qu'un <code>404</code> sur une inscription qui ne vous
      appartient pas : le message ne dit pas laquelle des deux raisons s'applique.
      Les appels à <code>/auth/*</code> sont journalisés dans
      <code>storage/logs/logs_api_mobile.log</code>, visible dans
      <a href="logs.php">Journaux système</a>.
    </div>
  </div>

</div>
<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
