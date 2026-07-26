<?php
require '../src/core/config.php';
require_once '../src/content/tracker.php';
trackPageVisit();
checkMaintenance();
require_once '../src/security/csrf.php';
require_once '../src/security/captcha.php';
require __DIR__ . '/../src/partials/navbar-data.php';

// Variables d'état
$hasGetParams = !empty($_GET);
$qrData = null;
$qrToken = $_GET['token'] ?? '';
$errorMessage = '';
$success_message = '';
$error_message = '';
$confirmation = null; // données de la page de confirmation (QR, membres) après succès

// Vérification du token QR si présent dans l'URL
if ($hasGetParams && $qrToken) {
    try {
        $stmt = $pdo->prepare(
            'SELECT organisation, description, is_active, onsite_mode, payment_label
             FROM qrcodes
             WHERE token = ? AND is_active = 1
               AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $stmt->execute([$qrToken]);
        $qrData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$qrData) {
            $errorMessage = 'QR Code invalide ou expiré.';
            $hasGetParams = false;
        }
    } catch (Exception $e) {
        $errorMessage = 'Erreur lors de la validation du QR Code.';
        $hasGetParams = false;
    }
} elseif ($hasGetParams && !$qrToken) {
    $errorMessage = 'Paramètres non autorisés. Accès refusé.';
    $hasGetParams = false;
}

// Traitement du formulaire si soumis
if ($_POST) {
    if (!csrf_verify()) {
        $error_message = 'Session expirée. Veuillez réessayer.';
    } else {
    $submittedToken = $_POST['qr_token'] ?? '';
    $validToken = false;

    if ($submittedToken) {
        try {
            $stmt = $pdo->prepare(
                'SELECT organisation, description, is_active, onsite_mode, payment_label, send_qrcode
                 FROM qrcodes
                 WHERE token = ? AND is_active = 1
                   AND (expires_at IS NULL OR expires_at > NOW())'
            );
            $stmt->execute([$submittedToken]);
            $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
            $validToken = (bool)$tokenData;
        } catch (Exception $e) {
            $validToken = false;
        }
    }

    // Refus si un token est présent (URL ou corps du POST) mais invalide/expiré :
    // un lien expiré (expires_at dépassé) ou désactivé ne doit jamais permettre
    // d'enregistrer une inscription, même via un POST forgé.
    if (($hasGetParams || $submittedToken !== '') && !$validToken) {
        $error_message = "Token invalide. Inscription refusée.";
    } elseif (!verifyPublicCaptcha($_POST, $pdo)) {
        // Même vérification anti-robot que le formulaire de contact (Turnstile ou
        // captcha maths de secours selon la configuration).
        $error_message = "Vérification anti-robot échouée. Recommencez.";
    } else {
        try {
            require_once '../src/content/form_fields.php';
            require_once '../src/content/registrations_core.php'; // regcore_naissanceToAge()
            $fieldCols = getAllActiveFieldColumns($pdo);
            $isOnsite  = !empty($tokenData['onsite_mode']);

            $stmtFee = $pdo->prepare('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1');
            $stmtFee->execute();
            $regFee = (float) ($stmtFee->fetchColumn() ?: 0);

            // Compteur atomique — existe-t-il ? (évite la race condition, CWE-362)
            $counterExists = false;
            try { $pdo->query('SELECT next_no FROM inscription_counter LIMIT 0'); $counterExists = true; }
            catch (PDOException $e) {}

            // Colonne group_id présente ? (migration update.php)
            $hasGroupIdCol = ((int) $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'group_id'"
            )->fetchColumn()) > 0;

            // Personnes supplémentaires (inscription groupée). Chaque bloc « extra[i][col] ».
            // On ignore les blocs totalement vides (ni nom ni prénom).
            $extras = [];
            if (isset($_POST['extra']) && is_array($_POST['extra'])) {
                foreach ($_POST['extra'] as $ex) {
                    if (!is_array($ex)) continue;
                    if (trim((string)($ex['nom'] ?? '')) === '' && trim((string)($ex['prenom'] ?? '')) === '') continue;
                    $extras[] = $ex;
                }
            }
            // 🔒 [SEC-DOS] Plafond serveur : le JS limite à 9 accompagnants, mais un POST
            // forgé pourrait en envoyer des milliers → autant d'INSERT en une requête.
            if (count($extras) > 9) $extras = array_slice($extras, 0, 9);
            $isGroup = count($extras) >= 1; // personne principale + ≥ 1 extra
            $groupId = ($isGroup && $hasGroupIdCol) ? bin2hex(random_bytes(12)) : null;

            // Insère UNE personne à partir d'une source de champs ($src) et renvoie
            // ['no','nom','prenom','email']. Réutilisé pour la personne principale et
            // chaque extra → aucune duplication de la logique d'insertion.
            $insertOne = function(array $src) use ($pdo, $fieldCols, $isOnsite, $tokenData, $regFee, $groupId, $counterExists) {
                if ($counterExists) {
                    $pdo->exec('UPDATE inscription_counter SET next_no = LAST_INSERT_ID(next_no + 1) WHERE id = 1');
                    $no = 'S' . (int)$pdo->lastInsertId();
                } else {
                    $m = $pdo->query("SELECT MAX(CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED)) FROM registrations")->fetchColumn();
                    $no = 'S' . (((int)$m) + 1);
                }

                $formData = ['inscription_no' => $no];
                $columns = ['inscription_no']; $placeholders = [':inscription_no'];

                foreach ($fieldCols as $col => $meta) {
                    if (!isset($src[$col])) continue; // champ non soumis → défaut colonne
                    $raw = $src[$col];
                    if ($col === 'commentaire') $raw = mb_substr((string) $raw, 0, 2000);
                    // On ne stocke QUE l'âge (année/âge → âge) pour le champ naissance.
                    if ($col === 'naissance') { $age = regcore_naissanceToAge((string) $raw); $raw = ($age !== null) ? $age : ''; }
                    // Colonnes ENUM : on assainit toute valeur vide/invalide vers une valeur
                    // valide, sinon MySQL renvoie « Data truncated » et l'inscription échoue.
                    if ($col === 'tshirt_size') { $raw = in_array($raw, ['-','XS','S','M','L','XL','XXL'], true) ? $raw : '-'; }
                    if ($col === 'sexe')        { $raw = in_array($raw, ['H','F','Autre'], true) ? $raw : 'Autre'; }
                    $formData[$col] = $meta['encrypted'] ? encrypt($raw) : $raw;
                    $columns[] = "`{$col}`"; $placeholders[] = ":{$col}";
                }

                if (!isset($formData['origine'])) {
                    $formData['origine'] = $_POST['origine'] ?? 'en ligne';
                    $columns[] = '`origine`'; $placeholders[] = ':origine';
                }

                if (!isset($formData['paiement_mode'])) {
                    if ($isOnsite) {
                        $pl = mb_substr(trim((string)($tokenData['payment_label'] ?? 'retrait t-shirt')), 0, 50);
                        $formData['paiement_mode'] = ($pl !== '') ? $pl : 'retrait t-shirt';
                    } else {
                        $formData['paiement_mode'] = $src['paiement_mode'] ?? ($_POST['paiement_mode'] ?? 'en ligne (CB)');
                    }
                    $columns[] = '`paiement_mode`'; $placeholders[] = ':paiement_mode';
                }

                // Montant + prestation calculés côté serveur (par personne).
                $allowedPresta = ['tarif_unique', 'enfant_gratuit', 'enfant_tshirt'];
                $postedPresta  = strtolower(trim((string) ($src['prestation'] ?? '')));
                if ($isOnsite && in_array($postedPresta, $allowedPresta, true)) {
                    $formData['prestation'] = $postedPresta;
                    $formData['montant_du'] = ($postedPresta === 'enfant_gratuit') ? 0.0 : $regFee;
                } else {
                    $formData['montant_du'] = (strcasecmp((string)($formData['paiement_mode'] ?? ''), 'gratuit') === 0) ? 0.0 : $regFee;
                    $pmPub = strtolower(trim((string)($formData['paiement_mode'] ?? '')));
                    $formData['prestation'] = ($pmPub === 'gratuit') ? 'enfant_gratuit'
                                            : (($pmPub === 'enfant_tshirt') ? 'enfant_tshirt' : 'tarif_unique');
                }
                $columns[] = '`montant_du`'; $placeholders[] = ':montant_du';
                $columns[] = '`prestation`'; $placeholders[] = ':prestation';

                if ($groupId !== null) { $formData['group_id'] = $groupId; $columns[] = '`group_id`'; $placeholders[] = ':group_id'; }

                $columns[] = 'created_at';       $placeholders[] = 'NOW()';
                $columns[] = 'date_inscription'; $placeholders[] = 'NOW()';

                $sql = 'INSERT INTO registrations (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $pdo->prepare($sql)->execute($formData);

                return [
                    'no'     => $no,
                    'nom'    => (string)($src['nom'] ?? ''),
                    'prenom' => (string)($src['prenom'] ?? ''),
                    'email'  => trim((string)($src['email'] ?? '')),
                ];
            };

            // Insertion : personne principale (champs à plat) puis extras.
            $created = [];
            $created[] = $insertOne($_POST);
            foreach ($extras as $ex) $created[] = $insertOne($ex);

            // ── Mail de confirmation ──
            $subject = 'Inscription enregistrée - Forbach en Rose';
            $GLOBALS['force_no_qrcode'] = (isset($tokenData['send_qrcode']) && (int)$tokenData['send_qrcode'] === 0);
            $recipient = $created[0]['email']; // l'e-mail de la personne principale = destinataire
            // 🔒 [SEC-MAIL] N'envoyer qu'à une adresse valide (défense en profondeur
            // contre l'injection d'en-têtes ; le sink googleMail nettoie déjà le CRLF).
            if ($recipient !== '' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) $recipient = '';
            // Config globale (qrcode_mail_mode / limite) requise par shouldIncludeQrCode :
            // sans elle, le mode retombe sur « none » → aucun QR groupé ne serait jamais joint.
            $data = $pdo->query('SELECT * FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            try {
                require_once '../src/mail/googleMail.php';
                if ($isGroup) {
                    // Récap groupé + QR groupé (selon config), même logique que l'ajout multiple.
                    $memberNos = array_column($created, 'no');
                    $qrOverride = (function_exists('shouldIncludeGroupQr') && $groupId !== null && shouldIncludeGroupQr($memberNos))
                        ? ('G:' . $groupId) : null;
                    $rowsHtml = '';
                    foreach ($created as $c) {
                        $rowsHtml .= '<tr>'
                                   . '<td style="padding:8px;border:1px solid #fbcfe8;font-family:monospace;">' . htmlspecialchars($c['no']) . '</td>'
                                   . '<td style="padding:8px;border:1px solid #fbcfe8;">' . htmlspecialchars($c['nom']) . '</td>'
                                   . '<td style="padding:8px;border:1px solid #fbcfe8;">' . htmlspecialchars($c['prenom']) . '</td></tr>';
                    }
                    $qrNote = $qrOverride
                        ? 'Le jour de la course, <strong>présentez le QR code ci-dessous</strong> pour récupérer les dossards et t-shirts du groupe (il liste tous les inscrits).'
                        : 'Le jour de la course, <strong>présentez ce mail à l\'accueil</strong>.';
                    // Même tableau que l'aperçu « Récap groupé » (mail-settings) : pleine largeur, N° / Nom / Prénom.
                    $desc = '<p style="font-size:15px;line-height:1.6;color:#334155;">Bonjour,<br><br>'
                          . 'Nous confirmons l\'inscription des <strong>' . count($created) . '</strong> personne(s) ci-dessous.<br><br>'
                          . $qrNote . '</p>'
                          . '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:14px;"><thead><tr style="background:#fdf2f6;">'
                          . '<th style="padding:8px;border:1px solid #fbcfe8;text-align:left;">N° inscription</th>'
                          . '<th style="padding:8px;border:1px solid #fbcfe8;text-align:left;">Nom</th>'
                          . '<th style="padding:8px;border:1px solid #fbcfe8;text-align:left;">Prénom</th></tr></thead><tbody>'
                          . $rowsHtml . '</tbody></table>';
                    if ($recipient !== '') {
                        sendMail($recipient, 'Inscriptions enregistrées - Forbach en Rose', 'Inscriptions Forbach en Rose',
                            $desc, null, null, 'info', null, 'bulk_recap', [], $qrOverride);
                    }
                } elseif ($recipient !== '') {
                    sendMail($recipient, $subject, null, null, $created[0]['nom'], $created[0]['prenom'], 'inscription', $created[0]['no']);
                }
            } catch (\Throwable $e) { /* l'échec du mail ne bloque jamais l'inscription */ }

            // ── Données de confirmation (page + persistance navigateur) ──
            // Le QR affiché à l'écran est TOUJOURS présent (preuve à conserver / présenter) :
            // groupe → « G:<group_id> », sinon le numéro d'inscription du billet.
            $success_message = "Inscription enregistrée avec succès !";
            $confirmation = [
                'group'   => $isGroup,
                'qr'      => $isGroup ? ('G:' . $groupId) : $created[0]['no'],
                'members' => array_map(function($c){ return ['no' => $c['no'], 'nom' => $c['nom'], 'prenom' => $c['prenom']]; }, $created),
                'org'     => (string)($qrData['organisation'] ?? ($tokenData['organisation'] ?? '')),
                'mailed'  => ($recipient !== ''),
            ];

        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $error_message = "Erreur lors de l'enregistrement. Veuillez réessayer.";
        }
    }
  } // end csrf_verify else
}

// Récupération des paramètres de configuration
try {
    $stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $data = [];
}

$assoconnectJs      = $data['assoconnect_js']     ?? null;
$assoconnectIframe  = $data['assoconnect_iframe'] ?? null;
$assoconnectUrl     = $data['assoconnect_url']    ?? '';
$title  = $data['title']   ?? '';
$registration_fee = $data['registration_fee'] ?? 0;
$childAge = (int) ($data['child_age_threshold'] ?? 12); // libellé « -N ans »
$course_km = $data['course_km'] ?? 7;
$accueil_active = !empty($data['accueil_active']) ? 1 : 0;

// Ouverture / fermeture automatique des inscriptions
$tz = new DateTimeZone('Europe/Paris');
$now = new DateTime('now', $tz);
$autoOpen  = !empty($data['registration_auto_open'])  ? new DateTime($data['registration_auto_open'], $tz)  : null;
$autoClose = !empty($data['registration_auto_close']) ? new DateTime($data['registration_auto_close'], $tz) : null;

if ($autoOpen && $now >= $autoOpen) {
    $accueil_active = 1;
}
if ($autoClose && $now >= $autoClose) {
    $accueil_active = 0;
}
// Découplage QR ↔ inscriptions en ligne : un QR valide et non expiré (sa propre
// date de fermeture est gérée par expires_at dans la requête ci-dessus) force
// l'ouverture du formulaire, même quand les inscriptions en ligne sont fermées.
if (!empty($qrData)) {
    $accueil_active = 1;
}
$div_reglementation = $data['div_reglementation'] ?? '';
$registration_closed_message = trim((string) ($data['registration_closed_message'] ?? ''));

// Formulaire dynamique
require_once '../src/content/form_fields.php';
try {
    $formFields = getActiveFields($pdo, 'qr');
} catch (PDOException $e) {
    error_log('[REGISTER] ' . $e->getMessage());
    $formFields = [];
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inscription</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
      rel="stylesheet"
      integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
      crossorigin="anonymous">
<link rel="stylesheet" href="../css/fer-modern.css">
<link rel="stylesheet" href="../css/register.css?v=<?= @filemtime(__DIR__ . '/../css/register.css') ?: time() ?>">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
</head>

<body>
  <?php include __DIR__ . '/../src/partials/preloader.php'; ?>

<div class="register-title-bar">
  <a href="accueil" title="Retour" class="back-btn">
    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3.3 11.3l6.8-6.8c.4-.4.4-1 0-1.4s-1-.4-1.4 0l-7.8 7.8c-.4.4-.4 1 0 1.4l7.8 7.8c.2.2.5.3.7.3s.5-.1.7-.3c.4-.4.4-1 0-1.4L3.3 12.7H22c.6 0 1-.4 1-1s-.4-1-1-1H3.3z"/></svg>
  </a>
  <div class="register-title-info">
    <h1>Inscription</h1>
    <span class="register-subtitle"><?= (int)$course_km ?> km course et marche solidaire contre le cancer</span>
  </div>
  <?php if (!empty($registration_fee)): ?>
    <span class="register-donation-badge"><?= htmlspecialchars($registration_fee) ?> € intégralement reversés</span>
  <?php endif; ?>
</div>

<?php if ($accueil_active === 0): ?>
  <main class="container-fluid px-0 flex-grow-1 d-flex justify-content-center">
    <div class="card card-form p-4 bg-white">
      <div class="p-4 w-100" role="alert" style="margin-top:5%; font-size: 1.2rem; background-color: #ffe1f0; color: #e03f8a; border-radius: var(--radius-lg);">
        🚫 Les inscriptions sont actuellement fermées. Merci de votre compréhension.
      </div>
      <?php if ($registration_closed_message !== ''): ?>
        <div class="registration-closed-message" style="margin-top:20px;">
          <?= sanitizeHtml($registration_closed_message) ?>
        </div>
      <?php endif; ?>
      <?php if ($autoOpen && $now < $autoOpen): ?>
        <p style="text-align:center; margin-top:20px; font-size:1rem; color:#b5366b;">
          📅 Les inscriptions ouvriront le <strong><?= $autoOpen->format('d/m/Y') ?></strong> à <strong><?= $autoOpen->format('H\hi') ?></strong>.
        </p>
      <?php endif; ?>
    </div>
  </main>
<?php else: ?>

<main class="container-fluid px-0 flex-grow-1 d-flex justify-content-center">
  <div class="card card-form p-4 bg-white">
    <div class="register-page-title text-center mb-3" style="font-size:clamp(24px,4vw,42px);font-weight:900;"><?= $title ?></div>

    <?php if ($errorMessage): ?>
      <div class="alert alert-danger text-center mb-4">
        <?= htmlspecialchars($errorMessage) ?>
      </div>
      <div class="text-center">
        <a href="?" class="btn-action-primary">Retour à l'accueil</a>
      </div>
    <?php elseif ($hasGetParams && $qrData): ?>
<?php // Le message de succès est désormais affiché en toast (voir src/partials/toast.php). ?>

      <?php if ($error_message): ?>
        <div class="alert alert-danger text-center mb-4">
          <?= htmlspecialchars($error_message) ?>
        </div>
      <?php endif; ?>

      <div id="regHeader">
        <h2 class="text-center mb-4">Inscription via QR Code</h2>

        <?php if ($qrData['organisation']): ?>
          <div class="text-center mb-4">
            <strong>Lieu d'inscription :</strong> <?= htmlspecialchars($qrData['organisation']) ?>
            <?php if ($qrData['description']): ?>
              <br><small><?= htmlspecialchars($qrData['description']) ?></small>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <form id="fPub" class="row g-3 needs-validation" method="POST" action="" novalidate>
        <?= csrf_field() ?>
        <?php foreach ($formFields as $f): ?>
          <?= renderFormField($f, '', false, 'qr') ?>
        <?php endforeach; ?>

        <input type="hidden" name="qr_token" value="<?= htmlspecialchars($qrToken) ?>">
        <input type="hidden" name="origine" value="QR-<?= htmlspecialchars($qrData['organisation']) ?>">

        <?php
          // Montant formaté (ex. « 12 € ») réutilisé par l'affichage et le script.
          $onFee      = (float) $registration_fee;
          $onFeeLabel = rtrim(rtrim(number_format($onFee, 2, '.', ''), '0'), '.') . ' €';
        ?>
        <?php if (!empty($qrData['onsite_mode'])): ?>
          <?php // Mode « inscription sur place » : choix de la prestation (paiement masqué, imposé côté serveur). ?>
          <div class="col-md-12">
            <label class="form-label">Prestation <span style="color:#ef4444">*</span></label>
            <select name="prestation" id="prestation_public" class="form-select" required>
              <option value="tarif_unique">Tarif unique — <?= htmlspecialchars($onFeeLabel) ?></option>
              <option value="enfant_gratuit">Enfant −<?= (int) $childAge ?> ans — Gratuit</option>
              <option value="enfant_tshirt">Enfant −<?= (int) $childAge ?> ans avec T-shirt — <?= htmlspecialchars($onFeeLabel) ?></option>
            </select>
            <div id="montantDuPublic" class="mt-2" style="font-size:14px;font-weight:600;color:#1e293b">
              Montant total dû : <span style="color:var(--primary, #f42182)"><?= htmlspecialchars($onFeeLabel) ?></span>
            </div>
          </div>
        <?php else: ?>
          <div class="col-md-12">
            <label class="form-label">Paiement <span style="color:#ef4444">*</span></label>
            <select name="paiement_mode" id="paiement_mode_public" class="form-select" required>
              <option value="En ligne" selected>En ligne (CB)</option>
              <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
            </select>
            <div id="montantDuPublic" class="mt-2" style="font-size:14px;font-weight:600;color:#1e293b">
              Montant total dû : <span style="color:var(--primary, #f42182)"><?= htmlspecialchars((string) $registration_fee) ?> €</span>
            </div>
          </div>
        <?php endif; ?>

        <!-- ── Inscription groupée : personnes supplémentaires (e-mail commun ci-dessus) ── -->
        <div class="col-12" id="extraPeople"></div>
        <div class="col-12">
          <button type="button" id="btnAddPerson" class="btn btn-outline-secondary btn-sm">+ Ajouter une personne</button>
          <div class="form-text" style="font-size:12px">Inscrivez plusieurs personnes d'un coup — la confirmation (et le QR groupé) est envoyée à l'e-mail renseigné ci-dessus.</div>
        </div>
        <?php
          // Template d'un bloc « personne supplémentaire » : mêmes champs que le
          // formulaire (sauf e-mail = destinataire commun), avec des noms préfixés
          // extra[__IDX__][…] et non obligatoires. Le bloc « autorisation parentale »
          // (guardian) est inclus : il s'affiche par carte si la personne est mineure.
          $extraFieldsHtml = '';
          foreach ($formFields as $ef) {
              $ebc = $ef['bdd_column'] ?? '';
              if ($ebc === 'email') continue;
              $extraFieldsHtml .= renderFormField($ef, '', false, 'qr');
          }
          if (!empty($qrData['onsite_mode'])) {
              $extraFieldsHtml .= '<div class="col-md-6"><label class="form-label">Prestation</label>'
                . '<select name="prestation" class="form-select">'
                . '<option value="tarif_unique">Tarif unique — ' . htmlspecialchars($onFeeLabel) . '</option>'
                . '<option value="enfant_gratuit">Enfant −' . (int)$childAge . ' ans — Gratuit</option>'
                . '<option value="enfant_tshirt">Enfant −' . (int)$childAge . ' ans avec T-shirt — ' . htmlspecialchars($onFeeLabel) . '</option>'
                . '</select></div>';
          }
          $extraFieldsHtml = preg_replace('/\bname="([a-zA-Z0-9_]+)"/', 'name="extra[__IDX__][$1]"', $extraFieldsHtml);
          $extraFieldsHtml = preg_replace('/\s+required(=("|\')required("|\'))?/', '', $extraFieldsHtml);
        ?>
        <template id="extraPersonTpl">
          <div class="extra-person p-3 mb-2" style="background:#faf7f9;border:1px solid #f0e0ea;border-radius:12px;position:relative">
            <button type="button" class="btn btn-sm btn-outline-danger extra-remove" style="position:absolute;top:8px;right:8px;line-height:1;padding:2px 8px">&times;</button>
            <div class="fw-semibold mb-2 extra-title" style="color:#9d174d">Personne supplémentaire</div>
            <div class="row g-3"><?= $extraFieldsHtml ?></div>
          </div>
        </template>

        <!-- Vérification anti-robot (Turnstile ou maths selon config) — identique au formulaire de contact -->
        <div class="col-12" id="regCaptcha">
          <div id="regTurnstileBox" style="display:none;"></div>
          <div id="regMathBox" style="display:none;">
            <div id="regCaptchaQuestion" class="reg-captcha-question">Chargement…</div>
            <div class="reg-captcha-row">
              <input id="regCaptchaAnswer" type="text" inputmode="numeric" autocomplete="off" placeholder="Votre réponse" class="reg-captcha-input">
              <button type="button" id="regCaptchaReload" class="reg-captcha-reload" title="Nouvelle question">↻</button>
            </div>
          </div>
          <div id="regCaptchaError" class="reg-captcha-error"></div>
          <input type="hidden" name="turnstile_token" id="regTurnstileToken">
          <input type="hidden" name="captcha_token"   id="regCaptchaToken">
          <input type="hidden" name="captcha_answer"  id="regCaptchaAnswerHidden">
        </div>

        <div class="col-12 d-grid">
          <button class="btn-action-primary btn-action-lg" type="submit" id="regSubmitBtn" disabled>
            Valider l'inscription
          </button>
        </div>
      </form>

      <!-- ═══ Page de confirmation (QR + membres) ═══
           Affichée après une inscription, OU au retour sur la page si une confirmation
           est mémorisée dans le navigateur (localStorage). -->
      <div id="regConfirm" style="display:none">
        <div class="text-center">
          <div class="mb-2" style="font-size:2.6rem;line-height:1;color:#16a34a">✓</div>
          <h2 class="mb-1">Inscription confirmée</h2>
          <p class="text-muted" id="regConfirmSub"></p>
          <div id="regConfirmMembers" class="mb-3"></div>
          <div class="p-3 d-inline-block" style="background:#fff;border:2px dashed var(--primary,#f42182);border-radius:16px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary,#f42182)">À présenter pour le retrait du t-shirt</div>
            <canvas id="regQrCanvas" width="220" height="220" style="display:block;margin:8px auto"></canvas>
          </div>
          <div class="d-grid gap-2 mt-4" style="max-width:340px;margin:0 auto">
            <button type="button" id="regSaveImg" class="btn-action-primary">Enregistrer l'image</button>
            <button type="button" id="regNew" class="btn btn-outline-secondary">Nouvelle inscription</button>
          </div>
          <p class="text-muted mt-3" id="regMailedNote" style="font-size:12px;display:none">Un e-mail de confirmation vous a également été envoyé.</p>
        </div>
      </div>

      <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      (function(){
        /* ── Personnes supplémentaires (inscription groupée) ── */
        var tpl = document.getElementById('extraPersonTpl');
        var box = document.getElementById('extraPeople');
        var addBtn = document.getElementById('btnAddPerson');
        var MAX_EXTRA = 9; // + la personne principale = 10 max
        var idx = 0;
        if (tpl && box && addBtn) {
          addBtn.addEventListener('click', function(){
            if (box.querySelectorAll('.extra-person').length >= MAX_EXTRA) { addBtn.disabled = true; return; }
            var html = tpl.innerHTML.replace(/__IDX__/g, String(idx++));
            var wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            var node = wrap.firstElementChild;
            box.appendChild(node);
            // Câble l'autorisation parentale de cette carte (affichage selon l'âge saisi).
            if (window.FERInscription) FERInscription.initForm(node);
            if (box.querySelectorAll('.extra-person').length >= MAX_EXTRA) addBtn.disabled = true;
          });
          box.addEventListener('click', function(e){
            var rm = e.target.closest('.extra-remove');
            if (!rm) return;
            var p = rm.closest('.extra-person');
            if (p) p.remove();
            addBtn.disabled = false;
          });
        }

        /* ── Page de confirmation + persistance navigateur ── */
        var KEY = 'fer_last_confirm';
        var serverConf = <?= $confirmation ? json_encode($confirmation) : 'null' ?>;
        var formEl = document.getElementById('fPub');
        var confEl = document.getElementById('regConfirm');
        if (serverConf) { try { localStorage.setItem(KEY, JSON.stringify({conf:serverConf, mailed:!!serverConf.mailed})); } catch(e){} }
        var saved = null;
        try { saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch(e){}
        if (!confEl || !saved || !saved.conf || !saved.conf.qr) return; // pas de confirmation → formulaire

        var conf = saved.conf, mem = conf.members || [];
        if (formEl) formEl.style.display = 'none';
        var hdrEl = document.getElementById('regHeader');
        if (hdrEl) hdrEl.style.display = 'none'; // masque « Inscription via QR Code / Lieu… » sur la confirmation
        confEl.style.display = 'block';
        function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
        var sub = document.getElementById('regConfirmSub');
        var mbox = document.getElementById('regConfirmMembers');
        if (conf.group) {
          sub.textContent = mem.length + ' inscription(s) — QR code du groupe.';
          mbox.innerHTML = mem.map(function(m){ return '<div><strong>'+esc((m.prenom||'')+' '+(m.nom||''))+'</strong> <span class="text-muted">N°'+esc(m.no)+'</span></div>'; }).join('');
        } else if (mem[0]) {
          sub.textContent = 'Conservez votre QR code.';
          mbox.innerHTML = '<div class="fs-5 fw-bold">'+esc((mem[0].prenom||'')+' '+(mem[0].nom||''))+'</div><div class="text-muted">N°'+esc(mem[0].no)+'</div>';
        }
        if (saved.mailed) document.getElementById('regMailedNote').style.display = 'block';

        var qrCanvas = document.getElementById('regQrCanvas');
        try { new QRious({ element: qrCanvas, value: conf.qr, size: 220, level: 'M' }); } catch(e){}

        document.getElementById('regNew').addEventListener('click', function(){
          try { localStorage.removeItem(KEY); } catch(e){}
          window.location = window.location.pathname + window.location.search;
        });

        // « Enregistrer l'image » : compose une carte propre (titre / nom / n° / QR) en PNG.
        document.getElementById('regSaveImg').addEventListener('click', function(){
          var W=420, H=conf.group ? 620 : 540, c=document.createElement('canvas'); c.width=W; c.height=H;
          var ctx=c.getContext('2d');
          ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,W,H);
          ctx.fillStyle='#f42182'; ctx.fillRect(0,0,W,68);
          ctx.fillStyle='#ffffff'; ctx.textAlign='center'; ctx.font='bold 24px sans-serif';
          ctx.fillText('Forbach en Rose', W/2, 43);
          ctx.fillStyle='#0f172a'; ctx.font='bold 20px sans-serif';
          var title = conf.group ? ('Groupe — '+mem.length+' inscrits')
                                 : (mem[0] ? ((mem[0].prenom||'')+' '+(mem[0].nom||'')).trim() : '');
          ctx.fillText(title, W/2, 108);
          if (!conf.group && mem[0]) { ctx.fillStyle='#64748b'; ctx.font='14px sans-serif'; ctx.fillText('N° '+mem[0].no, W/2, 132); }
          var qs=250; ctx.drawImage(qrCanvas, (W-qs)/2, 150, qs, qs);
          ctx.fillStyle='#f42182'; ctx.font='bold 12px sans-serif';
          ctx.fillText('À PRÉSENTER POUR LE RETRAIT DU T-SHIRT', W/2, 428);
          if (conf.group) { ctx.fillStyle='#334155'; ctx.font='12px sans-serif'; var y=456;
            mem.slice(0,8).forEach(function(m){ ctx.fillText(((m.prenom||'')+' '+(m.nom||'')).trim()+' — '+m.no, W/2, y); y+=17; });
          }
          var a=document.createElement('a'); a.href=c.toDataURL('image/png');
          a.download='inscription-forbach-en-rose.png'; a.click();
        });
      })();
      </script>
      <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
        .reg-captcha-question { font-size:1.05rem; font-weight:700; color:#1e293b; margin-bottom:.5rem; }
        .reg-captcha-row { display:flex; gap:.5rem; align-items:stretch; }
        .reg-captcha-input { flex:1; padding:.55rem .75rem; border:1px solid #cbd5e1; border-radius:.55rem; font-size:1rem; outline:none; background:#fff; }
        .reg-captcha-input:focus { border-color:var(--primary, #f42182); box-shadow:0 0 0 3px rgba(219,39,119,.15); }
        .reg-captcha-reload { background:#e2e8f0; border:none; border-radius:.55rem; padding:0 .9rem; font-size:1rem; font-weight:600; color:#334155; cursor:pointer; }
        .reg-captcha-reload:hover { background:#cbd5e1; }
        .reg-captcha-error { color:#b91c1c; font-size:.82rem; min-height:1.1em; margin-top:.4rem; }
        #regSubmitBtn:disabled { opacity:.55; cursor:not-allowed; filter:grayscale(.4); }
        /* Bouton « supprimer une personne » (croix) : contraste garanti au survol
           (un thème global rendait le fond ET le texte blancs → croix invisible). */
        .extra-remove { color:#dc3545 !important; background:#fff !important; border-color:#dc3545 !important; }
        .extra-remove:hover, .extra-remove:focus { color:#fff !important; background:#dc3545 !important; border-color:#dc3545 !important; }
        /* Bouton « Nouvelle inscription » : contraste garanti au survol
           (un thème global rendait le fond ET le texte blancs → bouton invisible). */
        #regNew, #btnAddPerson { color:#475569 !important; background:#fff !important; border-color:#cbd5e1 !important; }
        #regNew:hover, #regNew:focus,
        #btnAddPerson:hover, #btnAddPerson:focus { color:#475569 !important; background:#f1f5f9 !important; border-color:#94a3b8 !important; }
        /* L'âge se calcule en arrière-plan à l'enregistrement : on n'affiche pas l'indice « → âge X ans ». */
        .birthdate-hint { display:none !important; }
      </style>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      /* Vérification anti-robot du formulaire d'inscription QR (même mécanisme que contact.php) */
      (function () {
        var form      = document.getElementById('fPub');
        if (!form) return;
        var submitBtn = document.getElementById('regSubmitBtn');
        var tsBox     = document.getElementById('regTurnstileBox');
        var mathBox   = document.getElementById('regMathBox');
        var qEl       = document.getElementById('regCaptchaQuestion');
        var aEl       = document.getElementById('regCaptchaAnswer');
        var aHidden   = document.getElementById('regCaptchaAnswerHidden');
        var tokHidden = document.getElementById('regCaptchaToken');
        var tsHidden  = document.getElementById('regTurnstileToken');
        var errEl     = document.getElementById('regCaptchaError');
        var reloadBtn = document.getElementById('regCaptchaReload');

        var mode = null, tsWidgetId = null, tsLoading = null, didFallback = false;

        function setError(msg) { errEl.textContent = msg || ''; }
        function setValid(ok)  { submitBtn.disabled = !ok; }

        function ensureTurnstileScript() {
          if (window.turnstile) return Promise.resolve();
          if (tsLoading) return tsLoading;
          tsLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            s.async = true; s.defer = true;
            s.onload  = function () { resolve(); };
            s.onerror = function () { tsLoading = null; reject(new Error('load')); };
            document.head.appendChild(s);
          });
          return tsLoading;
        }

        function switchToMathFallback(reason) {
          if (didFallback) { setError(reason || 'Échec du captcha. Réessayez.'); return; }
          didFallback = true;
          setError('Vérification indisponible — bascule sur un captcha de secours…');
          if (tsWidgetId !== null && window.turnstile) {
            try { window.turnstile.remove(tsWidgetId); } catch (e) {}
            tsWidgetId = null;
          }
          fetch('../admin-api.php?route=partner-captcha-init&fallback=1', { method: 'GET' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j || !j.ok || j.mode !== 'math') throw new Error('fallback');
              mode = 'math';
              tsBox.style.display = 'none';
              mathBox.style.display = 'block';
              tokHidden.value = j.token; tsHidden.value = '';
              qEl.textContent = j.question; aEl.value = ''; setError('');
            })
            .catch(function () { setError('Impossible d\'afficher le captcha de secours. Réessayez plus tard.'); });
        }

        function initCaptcha() {
          setError(''); setValid(false); didFallback = false;
          tsHidden.value = ''; tokHidden.value = ''; aHidden.value = '';
          fetch('../admin-api.php?route=partner-captcha-init', { method: 'GET' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j || !j.ok) throw new Error('init');
              mode = j.mode;
              if (mode === 'turnstile') {
                tsBox.style.display = 'block'; mathBox.style.display = 'none';
                ensureTurnstileScript().then(function () {
                  if (tsWidgetId !== null) { try { window.turnstile.remove(tsWidgetId); } catch (e) {} tsWidgetId = null; }
                  tsWidgetId = window.turnstile.render(tsBox, {
                    sitekey: j.sitekey,
                    theme: 'light',
                    callback: function (token) { tsHidden.value = token; setValid(true); setError(''); },
                    'error-callback':   function () { tsHidden.value = ''; setValid(false); switchToMathFallback('Échec du captcha Cloudflare.'); },
                    'expired-callback': function () { tsHidden.value = ''; setValid(false); setError('Captcha expiré. Réessayez.'); }
                  });
                }).catch(function () { switchToMathFallback('Impossible de charger Cloudflare.'); });
              } else {
                tsBox.style.display = 'none'; mathBox.style.display = 'block';
                tokHidden.value = j.token; qEl.textContent = j.question; aEl.value = '';
              }
            })
            .catch(function () { setError('Impossible d\'initialiser le captcha. Rechargez la page.'); });
        }

        if (aEl) {
          aEl.addEventListener('input', function () {
            if (mode === 'math') { aHidden.value = aEl.value.trim(); setValid(aHidden.value.length > 0); }
          });
        }
        reloadBtn.addEventListener('click', initCaptcha);

        // Synchronise la réponse maths juste avant l'envoi (POST classique du formulaire).
        form.addEventListener('submit', function () {
          if (mode === 'math') aHidden.value = aEl.value.trim();
        });

        initCaptcha();
      })();
      </script>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      (function(){
        var sel = document.getElementById('paiement_mode_public');
        var disp = document.getElementById('montantDuPublic');
        if(!sel || !disp) return;
        var fee = <?= json_encode((float) $registration_fee) ?>;
        function fmt(n){ var v=parseFloat(n); if(!isFinite(v)) v=0; return v.toFixed(2).replace(/\.00$/,'') + ' €'; }
        function update(){
          if(sel.value === 'gratuit'){
            disp.innerHTML = 'Montant dû : <span style="color:#16a34a">' + fmt(0) + '</span>';
          } else {
            disp.innerHTML = 'Montant total dû : <span style="color:var(--primary, #f42182)">' + fmt(fee) + '</span>';
          }
        }
        sel.addEventListener('change', update);
        update();
      })();
      </script>
      <script src="../js/inscription-form.js?v=7" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      (function(){
        var form = document.getElementById('fPub');
        if(!form || !window.FERInscription) return;
        FERInscription.initForm(form);
        form.addEventListener('submit', function(e){
          // Le formulaire est en `novalidate` (pour piloter nous-mêmes le bloc
          // responsable) : on force donc la validation native des champs obligatoires
          // (nom, prénom, email, date de naissance…) avant tout traitement, sinon on
          // pourrait enregistrer une inscription avec des champs requis vides.
          if (!form.checkValidity()) { e.preventDefault(); e.stopImmediatePropagation(); form.reportValidity(); return; }
          // Inscrit mineur : responsable légal obligatoire (personne principale + extras).
          if (!FERInscription.ensureGuardian(form)) { e.preventDefault(); return; }
          var _cards = form.querySelectorAll('.extra-person');
          for (var _i = 0; _i < _cards.length; _i++) {
            if (!FERInscription.ensureGuardian(_cards[_i])) { e.preventDefault(); return; }
          }
          // Compose le commentaire (autorisation responsable) puis normalise la naissance,
          // pour la personne principale ET chaque personne supplémentaire.
          FERInscription.composeComment(form);
          var b = form.querySelector('[name="naissance"]');
          if (b) b.value = FERInscription.normalizeBirthValue(b.value);
          for (var _j = 0; _j < _cards.length; _j++) {
            FERInscription.composeComment(_cards[_j]);
            var _cb = _cards[_j].querySelector('[name$="[naissance]"]');
            if (_cb) _cb.value = FERInscription.normalizeBirthValue(_cb.value);
          }
        });
      })();
      </script>

      <?php if (!empty($qrData['onsite_mode'])): ?>
      <?php // Mode sur place : filtre les prestations selon l'âge saisi + relie le choix
            // « enfant » à l'affichage de l'autorisation parentale (comme si la date de
            // naissance d'un mineur était renseignée). ?>
      <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
      (function(){
        var form   = document.getElementById('fPub');
        if(!form) return;
        var presta = document.getElementById('prestation_public');
        var disp   = document.getElementById('montantDuPublic');
        var birth  = form.querySelector('[name="naissance"]');
        var block  = form.querySelector('[data-guardian-block]');
        if(!presta) return;

        var childAge = <?= (int) $childAge ?>;
        var feeLabel = <?= json_encode($onFeeLabel) ?>;
        var LABELS = {
          tarif_unique:   'Tarif unique — ' + feeLabel,
          enfant_gratuit: 'Enfant −' + childAge + ' ans — Gratuit',
          enfant_tshirt:  'Enfant −' + childAge + ' ans avec T-shirt — ' + feeLabel
        };

        function currentAge(){
          if (!birth || !window.FERInscription) return null;
          return FERInscription.ageFromBirth(FERInscription.normalizeBirthValue(birth.value));
        }

        // Reconstruit la liste des prestations selon l'âge :
        //   âge ≥ seuil          → Tarif unique seulement
        //   âge < seuil          → les 2 prestations enfant
        //   âge non renseigné    → les 3
        function rebuild(){
          var age = currentAge();
          var allow;
          if (age == null)            allow = ['tarif_unique','enfant_gratuit','enfant_tshirt'];
          else if (age >= childAge)   allow = ['tarif_unique'];
          else                        allow = ['enfant_gratuit','enfant_tshirt'];
          var cur = presta.value;
          presta.innerHTML = allow.map(function(v){
            return '<option value="'+v+'"'+((v===cur)?' selected':'')+'>'+LABELS[v]+'</option>';
          }).join('');
          if (allow.indexOf(cur) < 0) presta.selectedIndex = 0;
          updateMontant();
          updateGuardian();
        }

        function updateMontant(){
          if (!disp) return;
          disp.innerHTML = (presta.value === 'enfant_gratuit')
            ? 'Montant dû : <span style="color:#16a34a">0 €</span>'
            : 'Montant total dû : <span style="color:var(--primary, #f42182)">' + feeLabel + '</span>';
        }

        // Un enfant −childAge ans est forcément mineur → on affiche l'autorisation
        // parentale dès qu'une prestation « enfant » est choisie (même sans date de
        // naissance). Sinon, on laisse la date de naissance piloter l'affichage.
        function updateGuardian(){
          var isChild = (presta.value === 'enfant_gratuit' || presta.value === 'enfant_tshirt');
          if (!block) return;
          if (isChild){
            // Un enfant −childAge ans est mineur → on force l'affichage du bloc (les
            // champs personnalisés sont dedans, ils apparaissent avec lui) même sans
            // date de naissance. Chaque champ suit son propre « requis ».
            block.style.display = '';
            var reqd = block.getAttribute('data-guardian-required') !== '0';
            block.querySelectorAll('[data-guardian]').forEach(function(el){
              var isCustom = el.getAttribute('data-guardian') === 'custom';
              var elReq = isCustom ? (el.getAttribute('data-guardian-req') === '1') : reqd;
              if (elReq) el.setAttribute('required',''); else el.removeAttribute('required');
            });
          } else {
            block.querySelectorAll('[data-guardian]').forEach(function(el){ el.removeAttribute('required'); });
            if (window.FERInscription) FERInscription.refresh(form); // rebascule selon la naissance
          }
        }

        presta.addEventListener('change', function(){ updateMontant(); updateGuardian(); });
        if (birth){
          // On réévalue (prestations selon l'âge + bloc responsable) uniquement quand
          // l'utilisateur QUITTE le champ (change / blur), jamais pendant la frappe :
          // sinon taper « 20 » afficherait le bloc « mineur » à « 2 » puis le masquerait
          // à « 20 » (effet de flash). Cohérent avec la page d'ajout d'inscrit admin.
          ['change','blur'].forEach(function(ev){ birth.addEventListener(ev, rebuild); });
        }

        // Sécurité : si une prestation « enfant » est choisie, on exige le responsable
        // légal même sans date de naissance (le validateur standard se base sur la date),
        // et on injecte l'autorisation dans le commentaire s'il n'y est pas déjà.
        form.addEventListener('submit', function(e){
          if (presta.value !== 'enfant_gratuit' && presta.value !== 'enfant_tshirt') return;
          if (!block || block.getAttribute('data-guardian-required') === '0') return;
          var nom = block.querySelector('[data-guardian="nom"]');
          var pre = block.querySelector('[data-guardian="prenom"]');
          var nv  = nom ? nom.value.trim() : '';
          var pv  = pre ? pre.value.trim() : '';
          if (!nv || !pv){
            block.style.display = '';
            alert("Inscription d'un mineur : merci d'indiquer le nom et le prénom du responsable légal.");
            e.preventDefault();
            return;
          }
          // Champs personnalisés du bloc (ex. téléphone parent), désormais à l'intérieur.
          var customEls = block.querySelectorAll('[data-guardian="custom"]');
          var extraLines = [];
          for (var x = 0; x < customEls.length; x++){
            if (customEls[x].getAttribute('data-guardian-req') === '1' && !customEls[x].value.trim()){
              customEls[x].focus();
              alert("Inscription d'un mineur : merci de compléter les informations demandées pour le responsable légal.");
              e.preventDefault();
              return;
            }
            var v = (customEls[x].value||'').trim();
            if (v) extraLines.push((customEls[x].getAttribute('data-guardian-key') || 'Info') + ' : ' + v);
          }
          // Injecte l'autorisation dans le commentaire si le validateur standard ne l'a
          // pas déjà fait (prestation enfant choisie sans date de naissance renseignée).
          var com  = form.querySelector('[name="commentaire"]');
          var MARK = 'Autorisation du représentant légal';
          if (com && com.value.indexOf(MARK) < 0){
            var txt = MARK + ' (mineur) : Validé\nNom : ' + nv + '\nPrénom : ' + pv;
            if (extraLines.length) txt += '\n' + extraLines.join('\n');
            com.value = com.value ? (com.value + '\n\n' + txt) : txt;
          }
        });

        rebuild();
      })();
      </script>
      <?php endif; ?>
    <?php else: ?>
<?php // Le message de succès est désormais affiché en toast (voir src/partials/toast.php). ?>

      <?php if ($error_message): ?>
        <div class="alert alert-danger text-center mb-4">
          <?= htmlspecialchars($error_message) ?>
        </div>
      <?php endif; ?>

      <h2 class="register-online-title text-center mb-4">Inscription en ligne</h2>

      <?php
      // 🔒 [SEC-09] Validation domaine AssoConnect avant echo (CWE-79)
      // Méthode DIV + Script (la seule supportée pour un site externe).
      if ($assoconnectIframe && preg_match('#^<div[^>]+data-collect-id=["\'][A-Z0-9]{26}["\']#i', trim($assoconnectIframe))) {
          echo $assoconnectIframe, PHP_EOL;
      }
      if ($assoconnectJs && preg_match('#^<script[^>]+src=["\']https://[a-z0-9.-]*\.assoconnect\.com/#i', trim($assoconnectJs))) {
          echo $assoconnectJs, PHP_EOL;
      }

      // Bouton de repli : lien direct AssoConnect (si le formulaire ne se charge pas)
      $acUrlSafe = ($assoconnectUrl && filter_var($assoconnectUrl, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $assoconnectUrl)) ? $assoconnectUrl : '';
      ?>
      <?php if ($acUrlSafe): ?>
        <div class="text-center mt-4">
          <p class="text-muted small mb-2">Un problème avec le formulaire d'inscription&nbsp;? Ouvrez-le directement sur le site d'AssoConnect.</p>
          <a href="<?= htmlspecialchars($acUrlSafe, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px">
              <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
              <path d="M15 3h6v6M10 14 21 3"/>
            </svg>S'inscrire directement sur AssoConnect
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

<div class="reglement-wrap">
  <button type="button" class="reglement-cta" data-bs-toggle="modal" data-bs-target="#reglementModal">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M9 12h6M12 9l3 3-3 3"/>
      <path d="M5 4h11a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H5z"/>
    </svg>
    Voir la réglementation de la course
  </button>
</div>

<div class="modal fade" id="reglementModal" tabindex="-1" aria-labelledby="reglementModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="reglementModalLabel">Réglementation de la course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <?php
        // 🔒 [FIX-01] Sanitisation HTML via DOMDocument whitelist (CWE-79)
        echo sanitizeHtml($div_reglementation ?? '');
        ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<!-- Lightbox pour images TinyMCE -->
<div class="tiny-lightbox" id="tinyLightbox">
  <span class="tiny-lightbox-close">&times;</span>
  <img class="tiny-lightbox-img" id="tinyLightboxImg" alt="">
</div>


<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  const lb = document.getElementById('tinyLightbox');
  const lbImg = document.getElementById('tinyLightboxImg');
  if (!lb) return;
  document.querySelectorAll('.modal-body img').forEach(img => {
    img.addEventListener('click', () => { lbImg.src = img.src; lb.classList.add('active'); });
  });

  // Transformer les liens PDF en jolis boutons (dédupliqués)
  const seenPdf = new Set();
  document.querySelectorAll('.modal-body a[href$=".pdf"]').forEach(a => {
    const href = a.getAttribute('href');
    if (seenPdf.has(href)) { a.remove(); return; }
    seenPdf.add(href);
    const raw = (a.title || href.split('/').pop()).replace(/\.[^.]+$/, '');
    const name = /^tiny_[a-f0-9.]+$/.test(raw) ? 'Document' : raw;
    a.className = 'pdf-link';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.innerHTML = '<span class="pdf-link-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg></span><span class="pdf-link-info"><span class="pdf-link-name">' + name + '.pdf</span><span class="pdf-link-hint">Cliquer pour ouvrir</span></span>';
  });
  lb.querySelector('.tiny-lightbox-close').addEventListener('click', () => lb.classList.remove('active'));
  lb.addEventListener('click', e => { if (e.target === lb) lb.classList.remove('active'); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') lb.classList.remove('active'); });
})();
</script>

<?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>

<script src="../js/fer-modern.js"></script>

<?php include __DIR__ . '/../src/partials/toast.php'; // Notifications toast (identique aux pages admin) ?>

</body>
</html>
