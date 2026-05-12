<?php
require '../config/config.php';
checkMaintenance();
require '../inc/navbar-data.php';
require_once '../config/tracker.php';
trackPageVisit();

// Récupération du nombre d'inscrits
try {
    $stmtcount = $pdo->prepare('SELECT COUNT(*) AS total FROM registrations');
    $stmtcount->execute();
    $count = $stmtcount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {
    $count = 0;
}

// Recherche d'inscription par email (GET)
$searchEmail = trim($_GET['search_email'] ?? '');
$searchMessage = '';
$searchStatus = '';

if (isset($_GET['check_registration'])) {
    if ($searchEmail === '') {
        $searchStatus = 'warn';
        $searchMessage = "Indiquez votre email pour vérifier votre inscription.";
    } elseif (!filter_var($searchEmail, FILTER_VALIDATE_EMAIL)) {
        $searchStatus = 'warn';
        $searchMessage = "Oups, cet email ne semble pas valide. Pouvez‑vous le vérifier ?";
    } else {
        // 🔒 [SEC-07] Rate-limit : 10 recherches/min par IP (CWE-400)
        $_rlIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $_rlKey = substr(hash('sha256', 'email_search_' . $_rlIp), 0, 16);
        $_rlFile = sys_get_temp_dir() . '/fer_' . $_rlKey . '.json';
        $_rlTimes = [];
        if (@file_exists($_rlFile)) { $_rlTimes = json_decode(@file_get_contents($_rlFile), true) ?: []; }
        $_rlNow = time();
        $_rlTimes = array_values(array_filter($_rlTimes, fn($t) => $t > $_rlNow - 60));

        if (count($_rlTimes) >= 10) {
            $searchStatus = 'warn';
            $searchMessage = "Trop de recherches, veuillez patienter quelques instants.";
        } else {
            $_rlTimes[] = $_rlNow;
            @file_put_contents($_rlFile, json_encode($_rlTimes));

            // Les emails sont chiffrés AES-256-GCM (IV aléatoire) : on ne peut pas faire WHERE email = ?
            // On déchiffre côté PHP et on compare en minuscules.
            try {
                $stmtSearch = $pdo->query('SELECT email FROM registrations');
                $matchCount = 0;
                $needle = strtolower($searchEmail);
                while ($row = $stmtSearch->fetch(PDO::FETCH_ASSOC)) {
                    if (strtolower((string)decrypt($row['email'])) === $needle) {
                        $matchCount++;
                    }
                }

                if ($matchCount > 0) {
                    $searchStatus = 'success';
                    $countLabel = $matchCount === 1
                        ? "1 inscription enregistrée"
                        : "$matchCount inscriptions enregistrées";
                    $searchMessage = "Merci ! $countLabel pour cet email. Hâte de vous voir le jour J 😊";
                } else {
                    $searchStatus = 'danger';
                    $searchMessage = "On ne retrouve pas d'inscription avec cet email 😔. Vérifiez l'adresse ou inscrivez‑vous en 1 minute 😁";
                }
            } catch (PDOException $e) {
                $searchStatus = 'warn';
                $searchMessage = "Erreur lors de la recherche. Veuillez réessayer.";
            }
        }
    }
}

if (isset($_GET['ajax']) && isset($_GET['check_registration'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $searchStatus,
        'message' => $searchMessage,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Récupération des paramètres
try {
    $stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $data = [];
}

$titleAccueil  = $data['titleAccueil'] ?? '';
$picture = $data['picture'] ?? '';
$link_instagram = $data['link_instagram'] ?? null;
$link_facebook = $data['link_facebook'] ?? null;
$link_twitter = $data['link_twitter'] ?? null;
$link_youtube = $data['link_youtube'] ?? null;
$date_course = $data['date_course'] ?? null;
$date_formatted = $date_course ? date('Y-m-d\TH:i:s', strtotime($date_course)) : '2026-07-05T09:00:00';
$registration_fee = $data['registration_fee'] ?? 0;
$course_km = $data['course_km'] ?? 0;
$picture_partner = $data['picture_partner'] ?? '';
$flash_info_text = $data['flash_info_text'] ?? '';
$flash_info_active = !empty($data['flash_info_active']) ? 1 : 0;
$flash_bg_color = $data['flash_bg_color'] ?? '#db2777';
$flash_text_color = $data['flash_text_color'] ?? '#ffffff';

// Ouverture / fermeture automatique des inscriptions
$accueil_active = $data['accueil_active'] ? 1 : 0;
$tz = new DateTimeZone('Europe/Paris');
$now = new DateTime('now', $tz);
$autoOpen  = !empty($data['registration_auto_open'])  ? new DateTime($data['registration_auto_open'], $tz)  : null;
$autoClose = !empty($data['registration_auto_close']) ? new DateTime($data['registration_auto_close'], $tz) : null;
if ($autoOpen && $now >= $autoOpen) { $accueil_active = 1; }
if ($autoClose && $now >= $autoClose) { $accueil_active = 0; }

// navbar-data.php (ligne 4) charge déjà $galeries, $actualites, $partenaires, $actualites_cols2, etc.

$link_cancer = $data['link_cancer'] ?? null;

// Timeline preview mode : nécessite session valide + accès page 'timeline'
$isTimelinePreview = isset($_GET['preview_timeline']) && $_GET['preview_timeline'] == '1';
if ($isTimelinePreview) {
    if (!isset($_SESSION['uid']) || !canAccessPage('timeline')) {
        $isTimelinePreview = false;
    }
}

// MODE ÉDITEUR : accueil.php?editor=1 chargé dans une iframe depuis setting.php
// → désactive l'autoplay vidéo, le countdown live, les analytics ; ajoute data-attrs sur les sections
//   pour que le parent puisse positionner ses contrôles overlay
$isEditorMode = false;
if (isset($_GET['editor']) && $_GET['editor'] == '1' && isset($_SESSION['uid']) && canDoAction('settings.accueil.custom')) {
    $isEditorMode = true;
}

$hasTimelineStatusCol = false;
try { $pdo->query("SELECT status FROM timeline_items LIMIT 0"); $hasTimelineStatusCol = true; } catch (PDOException $e) {}

// Récupération des items de la timeline
try {
    if ($isTimelinePreview || !$hasTimelineStatusCol) {
        $stmtTimeline = $pdo->prepare('SELECT * FROM timeline_items ORDER BY sort_order ASC');
    } else {
        $stmtTimeline = $pdo->prepare("SELECT * FROM timeline_items WHERE status = 'published' ORDER BY sort_order ASC");
    }
    $stmtTimeline->execute();
    $timelineItems = $stmtTimeline->fetchAll(PDO::FETCH_ASSOC);

    $timelineElements = [];
    foreach ($timelineItems as $ti) {
        $stmtEl = $pdo->prepare('SELECT label FROM timeline_elements WHERE item_id = ? ORDER BY sort_order ASC');
        $stmtEl->execute([$ti['id']]);
        $timelineElements[$ti['id']] = $stmtEl->fetchAll(PDO::FETCH_COLUMN);
    }
    $timelineCount = count($timelineItems);
} catch (PDOException $e) {
    $timelineItems = [];
    $timelineElements = [];
    $timelineCount = 0;
}

/**
 * Generate SVG S-curve path for the timeline based on item count.
 * For 4 items, produces the exact same path as the original hardcoded version.
 */
function generateTimelineSVG(int $count): array {
    if ($count <= 0) return ['height' => 0, 'path' => ''];

    $segmentHeight = 200;
    $totalHeight = $count * $segmentHeight;
    $path = "M 100 0";

    if ($count >= 1) {
        $path .= " C 100 80, 190 120, 190 200";
    }

    for ($i = 1; $i < $count; $i++) {
        $y1 = ($i * $segmentHeight) + 80;
        $y2 = ($i + 1) * $segmentHeight;
        if ($i % 2 === 1) {
            $path .= " S 10 {$y1}, 10 {$y2}";
        } else {
            $path .= " S 190 {$y1}, 190 {$y2}";
        }
    }

    return ['height' => $totalHeight, 'path' => $path];
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Accueil</title>
  <link rel="stylesheet" href="../css/fer-modern.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Bebas+Neue&family=Oswald:wght@700&family=Montserrat:wght@700;900&family=Dancing+Script:wght@700&family=Lobster&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="../css/accueil.css">
<?php include __DIR__ . '/../config/theme.php'; ?>
</head>

<body<?php if ($flash_info_active && !empty($flash_info_text)): ?> class="has-flash-banner"<?php endif; ?>>

  <?php include '../inc/navbar-modern.php'; ?>

  <!-- FLASH INFO BANNER -->
  <?php if ($flash_info_active && !empty($flash_info_text)): ?>
  <div class="flash-banner" id="flashBanner" style="--flash-bg: <?= htmlspecialchars($flash_bg_color) ?>; --flash-text: <?= htmlspecialchars($flash_text_color) ?>;">
    <div class="flash-banner-track">
      <div class="flash-banner-group">
        <?php for ($i = 0; $i < 10; $i++): ?>
          <span class="flash-banner-item"><?= htmlspecialchars($flash_info_text) ?></span>
        <?php endfor; ?>
      </div>
      <div class="flash-banner-group">
        <?php for ($i = 0; $i < 10; $i++): ?>
          <span class="flash-banner-item"><?= htmlspecialchars($flash_info_text) ?></span>
        <?php endfor; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- PAGE -->
  <main>
    

    <div class="demo-wrap">
      <section class="demo-card" aria-label="Carte vidéo">
        <?php if (!empty($registration_fee) || !empty($course_km)): ?>
        <div class="demo-badges">
          <?php if (!empty($course_km)): ?>
          <a href="parcours" class="demo-badge demo-badge--km" style="text-decoration:none;cursor:pointer;">
            <span class="demo-badge-value"><?= (int)$course_km ?> km</span>
            <span class="demo-badge-label">Parcours</span>
          </a>
          <?php endif; ?>
          <?php if (!empty($registration_fee)): ?>
          <div class="demo-badge demo-badge--fee" id="badgeFee">
            <span class="demo-badge-value"><?= (int)$registration_fee ?>€</span>
            <div class="badge-tooltip" id="badgeTooltip">Entièrement reversé à la<br>Ligue contre le cancer</div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php $videoFile = $data['video_accueil'] ?? 'FER.mp4'; ?>
        <?php if ($videoFile && file_exists(__DIR__ . '/../files/' . $videoFile)): ?>
        <video class="demo-video" id="heroVideo" <?= $isEditorMode ? 'data-edit-field="video_accueil" data-edit-kind="video" data-edit-section="hero"' : 'autoplay muted loop playsinline' ?>>
          <source src="../files/<?= rawurlencode($videoFile) ?>" type="video/mp4" />
        </video>

        <!-- Bouton Play/Pause -->
        <button class="video-toggle" id="videoToggle" aria-label="Pause la vidéo" type="button">
          <svg class="vt-icon vt-pause" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
          <svg class="vt-icon vt-play" viewBox="0 0 24 24" fill="currentColor" style="display:none"><polygon points="6,4 20,12 6,20"/></svg>
        </button>
        <?php endif; ?>

        <div class="demo-overlay">
          <div class="demo-panel video-float">
            <div class="hero-text">
              <?php
                // Helper inline : applique la geometry persistée (drag libre + resize + scale)
                $applyGeo = function($field) use ($accueilGeometry) {
                  $g = $accueilGeometry[$field] ?? null;
                  if (!is_array($g)) return '';
                  $css = '';
                  if (isset($g['w'])) $css .= 'width:' . (int)$g['w'] . 'px;';
                  if (isset($g['h'])) $css .= 'height:' . (int)$g['h'] . 'px;';
                  $x = (int)($g['x'] ?? 0); $y = (int)($g['y'] ?? 0);
                  $sc = isset($g['scale']) ? (float)$g['scale'] : 1.0;
                  $parts = [];
                  if ($x || $y) $parts[] = 'translate(' . $x . 'px,' . $y . 'px)';
                  if ($sc && abs($sc - 1) > 0.001) $parts[] = 'scale(' . $sc . ')';
                  if (!empty($parts)) {
                    $css .= 'transform:' . implode(' ', $parts) . ';transform-origin:left top;position:relative;';
                  } elseif ($x || $y) {
                    $css .= 'position:relative;';
                  }
                  return $css;
                };
                $sizeSubtitle = (int)($accueilStyles['subtitle_accueil_size'] ?? 100);
                $cssTitle       = $applyGeo('titleAccueil');
                $cssTitleMobile = $applyGeo('titleAccueil_mobile') ?: $cssTitle;
                $cssSubtitle    = $sizeSubtitle !== 100 ? 'font-size:' . ($sizeSubtitle / 100) . 'em;' : '';
                $cssSubPC       = $applyGeo('subtitle_accueil') ?: $cssSubtitle;
                $cssSubM        = $applyGeo('subtitle_accueil_mobile') ?: $cssSubtitle;
                // Alignement
                $alignTitlePC = $accueilStyles['text_align__titleAccueil']        ?? '';
                $alignTitleM  = $accueilStyles['text_align__titleAccueil_mobile'] ?? '';
                $alignSubPC   = $accueilStyles['text_align__subtitle_accueil']    ?? '';
                $alignSubM    = $accueilStyles['text_align__subtitle_accueil_mobile'] ?? '';
                if ($alignTitlePC) $cssTitle      .= 'text-align:' . $alignTitlePC . ';';
                if ($alignTitleM)  $cssTitleMobile = preg_replace('/text-align:[^;]*;?/', '', $cssTitleMobile) . 'text-align:' . $alignTitleM . ';';
                if ($alignSubPC)   $cssSubPC      .= 'text-align:' . $alignSubPC . ';';
                if ($alignSubM)    $cssSubM       .= 'text-align:' . $alignSubM . ';';
              ?>
              <!-- PC -->
              <div class="hero-device hero-pc">
                <div class="demo-kicker" style="<?= $cssTitle ?>" <?= $isEditorMode ? 'data-edit-field="titleAccueil" data-edit-kind="tinymce" data-edit-section="hero"' : '' ?>><?= $data['titleAccueil'] ?? ($isEditorMode ? '<em style="color:#fce7f3">Cliquer pour ajouter un titre PC</em>' : '') ?></div>
              </div>
              <!-- Mobile -->
              <div class="hero-device hero-mobile">
                <div class="demo-kicker" style="<?= $cssTitleMobile ?>" <?= $isEditorMode ? 'data-edit-field="titleAccueil_mobile" data-edit-kind="tinymce" data-edit-section="hero"' : '' ?>><?= ($data['titleAccueil_mobile'] ?? '') ?: ($data['titleAccueil'] ?? ($isEditorMode ? '<em style="color:#fce7f3">Cliquer pour ajouter un titre mobile</em>' : '')) ?></div>
              </div>
              <?php
                $subPC = $data['subtitle_accueil'] ?? '';
                $subMobile = $data['subtitle_accueil_mobile'] ?? '';
              ?>
              <?php if ($subPC !== '' || $isEditorMode): ?>
                <p class="demo-desc hero-device hero-pc" style="<?= $cssSubPC ?>" <?= $isEditorMode ? 'data-edit-field="subtitle_accueil" data-edit-kind="text" data-edit-section="hero" data-edit-size="subtitle_accueil_size" data-edit-size-current="' . $sizeSubtitle . '"' : '' ?>><?= htmlspecialchars($subPC !== '' ? $subPC : 'Cliquer pour ajouter un sous-titre PC') ?></p>
              <?php endif; ?>
              <?php if ($subMobile !== '' || $isEditorMode): ?>
                <p class="demo-desc hero-device hero-mobile" style="<?= $cssSubM ?>" <?= $isEditorMode ? 'data-edit-field="subtitle_accueil_mobile" data-edit-kind="text" data-edit-section="hero" data-edit-size="subtitle_accueil_size" data-edit-size-current="' . $sizeSubtitle . '"' : '' ?>><?= htmlspecialchars($subMobile !== '' ? $subMobile : ($subPC !== '' ? $subPC : 'Cliquer pour ajouter un sous-titre mobile')) ?></p>
              <?php endif; ?>
            </div>

            <?php
              $sizeTimer = (int)($accueilStyles['hero_timer_size'] ?? 100);
              $cssTimer = $sizeTimer !== 100 ? 'transform:scale(' . ($sizeTimer / 100) . ');transform-origin:left top;display:inline-block;' : '';
              // Si une géométrie libre est définie pour le timer, elle prend le dessus mais conserve le scale.
              $geomTimer = $accueilGeometry['hero_timer'] ?? null;
              if (is_array($geomTimer)) {
                $cssTimer = '';
                if (isset($geomTimer['w'])) $cssTimer .= 'width:' . (int)$geomTimer['w'] . 'px;';
                if (isset($geomTimer['h'])) $cssTimer .= 'height:' . (int)$geomTimer['h'] . 'px;';
                $tx = (int)($geomTimer['x'] ?? 0);
                $ty = (int)($geomTimer['y'] ?? 0);
                $scaleTimer = isset($geomTimer['scale']) ? (float)$geomTimer['scale'] : ($sizeTimer / 100);
                $transformParts = [];
                if ($tx || $ty) $transformParts[] = 'translate(' . $tx . 'px,' . $ty . 'px)';
                if ($scaleTimer && abs($scaleTimer - 1) > 0.001) $transformParts[] = 'scale(' . $scaleTimer . ')';
                if (!empty($transformParts)) {
                  $cssTimer .= 'transform:' . implode(' ', $transformParts) . ';transform-origin:left top;';
                }
                $cssTimer .= 'position:relative;display:inline-block;';
              }
            ?>
            <div class="countdown-wrap" style="<?= $cssTimer ?>" <?= $isEditorMode ? 'data-edit-field="hero_timer" data-edit-kind="size-only" data-edit-section="hero" data-edit-size="hero_timer_size" data-edit-size-current="' . $sizeTimer . '"' : '' ?>>
              <div class="countdown-row" aria-label="Compte à rebours">
                <div class="timebox">
                  <div class="num" id="cd_days">0</div>
                  <div class="lbl">Jours</div>
                </div>
                <div class="timebox">
                  <div class="num" id="cd_hours">00</div>
                  <div class="lbl">Heures</div>
                </div>
                <div class="timebox">
                  <div class="num" id="cd_minutes">00</div>
                  <div class="lbl">Minutes</div>
                </div>
                <div class="timebox timebox-seconds">
                  <div class="num" id="cd_seconds">00</div>
                  <div class="lbl">Secondes</div>
                </div>
              </div>
            </div>

            <div class="actions">
              <?php
                // Texte du bouton inscription : récupère depuis accueil_texts si défini,
                // sinon défaut "Je m'inscris →". Éditable dans l'éditeur via data-edit-field.
                $ctaRegisterTxt = (string)($accueilTexts['hero.cta_register'] ?? "Je m'inscris →");
              ?>
              <a class="cta-pink" href="register" <?= $isEditorMode ? 'data-edit-field="hero.cta_register" data-edit-kind="text" data-edit-section="hero"' : '' ?>><?= htmlspecialchars($ctaRegisterTxt) ?></a>
            </div>
          </div>
        </div>
      </section>

      <div class="video-social-card" aria-label="Réseaux sociaux">
        <?php if (!empty($link_instagram)): ?>
        <a class="social-btn" href="<?= htmlspecialchars($link_instagram) ?>" target="_blank" rel="noopener" aria-label="Instagram">
          <img src="../files/_logos/instagram.png" alt="Instagram">
        </a>
        <?php endif; ?>
        <?php if (!empty($link_facebook)): ?>
        <a class="social-btn" href="<?= htmlspecialchars($link_facebook) ?>" target="_blank" rel="noopener" aria-label="Facebook">
          <img src="../files/_logos/facebook.png" alt="Facebook">
        </a>
        <?php endif; ?>
        <?php if (!empty($link_cancer)): ?>
        <a class="social-btn ligue" href="<?= htmlspecialchars($link_cancer) ?>" target="_blank" rel="noopener" aria-label="Ligue contre le cancer">
          <img src="../files/_logos/ligue-cancer.png" alt="Ligue contre le cancer">
        </a>
        <?php endif; ?>
      </div>
    </div>

    <?php
    // ───────────────────────────────────────────────────────────────────────
    // RENDU DYNAMIQUE DES SECTIONS DE LA PAGE D'ACCUEIL
    // L'ordre, la visibilité et les blocs personnalisés sont configurables
    // depuis Réglages → Accueil → Mise en page de l'accueil.
    // Le Héro (vidéo + countdown) ci-dessus reste fixé en haut.
    // ───────────────────────────────────────────────────────────────────────

    require_once __DIR__ . '/../config/accueil_layout.php';
    require_once __DIR__ . '/../config/accueil_sections.php';
    // En mode éditeur (?editor=1), on charge le brouillon (accueil_layout_draft) avec
    // fallback sur la version publiée. En mode normal (live), on charge directement
    // la version publiée → le grand public voit ce qui a été "Publié", pas le brouillon.
    $accueilLayout = loadAccueilLayout($data, $isEditorMode);

    // Idem pour les styles/géometrie : en éditeur, on lit le draft avec fallback.
    $accueilStyles = [];
    $stylesRaw = $isEditorMode
        ? ($data['accueil_styles_draft'] ?? null) ?: ($data['accueil_styles'] ?? null)
        : ($data['accueil_styles'] ?? null);
    if (!empty($stylesRaw)) {
        $decoded = json_decode($stylesRaw, true);
        if (is_array($decoded)) $accueilStyles = $decoded;
    }
    $accueilGeometry = [];
    $geomRaw = $isEditorMode
        ? ($data['accueil_geometry_draft'] ?? null) ?: ($data['accueil_geometry'] ?? null)
        : ($data['accueil_geometry'] ?? null);
    if (!empty($geomRaw)) {
        $decoded = json_decode($geomRaw, true);
        if (is_array($decoded)) $accueilGeometry = $decoded;
    }
    // Textes hardcodés override-ables (reg_bar.kicker_open, partners.title, etc.)
    $accueilTexts = [];
    $textsRaw = $isEditorMode
        ? ($data['accueil_texts_draft'] ?? null) ?: ($data['accueil_texts'] ?? null)
        : ($data['accueil_texts'] ?? null);
    if (!empty($textsRaw)) {
        $decoded = json_decode($textsRaw, true);
        if (is_array($decoded)) $accueilTexts = $decoded;
    }
    $sectionCtx = [
        'count'             => $count,
        'accueil_active'    => $accueil_active,
        'autoOpen'          => $autoOpen,
        'now'               => $now,
        'searchEmail'       => $searchEmail,
        'searchMessage'     => $searchMessage,
        'searchStatus'      => $searchStatus,
        'picture_partner'   => $picture_partner,
        'timelineItems'     => $timelineItems,
        'timelineElements'  => $timelineElements,
        'timelineCount'     => $timelineCount,
        'isTimelinePreview' => $isTimelinePreview,
        'actualites'        => $actualites,
        // Champs Hero (utilisés par renderAccueilSection_hero qui peut être inclus dans une ligne du layout)
        'titleAccueil'            => $data['titleAccueil'] ?? '',
        'titleAccueil_mobile'     => $data['titleAccueil_mobile'] ?? '',
        'subtitle_accueil'        => $data['subtitle_accueil'] ?? '',
        'subtitle_accueil_mobile' => $data['subtitle_accueil_mobile'] ?? '',
        'video_accueil'           => $data['video_accueil'] ?? 'FER.mp4',
        'registration_fee'        => $data['registration_fee'] ?? 0,
        'course_km'               => $data['course_km'] ?? 7,
        'styles'                  => $accueilStyles,
        'geometry'                => $accueilGeometry,
        'texts'                   => $accueilTexts,
        '_editor'                 => $isEditorMode,
    ];

    // ── Anciennes closures (NON UTILISÉES — gardées dead code, le rendu réel est dans config/accueil_sections.php) ──
    if (false):
    $renderRegBar = function() use ($count, $accueil_active, $autoOpen, $now, $searchEmail, $searchMessage, $searchStatus) {
    ?>
    <section class="reg-bar" id="reg-bar" aria-label="Inscriptions">
      <div class="reg-card">
        <?php if ((int)$count === 0 && $accueil_active === 0): ?>
        <div class="reg-count">
          <div class="reg-kicker">Inscriptions</div>
          <div class="reg-value" style="font-size:1.2rem;">Fermées</div>
        </div>
        <div class="reg-search">
          <div class="reg-title" style="display:flex;align-items:center;justify-content:center;gap:6px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg><span>Inscriptions actuellement fermées</span></div>
          <?php if ($autoOpen && $now < $autoOpen): ?>
            <p style="margin-top:10px;font-size:.95rem;color:#b5366b;display:flex;align-items:center;justify-content:center;gap:5px;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span>Ouverture le <strong><?= $autoOpen->format('d/m/Y') ?></strong> à <strong><?= $autoOpen->format('H\hi') ?></strong></span>
            </p>
          <?php else: ?>
            <p style="margin-top:10px;font-size:.95rem;color:#64748b;">Merci de votre compréhension.</p>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="reg-count">
          <div class="reg-kicker">Déjà inscrits</div>
          <div class="reg-value"><?= number_format((int)$count, 0, ',', ' ') ?></div>
        </div>
        <div class="reg-search">
          <div class="reg-title">Vérifier mon inscription</div>
          <form class="reg-form" method="get" action="accueil#reg-bar">
            <input type="hidden" name="check_registration" value="1">
            <input class="reg-input" type="email" name="search_email" placeholder="Votre adresse email"
                  value="<?= htmlspecialchars($searchEmail) ?>" autocomplete="email" required>
            <button class="reg-submit" type="submit">Vérifier →</button>
          </form>
          <p id="regResult" class="reg-result <?= htmlspecialchars($searchStatus) ?>" aria-live="polite"
             style="<?= $searchMessage !== '' ? '' : 'display:none;' ?>"><?= htmlspecialchars($searchMessage) ?></p>
          <p id="regHint" class="reg-hint" style="<?= $searchMessage !== '' ? 'display:none;' : '' ?>">
            Saisissez l'email utilisé lors de votre inscription.
          </p>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
    };

    // ── Closure : Bandeau Partenaires ──
    $renderPartners = function() use ($picture_partner) {
    ?>
    <section class="community-section" aria-label="Devenez partenaire">
      <div class="community-container<?php if (empty($picture_partner) || !is_file('../files/_pictures/' . $picture_partner)): ?> no-partner-img<?php endif; ?>">
        <?php if (!empty($picture_partner) && is_file('../files/_pictures/' . $picture_partner)): ?>
        <div class="community-image">
          <img src="../files/_pictures/<?= htmlspecialchars($picture_partner) ?>" alt="Nos partenaires - Forbach en Rose">
        </div>
        <?php endif; ?>
        <div class="community-content">
          <h2 class="community-title">Rejoignez le clan de nos partenaires engagés</h2>
          <p class="community-text">
            Chaque année, des entreprises et associations locales s'associent à Forbach en Rose
            pour soutenir la lutte contre le cancer. En devenant partenaire, vous contribuez
            directement à la réussite de cet événement caritatif et affichez votre engagement
            solidaire auprès de notre communauté.
          </p>
          <form class="partner-form" id="partnerForm">
            <div class="form-group">
              <input type="email" id="partnerEmail" name="partner_email" class="partner-email-input"
                     placeholder="Votre email professionnel" required aria-label="Email professionnel">
              <button type="submit" class="partner-submit" id="partnerSubmitBtn">
                Devenir partenaire
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
            <p class="form-note">Nous vous recontacterons dans les plus brefs délais pour discuter des modalités de partenariat.</p>
            <div id="partnerResult" style="display:none;margin-top:.75rem;padding:.6rem 1rem;border-radius:.5rem;font-size:.9rem;"></div>
          </form>
        </div>
      </div>
    </section>
    <?php
    };

    // ── Closure : Timeline / Historique ──
    $renderTimeline = function() use ($timelineCount, $timelineItems, $timelineElements, $isTimelinePreview) {
        if ($timelineCount <= 0) return;
        $svg = generateTimelineSVG($timelineCount);
    ?>
    <?php if ($isTimelinePreview): ?>
    <div style="background:#fd7e14;color:#fff;text-align:center;padding:10px;font-weight:600;font-size:14px;margin:12px auto;border-radius:8px;max-width:1200px;">
      Aperçu Timeline – Les brouillons sont visibles
    </div>
    <?php endif; ?>
    <div class="timeline-wrap">
      <section class="timeline" aria-label="Timeline">
        <div class="timeline-head"><h2 class="timeline-title">Historique</h2></div>
        <div class="timeline-track">
          <svg class="timeline-svg" viewBox="0 0 200 <?= $svg['height'] ?>" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="gradient-line" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" stop-color="#fce7f3" />
                <stop offset="50%" stop-color="#F42182" />
                <stop offset="100%" stop-color="#db2777" />
              </linearGradient>
            </defs>
            <path class="timeline-path" d="<?= $svg['path'] ?>" />
          </svg>
          <div class="timeline-items">
            <?php foreach ($timelineItems as $index => $ti):
                if (empty($ti['title']) && empty($ti['content']) && empty($ti['image'])) continue;
                $side = ($index % 2 === 0) ? 'left' : 'right';
                $elements = $timelineElements[$ti['id']] ?? [];
            ?>
            <div class="t-item <?= $side ?>">
              <span class="t-dot" aria-hidden="true"></span>
              <article class="t-card">
                <div class="t-media">
                  <div class="t-media-inner">
                  <?php if (!empty($ti['image']) && is_file('../files/_TimeLine/' . $ti['image'])):
                    $posRaw = $ti['image_position'] ?? '50% 50% 1';
                    $posParts = preg_split('/\s+/', trim($posRaw));
                    $imgXPct = $posParts[0] ?? '50%';
                    $imgYPct = $posParts[1] ?? '50%';
                    $imgScale = floatval(str_replace('%', '', $posParts[2] ?? '1'));
                    if ($imgScale <= 0) $imgScale = 1;
                    $imgStyle = "object-position:{$imgXPct} {$imgYPct}";
                    if ($imgScale > 1) {
                      $imgStyle .= ";--zoom:{$imgScale};transform-origin:{$imgXPct} {$imgYPct}";
                    }
                  ?>
                    <img src="../files/_TimeLine/<?= htmlspecialchars($ti['image']) ?>" alt="<?= htmlspecialchars($ti['title']) ?>" style="<?= $imgStyle ?>">
                  <?php endif; ?>
                  </div>
                  <div class="t-kicker"><?= htmlspecialchars($ti['title']) ?></div>
                </div>
                <div class="t-content">
                  <div class="t-amount"><?= htmlspecialchars($ti['content']) ?></div>
                  <?php if (!empty($elements)): ?>
                  <div class="t-meta">
                    <?php foreach ($elements as $label): ?>
                      <span class="t-pill"><?= htmlspecialchars($label) ?></span>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </article>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    </div>
    <?php
    };

    // ── Closure : Dernières actualités ──
    $renderNews = function() use ($actualites) {
        if (empty($actualites)) return;
    ?>
    <section class="news-band" aria-label="Dernières actualités">
      <div class="news-band-container">
        <div class="news-band-head">
          <h3 class="news-band-title">Dernières actualités</h3>
          <a class="news-band-link" href="news">Voir tout</a>
        </div>
        <div class="news-grid">
          <?php $news_cards = array_slice($actualites, 0, 4); ?>
          <?php if (!empty($news_cards)): ?>
            <?php foreach ($news_cards as $actu):
              if (empty($actu['title'])) continue;
              $dateLabel = $dateAttr = '';
              if (!empty($actu['date_publication'])) {
                $ts = strtotime($actu['date_publication']);
                if ($ts) { $dateLabel = date('d/m/Y', $ts); $dateAttr = date('Y-m-d', $ts); }
              }
            ?>
              <a class="news-card" href="news?id=<?= $actu['id'] ?>">
                <div class="news-body">
                  <span class="news-kicker">Actualité</span>
                  <span class="news-title"><?= htmlspecialchars($actu['title']) ?></span>
                  <?php if ($dateLabel !== ''): ?>
                    <time class="news-date" datetime="<?= htmlspecialchars($dateAttr) ?>"><?= htmlspecialchars($dateLabel) ?></time>
                  <?php endif; ?>
                  <span class="news-cta">Lire →</span>
                </div>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="news-empty">Aucune actualité pour le moment.</div>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <?php
    };

    // ── Closure : Bloc personnalisé (HTML produit par TinyMCE en admin) ──
    $renderCustom = function(array $section) {
        $content = (string)($section['content'] ?? '');
        if (trim($content) === '') return;
    ?>
    <section class="custom-content-section">
      <div class="custom-content-inner"><?= $content ?></div>
    </section>
    <?php
    };
    endif; // if (false)

    // Wrapper en flex column → désactive le margin-collapse entre rows adjacentes.
    // Ainsi chaque row contrôle indépendamment son margin-top ET margin-bottom (sinon
    // ils s'écrasent en CSS standard et l'utilisateur ne voit pas l'effet de 0/0).
    echo '<div class="accueil-rows-wrapper">';
    // ── Boucle de rendu : lignes + colonnes (format V2) via dispatcher partagé ──
    foreach ($accueilLayout as $rowIdx => $row) {
        $visibleCols = [];
        $allColsCount = count($row['columns']);
        foreach ($row['columns'] as $colIdx => $col) {
            if (!empty($col['section']['visible']) || $isEditorMode) {
                // En mode éditeur, on affiche TOUTES les colonnes même masquées (avec opacity)
                $visibleCols[] = ['col' => $col, 'origIdx' => $colIdx];
            }
        }
        if (empty($visibleCols)) continue;
        $isMultiCol = count($visibleCols) > 1;
        $rowAlign = (string)($row['align'] ?? 'left');
        if (!in_array($rowAlign, ['left', 'center', 'right'], true)) $rowAlign = 'left';
        $rowValign = (string)($row['valign'] ?? 'center');
        if (!in_array($rowValign, ['top', 'center', 'bottom'], true)) $rowValign = 'center';
        $rowAttrs = '';
        if ($isEditorMode) {
            $rowAttrs = ' data-editor-row-id="' . htmlspecialchars($row['id']) . '" data-editor-row-idx="' . $rowIdx . '" data-editor-row-align="' . $rowAlign . '" data-editor-row-valign="' . $rowValign . '"';
        }
        $rowClass = 'accueil-row' . ($isMultiCol ? ' accueil-row-multi' : '') . ' accueil-row-align-' . $rowAlign . ' accueil-row-valign-' . $rowValign;
        // Override d'espacement par ligne (rem) — sinon CSS par défaut
        $rowInlineStyle = '';
        if (isset($row['spaceTop']) && is_numeric($row['spaceTop'])) {
            $rowInlineStyle .= 'margin-top:' . (float)$row['spaceTop'] . 'rem;';
        }
        if (isset($row['spaceBottom']) && is_numeric($row['spaceBottom'])) {
            $rowInlineStyle .= 'margin-bottom:' . (float)$row['spaceBottom'] . 'rem;';
        }
        $rowStyleAttr = $rowInlineStyle ? ' style="' . $rowInlineStyle . '"' : '';
        echo '<div class="' . $rowClass . '"' . $rowAttrs . $rowStyleAttr . '>';
        foreach ($visibleCols as $vc) {
            $col = $vc['col'];
            $w = max(1, min(12, (int)$col['width']));
            $colAttrs = '';
            $hidden = empty($col['section']['visible']);
            $colClass = 'accueil-col';
            // Marqueur pour les colonnes contenant un bloc HTML : leur col doit toujours
            // stretcher pleine hauteur (ignorer row.valign) pour que l'alignement interne
            // du bloc HTML (via flex justify/align) soit visible.
            $isHtmlBlock = ($col['section']['type'] === 'custom') && (($col['section']['kind'] ?? '') === 'html');
            if ($isHtmlBlock) $colClass .= ' accueil-col-html';
            if ($isEditorMode) {
                $colAttrs = ' data-editor-col-idx="' . $vc['origIdx']
                          . '" data-editor-col-width="' . $w
                          . '" data-editor-section-type="' . htmlspecialchars($col['section']['type'])
                          . '" data-editor-col-visible="' . ($hidden ? '0' : '1') . '"';
                if ($col['section']['type'] === 'custom') {
                    $colAttrs .= ' data-editor-section-id="' . htmlspecialchars($col['section']['id'] ?? '') . '"';
                }
                if ($hidden) $colClass .= ' accueil-col-hidden';
            }
            // --col-w = entier 1..12 (flex-grow proportionnel), pas un pourcentage
            echo '<div class="' . $colClass . '" style="--col-w:' . $w . '"' . $colAttrs . '>';
            // En éditeur, on rend même les sections masquées (avec opacity via CSS)
            $sec = $col['section'];
            if ($isEditorMode || !empty($sec['visible'])) {
                renderAccueilSection($sec, $sectionCtx);
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>'; // /accueil-rows-wrapper
    ?>

  </main>


  

<?php include '../inc/footer-modern.php'; ?>

  <script src="../js/fer-modern.js"></script>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
    // NOTE: Mega menu, Mobile menu, Nav scroll and Theme toggle are in fer-modern.js

    // ===== Registration check (AJAX, no refresh) =====
    (function(){
      const form = document.querySelector('.reg-form');
      if (!form) return;

      const input = form.querySelector('input[name="search_email"]');
      const submitBtn = form.querySelector('button[type="submit"]');
      const resultEl = document.getElementById('regResult');
      const hintEl = document.getElementById('regHint');

      form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const email = (input && input.value ? input.value : '').trim();
        const action = form.getAttribute('action') || 'accueil.php';
        const base = action.split('#')[0];

        const params = new URLSearchParams();
        params.set('check_registration', '1');
        params.set('search_email', email);
        params.set('ajax', '1');

        if (submitBtn) submitBtn.disabled = true;
        form.setAttribute('aria-busy', 'true');

        try {
          const res = await fetch(base + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' }
          });
          if (!res.ok) throw new Error('bad response');
          const data = await res.json();

          if (resultEl) {
            resultEl.textContent = data.message || "Une erreur est survenue.";
            resultEl.className = 'reg-result ' + (data.status || 'warn');
            resultEl.style.display = 'inline-block';
          }
          if (hintEl) {
            hintEl.style.display = 'none';
          }
        } catch (err) {
          form.submit();
          return;
        } finally {
          if (submitBtn) submitBtn.disabled = false;
          form.removeAttribute('aria-busy');
        }
      });
    })();

    // ===== PARTNER FORM =====
    (function(){
      const form = document.getElementById('partnerForm');
      if (!form) return;

      // Liste indicative côté client (le serveur reste autoritaire)
      const FREE_DOMAINS = new Set([
        'gmail.com','googlemail.com','yahoo.com','yahoo.fr','yahoo.be','yahoo.co.uk',
        'hotmail.com','hotmail.fr','hotmail.be','hotmail.co.uk',
        'outlook.com','outlook.fr','outlook.be','live.com','live.fr','live.be',
        'msn.com','icloud.com','me.com','mac.com','aol.com',
        'free.fr','sfr.fr','orange.fr','wanadoo.fr','laposte.net',
        'bbox.fr','numericable.fr','club-internet.fr','alice.fr',
        'protonmail.com','proton.me','tutanota.com','tutamail.com',
        'yopmail.com','mailinator.com','guerrillamail.com','tempmail.com'
      ]);
      function isFreeDomain(email) {
        const at = email.lastIndexOf('@');
        if (at < 0) return false;
        return FREE_DOMAINS.has(email.slice(at + 1).toLowerCase());
      }

      async function sendPartnerRequest(email, confirmed, payload) {
        const btn    = document.getElementById('partnerSubmitBtn');
        const result = document.getElementById('partnerResult');
        btn.disabled = true;
        try {
          const body = Object.assign({ email: email, confirmed: confirmed }, payload || {});
          const res = await fetch('../config/api.php?route=partner-request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
          });
          const data = await res.json();
          if (data.ok) {
            result.style.display = 'block';
            result.style.background = '#d1fae5';
            result.style.color = '#065f46';
            result.style.border = '1px solid #6ee7b7';
            result.textContent = data.message;
            form.reset();
          } else if (data.err === 'non_pro') {
            result.style.display = 'none';
            btn.disabled = false;
            // Cas rare : client a cru "pro" mais le serveur dit non. On ré-ouvre le popup,
            // puis un nouveau captcha avant l'envoi confirmé.
            showNonProPopup(email);
          } else if (data.err === 'captcha') {
            result.style.display = 'none';
            btn.disabled = false;
            // Le captcha a expiré ou la réponse était fausse : on relance le modal
            openCaptchaModal(email, confirmed);
          } else {
            result.style.display = 'block';
            result.style.background = '#fee2e2';
            result.style.color = '#991b1b';
            result.style.border = '1px solid #fca5a5';
            result.textContent = data.err || 'Une erreur est survenue.';
            btn.disabled = false;
          }
        } catch (err) {
          result.style.display = 'block';
          result.style.background = '#fee2e2';
          result.style.color = '#991b1b';
          result.style.border = '1px solid #fca5a5';
          result.textContent = 'Erreur de connexion. Réessayez.';
          btn.disabled = false;
        }
      }

      function showNonProPopup(email) {
        const overlay = document.createElement('div');
        overlay.id = 'partnerPopupOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';

        overlay.innerHTML = `
          <div style="background:#fff;border-radius: var(--radius-lg);padding:2rem;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center;">
            <div style="font-size:2.5rem;margin-bottom:.75rem;">💌</div>
            <h3 style="margin:0 0 .75rem;color:#1e293b;font-size:1.15rem;font-weight:700;">Un email personnel détecté</h3>
            <p style="color:#475569;font-size:.92rem;line-height:1.6;margin:0 0 1.25rem;">
              L'adresse <strong style="color:#db2777;">${escHtml(email)}</strong> semble être une adresse personnelle.<br>
              Pour les partenariats professionnels, nous recommandons d'utiliser votre email d'entreprise.<br><br>
              <span style="color:#64748b;font-size:.85rem;">Vous pouvez quand même envoyer votre demande si vous le souhaitez !</span>
            </p>
            <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;">
              <button id="partnerPopupCancel" style="background:#f1f5f9;border:none;border-radius:.6rem;padding:.6rem 1.25rem;font-size:.9rem;font-weight:600;color:#475569;cursor:pointer;">Modifier mon email</button>
              <button id="partnerPopupConfirm" style="background:linear-gradient(135deg,#e91e8c,#c2166a);border:none;border-radius:.6rem;padding:.6rem 1.25rem;font-size:.9rem;font-weight:600;color:#fff;cursor:pointer;">Envoyer quand même</button>
            </div>
          </div>`;

        document.body.appendChild(overlay);

        document.getElementById('partnerPopupCancel').onclick = function() {
          overlay.remove();
          document.getElementById('partnerEmail').focus();
        };
        document.getElementById('partnerPopupConfirm').onclick = function() {
          overlay.remove();
          // Après la confirmation du popup non-pro : ouvrir le captcha
          openCaptchaModal(email, true);
        };
        overlay.addEventListener('click', function(e) {
          if (e.target === overlay) {
            overlay.remove();
            document.getElementById('partnerEmail').focus();
          }
        });
      }

      // Chargement paresseux du script Turnstile (une seule fois)
      let turnstileLoading = null;
      function ensureTurnstileScript() {
        if (window.turnstile) return Promise.resolve();
        if (turnstileLoading) return turnstileLoading;
        turnstileLoading = new Promise(function(resolve, reject) {
          const s = document.createElement('script');
          s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
          s.async = true; s.defer = true;
          s.onload = function(){ resolve(); };
          s.onerror = function(){ turnstileLoading = null; reject(new Error('turnstile_load_failed')); };
          document.head.appendChild(s);
        });
        return turnstileLoading;
      }

      function openCaptchaModal(email, confirmed) {
        const overlay = document.createElement('div');
        overlay.id = 'partnerCaptchaOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
        overlay.innerHTML = `
          <div style="background:#fff;border-radius: var(--radius-lg);padding:1.75rem;max-width:400px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center;">
            <h3 style="margin:0 0 .25rem;color:#1e293b;font-size:1.1rem;font-weight:700;">Vérification anti-robot</h3>
            <p style="color:#64748b;font-size:.85rem;margin:0 0 1rem;">Confirmez que vous n'êtes pas un robot pour envoyer votre demande.</p>

            <!-- Zone Turnstile (Cloudflare) -->
            <div id="partnerTurnstileBox" style="display:none;margin:0 auto 1rem;display:flex;justify-content:center;min-height:65px;"></div>

            <!-- Zone fallback maths -->
            <div id="partnerMathBox" style="display:none;">
              <div id="partnerCaptchaQuestion" style="font-size:1.25rem;font-weight:700;color:#1e293b;margin-bottom:.85rem;min-height:1.6em;">Chargement…</div>
              <input id="partnerCaptchaAnswer" type="text" inputmode="numeric" autocomplete="off" placeholder="Votre réponse"
                style="width:100%;padding:.65rem .8rem;border:1px solid #cbd5e1;border-radius:.55rem;font-size:1rem;text-align:center;margin-bottom:.5rem;outline:none;" />
            </div>

            <div id="partnerCaptchaError" style="color:#b91c1c;font-size:.82rem;min-height:1.1em;margin-bottom:.5rem;"></div>
            <div style="display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;">
              <button id="partnerCaptchaCancel" type="button" style="background:#f1f5f9;border:none;border-radius:.55rem;padding:.55rem 1.1rem;font-size:.88rem;font-weight:600;color:#475569;cursor:pointer;">Annuler</button>
              <button id="partnerCaptchaReload" type="button" title="Recharger" style="background:#e2e8f0;border:none;border-radius:.55rem;padding:.55rem .85rem;font-size:.88rem;font-weight:600;color:#334155;cursor:pointer;display:none;">↻</button>
              <button id="partnerCaptchaSubmit" type="button" style="background:linear-gradient(135deg,#e91e8c,#c2166a);border:none;border-radius:.55rem;padding:.55rem 1.1rem;font-size:.88rem;font-weight:600;color:#fff;cursor:pointer;" disabled>Envoyer la demande</button>
            </div>
          </div>`;
        document.body.appendChild(overlay);

        const tsBox  = overlay.querySelector('#partnerTurnstileBox');
        const mBox   = overlay.querySelector('#partnerMathBox');
        const qEl    = overlay.querySelector('#partnerCaptchaQuestion');
        const aEl    = overlay.querySelector('#partnerCaptchaAnswer');
        const errEl  = overlay.querySelector('#partnerCaptchaError');
        const okBtn  = overlay.querySelector('#partnerCaptchaSubmit');
        const reBtn  = overlay.querySelector('#partnerCaptchaReload');
        const cxBtn  = overlay.querySelector('#partnerCaptchaCancel');

        let mode = null;          // 'turnstile' | 'math'
        let mathToken = null;
        let tsToken = null;
        let tsWidgetId = null;
        let didFallback = false;  // évite la boucle infinie de fallback

        function close() {
          if (tsWidgetId !== null && window.turnstile) {
            try { window.turnstile.remove(tsWidgetId); } catch(e){}
          }
          overlay.remove();
        }

        function setError(msg) { errEl.textContent = msg || ''; }

        async function switchToMathFallback(reason) {
          if (didFallback) { setError(reason || 'Échec du captcha. Réessayez.'); return; }
          didFallback = true;
          setError('Vérification indisponible — bascule sur un captcha de secours…');
          if (tsWidgetId !== null && window.turnstile) {
            try { window.turnstile.remove(tsWidgetId); } catch(e){}
            tsWidgetId = null;
          }
          try {
            const r = await fetch('../config/api.php?route=partner-captcha-init&fallback=1', { method: 'GET' });
            const j = await r.json();
            if (!j || !j.ok || j.mode !== 'math') throw new Error('fallback');
            mode = 'math';
            tsBox.style.display = 'none';
            mBox.style.display = 'block';
            reBtn.style.display = 'inline-block';
            mathToken = j.token;
            qEl.textContent = j.question;
            aEl.value = '';
            okBtn.disabled = false;
            setError('');
            aEl.focus();
          } catch (e) {
            setError('Impossible d\'afficher le captcha de secours. Réessayez plus tard.');
          }
        }

        async function init() {
          setError('');
          okBtn.disabled = true;
          try {
            const r = await fetch('../config/api.php?route=partner-captcha-init', { method: 'GET' });
            const j = await r.json();
            if (!j || !j.ok) throw new Error('init');
            mode = j.mode;

            if (mode === 'turnstile') {
              tsBox.style.display = 'flex';
              mBox.style.display = 'none';
              reBtn.style.display = 'none';
              try {
                await ensureTurnstileScript();
                tsWidgetId = window.turnstile.render(tsBox, {
                  sitekey: j.sitekey,
                  theme: 'light',
                  callback: function(token) { tsToken = token; okBtn.disabled = false; setError(''); },
                  'error-callback': function() { tsToken = null; okBtn.disabled = true; switchToMathFallback('Échec du captcha Cloudflare.'); },
                  'expired-callback': function() { tsToken = null; okBtn.disabled = true; setError('Captcha expiré. Réessayez.'); }
                });
              } catch (e) {
                switchToMathFallback('Impossible de charger Cloudflare.');
              }
            } else {
              // mode math
              tsBox.style.display = 'none';
              mBox.style.display = 'block';
              reBtn.style.display = 'inline-block';
              mathToken = j.token;
              qEl.textContent = j.question;
              aEl.value = '';
              okBtn.disabled = false;
              aEl.focus();
            }
          } catch (e) {
            setError('Impossible d\'initialiser le captcha. Réessayez.');
          }
        }

        cxBtn.onclick = close;
        reBtn.onclick = init;
        okBtn.onclick = function() {
          if (mode === 'turnstile') {
            if (!tsToken) { setError('Veuillez compléter la vérification.'); return; }
            close();
            sendPartnerRequest(email, confirmed, { turnstile_token: tsToken });
          } else if (mode === 'math') {
            const ans = aEl.value.trim();
            if (!ans) { setError('Saisissez votre réponse.'); aEl.focus(); return; }
            if (!mathToken) { setError('Captcha indisponible.'); return; }
            close();
            sendPartnerRequest(email, confirmed, { captcha_token: mathToken, captcha_answer: ans });
          }
        };
        aEl.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') { e.preventDefault(); okBtn.click(); }
        });
        overlay.addEventListener('click', function(e) {
          if (e.target === overlay) close();
        });

        init();
      }

      function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }

      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const email = document.getElementById('partnerEmail').value.trim();
        if (!email) return;
        // Email perso connu → popup d'abord, captcha ensuite
        if (isFreeDomain(email)) {
          showNonProPopup(email);
        } else {
          openCaptchaModal(email, false);
        }
      });
    })();

        // ===== COUNTDOWN =====
    (function(){
      // Modifie la date/heure ici si besoin (YYYY-MM-DDTHH:MM:SS)
      const target = new Date("<?= $date_formatted ?>");

      const elDays = document.getElementById('cd_days');
      const elHours = document.getElementById('cd_hours');
      const elMinutes = document.getElementById('cd_minutes');
      const elSeconds = document.getElementById('cd_seconds');

      function pad(n){ return String(n).padStart(2, '0'); }

      function tick(){
        const now = new Date();
        let diff = target.getTime() - now.getTime();
        if(diff < 0) diff = 0;

        const totalSeconds = Math.floor(diff / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        elDays.textContent = String(days);
        elHours.textContent = pad(hours);
        elMinutes.textContent = pad(minutes);
        elSeconds.textContent = pad(seconds);
      }

      tick();
      setInterval(tick, 1000);
    })();
    // ===== TIMELINE S-CURVE DRAW =====
    (function(){
      const path = document.querySelector('.timeline-path');
      if (!path) return;

      const pathLength = path.getTotalLength();
      path.style.strokeDasharray = pathLength;
      path.style.strokeDashoffset = pathLength;

      function updatePath(){
        const timeline = document.querySelector('.timeline-track');
        if (!timeline) return;
        const rect = timeline.getBoundingClientRect();
        const windowHeight = window.innerHeight;
        const anchorY = windowHeight * 0.65; // point de tracé ~milieu d'écran
        const height = Math.max(rect.height, 1);
        let progress = (anchorY - rect.top) / height;
        progress = Math.min(Math.max(progress, 0), 1);
        path.style.strokeDashoffset = pathLength * (1 - progress);
      }

    window.addEventListener('scroll', updatePath, { passive: true });
    window.addEventListener('resize', updatePath);
    updatePath();
  })();

    // ===== Keep reg-bar 30px below bottom bar (mobile, no scroll) =====
    (function(){
      const demoCard = document.querySelector('.demo-card');
      const community = document.querySelector('.reg-bar') || document.querySelector('.community-section');
      const bottomBar = document.getElementById('mobileBottomBar');

      if (!demoCard || !community || !bottomBar) return;

      let locked = false;

      function isMobile(){
        return window.matchMedia('(max-width: 1040px)').matches;
      }

      function getDemoAbsoluteTop(){
        // Position absolue du demoCard dans la page (indépendante du scroll)
        let top = 0;
        let el = demoCard;
        while (el) {
          top += el.offsetTop;
          el = el.offsetParent;
        }
        return top;
      }

      function updateHeroHeight(){
        if (!isMobile()){
          demoCard.style.removeProperty('--demo-card-height');
          return;
        }

        const bottomInner = bottomBar.querySelector('.mobile-bottom-actions') || bottomBar;
        const bottomRect = bottomInner.getBoundingClientRect();
        if (!bottomRect.height) return;

        const gapAboveBar = 15;
        // La navbar est fixed : bottomRect.top est sa vraie position viewport (constante)
        // demoAbsoluteTop est la position absolue dans la page (indépendante du scroll)
        const demoAbsoluteTop = getDemoAbsoluteTop();
        let nextHeight = bottomRect.top - gapAboveBar - demoAbsoluteTop;

        if (!Number.isFinite(nextHeight)) return;

        const minHeight = 260;
        const maxHeight = window.innerHeight;
        nextHeight = Math.max(minHeight, Math.min(maxHeight, nextHeight));

        demoCard.style.setProperty('--demo-card-height', `${Math.round(nextHeight)}px`);
        if (!demoCard.classList.contains('ready')) {
          requestAnimationFrame(() => demoCard.classList.add('ready'));
        }
      }

      function scheduleUpdate(){
        requestAnimationFrame(() => requestAnimationFrame(() => updateHeroHeight()));
      }

      // Sur desktop, afficher directement
      if (!isMobile()) demoCard.classList.add('ready');

      window.addEventListener('load', () => {
        scheduleUpdate();
        setTimeout(() => scheduleUpdate(), 300);
        setTimeout(() => scheduleUpdate(), 800);
      });
      window.addEventListener('resize', () => scheduleUpdate());
      window.addEventListener('orientationchange', () => scheduleUpdate());
      scheduleUpdate();
    })();

    // ===== Badge fee tooltip =====
    (function(){
      const badge = document.getElementById('badgeFee');
      if (!badge) return;
      badge.addEventListener('click', function(e){
        e.stopPropagation();
        badge.classList.toggle('expanded');
      });
      document.addEventListener('click', function(){
        badge.classList.remove('expanded');
      });

      // Auto-open once per browser session (cleared when browser closes)
      try {
        if (!sessionStorage.getItem('badgeFeeAutoShown')) {
          sessionStorage.setItem('badgeFeeAutoShown', '1');
          setTimeout(function(){
            badge.classList.add('expanded');
            setTimeout(function(){
              badge.classList.remove('expanded');
            }, 7000);
          }, 2000);
        }
      } catch(e) {}
    })();

    // ===== Video Play/Pause toggle =====
    (function(){
      const vid = document.getElementById('heroVideo');
      const btn = document.getElementById('videoToggle');
      if (!vid || !btn) return;
      const iconPause = btn.querySelector('.vt-pause');
      const iconPlay  = btn.querySelector('.vt-play');
      btn.addEventListener('click', function(){
        if (vid.paused) {
          vid.play();
          iconPause.style.display = '';
          iconPlay.style.display  = 'none';
          btn.setAttribute('aria-label', 'Pause la vidéo');
        } else {
          vid.pause();
          iconPause.style.display = 'none';
          iconPlay.style.display  = '';
          btn.setAttribute('aria-label', 'Lancer la vidéo');
        }
      });
    })();
  </script>

<?php
// Charge le JS de lightbox + transformation PDF uniquement si au moins un bloc
// personnalisé est présent et visible dans le layout (format V2 : rows > columns > section).
$hasVisibleCustom = false;
foreach ($accueilLayout as $_row) {
    if (empty($_row['columns']) || !is_array($_row['columns'])) continue;
    foreach ($_row['columns'] as $_col) {
        $_sec = $_col['section'] ?? null;
        if (is_array($_sec) && ($_sec['type'] ?? '') === 'custom' && !empty($_sec['visible']) && trim((string)($_sec['content'] ?? '')) !== '') {
            $hasVisibleCustom = true; break 2;
        }
    }
}
?>
<?php if ($hasVisibleCustom): ?>
<!-- Lightbox + PDF pour contenu personnalisé -->
<div class="cc-lightbox" id="ccLightbox">
  <span class="cc-lightbox-close" id="ccLbClose">&times;</span>
  <img class="cc-lightbox-img" id="ccLbImg" src="" alt="">
</div>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  // Transformer les liens PDF en boutons stylés
  var seenPdf = {};
  document.querySelectorAll('.custom-content-inner a[href$=".pdf"]').forEach(function(a) {
    var href = a.getAttribute('href');
    if (seenPdf[href]) { a.remove(); return; }
    seenPdf[href] = true;
    var raw = (a.title || href.split('/').pop()).replace(/\.[^.]+$/, '');
    var name = /^tiny_[a-f0-9.]+$/.test(raw) ? 'Document' : raw;
    a.className = 'cc-pdf-link';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.innerHTML = '<span class="cc-pdf-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg></span><span class="cc-pdf-info"><span class="cc-pdf-name">' + name + '.pdf</span><span class="cc-pdf-hint">Cliquer pour ouvrir</span></span>';
  });

  // Lightbox pour images
  var lb = document.getElementById('ccLightbox');
  var lbImg = document.getElementById('ccLbImg');
  if (lb) {
    document.querySelectorAll('.custom-content-inner img').forEach(function(img) {
      img.addEventListener('click', function() { lbImg.src = img.src; lb.classList.add('active'); });
    });
    document.getElementById('ccLbClose').addEventListener('click', function() { lb.classList.remove('active'); });
    lb.addEventListener('click', function(e) { if (e.target === lb) lb.classList.remove('active'); });
  }
})();
</script>
<?php endif; ?>

<?php if ($isEditorMode): ?>
<!-- interact.js : drag libre + resize 4-coins (image partenaires, timer Hero, sous-titre, etc.) -->
<script src="https://cdn.jsdelivr.net/npm/interactjs@1.10.27/dist/interact.min.js"></script>
<!-- ═══════════════════════════════════════════════════════════════════
     MODE ÉDITEUR : communication avec le parent (setting.php) via postMessage
     - Au load, envoie la liste des rangées + colonnes avec leurs rects
     - Sur clic d'une section → envoie le sectionId au parent
     - Sur edit d'un élément → envoie l'event au parent qui gère le modal
     - Édition inline pour text + tinymce, modal pour image/vidéo
     ═══════════════════════════════════════════════════════════════════ -->
<style>
  /* Mode éditeur : surfaces pour aider le parent à positionner ses overlays */
  /* CRITIQUE : en mode éditeur, on neutralise `min-height: 100vh` + `display: flex`
     du body sinon le body grandit avec l'iframe → docHeight envoyée au parent grandit
     → parent agrandit l'iframe → body grandit encore → BOUCLE INFINIE (espace blanc
     qui s'accroit sous le footer toutes les secondes). */
  body.editor-mode {
    overflow-x: hidden; cursor: default;
    min-height: 0 !important;
    display: block !important;
  }
  body.editor-mode main { flex: none !important; }
  body.editor-mode .demo-video, body.editor-mode .video-toggle { pointer-events: none; }
  body.editor-mode .accueil-row, body.editor-mode .accueil-col {
    position: relative; outline: 0;
    transition: outline .12s, background .12s;
  }
  body.editor-mode .accueil-row:hover { outline: 2px dashed rgba(244,33,130,.5); outline-offset: -2px; }
  body.editor-mode .accueil-col:hover { outline: 2px dashed rgba(244,33,130,.7); outline-offset: -2px; }
  body.editor-mode .accueil-col-hidden {
    opacity: 0.4;
    background: repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(244,33,130,.05) 10px, rgba(244,33,130,.05) 20px);
  }
  /* Bloc HTML brut en mode éditeur : cursor pointer + hover indicator.
     Le contenu placeholder (texte cliquable) est rendu côté serveur dans le bloc. */
  body.editor-mode .custom-html-section { cursor: pointer; min-height: 40px; }
  body.editor-mode .custom-html-section:hover {
    outline: 2px dashed #6366f1; outline-offset: -2px;
  }
  /* Fallback si le bloc est vraiment vide */
  body.editor-mode .custom-html-section:empty::before {
    content: '< > Cliquer pour modifier ce code…';
    display: block; padding: 20px; text-align: center;
    color: #6366f1; font-family: monospace; font-size: 13px;
    border: 2px dashed #c7d2fe; border-radius: 8px;
  }
  body.editor-mode [data-edit-field] {
    cursor: pointer; outline: 1px dashed transparent; outline-offset: 2px;
    transition: outline .12s;
  }
  body.editor-mode [data-edit-field]:hover { outline-color: #F42182; }
  /* Désactive tous les liens et formulaires en mode éditeur */
  body.editor-mode a, body.editor-mode button[type="submit"] { pointer-events: none !important; }
  body.editor-mode form { pointer-events: none; }
  body.editor-mode [data-edit-field],
  body.editor-mode [data-editor-row-id],
  body.editor-mode [data-editor-col-idx] { pointer-events: auto !important; }
  /* Override spécifique : un button[type=submit] avec data-edit-field doit rester
     cliquable (sinon la règle "button[type=submit]" plus spécifique bloque le hover/click).
     Sans cette règle : Vérifier → et Devenir partenaire ne sont pas éditables. */
  body.editor-mode button[type="submit"][data-edit-field],
  body.editor-mode a[data-edit-field] { pointer-events: auto !important; cursor: pointer !important; }
  /* Force clickable les éléments éditables même imbriqués */
  body.editor-mode .demo-overlay { pointer-events: auto !important; }
  body.editor-mode .demo-overlay > * { pointer-events: none; }
  body.editor-mode .demo-overlay [data-edit-field] { pointer-events: auto !important; }
  body.editor-mode .countdown-wrap[data-edit-field] {
    cursor: pointer; outline: 2px dashed transparent; outline-offset: 4px; transition: outline .12s;
  }
  body.editor-mode .countdown-wrap[data-edit-field]:hover { outline-color: #F42182; }

  /* Guides d'alignement (apparaissent pendant le drag d'un élément) */
  body.editor-mode .align-guide {
    position: absolute; pointer-events: none; z-index: 9999;
    background: #ec4899;
    box-shadow: 0 0 4px rgba(236,72,153,.6);
  }
  body.editor-mode .align-guide.vertical   { width: 1px; }
  body.editor-mode .align-guide.horizontal { height: 1px; }
  body.editor-mode #alignment-guides-container {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    pointer-events: none; z-index: 9998;
  }

  /* CRITIQUE : neutralise les unités vh qui causent une boucle infinie de redimensionnement
     dans l'iframe (le Hero a height: 70vh → si iframe = 1500px, Hero = 1050px → iframe + grand → ...)
     En éditeur on fixe les hauteurs basées sur vh à des valeurs absolues. */
  body.editor-mode .demo-card {
    height: 600px !important;
    min-height: 600px !important;
    max-height: 600px !important;
  }
  body.editor-mode .timeline-svg { /* la timeline aussi peut avoir vh */
    max-height: none !important;
  }
  /* (overflow-x: hidden déjà déclaré plus haut dans body.editor-mode) */
</style>
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
(function(){
  document.body.classList.add('editor-mode');

  // Désactive l'autoplay et les redirections
  var heroVideo = document.getElementById('heroVideo');
  if (heroVideo) { heroVideo.removeAttribute('autoplay'); try { heroVideo.pause(); } catch(e) {} heroVideo.muted = true; }

  // Envoie au parent la structure du layout avec les positions (rects)
  function sendLayoutStructure() {
    var rows = [];
    document.querySelectorAll('[data-editor-row-id]').forEach(function(rowEl) {
      var rowRect = rowEl.getBoundingClientRect();
      var cols = [];
      rowEl.querySelectorAll('[data-editor-col-idx]').forEach(function(colEl) {
        var colRect = colEl.getBoundingClientRect();
        cols.push({
          colIdx: parseInt(colEl.dataset.editorColIdx, 10),
          width: parseInt(colEl.dataset.editorColWidth, 10),
          sectionType: colEl.dataset.editorSectionType,
          sectionId: colEl.dataset.editorSectionId || null,
          visible: colEl.dataset.editorColVisible === '1',
          rect: { top: colRect.top + window.scrollY, left: colRect.left, width: colRect.width, height: colRect.height }
        });
      });
      // Liste des éléments éditables dans cette row :
      //   - textes hardcodés (data-edit-field) : textes du hero, reg_bar.kicker, etc.
      //   - blocs custom (data-editor-section-type="custom") : un par colonne, text ou html
      var editables = [];
      rowEl.querySelectorAll('[data-edit-field]').forEach(function(ed) {
        var kind = ed.dataset.editKind || 'text';
        var field = ed.dataset.editField;
        var preview = '';
        if (kind === 'tinymce' || kind === 'text') {
          preview = (ed.textContent || '').trim().slice(0, 60);
        } else if (kind === 'image' || kind === 'video') {
          var srcEl = ed.tagName === 'IMG' ? ed : ed.querySelector('source,img');
          preview = srcEl ? ((srcEl.getAttribute('src') || '').split('/').pop()) : '';
        } else if (kind === 'size-only') {
          preview = '';
        }
        editables.push({ field: field, kind: kind, preview: preview });
      });
      // Blocs custom (text ou html) de la row — un par colonne custom
      rowEl.querySelectorAll('[data-editor-col-idx]').forEach(function(colEl) {
        if (colEl.dataset.editorSectionType !== 'custom') return;
        var sectionId = colEl.dataset.editorSectionId;
        if (!sectionId) return;
        var isHtml = !!colEl.querySelector('.custom-html-section');
        var preview = (colEl.textContent || '').trim().slice(0, 60);
        editables.push({
          field: sectionId,
          kind: isHtml ? 'custom-html' : 'custom-text',
          sectionId: sectionId,
          preview: preview
        });
      });
      rows.push({
        rowId: rowEl.dataset.editorRowId,
        rowIdx: parseInt(rowEl.dataset.editorRowIdx, 10),
        rect: { top: rowRect.top + window.scrollY, left: rowRect.left, width: rowRect.width, height: rowRect.height },
        cols: cols,
        editables: editables
      });
    });
    parent.postMessage({
      type: 'editor-layout',
      rows: rows,
      docHeight: Math.max(document.body.scrollHeight, document.documentElement.scrollHeight)
    }, '*');
  }

  // Envoie au load + sur resize (debounced 200ms pour éviter les boucles)
  var resizeTimer = null;
  var lastDocHeight = 0;
  function debouncedSend() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
      var newH = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
      // Ignore les changements de < 5px (parasites quand le parent ajuste l'iframe height)
      if (Math.abs(newH - lastDocHeight) > 5) {
        lastDocHeight = newH;
        sendLayoutStructure();
      }
    }, 200);
  }
  window.addEventListener('load', function() { setTimeout(function() { lastDocHeight = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight); sendLayoutStructure(); }, 100); });
  window.addEventListener('resize', debouncedSend);

  // Récupère les infos d'un élément data-edit-field
  function getEditPayload(ed, type) {
    var src = '';
    if (ed.tagName === 'IMG') src = ed.getAttribute('src') || '';
    else if (ed.querySelector('source')) src = ed.querySelector('source').getAttribute('src') || '';
    return {
      type: type,
      field: ed.dataset.editField,
      kind: ed.dataset.editKind,
      section: ed.dataset.editSection,
      sizeKey: ed.dataset.editSize || null,
      sizeCurrent: parseInt(ed.dataset.editSizeCurrent || '100', 10),
      currentValue: (ed.dataset.editKind === 'tinymce') ? ed.innerHTML
                     : (ed.dataset.editKind === 'text') ? ed.textContent.trim()
                     : src.split('/').pop()
    };
  }

  // SINGLE CLICK : sélectionne, montre le slider de taille dans la sidebar parent
  document.addEventListener('click', function(e) {
    var ed = e.target.closest('[data-edit-field]');
    if (ed) {
      e.preventDefault(); e.stopPropagation();
      parent.postMessage(getEditPayload(ed, 'editor-select-edit'), '*');
      return;
    }

    // Clic sur le contenu d'un bloc custom (texte WYSIWYG OU code HTML) → ouvre
    // l'éditeur approprié pour CETTE colonne (le parent route selon le kind).
    var customInner = e.target.closest('.custom-content-inner, .custom-html-section');
    if (customInner) {
      var colEl = customInner.closest('[data-editor-section-id]');
      var rowEl = customInner.closest('[data-editor-row-id]');
      if (colEl && rowEl) {
        e.preventDefault(); e.stopPropagation();
        parent.postMessage({
          type: 'editor-edit-custom-col',
          rowId: rowEl.dataset.editorRowId,
          colIdx: parseInt(colEl.dataset.editorColIdx, 10),
          sectionId: colEl.dataset.editorSectionId
        }, '*');
        return;
      }
    }

    var col = e.target.closest('[data-editor-col-idx]');
    var row = e.target.closest('[data-editor-row-id]');
    if (col || row) {
      e.preventDefault();
      parent.postMessage({
        type: 'editor-click-section',
        rowId: row ? row.dataset.editorRowId : null,
        rowIdx: row ? parseInt(row.dataset.editorRowIdx, 10) : null,
        colIdx: col ? parseInt(col.dataset.editorColIdx, 10) : null,
        sectionType: col ? col.dataset.editorSectionType : null
      }, '*');
    }
  }, true);

  // CLIC DROIT : menu contextuel transmis au parent (sidebar) pour proposer toutes
  // les actions sur l'élément ciblé (modifier, supprimer, masquer, extraire, etc.)
  document.addEventListener('contextmenu', function(e) {
    var col = e.target.closest('[data-editor-col-idx]');
    var row = e.target.closest('[data-editor-row-id]');
    if (!col && !row) return;
    e.preventDefault();
    parent.postMessage({
      type: 'editor-contextmenu',
      // Position dans l'iframe (le parent ajoutera l'offset de l'iframe)
      x: e.clientX,
      y: e.clientY,
      rowId: row ? row.dataset.editorRowId : null,
      colIdx: col ? parseInt(col.dataset.editorColIdx, 10) : null,
      sectionType: col ? col.dataset.editorSectionType : null,
      sectionId: col ? col.dataset.editorSectionId : null
    }, '*');
  }, true);

  // DOUBLE CLICK : édition inline pour les textes simples, modal pour le reste
  document.addEventListener('dblclick', function(e) {
    var ed = e.target.closest('[data-edit-field]');
    if (!ed || ed.dataset.editKind === 'size-only') return;
    e.preventDefault(); e.stopPropagation();

    // Édition INLINE pour les textes simples (kind=text)
    if (ed.dataset.editKind === 'text') {
      var origValue = ed.textContent.trim();
      ed.setAttribute('contenteditable', 'true');
      ed.style.outline = '2px solid #F42182';
      ed.style.background = 'rgba(244,33,130,0.08)';
      ed.focus();
      var range = document.createRange();
      range.selectNodeContents(ed);
      range.collapse(false);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);

      function finishEdit() {
        ed.removeAttribute('contenteditable');
        ed.style.outline = '';
        ed.style.background = '';
        var newValue = ed.textContent.trim();
        if (newValue !== origValue) {
          parent.postMessage({
            type: 'editor-save-inline',
            field: ed.dataset.editField,
            value: newValue
          }, '*');
        }
        ed.removeEventListener('blur', finishEdit);
        ed.removeEventListener('keydown', onKey);
      }
      function onKey(ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); ed.blur(); }
        if (ev.key === 'Escape') { ev.preventDefault(); ed.textContent = origValue; ed.blur(); }
      }
      ed.addEventListener('blur', finishEdit);
      ed.addEventListener('keydown', onKey);
      return;
    }

    // Pour TinyMCE / image / vidéo : tout passe par la sidebar côté parent
    // (l'édition inline TinyMCE était instable, on retire et on laisse l'input sidebar gérer)
    parent.postMessage(getEditPayload(ed, 'editor-dblclick-edit'), '*');
  }, true);

  // ── Drag libre + resize 4-coins via interact.js + GUIDES D'ALIGNEMENT ──
  if (typeof interact !== 'undefined') {
    function postGeometry(field, geom) {
      parent.postMessage({ type: 'editor-save-geometry', field: field, geometry: geom }, '*');
    }

    // Conteneur pour les guides d'alignement (créé une fois)
    var guidesContainer = document.createElement('div');
    guidesContainer.id = 'alignment-guides-container';
    document.body.appendChild(guidesContainer);

    var GUIDE_TOLERANCE = 12; // px : zone d'affichage du guide visuel
    var SNAP_TOLERANCE  = 2;  // px : zone de snap (verrouille quand on passe à proximité, sans bloquer)

    function clearGuides() { guidesContainer.innerHTML = ''; }

    function drawGuide(orientation, position) {
      var line = document.createElement('div');
      line.className = 'align-guide ' + orientation;
      if (orientation === 'vertical') {
        line.style.left = position + 'px';
        line.style.top = '0'; line.style.bottom = '0';
      } else {
        line.style.top = position + 'px';
        line.style.left = '0'; line.style.right = '0';
      }
      guidesContainer.appendChild(line);
    }

    // Calcule guides + snap doux. Retourne {dx, dy} si snap, sinon 0.
    function computeAlignment(currentEl, currentRect) {
      clearGuides();
      var currentEdges = {
        v: [currentRect.left, currentRect.left + currentRect.width / 2, currentRect.right],
        h: [currentRect.top,  currentRect.top  + currentRect.height / 2, currentRect.bottom]
      };
      var snapDx = 0, snapDy = 0;
      var bestVDist = SNAP_TOLERANCE + 1, bestHDist = SNAP_TOLERANCE + 1;
      document.querySelectorAll('[data-edit-field]').forEach(function(other) {
        if (other === currentEl) return;
        if (other.dataset.editKind === 'video') return;
        var r = other.getBoundingClientRect();
        var otherV = [r.left, r.left + r.width / 2, r.right];
        var otherH = [r.top,  r.top  + r.height / 2, r.bottom];
        currentEdges.v.forEach(function(cv) {
          otherV.forEach(function(ov) {
            var d = Math.abs(cv - ov);
            if (d <= GUIDE_TOLERANCE) drawGuide('vertical', ov);
            if (d <= SNAP_TOLERANCE && d < bestVDist) { bestVDist = d; snapDx = ov - cv; }
          });
        });
        currentEdges.h.forEach(function(ch) {
          otherH.forEach(function(oh) {
            var d = Math.abs(ch - oh);
            if (d <= GUIDE_TOLERANCE) drawGuide('horizontal', oh);
            if (d <= SNAP_TOLERANCE && d < bestHDist) { bestHDist = d; snapDy = oh - ch; }
          });
        });
      });
      return { dx: snapDx, dy: snapDy };
    }

    // Lit le scale actuel d'un élément (mémorisé dans dataset.scale, sinon parsé de
     // style.transform, sinon dérivé de data-edit-size-current). Sert à préserver le
     // scale du slider de taille pendant un drag/resize.
    function getElementScale(el) {
      if (el.dataset.scale) return parseFloat(el.dataset.scale) || 1;
      var t = el.style.transform || '';
      var m = t.match(/scale\(([\d.]+)\)/);
      if (m) { el.dataset.scale = m[1]; return parseFloat(m[1]); }
      if (el.dataset.editSizeCurrent) {
        var sz = parseInt(el.dataset.editSizeCurrent, 10) / 100;
        if (sz && sz !== 1) { el.dataset.scale = sz; return sz; }
      }
      return 1;
    }

    // Construit transform = translate(...) [scale(...)] en préservant le scale courant.
    function applyTransform(el, x, y, scaleOverride) {
      var scale = (scaleOverride !== undefined) ? scaleOverride : getElementScale(el);
      var t = 'translate(' + x + 'px, ' + y + 'px)';
      if (scale && scale !== 1) {
        t += ' scale(' + scale + ')';
        el.style.transformOrigin = el.style.transformOrigin || 'left top';
      }
      el.style.transform = t;
    }

    // Liste des champs qui sont des BOUTONS d'action → édition simple (textarea sidebar),
    // PAS de drag libre ni resize 4-coins. Sinon le clic ferait apparaître les
    // poignées de redimensionnement ce que l'utilisateur ne veut pas pour ces boutons.
    var NO_DRAG_FIELDS = ['hero.cta_register', 'reg_bar.btn_check', 'partners.btn_submit'];
    var draggables = document.querySelectorAll('[data-edit-field][data-edit-section="hero"], [data-edit-field][data-edit-section="partners"]');
    draggables.forEach(function(el) {
      if (el.dataset.editKind === 'video') return;
      if (NO_DRAG_FIELDS.indexOf(el.dataset.editField) !== -1) return;
      el.style.position = el.style.position || 'relative';
      el.style.touchAction = 'none';
      el.dataset.x = el.dataset.x || '0';
      el.dataset.y = el.dataset.y || '0';

      interact(el)
        .draggable({
          listeners: {
            move: function(event) {
              var x = (parseFloat(event.target.dataset.x) || 0) + event.dx;
              var y = (parseFloat(event.target.dataset.y) || 0) + event.dy;
              applyTransform(event.target, x, y);
              event.target.dataset.x = x;
              event.target.dataset.y = y;
              // Snap actif UNIQUEMENT en mouvement lent (recherche de précision).
              // En mouvement rapide, déplacement 100% libre — pas de blocage.
              var snap = computeAlignment(event.target, event.target.getBoundingClientRect());
              var slow = (event.speed || 0) < 250; // px/sec
              if (slow && (snap.dx || snap.dy)) {
                var nx = x + snap.dx, ny = y + snap.dy;
                applyTransform(event.target, nx, ny);
                event.target.dataset.x = nx;
                event.target.dataset.y = ny;
              }
            },
            end: function(event) {
              clearGuides();
              var field = event.target.dataset.editField;
              if (!field) return;
              postGeometry(field, {
                x: parseFloat(event.target.dataset.x) || 0,
                y: parseFloat(event.target.dataset.y) || 0,
                w: event.target.offsetWidth,
                h: event.target.offsetHeight,
                scale: parseFloat(event.target.dataset.scale) || 1
              });
            }
          }
        })
        .resizable({
          edges: { left: true, right: true, bottom: true, top: true },
          modifiers: [interact.modifiers.restrictSize({ min: { width: 50, height: 20 } })],
          listeners: {
            move: function(event) {
              var x = (parseFloat(event.target.dataset.x) || 0) + event.deltaRect.left;
              var y = (parseFloat(event.target.dataset.y) || 0) + event.deltaRect.top;
              event.target.style.width  = event.rect.width + 'px';
              event.target.style.height = event.rect.height + 'px';
              applyTransform(event.target, x, y);
              event.target.dataset.x = x;
              event.target.dataset.y = y;
            },
            end: function(event) {
              var field = event.target.dataset.editField;
              if (!field) return;
              postGeometry(field, {
                x: parseFloat(event.target.dataset.x) || 0,
                y: parseFloat(event.target.dataset.y) || 0,
                w: event.target.offsetWidth,
                h: event.target.offsetHeight,
                scale: parseFloat(event.target.dataset.scale) || 1
              });
            }
          }
        });
    });
  }

  // Réception de commandes du parent (pour scroll-to-section, highlight, update DOM sans reload, etc.)
  window.addEventListener('message', function(e) {
    if (!e.data || typeof e.data !== 'object') return;

    // Édition déclenchée depuis la sidebar parent (clic sur un bouton "Modifier ce texte")
    // → simule un dblclick sur l'élément correspondant pour réutiliser le flow existant
    // (édition inline pour text simple, modal pour tinymce/image/video, etc.)
    if (e.data.type === 'parent-trigger-edit' && e.data.field) {
      var target = document.querySelector('[data-edit-field="' + e.data.field + '"]');
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var evt = new MouseEvent('dblclick', { bubbles: true, cancelable: true, view: window });
        target.dispatchEvent(evt);
      }
      return;
    }

    // Reset géométrie : retire les styles inline et le dataset.x/y/scale pour un champ
    if (e.data.type === 'parent-reset-geometry') {
      var fields = [e.data.field];
      // Champs liés (PC + mobile partagent souvent la même clé "size")
      if (e.data.field === 'titleAccueil')     fields.push('titleAccueil_mobile');
      if (e.data.field === 'subtitle_accueil') fields.push('subtitle_accueil_mobile');
      fields.forEach(function(f) {
        document.querySelectorAll('[data-edit-field="' + f + '"]').forEach(function(el) {
          el.style.transform = '';
          el.style.width = '';
          el.style.height = '';
          el.style.transformOrigin = '';
          delete el.dataset.x;
          delete el.dataset.y;
          delete el.dataset.scale;
        });
      });
      return;
    }

    // Mise à jour d'un champ texte sans reload (depuis l'édition sidebar du parent)
    if (e.data.type === 'parent-update-text') {
      var els = document.querySelectorAll('[data-edit-field="' + e.data.field + '"]');
      els.forEach(function(el) {
        if (el.dataset.editKind === 'tinymce') el.innerHTML = e.data.value;
        else el.textContent = e.data.value;
      });
      return;
    }

    // Mise à jour d'un style sans reload (taille, alignement)
    if (e.data.type === 'parent-update-style') {
      var key = e.data.key;
      var val = e.data.value;
      // Tailles : titleAccueil_size / subtitle_accueil_size / hero_timer_size
      if (key === 'titleAccueil_size') {
        document.querySelectorAll('.demo-kicker').forEach(function(el) {
          el.style.fontSize = (parseInt(val,10)/100) + 'em';
        });
      } else if (key === 'subtitle_accueil_size') {
        document.querySelectorAll('.demo-desc').forEach(function(el) {
          el.style.fontSize = (parseInt(val,10)/100) + 'em';
        });
      } else if (key === 'hero_timer_size') {
        var t = document.querySelector('.countdown-wrap');
        if (t) {
          var s = parseInt(val,10)/100;
          t.dataset.scale = s;
          // Préserve la position courante (translate) si l'utilisateur a déjà déplacé l'élément
          var tx = parseFloat(t.dataset.x) || 0;
          var ty = parseFloat(t.dataset.y) || 0;
          var transform = 'translate(' + tx + 'px, ' + ty + 'px)';
          if (s !== 1) transform += ' scale(' + s + ')';
          t.style.transform = transform;
          t.style.transformOrigin = 'left top';
          t.style.display = 'inline-block';
        }
      } else if (key.indexOf('text_align__') === 0) {
        // Alignement : key = "text_align__<field>"
        var field = key.substring('text_align__'.length);
        document.querySelectorAll('[data-edit-field="' + field + '"]').forEach(function(el) {
          el.style.textAlign = val;
        });
      }
      return;
    }

    if (e.data.type === 'editor-scroll-to') {
      var rowEl = document.querySelector('[data-editor-row-id="' + e.data.rowId + '"]');
      if (rowEl) rowEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    if (e.data.type === 'editor-highlight') {
      document.querySelectorAll('.editor-highlighted').forEach(function(x){ x.classList.remove('editor-highlighted'); });
      var sel = e.data.colIdx != null
        ? '[data-editor-row-id="' + e.data.rowId + '"] [data-editor-col-idx="' + e.data.colIdx + '"]'
        : '[data-editor-row-id="' + e.data.rowId + '"]';
      var el = document.querySelector(sel);
      if (el) {
        el.classList.add('editor-highlighted');
        el.style.outline = '3px solid #F42182';
        el.style.outlineOffset = '4px';
      }
    }
  });
})();
</script>
<?php endif; ?>

</body>
</html>
