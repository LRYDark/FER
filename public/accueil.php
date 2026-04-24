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
$accueil_custom_content = $data['accueil_custom_content'] ?? '';
$accueil_custom_position = $data['accueil_custom_position'] ?? 'off';
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
        <video class="demo-video" id="heroVideo" autoplay muted loop playsinline>
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
              <!-- PC -->
              <div class="hero-device hero-pc">
                <div class="demo-kicker"><?= $data['titleAccueil'] ?? '' ?></div>
              </div>
              <!-- Mobile -->
              <div class="hero-device hero-mobile">
                <div class="demo-kicker"><?= ($data['titleAccueil_mobile'] ?? '') ?: ($data['titleAccueil'] ?? '') ?></div>
              </div>
              <?php
                $subPC = $data['subtitle_accueil'] ?? '';
                $subMobile = $data['subtitle_accueil_mobile'] ?? '';
              ?>
              <?php if ($subPC !== ''): ?>
                <p class="demo-desc hero-device hero-pc"><?= htmlspecialchars($subPC) ?></p>
              <?php endif; ?>
              <?php if ($subMobile !== ''): ?>
                <p class="demo-desc hero-device hero-mobile"><?= htmlspecialchars($subMobile) ?></p>
              <?php elseif ($subPC !== ''): ?>
                <p class="demo-desc hero-device hero-mobile"><?= htmlspecialchars($subPC) ?></p>
              <?php endif; ?>
            </div>

            <div class="countdown-wrap">
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
              <a class="cta-pink" href="register">Je m’inscris →</a>
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

          <p
            id="regResult"
            class="reg-result <?= htmlspecialchars($searchStatus) ?>"
            aria-live="polite"
            style="<?= $searchMessage !== '' ? '' : 'display:none;' ?>"
          >
            <?= htmlspecialchars($searchMessage) ?>
          </p>
          <p
            id="regHint"
            class="reg-hint"
            style="<?= $searchMessage !== '' ? 'display:none;' : '' ?>"
          >
            Saisissez l'email utilisé lors de votre inscription.
          </p>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <?php
      $partnerDir = __DIR__ . '/files/_partners';
      $partnerWebPath = 'files/_partners';
      $partnerImages = [];
      if (is_dir($partnerDir)) {
        $files = glob($partnerDir . '/*.{png,jpg,jpeg,webp,gif,svg}', GLOB_BRACE);
        natsort($files);
        foreach ($files as $file) {
          $base = basename($file);
          $alt = preg_replace('/\\.[^.]+$/', '', $base);
          $alt = preg_replace('/[-_]+/', ' ', $alt);
          $alt = trim($alt);
          if ($alt === '') { $alt = 'Partenaire'; }
          $partnerImages[] = [
            'src' => $partnerWebPath . '/' . rawurlencode($base),
            'alt' => $alt
          ];
        }
      }
    ?>
    


    <?php if ($accueil_custom_position === 'after_inscrits' && !empty($accueil_custom_content)): ?>
    <section class="custom-content-section">
      <div class="custom-content-inner"><?= $accueil_custom_content ?></div>
    </section>
    <?php endif; ?>

    <!-- COMMUNITY SECTION (style Vimeo) -->
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
              <input
                type="email"
                id="partnerEmail"
                name="partner_email"
                class="partner-email-input"
                placeholder="Votre email professionnel"
                required
                aria-label="Email professionnel"
              >
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


<?php if ($accueil_custom_position === 'after_partners' && !empty($accueil_custom_content)): ?>
    <section class="custom-content-section pos-after-partners">
      <div class="custom-content-inner"><?= $accueil_custom_content ?></div>
    </section>
    <?php endif; ?>

<!-- TIMELINE (below video) -->
    <?php if ($isTimelinePreview): ?>
    <div style="background:#fd7e14;color:#fff;text-align:center;padding:10px;font-weight:600;font-size:14px;margin:12px auto;border-radius:8px;max-width:1200px;">
      Aperçu Timeline – Les brouillons sont visibles
    </div>
    <?php endif; ?>
    <?php if ($timelineCount > 0):
        $svg = generateTimelineSVG($timelineCount);
    ?>
    <div class="timeline-wrap">
      <section class="timeline" aria-label="Timeline">
        <div class="timeline-head">
          <h2 class="timeline-title">Historique</h2>
        </div>

        <div class="timeline-track">
          <!-- SVG S-Curve (dynamic) -->
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
    <?php endif; ?>

  </main>


  

<?php if (!empty($actualites)): ?>
<!-- NEWS BAND (latest news) -->
  <section class="news-band" aria-label="Dernières actualités">
    <div class="news-band-container">
      <div class="news-band-head">
        <h3 class="news-band-title">Dernières actualités</h3>
        <a class="news-band-link" href="news">Voir tout</a>
      </div>
      <div class="news-grid">
        <?php $news_cards = array_slice($actualites, 0, 4); ?>
        <?php if (!empty($news_cards)): ?>
          <?php foreach ($news_cards as $actu): ?>
            <?php
              if (empty($actu['title'])) continue;
              $dateLabel = '';
              $dateAttr = '';
              if (!empty($actu['date_publication'])) {
                $ts = strtotime($actu['date_publication']);
                if ($ts) {
                  $dateLabel = date('d/m/Y', $ts);
                  $dateAttr = date('Y-m-d', $ts);
                }
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
<?php endif; ?>

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

      async function sendPartnerRequest(email, confirmed) {
        const btn    = document.getElementById('partnerSubmitBtn');
        const result = document.getElementById('partnerResult');
        btn.disabled = true;
        try {
          const res = await fetch('../config/api.php?route=partner-request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, confirmed: confirmed })
          });
          const data = await res.json();
          result.style.display = 'block';
          if (data.ok) {
            result.style.background = '#d1fae5';
            result.style.color = '#065f46';
            result.style.border = '1px solid #6ee7b7';
            result.textContent = data.message;
            form.reset();
          } else if (data.err === 'non_pro') {
            // Show friendly popup
            result.style.display = 'none';
            btn.disabled = false;
            showNonProPopup(email);
          } else {
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
        // Create overlay
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
          sendPartnerRequest(email, true);
        };
        overlay.addEventListener('click', function(e) {
          if (e.target === overlay) {
            overlay.remove();
            document.getElementById('partnerEmail').focus();
          }
        });
      }

      function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }

      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const email = document.getElementById('partnerEmail').value.trim();
        sendPartnerRequest(email, false);
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

<?php if ($accueil_custom_position !== 'off' && !empty($accueil_custom_content)): ?>
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

</body>
</html>
