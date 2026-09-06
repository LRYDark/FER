<?php
require '../src/core/config.php';
checkMaintenance();
require __DIR__ . '/../src/partials/navbar-data.php';
require_once '../src/content/tracker.php';
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
            // On déchiffre côté PHP et on compare en minuscules. Certains imports ont pu stocker
            // l'email EN CLAIR : on compare aussi la valeur brute pour ne rater aucune inscription.
            try {
                $stmtSearch = $pdo->query('SELECT email FROM registrations');
                $matchCount = 0;
                $needle = strtolower($searchEmail);
                while ($row = $stmtSearch->fetch(PDO::FETCH_ASSOC)) {
                    if (strtolower((string)@decrypt($row['email'])) === $needle
                        || strtolower((string)$row['email']) === $needle) {
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
// Mode on / off / auto (planifié). En auto, s'active/désactive seul selon les dates.
$flash_info_active = flashBannerActive($data) ? 1 : 0;
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

// Chargement des styles / géométrie / textes de l'accueil AVANT le rendu du Hero.
// (Le Hero utilise $accueilStyles['subtitle_accueil_size'], $accueilStyles['hero_timer_size'],
//  $accueilGeometry[...] et $accueilTexts[...] — il faut donc qu'ils soient définis ici,
//  pas plus bas dans la section de rendu du layout.)
// En mode éditeur (?editor=1), on lit le brouillon avec fallback sur la version publiée.
// En mode live, on lit uniquement la version publiée.
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
$accueilTexts = [];
$textsRaw = $isEditorMode
    ? ($data['accueil_texts_draft'] ?? null) ?: ($data['accueil_texts'] ?? null)
    : ($data['accueil_texts'] ?? null);
if (!empty($textsRaw)) {
    $decoded = json_decode($textsRaw, true);
    if (is_array($decoded)) $accueilTexts = $decoded;
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

  <link rel="stylesheet" href="../css/accueil.css?v=<?= @filemtime(__DIR__ . '/../css/accueil.css') ?: time() ?>">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
  /* ─── --sbw : largeur RÉELLE de la barre de défilement ────────────────────
     Le héro « pleine largeur » sort du <main> en se donnant 100vw + des marges
     négatives (cf. .demo-wrap.fullwidth-desktop dans accueil.css). Or 100vw
     COMPTE la barre de défilement, alors que la zone réellement visible, elle,
     ne la compte pas : la vidéo débordait donc de ~8 px de chaque côté, débord
     rogné en silence par `html,body{overflow-x:hidden}`. Les coins arrondis du
     bas y perdaient leur courbure et la vidéo paraissait à angles droits — le
     tout invisible dans l aperçu de l éditeur, dont la barre de défilement est
     masquée. On mesure donc la barre et le CSS la déduit.
     innerWidth compte la barre, documentElement.clientWidth non : la différence
     EST sa largeur (0 sur mobile et sur les barres flottantes de macOS).
     Recalculé au redimensionnement ET au chargement complet, car l apparition
     ou la disparition d une barre de défilement change la donne. */
  (function () {
    var root = document.documentElement;
    function syncScrollbarWidth() {
      var w = window.innerWidth - root.clientWidth;
      root.style.setProperty('--sbw', (w > 0 ? w : 0) + 'px');
    }
    syncScrollbarWidth();
    document.addEventListener('DOMContentLoaded', syncScrollbarWidth);
    window.addEventListener('load', syncScrollbarWidth);
    window.addEventListener('resize', syncScrollbarWidth);
  })();
  </script>
</head>

<body<?php if ($flash_info_active && !empty($flash_info_text)): ?> class="has-flash-banner"<?php endif; ?>>
  <?php include __DIR__ . '/../src/partials/preloader.php'; ?>

  <?php include __DIR__ . '/../src/partials/navbar-modern.php'; ?>

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
    

    <?php
      // Réglages de la "carte vidéo" Hero : hauteur personnalisée et pleine largeur.
      // Lus depuis accueil_styles ; valeurs par défaut : laissées au CSS (clamp 70vh).
      $heroCardHeight       = (int)($accueilStyles['hero_card_height'] ?? 0);
      $heroCardHeightMobile = (int)($accueilStyles['hero_card_height_mobile'] ?? 0);
      $heroCardWidth        = (int)($accueilStyles['hero_card_width'] ?? 0);
      $heroCardWidthMobile  = (int)($accueilStyles['hero_card_width_mobile'] ?? 0);
      $heroCardFullwidth        = !empty($accueilStyles['hero_card_fullwidth']);
      $heroCardFullwidthMobile  = !empty($accueilStyles['hero_card_fullwidth_mobile']);
      $heroWrapClass = 'demo-wrap';
      if ($heroCardFullwidth)       $heroWrapClass .= ' fullwidth-desktop';
      if ($heroCardFullwidthMobile) $heroWrapClass .= ' fullwidth-mobile';
      $heroCardInlineStyle = '';
      if ($heroCardHeight)       $heroCardInlineStyle .= '--hero-card-height:' . $heroCardHeight . 'px;';
      if ($heroCardHeightMobile) $heroCardInlineStyle .= '--hero-card-height-mobile:' . $heroCardHeightMobile . 'px;';
      // Largeur en vw + marge calculée pour centrer la card pile sur la viewport
      // (sortie du container parent). 100 % = pleine fenêtre, < 100 % = marge symétrique.
      if ($heroCardWidth) {
        $heroCardInlineStyle .= '--hero-card-width:' . $heroCardWidth . 'vw;';
        $heroCardInlineStyle .= '--hero-card-mx:calc(50% - 50vw + ' . ((100 - $heroCardWidth) / 2) . 'vw);';
      }
      if ($heroCardWidthMobile) {
        $heroCardInlineStyle .= '--hero-card-width-mobile:' . $heroCardWidthMobile . 'vw;';
        $heroCardInlineStyle .= '--hero-card-mx-mobile:calc(50% - 50vw + ' . ((100 - $heroCardWidthMobile) / 2) . 'vw);';
      }
    ?>
    <div class="<?= $heroWrapClass ?>" data-edit-section="hero-card-wrap">
      <section class="demo-card" aria-label="Carte vidéo"<?= $heroCardInlineStyle !== '' ? ' style="' . $heroCardInlineStyle . '"' : '' ?>>
        <?php if (!empty($registration_fee) || !empty($course_km)): ?>
        <?php
          // Helper : construit le CSS inline + les data-attrs pour un badge.
          // - Position en topPct/leftPct (% de .demo-card) : on émet des data-pos-* ; le translate
          //   sera calculé au runtime par applyHeroPositionPct (JS) selon les dimensions courantes
          //   de .demo-card → cohérence parfaite éditeur ↔ live.
          // - Taille (scale) : émis inline (le texte interne suit naturellement).
          // - Fallback px (anciennes données) : transform translate inline.
          // Construit les data-attrs de position pour le runtime JS (applyHeroPositionPct).
          // Priorité : ancre+offset (modèle figé en px) > topPct/leftPct (legacy) > px brut (legacy).
          // Le paramètre $suffix permet de produire la variante mobile (-mobile) sur le même
          // élément HTML quand desktop et mobile cohabitent (badges, timer, toggle, social).
          $buildPosAttrs = function($geom, $suffix = '') {
            if (!is_array($geom)) return '';
            // Mode d'ancrage (axis-mode) : émis indépendamment de la position ; utile pour
            // un élément "centré sur axe X/Y" même sans anchor+offset enregistrés (cas où
            // l'admin a passé l'élément en mode "centré" via clic droit avant tout drag).
            $modeAttr = '';
            if (isset($geom['mode']) && in_array($geom['mode'], ['centerX','centerY','center'], true)) {
              $modeAttr = ' data-axis-mode' . $suffix . '="' . htmlspecialchars($geom['mode'], ENT_QUOTES) . '"';
            }
            if (isset($geom['anchorX']) && isset($geom['anchorY'])
                && isset($geom['offsetX']) && isset($geom['offsetY'])) {
              return ' data-anchor-x' . $suffix . '="' . htmlspecialchars($geom['anchorX'], ENT_QUOTES) . '"'
                   . ' data-anchor-y' . $suffix . '="' . htmlspecialchars($geom['anchorY'], ENT_QUOTES) . '"'
                   . ' data-offset-x' . $suffix . '="' . round((float)$geom['offsetX'], 2) . '"'
                   . ' data-offset-y' . $suffix . '="' . round((float)$geom['offsetY'], 2) . '"'
                   . $modeAttr;
            }
            if (isset($geom['topPct']) && isset($geom['leftPct'])) {
              return ' data-pos-top' . $suffix . '="'  . round((float)$geom['topPct'], 4)
                   . '" data-pos-left' . $suffix . '="' . round((float)$geom['leftPct'], 4) . '"'
                   . $modeAttr;
            }
            return $modeAttr; // mode tout seul (sans position) reste valide pour center 2D
          };
          // Positions par défaut par device pour les éléments qui ont une attente forte
          // (le bouton play/pause est attendu en bas-droite desktop, haut-droite mobile).
          // Les autres éléments (badges, timer, social) restent en flow CSS naturel si rien
          // n'est sauvegardé — leur position par défaut vient de la mise en page Flexbox.
          // Defaults : positions générées par PHP quand AUCUNE position n'est sauvée.
          // ATTENTION : applyHeroPositionPct écrase `el.style.transform` (donc le
          // `translateY(-50%)` du CSS) quand il applique left/top — donc tout default
          // émis ici doit avoir un `offsetY` calculé en SUPPOSANT que le CSS transform
          // ne s'applique PAS. Pour video_social_card (CSS desktop = top:50% +
          // translateY(-50%)) on ne peut PAS émettre de default sans casser le centrage
          // vertical → on retire le default et laisse le CSS pur prendre la main.
          $heroDefaults = [
            // video_toggle : PAS de default ici. Le CSS place déjà le bouton play/pause
            // bas-droite (desktop : .video-toggle { bottom:1rem; right:1rem }) et haut-droite
            // (mobile : .video-toggle:not([data-anchor-x-mobile]) { top:.75rem; right:.75rem }),
            // aux MÊMES coordonnées (16px / 12px). Émettre un default PHP forçait l'anti-flash
            // opacity:0 + un placement par JS → le bouton apparaissait puis « sautait ». Sans
            // default, aucun data-attr n'est émis quand rien n'est sauvé : le CSS place le
            // bouton dès le 1er rendu, sans JS ni saut. Une position sauvée par l'éditeur
            // (dans $accueilGeometry) reste évidemment prioritaire et pilotée par le JS.

            // video_social_card : pas de default — le CSS d'origine (top:50%; right:1.5rem;
            // transform:translateY(-50%)) suffit pour centrer la card à droite en desktop.
            // En mobile, le CSS @media (avec :not([data-anchor-x-mobile])) gère la position.
            // Le bug "ça monte après Publier" venait d'un default avec offsetY:240 qui
            // forçait la card à top:240 sans le translateY(-50%) → décalage visuel.
          ];
          // Émet desktop + mobile sur le MÊME élément HTML (utilisé pour les éléments
          // qui n'ont pas de jumeau .hero-pc/.hero-mobile : badges, timer, toggle, social).
          // Le runtime JS (applyHeroPositionPct) choisit le set selon innerWidth < 1040.
          // Fallback : si aucune géométrie sauvée pour un device, on retombe sur
          // $heroDefaults[$field][$device] s'il existe.
          $buildPosAttrsResponsive = function($field) use ($accueilGeometry, $buildPosAttrs, $heroDefaults) {
            $desk = $accueilGeometry[$field] ?? null;
            $mob  = $accueilGeometry[$field . '_mobile'] ?? null;
            if (!is_array($desk) && isset($heroDefaults[$field]['desktop'])) {
              $desk = $heroDefaults[$field]['desktop'];
            }
            if (!is_array($mob)  && isset($heroDefaults[$field]['mobile'])) {
              $mob  = $heroDefaults[$field]['mobile'];
            }
            return $buildPosAttrs($desk) . $buildPosAttrs($mob, '-mobile');
          };
          $buildBadgeCss = function($field, $sizeKey) use ($accueilStyles, $accueilGeometry, $buildPosAttrs, $buildPosAttrsResponsive) {
            $size = (int)($accueilStyles[$sizeKey] ?? 100);
            // Taille mobile : null si non définie explicitement. Le rendu HTML n'émet
            // alors PAS d'attribut data-size-mobile, et le runtime n'applique aucun
            // scale mobile spécifique → on retombe sur le CSS naturel / la taille
            // desktop (selon ce que fait applyHeroPositionPct). Indispensable pour que
            // "Restaurer hero (mobile)" produise un vrai reset visuel.
            $sizeMobile = isset($accueilStyles[$sizeKey . '_mobile'])
                ? (int)$accueilStyles[$sizeKey . '_mobile']
                : null;
            $geom  = $accueilGeometry[$field] ?? null;
            $scale = $size / 100;
            $hasScale = abs($scale - 1) > 0.001;
            $css = '';
            // On émet desktop + mobile : le runtime choisit selon innerWidth.
            $dataAttrs = $buildPosAttrsResponsive($field);
            // hasRuntimePos vrai dès qu'au moins un set (desktop OU mobile) est défini.
            $hasRuntimePos = $dataAttrs !== '';
            if ($hasRuntimePos) {
              if ($hasScale) {
                $css .= 'transform:scale(' . $scale . ');transform-origin:left top;';
              }
            } else {
              // Fallback historique px : translate + scale chaînés (anciens enregistrements)
              $tx = is_array($geom) ? (int)($geom['x'] ?? 0) : 0;
              $ty = is_array($geom) ? (int)($geom['y'] ?? 0) : 0;
              $parts = [];
              if ($tx || $ty) $parts[] = 'translate(' . $tx . 'px,' . $ty . 'px)';
              if ($hasScale)  $parts[] = 'scale(' . $scale . ')';
              if (!empty($parts)) {
                $css .= 'transform:' . implode(' ', $parts) . ';transform-origin:left top;';
              }
              if ($tx || $ty) $css .= 'position:relative;';
            }
            return [$css, $dataAttrs, $size, $sizeMobile];
          };
          [$cssBadgeKm,  $attrsBadgeKm,  $sizeBadgeKm,  $sizeBadgeKmMobile]  = $buildBadgeCss('badge_km',  'badge_km_size');
          [$cssBadgeFee, $attrsBadgeFee, $sizeBadgeFee, $sizeBadgeFeeMobile] = $buildBadgeCss('badge_fee', 'badge_fee_size');
        ?>
        <div class="demo-badges">
          <?php if (!empty($course_km)): ?>
          <a href="parcours" class="demo-badge demo-badge--km" id="badgeKm" style="text-decoration:none;cursor:pointer;<?= $cssBadgeKm ?>"<?= $attrsBadgeKm ?> <?= $isEditorMode ? 'data-edit-field="badge_km" data-edit-kind="size-only" data-edit-section="hero" data-edit-size="badge_km_size" data-edit-size-current="' . $sizeBadgeKm . '"' . ($sizeBadgeKmMobile !== null ? ' data-edit-size-current-mobile="' . $sizeBadgeKmMobile . '"' : '') : '' ?><?= $sizeBadgeKmMobile !== null ? ' data-size-mobile="' . $sizeBadgeKmMobile . '"' : '' ?>>
            <span class="demo-badge-value"><?= (int)$course_km ?> km</span>
            <span class="demo-badge-label">Parcours</span>
          </a>
          <?php endif; ?>
          <?php if (!empty($registration_fee)): ?>
          <?php $badgeFeeTooltipText = (string)($accueilTexts['badge_fee.tooltip'] ?? 'Entièrement reversé à la Ligue contre le cancer'); ?>
          <div class="demo-badge demo-badge--fee" id="badgeFee" style="<?= $cssBadgeFee ?>"<?= $attrsBadgeFee ?> <?= $isEditorMode ? 'data-edit-field="badge_fee" data-edit-kind="size-only" data-edit-section="hero" data-edit-size="badge_fee_size" data-edit-size-current="' . $sizeBadgeFee . '"' . ($sizeBadgeFeeMobile !== null ? ' data-edit-size-current-mobile="' . $sizeBadgeFeeMobile . '"' : '') : '' ?><?= $sizeBadgeFeeMobile !== null ? ' data-size-mobile="' . $sizeBadgeFeeMobile . '"' : '' ?>>
            <span class="demo-badge-value"><?= (int)$registration_fee ?>€</span>
            <div class="badge-tooltip" id="badgeTooltip" <?= $isEditorMode ? 'data-edit-field="badge_fee.tooltip" data-edit-kind="text" data-edit-section="hero"' : '' ?>><?= htmlspecialchars($badgeFeeTooltipText) ?></div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php
          $videoFile = $data['video_accueil'] ?? 'FER.mp4';
          $hasHeroVideo = $videoFile && file_exists(__DIR__ . '/../files/' . $videoFile);
          $cssVideoToggle = $attrsVideoToggle = $cssVideoSocial = $attrsVideoSocial = '';
        ?>
        <div class="demo-card-media">
        <?php if ($hasHeroVideo): ?>
          <video class="demo-video" id="heroVideo" <?= $isEditorMode ? 'data-edit-field="video_accueil" data-edit-kind="video" data-edit-section="hero"' : 'autoplay muted loop playsinline' ?>>
            <source src="../files/<?= rawurlencode($videoFile) ?>" type="video/mp4" />
          </video>
        <?php endif; ?>
        </div>

        <?php
          // Helper : retourne [$css_inline, $data_attrs] pour video_toggle / video_social_card.
          // - Si anchorX/anchorY/offsetX/offsetY : émet data-anchor-*/data-offset-* (modèle figé en px).
          // - Si topPct/leftPct : émet data-pos-* (legacy — % de .demo-card).
          // - Si px (legacy)    : transform translate inline (ancien comportement).
          // - Sinon : pas de style → CSS par défaut.
          $buildPositionCss = function($field) use ($accueilGeometry, $buildPosAttrs, $buildPosAttrsResponsive) {
            $geom       = $accueilGeometry[$field] ?? null;
            $mobileGeom = $accueilGeometry[$field . '_mobile'] ?? null;
            // Si au moins l'un des deux (desktop ou mobile) a un format runtime,
            // on émet les data-attrs des deux et on laisse le JS choisir.
            $attrs = $buildPosAttrsResponsive($field);
            if ($attrs !== '') return ['', $attrs];
            if (!is_array($geom)) return ['', ''];
            // Fallback historique en px (kept au cas où d'anciennes geom px existeraient en base).
            $tx = (int)($geom['x'] ?? 0);
            $ty = (int)($geom['y'] ?? 0);
            if (!$tx && !$ty) return ['', ''];
            return ['transform:translate(' . $tx . 'px,' . $ty . 'px);transform-origin:left top;', ''];
          };
          [$cssVideoToggle, $attrsVideoToggle] = $buildPositionCss('video_toggle');
          [$cssVideoSocial, $attrsVideoSocial] = $buildPositionCss('video_social_card');

          /* ── Pré-rendu CSS des positions sauvegardées (perf 1er affichage) ──
             Le modèle ancré (anchorX/Y + offset px) et le legacy % s'expriment en
             CSS pur : on émet ici un <style> par device (media queries) pour que
             ces éléments soient placés DÈS le premier rendu, sans attendre le JS
             (applyHeroPositionPct tourne ensuite et aboutit au même visuel — il
             reste maître pour le resize et l'éditeur). L'`opacity:1` neutralise
             l'anti-flash pour ces éléments déjà bien placés (l'ID gagne en
             spécificité sur le sélecteur d'attributs).
             Restreint aux 4 champs dont le conteneur = repère de calcul du JS
             (.demo-card ou .demo-badges en inset:0). hero_timer / hero.cta_register
             sont exclus : ils doivent être re-parentés par le JS (transform parent).
             Désactivé en mode éditeur (le reset "Restaurer" doit retomber sur le
             CSS d'origine, pas sur ce pré-rendu). */
          $heroPreposCss = '';
          if (!$isEditorMode) {
            $preposMap = [
              'video_toggle'      => '#videoToggle',
              'video_social_card' => '#videoSocialCard',
              'badge_fee'         => '#badgeFee',
              'badge_km'          => '#badgeKm',
            ];
            $preposRules = ['desktop' => [], 'mobile' => []];
            foreach ($preposMap as $preField => $preSel) {
              foreach (['desktop' => '', 'mobile' => '_mobile'] as $preDevice => $preSuffix) {
                $g = $accueilGeometry[$preField . $preSuffix] ?? null;
                if (!is_array($g)) continue;
                $axisMode = (string)($g['axisMode'] ?? 'free');
                if ($axisMode !== '' && $axisMode !== 'free') continue; // centrage → dimensions requises → JS seul
                $css = '';
                if (isset($g['anchorX'], $g['anchorY'], $g['offsetX'], $g['offsetY'])) {
                  $ox = round((float)$g['offsetX'], 2);
                  $oy = round((float)$g['offsetY'], 2);
                  $css .= ($g['anchorX'] === 'right') ? "right:{$ox}px;left:auto;" : "left:{$ox}px;right:auto;";
                  $css .= ($g['anchorY'] === 'bottom') ? "bottom:{$oy}px;top:auto;" : "top:{$oy}px;bottom:auto;";
                } elseif (isset($g['topPct'], $g['leftPct'])) {
                  $css .= 'left:' . round((float)$g['leftPct'], 4) . '%;right:auto;'
                        . 'top:'  . round((float)$g['topPct'], 4)  . '%;bottom:auto;';
                } else {
                  continue;
                }
                $css .= 'position:absolute;margin:0;opacity:1;';
                // La card sociale a un translateY(-50%) par défaut que le JS efface
                // quand une position est sauvée → même chose au pré-rendu.
                if ($preField === 'video_social_card') $css .= 'transform:none;';
                $preposRules[$preDevice][] = $preSel . '{' . $css . '}';
              }
            }
            if ($preposRules['desktop']) $heroPreposCss .= '@media (min-width:1041px){' . implode('', $preposRules['desktop']) . '}';
            if ($preposRules['mobile'])  $heroPreposCss .= '@media (max-width:1040px){' . implode('', $preposRules['mobile']) . '}';
          }
        ?>
        <?php if ($heroPreposCss !== ''): ?>
        <style nonce="<?= $GLOBALS['csp_nonce'] ?>"><?= $heroPreposCss ?></style>
        <?php endif; ?>

        <!-- Bouton Play/Pause -->
        <?php if ($hasHeroVideo): ?>
        <button class="video-toggle" id="videoToggle" aria-label="Pause la vidéo" type="button" style="<?= $cssVideoToggle ?>"<?= $attrsVideoToggle ?> <?= $isEditorMode ? 'data-edit-field="video_toggle" data-edit-kind="size-only" data-edit-section="hero"' : '' ?>>
          <svg class="vt-icon vt-pause" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
          <svg class="vt-icon vt-play" viewBox="0 0 24 24" fill="currentColor" style="display:none"><polygon points="6,4 20,12 6,20"/></svg>
        </button>
        <?php endif; ?>

        <div class="demo-overlay">
          <div class="demo-panel video-float">
            <div class="hero-text">
              <?php
                // Helper inline : applique la geometry persistée (drag libre + resize + scale).
                // Le translate (positionnement) est calculé au runtime par applyHeroPositionPct
                // à partir des data-attrs (ancre+offset px ou topPct/leftPct legacy).
                $applyGeo = function($field) use ($accueilGeometry) {
                  $g = $accueilGeometry[$field] ?? null;
                  if (!is_array($g)) return '';
                  $css = '';
                  if (isset($g['w'])) $css .= 'width:' . (int)$g['w'] . 'px;';
                  if (isset($g['h'])) $css .= 'height:' . (int)$g['h'] . 'px;';
                  $sc = isset($g['scale']) ? (float)$g['scale'] : 1.0;
                  $hasScale = $sc && abs($sc - 1) > 0.001;
                  $hasRuntimePos = (isset($g['anchorX']) && isset($g['anchorY'])
                                    && isset($g['offsetX']) && isset($g['offsetY']))
                                || (isset($g['topPct']) && isset($g['leftPct']));
                  if ($hasRuntimePos) {
                    // Le runtime JS appliquera le translate. On n'émet ici QUE le scale
                    // (et width/height plus haut si applicable).
                    if ($hasScale) {
                      $css .= 'transform:scale(' . $sc . ');transform-origin:left top;';
                    }
                  } else {
                    // Fallback historique px
                    $x = (int)($g['x'] ?? 0); $y = (int)($g['y'] ?? 0);
                    $parts = [];
                    if ($x || $y) $parts[] = 'translate(' . $x . 'px,' . $y . 'px)';
                    if ($hasScale) $parts[] = 'scale(' . $sc . ')';
                    if (!empty($parts)) {
                      $css .= 'transform:' . implode(' ', $parts) . ';transform-origin:left top;position:relative;';
                    } elseif ($x || $y) {
                      $css .= 'position:relative;';
                    }
                  }
                  return $css;
                };
                // Helper miroir : renvoie les data-attrs (ancre/offset OU topPct/leftPct) pour le runtime.
                $posAttrs = function($field) use ($accueilGeometry, $buildPosAttrs) {
                  return $buildPosAttrs($accueilGeometry[$field] ?? null);
                };
                // Taille subtitle : PC et MOBILE INDÉPENDANTS. Si pas de valeur mobile
                // explicite (subtitle_accueil_size_mobile), on retombe sur la valeur PC
                // (pour conserver l'ancien comportement quand l'admin n'a jamais réglé mobile).
                $sizeSubtitle       = (int)($accueilStyles['subtitle_accueil_size'] ?? 100);
                $sizeSubtitleMobile = (int)($accueilStyles['subtitle_accueil_size_mobile'] ?? $sizeSubtitle);
                $cssTitle       = $applyGeo('titleAccueil');
                $cssTitleMobile = $applyGeo('titleAccueil_mobile') ?: $cssTitle;
                $cssSubtitlePC    = $sizeSubtitle       !== 100 ? 'font-size:' . ($sizeSubtitle       / 100) . 'em;' : '';
                $cssSubtitleMob   = $sizeSubtitleMobile !== 100 ? 'font-size:' . ($sizeSubtitleMobile / 100) . 'em;' : '';
                // La taille (font-size) du curseur s'ajoute TOUJOURS à la géométrie éventuelle :
                // sans cela, dès qu'une géométrie était enregistrée (drag/resize), le font-size était écrasé.
                $cssSubPC       = $applyGeo('subtitle_accueil')        . $cssSubtitlePC;
                $cssSubM        = $applyGeo('subtitle_accueil_mobile') . $cssSubtitleMob;
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
                <div class="demo-kicker" style="<?= $cssTitle ?>"<?= $posAttrs('titleAccueil') ?> <?= $isEditorMode ? 'data-edit-field="titleAccueil" data-edit-kind="tinymce" data-edit-section="hero"' : '' ?>><?= $data['titleAccueil'] ?? ($isEditorMode ? '<em style="color:#fce7f3">Cliquer pour ajouter un titre PC</em>' : '') ?></div>
              </div>
              <!-- Mobile -->
              <div class="hero-device hero-mobile">
                <div class="demo-kicker" style="<?= $cssTitleMobile ?>"<?= $posAttrs('titleAccueil_mobile') ?> <?= $isEditorMode ? 'data-edit-field="titleAccueil_mobile" data-edit-kind="tinymce" data-edit-section="hero"' : '' ?>><?= ($data['titleAccueil_mobile'] ?? '') ?: ($data['titleAccueil'] ?? ($isEditorMode ? '<em style="color:#fce7f3">Cliquer pour ajouter un titre mobile</em>' : '')) ?></div>
              </div>
              <?php
                $subPC = $data['subtitle_accueil'] ?? '';
                $subMobile = $data['subtitle_accueil_mobile'] ?? '';
              ?>
              <?php if ($subPC !== '' || $isEditorMode): ?>
                <p class="demo-desc hero-device hero-pc" style="<?= $cssSubPC ?>"<?= $posAttrs('subtitle_accueil') ?> <?= $isEditorMode ? 'data-edit-field="subtitle_accueil" data-edit-kind="text" data-edit-section="hero" data-edit-size="subtitle_accueil_size" data-edit-size-current="' . $sizeSubtitle . '"' : '' ?>><?= htmlspecialchars($subPC !== '' ? $subPC : 'Cliquer pour ajouter un sous-titre PC') ?></p>
              <?php endif; ?>
              <?php if ($subMobile !== '' || $isEditorMode): ?>
                <p class="demo-desc hero-device hero-mobile" style="<?= $cssSubM ?>"<?= $posAttrs('subtitle_accueil_mobile') ?> <?= $isEditorMode ? 'data-edit-field="subtitle_accueil_mobile" data-edit-kind="text" data-edit-section="hero" data-edit-size="subtitle_accueil_size_mobile" data-edit-size-current="' . $sizeSubtitleMobile . '"' : '' ?>><?= htmlspecialchars($subMobile !== '' ? $subMobile : ($subPC !== '' ? $subPC : 'Cliquer pour ajouter un sous-titre mobile')) ?></p>
              <?php endif; ?>
            </div>

            <?php
              $sizeTimer       = (int)($accueilStyles['hero_timer_size'] ?? 100);
              // null si pas de valeur mobile explicite → data-size-mobile non émis →
              // pas de scale mobile imposé → "Restaurer hero (mobile)" produit un vrai
              // reset visuel (le timer revient à sa taille CSS naturelle en mobile).
              $sizeTimerMobile = isset($accueilStyles['hero_timer_size_mobile'])
                  ? (int)$accueilStyles['hero_timer_size_mobile']
                  : null;
              // On N'émet PAS `display:inline-block` inline : ça écrasait le CSS responsive
              // mobile (.countdown-wrap=display:flex) et faisait que le timer mobile gardait
              // le layout desktop. Le scale visuel desktop est appliqué uniquement au runtime
              // par __syncFieldNamesToDevice / applyHeroPositionPct selon le device courant.
              $cssTimer = '';
              // Si une géométrie libre est définie pour le timer, elle prend le dessus mais conserve le scale.
              $geomTimer       = $accueilGeometry['hero_timer'] ?? null;
              $geomTimerMobile = $accueilGeometry['hero_timer_mobile'] ?? null;
              // Le timer est un élément HTML unique : on émet desktop + mobile sur le même nœud.
              $timerPosAttrs = $buildPosAttrsResponsive('hero_timer');
              if (is_array($geomTimer) || is_array($geomTimerMobile)) {
                $cssTimer = '';
                // width/height proviennent de la géométrie desktop (pas de override mobile).
                if (is_array($geomTimer) && isset($geomTimer['w'])) $cssTimer .= 'width:' . (int)$geomTimer['w'] . 'px;';
                if (is_array($geomTimer) && isset($geomTimer['h'])) $cssTimer .= 'height:' . (int)$geomTimer['h'] . 'px;';
                // Le curseur de taille (accueil_styles.hero_timer_size) est la seule source de vérité pour le scale.
                $scaleTimer = $sizeTimer / 100;
                $hasScaleTimer = $scaleTimer && abs($scaleTimer - 1) > 0.001;
                if ($timerPosAttrs !== '') {
                  // Le runtime JS calculera le translate à partir des data-attrs.
                  if ($hasScaleTimer) {
                    $cssTimer .= 'transform:scale(' . $scaleTimer . ');transform-origin:left top;';
                  }
                  // CRITIQUE : on N'émet PAS `position:relative; display:inline-block` ici.
                  // Sinon ces inline styles écrasaient le CSS responsive mobile
                  // (`position:absolute; bottom:0; width:100%`) → dès qu'une position
                  // DESKTOP était sauvée pour le timer, le rendu MOBILE devenait
                  // décalé / mauvaise position. Le translate fonctionne sur n'importe
                  // quelle position CSS, donc pas besoin de forcer relative.
                } else {
                  // Fallback historique px (uniquement si geom desktop existe).
                  $tx = is_array($geomTimer) ? (int)($geomTimer['x'] ?? 0) : 0;
                  $ty = is_array($geomTimer) ? (int)($geomTimer['y'] ?? 0) : 0;
                  $transformParts = [];
                  if ($tx || $ty) $transformParts[] = 'translate(' . $tx . 'px,' . $ty . 'px)';
                  if ($hasScaleTimer) $transformParts[] = 'scale(' . $scaleTimer . ')';
                  if (!empty($transformParts)) {
                    $cssTimer .= 'transform:' . implode(' ', $transformParts) . ';transform-origin:left top;';
                  }
                  $cssTimer .= 'position:relative;display:inline-block;';
                }
              }
            ?>
            <div class="countdown-wrap" style="<?= $cssTimer ?>"<?= $timerPosAttrs ?> <?= $isEditorMode ? 'data-edit-field="hero_timer" data-edit-kind="size-only" data-edit-section="hero" data-edit-size="hero_timer_size" data-edit-size-current="' . $sizeTimer . '"' . ($sizeTimerMobile !== null ? ' data-edit-size-current-mobile="' . $sizeTimerMobile . '"' : '') : '' ?><?= $sizeTimerMobile !== null ? ' data-size-mobile="' . $sizeTimerMobile . '"' : '' ?>>
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
                // Position personnalisée (drag dans l'éditeur). Émis pour les 2 devices.
                $ctaPosAttrs = $buildPosAttrsResponsive('hero.cta_register');
              ?>
              <a class="cta-pink" href="register"<?= $ctaPosAttrs ?> <?= $isEditorMode ? 'data-edit-field="hero.cta_register" data-edit-kind="text" data-edit-section="hero"' : '' ?>><?= htmlspecialchars($ctaRegisterTxt) ?></a>
            </div>
          </div>
        </div>
        <div class="video-social-card" id="videoSocialCard" aria-label="Réseaux sociaux" style="<?= $cssVideoSocial ?>"<?= $attrsVideoSocial ?> <?= $isEditorMode ? 'data-edit-field="video_social_card" data-edit-kind="size-only" data-edit-section="hero"' : '' ?>>
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
      </section>
    </div>

    <?php
    // ───────────────────────────────────────────────────────────────────────
    // RENDU DYNAMIQUE DES SECTIONS DE LA PAGE D'ACCUEIL
    // L'ordre, la visibilité et les blocs personnalisés sont configurables
    // depuis Réglages → Accueil → Mise en page de l'accueil.
    // Le Héro (vidéo + countdown) ci-dessus reste fixé en haut.
    // ───────────────────────────────────────────────────────────────────────

    require_once __DIR__ . '/../src/content/accueil_layout.php';
    require_once __DIR__ . '/../src/content/accueil_sections.php';
    // En mode éditeur (?editor=1), on charge le brouillon (accueil_layout_draft) avec
    // fallback sur la version publiée. En mode normal (live), on charge directement
    // la version publiée → le grand public voit ce qui a été "Publié", pas le brouillon.
    $accueilLayout = loadAccueilLayout($data, $isEditorMode);

    // $accueilStyles, $accueilGeometry et $accueilTexts sont déjà chargés en haut
    // (avant le rendu du Hero qui les utilise). Voir bloc en début de fichier.
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
        // Section "Retrouver le départ" (colonnes SQL dédiées)
        'start_point_address'     => $data['start_point_address'] ?? '',
        'start_point_coords'      => $data['start_point_coords'] ?? '',
        'styles'                  => $accueilStyles,
        'geometry'                => $accueilGeometry,
        'texts'                   => $accueilTexts,
        '_editor'                 => $isEditorMode,
        // Accès BDD pour les placeholders {count_last}/{count_YYYY} et la source
        // "dernière archive" du compteur d'inscrits
        'pdo'                     => $pdo,
    ];


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
            /* ── Présentation : bandeau (défaut) ou carte grise ───────────────
             * Le choix est fait UNE FOIS ICI, autour du rendu, plutôt que dans
             * chacune des sept fonctions de rendu : elles n'ont pas à savoir
             * dans quel contenant elles atterrissent, et une section ajoutée
             * plus tard hérite du réglage sans une ligne de plus. */
            $secStyle = (string)($accueilStyles[$sec['type'] . '.bloc_style'] ?? 'bandeau');
            $enCarte  = ($secStyle === 'carte');
            /* Trait de couleur en haut de section : visible par défaut, donc on
               ne pose la classe que lorsqu'il est explicitement désactivé. */
            $sansTrait = ((string)($accueilStyles[$sec['type'] . '.bloc_trait'] ?? '1') === '0');
            /* ⚠️ EN MODE CARTE, LE TRAIT SE DESSINE SUR LA CARTE, PAS SUR LA
               SECTION. La carte a un remplissage : un trait posé sur la section
               flotte au milieu, à distance des bords, au lieu d épouser les
               coins arrondis. On le déplace donc sur le conteneur. */
            $aTrait = in_array($sec['type'], ['news', 'partners'], true) && !$sansTrait;
            $classesWrap = trim(($enCarte ? 'accueil-carte ' : '')
                               . ($sansTrait ? 'accueil-sans-trait ' : '')
                               . (($enCarte && $aTrait) ? 'accueil-trait' : ''));
            if ($classesWrap !== '') echo '<div class="' . $classesWrap . '">';
            if ($isEditorMode || !empty($sec['visible'])) {
                renderAccueilSection($sec, $sectionCtx);
            }
            if ($classesWrap !== '') echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>'; // /accueil-rows-wrapper
    ?>

  </main>


  

<?php /* En mode éditeur, le pied de page devient sélectionnable comme une
         section : il n'est pas dans le layout, on l'enveloppe donc d'un
         marqueur que le gestionnaire de clic reconnaît. */ ?>
<?php /* display:contents — le conteneur existe dans le document pour que le clic
         le retrouve, mais ne participe PAS à la mise en page : le pied de page
         sort de son conteneur avec la ruse du 100vw, et une boîte de plus
         autour de lui pouvait décaler ou rallonger la page. */ ?>
<?php if ($isEditorMode): ?><div data-editor-footer="1" style="display:contents"><?php endif; ?>
<?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>
<?php if ($isEditorMode): ?></div><?php endif; ?>

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
      let regHideTimer = null;

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
            // display vidé → la mise en page flex de .reg-result (CSS) reprend la main.
            resultEl.style.transition = '';
            resultEl.style.opacity = '';
            resultEl.style.display = '';
          }
          if (hintEl) {
            hintEl.style.display = 'none';
          }
          // Disparition automatique du message de retour au bout de 5 secondes.
          if (regHideTimer) clearTimeout(regHideTimer);
          regHideTimer = setTimeout(function() {
            if (!resultEl) return;
            resultEl.style.transition = 'opacity .3s ease';
            resultEl.style.opacity = '0';
            setTimeout(function() {
              resultEl.style.display = 'none';
              resultEl.style.opacity = '';
              resultEl.style.transition = '';
              if (hintEl) hintEl.style.display = '';
            }, 300);
          }, 5000);
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
          const res = await fetch('../admin-api.php?route=partner-request', {
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
              L'adresse <strong style="color:var(--primary, #f42182);">${escHtml(email)}</strong> semble être une adresse personnelle.<br>
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
            const r = await fetch('../admin-api.php?route=partner-captcha-init&fallback=1', { method: 'GET' });
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
            const r = await fetch('../admin-api.php?route=partner-captcha-init', { method: 'GET' });
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
          scheduleHeroPositionRefresh();
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
        scheduleHeroPositionRefresh();
      }

      function scheduleUpdate(){
        requestAnimationFrame(() => requestAnimationFrame(() => updateHeroHeight()));
      }

      function scheduleHeroPositionRefresh(){
        requestAnimationFrame(() => requestAnimationFrame(() => {
          if (typeof window.__applyHeroPositionPct === 'function') {
            window.__applyHeroPositionPct();
          }
        }));
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

    // ===== Positionnement Hero (cohérence éditeur ↔ live) =====
    // Deux modèles supportés :
    //   1) ANCRÉ (préféré) — data-anchor-x/y + data-offset-x/y : position figée en px
    //      depuis un coin de .demo-card → visuellement stable malgré les changements de
    //      taille du hero.
    //   2) POURCENTAGE (legacy) — data-pos-top/left : % de .demo-card. Conservé pour les
    //      anciennes géométries.
    // Chaque attribut peut exister en variante "-mobile" (data-anchor-x-mobile, etc.) :
    // sous la largeur 1040px, le runtime privilégie ces variantes. Cela permet à l'admin
    // d'éditer séparément les positions desktop et mobile (cf. toggle dans setting.php).
    // S'exécute aussi bien en mode éditeur (drag/resize) qu'en mode live (rendu final).
    (function(){
      var MOBILE_BREAKPOINT = 1040;
      function applyHeroPositionPct() {
        // Le sélecteur capture les éléments qui ont au moins un set (desktop OU mobile).
        var selector = ''
          + '[data-anchor-x][data-anchor-y][data-offset-x][data-offset-y],'
          + '[data-anchor-x-mobile][data-anchor-y-mobile][data-offset-x-mobile][data-offset-y-mobile],'
          + '[data-pos-top][data-pos-left],'
          + '[data-pos-top-mobile][data-pos-left-mobile],'
          + '[data-axis-mode],'
          + '[data-axis-mode-mobile],'
          // Inclut aussi les éléments qui ont SEULEMENT un scale (taille) : sans ça,
          // un timer/badge avec scale desktop (mais pas de position) gardait son
          // inline `transform:scale(1.3)` en mobile car le runtime ne le sélectionnait
          // jamais — d'où le bug "slider mobile à 100% mais visuellement à 130%".
          + '[data-edit-section="hero"][data-size-mobile],'
          + '[data-edit-section="hero"][data-edit-size]';
        var fallbackCard = document.querySelector('.demo-card');
        // Champs positionnés en absolute (left/top direct) dans .demo-card. Avec ce
        // modèle, la position visuelle est INDÉPENDANTE du flow CSS du parent (donc
        // identique entre l'éditeur — où .demo-card est forcée à 600px — et la vraie
        // page — où elle suit `height: clamp(70vh, ...)`).
        // `hero_timer` y figure : sans ça, son drag en mode "Libre" calculait un
        // translate(dx, dy) basé sur sa position naturelle dans le flux flex de
        // .hero-text, qui diffère entre editor et live → le timer apparaissait au
        // centre-bas par défaut sur la vraie page au lieu de la position sauvée.
        var directHeroFields = ['hero_timer', 'video_toggle', 'video_social_card', 'badge_fee', 'badge_km', 'hero.cta_register'];
        var isMobile = window.innerWidth < MOBILE_BREAKPOINT;
        // Lit un attribut "device-aware". Desktop et mobile sont 100% INDÉPENDANTS :
        // en mobile, on n'utilise PAS la config desktop comme fallback (sinon une
        // position desktop déborderait sur mobile et "Restaurer hero mobile" ne
        // pourrait jamais revenir au rendu CSS par défaut). L'admin configure
        // explicitement le mobile s'il veut un layout custom mobile, sinon le CSS
        // d'origine s'applique pleinement.
        function pick(ds, baseKey, mobileKey) {
          if (isMobile) {
            return (ds[mobileKey] !== undefined && ds[mobileKey] !== '') ? ds[mobileKey] : undefined;
          }
          return ds[baseKey];
        }
        // Reset des styles inline appliqués au passage précédent : utilisé quand
        // l'élément n'a aucune config pour le device courant (ex: après "Restaurer
        // hero mobile", le timer doit redevenir CSS pur en mobile, sans hériter
        // de l'inline desktop encore présent sur l'élément).
        function resetInlinePosition(el) {
          el.style.transform       = '';
          el.style.left            = '';
          el.style.top             = '';
          el.style.right           = '';
          el.style.bottom          = '';
          el.style.position        = '';
          el.style.marginLeft      = '';
          el.style.marginTop       = '';
          el.style.transformOrigin = '';
          delete el.dataset.x;
          delete el.dataset.y;
        }
        function preferredAnchorY(top, height, cardHeight) {
          return (top + (height / 2) < cardHeight / 2) ? 'top' : 'bottom';
        }
        document.querySelectorAll(selector).forEach(function(el) {
          var card = el.closest('.demo-card') || fallbackCard;
          if (!card) return;
          var cardRect = card.getBoundingClientRect();
          if (cardRect.width <= 0 || cardRect.height <= 0) return;
          // Préserve le scale courant (slider de taille) — qu'il vienne du dataset, du style,
          // ou de data-edit-size-current (initial render).
          // DEVICE-AWARE pour le scale : en mobile, on n'hérite PAS de la taille desktop si
          // aucune taille mobile n'a été définie explicitement. Sinon, déplacer le timer en
          // mobile lui appliquerait la taille desktop (via dataset.editSizeCurrent) au lieu
          // de garder sa taille CSS naturelle (bug "le timer rétrécit au drag mobile").
          var existing = el.style.transform || '';
          var scaleStr = '';
          var mobileSizeRaw = el.dataset.sizeMobile;
          if (isMobile) {
            if (mobileSizeRaw) {
              var smz = parseInt(mobileSizeRaw, 10) / 100;
              if (smz && Math.abs(smz - 1) > 0.001) scaleStr = ' scale(' + smz + ')';
            }
            // En mobile sans data-size-mobile explicite : pas de scale appliqué (taille CSS naturelle).
          } else {
            var scaleMatch = existing.match(/scale\([\d.]+\)/);
            if (scaleMatch) {
              scaleStr = ' ' + scaleMatch[0];
            } else if (el.dataset.scale && parseFloat(el.dataset.scale) !== 1) {
              scaleStr = ' scale(' + parseFloat(el.dataset.scale) + ')';
            } else if (el.dataset.editSizeCurrent) {
              var sz = parseInt(el.dataset.editSizeCurrent, 10) / 100;
              if (sz && Math.abs(sz - 1) > 0.001) scaleStr = ' scale(' + sz + ')';
            }
          }
          var isDirect = directHeroFields.indexOf(el.dataset.editField || '') !== -1;
          // On ne reset PLUS le transform pour mesurer la position naturelle : cela créait
          // un flash visible (l'élément sautait à sa position CSS naturelle puis revenait
          // à la position cible à chaque tick — load, resize, ResizeObserver). À la place,
          // on lit la position courante et on soustrait le translate déjà appliqué
          // (mémorisé dans dataset.x/y, sinon extrait du style.transform inline).
          var elRect = el.getBoundingClientRect();
          var appliedDx = parseFloat(el.dataset.x);
          var appliedDy = parseFloat(el.dataset.y);
          if (isNaN(appliedDx) || isNaN(appliedDy)) {
            var trMatch = existing.match(/translate\(\s*([-\d.]+)px\s*,\s*([-\d.]+)px/);
            if (trMatch) {
              if (isNaN(appliedDx)) appliedDx = parseFloat(trMatch[1]) || 0;
              if (isNaN(appliedDy)) appliedDy = parseFloat(trMatch[2]) || 0;
            }
            if (isNaN(appliedDx)) appliedDx = 0;
            if (isNaN(appliedDy)) appliedDy = 0;
          }
          var naturalTop  = elRect.top  - cardRect.top  - appliedDy;
          var naturalLeft = elRect.left - cardRect.left - appliedDx;
          var targetTop, targetLeft;
          var anchorX = pick(el.dataset, 'anchorX', 'anchorXMobile');
          var anchorY = pick(el.dataset, 'anchorY', 'anchorYMobile');
          var offsetXAttr = pick(el.dataset, 'offsetX', 'offsetXMobile');
          var offsetYAttr = pick(el.dataset, 'offsetY', 'offsetYMobile');
          var posTopAttr  = pick(el.dataset, 'posTop',  'posTopMobile');
          var posLeftAttr = pick(el.dataset, 'posLeft', 'posLeftMobile');
          // Mode d'ancrage : 'centerX' centre horizontalement (axe X figé au centre),
          // 'centerY' centre verticalement, 'center' = les deux, 'free' (ou absent) = libre.
          var axisMode = pick(el.dataset, 'axisMode', 'axisModeMobile') || 'free';
          if (anchorX && anchorY) {
            // Modèle ANCRÉ : offset px depuis un coin fixe → position visuelle figée.
            var offsetX = parseFloat(offsetXAttr) || 0;
            var offsetY = parseFloat(offsetYAttr) || 0;
            targetLeft = (anchorX === 'right')
              ? (cardRect.width  - elRect.width  - offsetX)
              : offsetX;
            targetTop  = (anchorY === 'bottom')
              ? (cardRect.height - elRect.height - offsetY)
              : offsetY;
          } else if (posTopAttr !== undefined && posLeftAttr !== undefined) {
            // Modèle legacy POURCENTAGE.
            var topPct  = parseFloat(posTopAttr);
            var leftPct = parseFloat(posLeftAttr);
            if (isNaN(topPct) || isNaN(leftPct)) return;
            targetTop   = cardRect.height * topPct  / 100;
            targetLeft  = cardRect.width  * leftPct / 100;
          } else if (axisMode !== 'free') {
            // Mode pur : pas d'offset enregistré, position dictée 100% par le mode.
            targetLeft = (cardRect.width  - elRect.width)  / 2;
            targetTop  = (cardRect.height - elRect.height) / 2;
          } else {
            // Aucune config pour ce device : on RESET les styles inline pour que le
            // CSS d'origine reprenne pleinement la main (sinon une position posée au
            // passage précédent sur l'autre device persiste). Indispensable pour que
            // "Restaurer hero mobile" donne le rendu CSS pur en mobile.
            // Cas particulier : on PRÉSERVE le scale (slider de taille) car il n'est
            // pas une position — sinon, après un reset position, le timer reprendrait
            // sa taille 100 % et l'admin perdrait son réglage de taille.
            resetInlinePosition(el);
            if (scaleStr.trim()) {
              el.style.transform       = scaleStr.trim();
              el.style.transformOrigin = 'left top';
            }
            return;
          }
          // Application du mode d'ancrage AU-DESSUS des anchor/offset calculés ci-dessus.
          // Le mode "écrase" l'axe correspondant pour que l'élément reste centré
          // visuellement quel que soit le redimensionnement de .demo-card.
          if (axisMode === 'centerX' || axisMode === 'center') {
            targetLeft = (cardRect.width - elRect.width) / 2;
          }
          if (axisMode === 'centerY' || axisMode === 'center') {
            targetTop = (cardRect.height - elRect.height) / 2;
          }
          var preferredY = preferredAnchorY(targetTop, elRect.height, cardRect.height);
          var isEditorMode = document.body.classList.contains('editor-mode');
          var needsAnchorMigration = isEditorMode && (
            (el.dataset.anchorX && el.dataset.anchorY && (el.dataset.anchorX !== 'left' || el.dataset.anchorY !== preferredY))
            || (el.dataset.posTop && el.dataset.posLeft)
          );
          if (needsAnchorMigration) {
            var migOffsetY = preferredY === 'bottom'
              ? (cardRect.height - targetTop - elRect.height)
              : targetTop;
            // Route vers le bon set (desktop OU mobile) selon le device courant
            // de l'éditeur, sinon la migration auto pollue le set desktop quand
            // l'admin est en train d'éditer en mobile.
            if (typeof window.__writeAnchorAttrs === 'function') {
              window.__writeAnchorAttrs(el, 'left', preferredY, targetLeft, migOffsetY);
            } else {
              el.dataset.anchorX = 'left';
              el.dataset.anchorY = preferredY;
              el.dataset.offsetX = targetLeft;
              el.dataset.offsetY = migOffsetY;
              delete el.dataset.posTop;
              delete el.dataset.posLeft;
            }
            el.dataset.needsGeometryMigration = '1';
          }
          if (directHeroFields.indexOf(el.dataset.editField || '') !== -1) {
            var alreadyStableAnchor = el.dataset.anchorX === 'left'
              && (el.dataset.anchorY === 'top' || el.dataset.anchorY === 'bottom')
              && el.dataset.offsetX
              && el.dataset.offsetY
              && !el.dataset.posTop
              && !el.dataset.posLeft;
            if (isEditorMode && !alreadyStableAnchor) {
              el.dataset.needsGeometryMigration = '1';
            }
            // SEUL hero_timer doit être déplacé directement dans `.demo-card` : il vit
            // dans `.demo-panel.video-float` qui a `position:relative` + `transform:
            // translate(35px,-35px)`, ce qui pénalise `position:absolute`. Les autres
            // directHero (badges, toggle, social) sont déjà dans des conteneurs qui
            // ne créent PAS de contexte de positionnement piégeux : les badges sont
            // dans `.demo-badges` (qui a position:absolute; inset:0 ET z-index:10, donc
            // au-dessus de .demo-overlay), le toggle/social directement dans `.demo-card`.
            // Les déplacer dans .demo-card les ferait passer SOUS l'overlay (z-index:2)
            // → effet "grisé" sur le badge km, exactement le bug que l'admin signale.
            // hero_timer et hero.cta_register vivent dans .demo-panel.video-float qui a
            // `position:relative` + `transform: translate(35px,-35px)` → on les sort dans
            // .demo-card pour que `position:absolute` se résolve contre .demo-card direct.
            // On garde une trace du parent ORIGINAL (id ou data-original-parent-id) pour
            // pouvoir restaurer l'élément à son emplacement DOM d'origine lors d'un reset.
            if ((el.dataset.editField === 'hero_timer'
              || el.dataset.editField === 'hero.cta_register')
              && el.parentNode !== card) {
              if (!el.dataset.originalParent && el.parentNode) {
                // Marqueur d'origine sur le parent + sur l'élément, pour reset later.
                if (!el.parentNode.id) el.parentNode.id = '__heroOrigParent_' + el.dataset.editField.replace(/[^a-z0-9]/gi, '_');
                el.dataset.originalParent = el.parentNode.id;
              }
              card.appendChild(el);
            }
            el.style.position = 'absolute';
            el.style.left = targetLeft + 'px';
            el.style.top  = targetTop  + 'px';
            el.style.right = 'auto';
            el.style.bottom = 'auto';
            el.style.marginLeft = '0px';
            el.style.marginTop = '0px';
            el.style.transform = scaleStr.trim();
            el.style.transformOrigin = 'left top';
            // z-index élevé pour les éléments DÉPLACÉS dans .demo-card (timer + cta) :
            // sans ça, ils passent SOUS .demo-overlay (z-index:2) qui a un gradient gris
            // semi-transparent → effet "grisé" + clic intercepté. C'est le bug "bouton
            // m'inscris devient grisé et non cliquable" après drag.
            if (el.parentNode === card) {
              el.style.zIndex = '10';
            }
            delete el.dataset.posTop;
            delete el.dataset.posLeft;
            el.dataset.x = 0;
            el.dataset.y = 0;
            return;
          }
          var dx = targetLeft - naturalLeft;
          var dy = targetTop  - naturalTop;
          el.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)' + scaleStr;
          el.style.transformOrigin = 'left top';
          el.dataset.x = dx;
          el.dataset.y = dy;
        });
        // Reveal : au 1er pass complet, on retire le opacity:0 anti-flash sur .demo-card.
        var __cardReveal = document.querySelector('.demo-card');
        if (__cardReveal && !__cardReveal.classList.contains('hero-pos-ready')) {
          __cardReveal.classList.add('hero-pos-ready');
        }
      }
      // Filet de sécurité : si JS plante, on rend visible après 1.5s pour ne pas
      // laisser les éléments invisibles indéfiniment.
      setTimeout(function(){
        var c = document.querySelector('.demo-card');
        if (c) c.classList.add('hero-pos-ready');
      }, 1500);
      // Première application asap (sans attendre tout 'load') pour éviter le flash.
      if (document.readyState !== 'loading') {
        requestAnimationFrame(applyHeroPositionPct);
      } else {
        document.addEventListener('DOMContentLoaded', function() {
          requestAnimationFrame(applyHeroPositionPct);
        });
      }
      window.addEventListener('load', applyHeroPositionPct);
      var __heroPosResizeTimer = null;
      window.addEventListener('resize', function() {
        clearTimeout(__heroPosResizeTimer);
        __heroPosResizeTimer = setTimeout(applyHeroPositionPct, 80);
      });
      var heroCardForPosition = document.querySelector('.demo-card');
      if (window.ResizeObserver && heroCardForPosition) {
        var heroPositionObserver = new ResizeObserver(function() {
          clearTimeout(__heroPosResizeTimer);
          __heroPosResizeTimer = setTimeout(applyHeroPositionPct, 40);
        });
        heroPositionObserver.observe(heroCardForPosition);
      }
      // Exposé pour que l'éditeur puisse le rappeler après un drag (mise à jour cohérente
      // de dataset.x/y même quand seul un attribut data-pos-* change).
      window.__applyHeroPositionPct = applyHeroPositionPct;
    })();

    // ===== Badge fee tooltip =====
    (function(){
      const badge = document.getElementById('badgeFee');
      if (!badge) return;
      // En mode éditeur, on ne veut ni le toggle ni l'auto-show (le clic sert à sélectionner
      // le badge pour le drag/resize).
      if (document.body.classList.contains('editor-mode')) return;
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
      // En mode éditeur, le bouton sert au drag — pas au play/pause.
      if (document.body.classList.contains('editor-mode')) return;
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
    /* Cache la scrollbar visuelle DANS l'iframe (le scroll fonctionne quand même via
       wheel/touch). En mode éditeur, la hauteur est ajustée dynamiquement par le parent
       au contenu réel, donc la scrollbar interne n'a aucune utilité — elle s'affichait
       parasitairement entre l'iframe et le wrap noir en mode mobile. */
    scrollbar-width: none; /* Firefox */
  }
  body.editor-mode::-webkit-scrollbar,
  html:has(body.editor-mode)::-webkit-scrollbar { width: 0; height: 0; display: none; } /* Chrome/Safari */
  body.editor-mode main { flex: none !important; }
  /* En mode éditeur : la vidéo est CLIQUABLE pour pouvoir la sélectionner depuis
     l'aperçu (au lieu d'un bouton overlay). On la passe au-dessus de .demo-overlay
     (z-index 2) pour que les clics atteignent la <video> et déclenchent le système
     de sélection existant (outline rose + sidebar propriétés). */
  /* La vidéo est cliquable en mode éditeur. PAS de z-index élevé pour ne PAS passer
     devant l'overlay (.demo-overlay z-index:2) qui contient le gradient sombre — sinon
     le visuel du hero serait cassé. Les clics arrivent à la vidéo parce que l'overlay
     a `pointer-events: none` en mode éditeur (cf. règles plus bas). */
  body.editor-mode .demo-video {
    pointer-events: auto !important;
    cursor: pointer;
  }
  /* La vidéo `<video>` est un élément remplacé qui n'accepte ni outline (Chrome)
     ni pseudo-éléments. On dessine le pointillé sur son PARENT `.demo-card-media`
     via `::after`. Le pseudo couvre la même surface que la vidéo (inset:0) et
     reçoit le hover quand la souris est sur la zone vidéo (l'overlay est en
     pointer-events:none → le hit-test atteint `.demo-card-media`). */
  body.editor-mode .demo-card-media::after {
    content: '';
    position: absolute;
    /* `inset: 8px` = 8 px à l'intérieur des bords de la vidéo, créant un GAP visible
       entre le pointillé rose et le bord de la vidéo. Sans cet espacement, le tracé
       était collé au bord et peu lisible. */
    inset: 8px;
    border: 3px dashed transparent;
    border-radius: 6px;
    pointer-events: none;
    transition: border-color .12s, border-style .12s, border-width .12s;
    z-index: 5;
  }
  body.editor-mode .demo-card-media:hover::after {
    border-color: var(--primary, #f42182);
  }
  /* Sélection : trait plein + plus épais. On utilise :has() pour cibler le parent
     quand la <video> à l'intérieur reçoit la classe .le-edit-selected (posée par
     le système de sélection existant au clic). */
  body.editor-mode .demo-card-media:has(.demo-video.le-edit-selected)::after {
    border-color: var(--primary, #f42182);
    border-style: solid;
    border-width: 3px;
  }
  /* .video-toggle reste interactif en mode éditeur pour permettre le drag */
  body.editor-mode .accueil-row, body.editor-mode .accueil-col {
    position: relative;
    /* Largeur d'outline CONSTANTE (3 px) avec couleur transparente au repos, puis
       seule la couleur change au hover → pas de "flash" où le pointillé apparait
       petit puis grandit pendant la transition outline-width. */
    outline: 3px dashed transparent;
    outline-offset: 4px;
    transition: outline-color .12s, background .12s;
  }
  body.editor-mode .accueil-row:hover { outline-color: color-mix(in srgb, var(--primary, #f42182) 50%, transparent); }
  body.editor-mode .accueil-col:hover { outline-color: color-mix(in srgb, var(--primary, #f42182) 70%, transparent); }
  body.editor-mode .accueil-col-hidden {
    opacity: 0.4;
    background: repeating-linear-gradient(45deg, transparent, transparent 10px, color-mix(in srgb, var(--primary, #f42182) 5%, transparent) 10px, color-mix(in srgb, var(--primary, #f42182) 5%, transparent) 20px);
  }
  /* Bloc HTML brut en mode éditeur : cursor pointer + hover indicator.
     Le contenu placeholder (texte cliquable) est rendu côté serveur dans le bloc. */
  body.editor-mode .custom-html-section { cursor: pointer; min-height: 40px; }
  body.editor-mode .custom-html-section:hover {
    outline: 3px dashed #6366f1; outline-offset: 4px;
  }
  /* Fallback si le bloc est vraiment vide */
  body.editor-mode .custom-html-section:empty::before {
    content: '< > Cliquer pour modifier ce code…';
    display: block; padding: 20px; text-align: center;
    color: #6366f1; font-family: monospace; font-size: 13px;
    border: 2px dashed #c7d2fe; border-radius: 8px;
  }
  body.editor-mode [data-edit-field] {
    cursor: pointer;
    /* Style harmonisé pour TOUS les éléments éditables : 3 px dashed + 4 px de gap.
       PAS de border-radius forcé : on garde celui de chaque élément (badges ronds,
       cartes arrondies, etc.). Les navigateurs modernes font suivre l'outline aux
       coins arrondis naturels de l'élément. */
    outline: 3px dashed transparent;
    outline-offset: 4px;
    transition: outline-color .12s;
  }
  body.editor-mode [data-edit-field]:hover { outline-color: var(--primary, #f42182); }
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
  /* L'overlay ne capture PLUS les clics : seuls les éléments éditables le font
     (badges, timer, sous-titre, bouton CTA…). Les zones vides laissent passer
     le clic vers `.demo-card-media > video[data-edit-field="video_accueil"]`
     → sélection visuelle + sidebar avec les propriétés (slider hauteur, toggle
     pleine largeur, bouton "Modifier le contenu" qui ouvre l'upload existant). */
  body.editor-mode .demo-overlay { pointer-events: none !important; }
  body.editor-mode .demo-overlay [data-edit-field] { pointer-events: auto !important; }
  body.editor-mode .demo-video[data-edit-field] { pointer-events: auto !important; cursor: pointer !important; }
  /* Le timer hérite désormais du style commun `body.editor-mode [data-edit-field]`
     (3 px dashed, 4 px de gap). On retire l'override 2 px qui dérogeait. */

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
     En éditeur on remplace par une valeur en px ABSOLUE. La CSS variable
     `--hero-card-height` (posée par le slider de l'éditeur) reste prioritaire pour
     que l'admin puisse override la hauteur — fallback 600 px sinon. */
  /* En mode éditeur DESKTOP : on utilise UNIQUEMENT --hero-card-height (desktop).
     Sans ça, le fallback `var(--hero-card-height-mobile, ...)` prenait la valeur
     mobile en priorité → toucher la hauteur en mobile affectait l'aperçu desktop
     (bug "mobile et desktop liés pour la vidéo"). */
  body.editor-mode .demo-card {
    height:     var(--hero-card-height, 600px) !important;
    min-height: var(--hero-card-height, 600px) !important;
    max-height: var(--hero-card-height, 600px) !important;
  }
  /* En mode éditeur MOBILE (iframe rétrécie < 1040px) : --hero-card-height-mobile
     prioritaire, fallback desktop puis 600px. Cohérent avec le viewport de l'iframe. */
  @media (max-width: 1040px) {
    body.editor-mode .demo-card {
      height:     var(--hero-card-height-mobile, var(--hero-card-height, 600px)) !important;
      min-height: var(--hero-card-height-mobile, var(--hero-card-height, 600px)) !important;
      max-height: var(--hero-card-height-mobile, var(--hero-card-height, 600px)) !important;
    }
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


  // Device courant signalé par le parent (settings) via postMessage 'editor-set-device'.
  // Influe sur la clé de sauvegarde (suffixe _mobile côté PHP) ET sur le calcul des
  // ancres pendant un drag : en mobile, getBoundingClientRect lit la version mobile
  // du DOM (l'iframe est rétrécie, le CSS media query bascule en layout mobile).
  //
  // SOURCE DE VÉRITÉ : on lit directement la classe `.is-mobile` sur le wrapper
  // `#ifePreviewWrap` du parent (settings). C'est plus fiable que la variable cachée
  // `__currentEditorDevice` qui dépendait du timing du postMessage 'editor-set-device'
  // (parfois reçu APRÈS un drag rapide → écriture dans le mauvais set d'attrs).
  // Fallback sur __currentEditorDevice + innerWidth si l'accès parent échoue.
  var __currentEditorDevice = 'desktop';
  function __detectEditorDevice() {
    try {
      var w = window.parent && window.parent.document
        ? window.parent.document.getElementById('ifePreviewWrap')
        : null;
      if (w) return w.classList.contains('is-mobile') ? 'mobile' : 'desktop';
    } catch(e) { /* cross-origin ou parent pas accessible : fallback */ }
    if (__currentEditorDevice === 'mobile' || __currentEditorDevice === 'desktop') {
      return __currentEditorDevice;
    }
    return window.innerWidth < 1040 ? 'mobile' : 'desktop';
  }
  window.__getEditorDevice = __detectEditorDevice;

  // Champs qui ont leur propre élément HTML pour PC et mobile (séparés via CSS).
  // Pour ces champs, le nom du champ porte déjà le device (titleAccueil_mobile),
  // donc on écrit dans les attributs de base. Pour les AUTRES éléments (badges,
  // timer, social, toggle) qui n'ont qu'un seul nœud DOM partagé, l'écriture
  // doit cibler la variante *-mobile du dataset quand on édite en mobile, sinon
  // l'édition mobile écrase la position desktop en mémoire (bug "PC et mobile liés").
  var __TWIN_HTML_FIELDS = [
    'titleAccueil', 'titleAccueil_mobile',
    'subtitle_accueil', 'subtitle_accueil_mobile'
  ];
  // Liste des champs hero qui ont UN SEUL élément HTML (pas de jumeau .hero-pc/.hero-mobile).
  // Pour ces champs, on simule la séparation comme pour subtitle_accueil_mobile en :
  //  - modifiant `data-edit-field` selon le device courant (`hero_timer` ↔ `hero_timer_mobile`)
  //  - idem pour `data-edit-size` (`hero_timer_size` ↔ `hero_timer_size_mobile`)
  // Le drag/save part alors avec le bon field, la sidebar parent affiche le bon nom,
  // et le PHP enregistre sans avoir besoin de logique de suffixage (le field arrive déjà
  // suffixé). En clair : depuis le point de vue de l'admin, chaque device a SON champ.
  var __DEVICE_SUFFIX_FIELDS = ['hero_timer', 'badge_fee', 'badge_km', 'video_toggle', 'video_social_card', 'video_accueil'];
  function __syncFieldNamesToDevice() {
    var dev = __detectEditorDevice();
    var addSuffix = (dev === 'mobile');
    __DEVICE_SUFFIX_FIELDS.forEach(function(baseField) {
      // Cherche l'élément par son field actuel (peut être base OU déjà suffixé).
      var el = document.querySelector(
        '[data-edit-field="' + baseField + '"], [data-edit-field="' + baseField + '_mobile"]'
      );
      if (!el) return;
      var targetField    = addSuffix ? (baseField + '_mobile') : baseField;
      var baseSizeKey    = el.dataset.editSize ? el.dataset.editSize.replace(/_mobile$/, '') : null;
      var targetSizeKey  = (baseSizeKey && addSuffix) ? (baseSizeKey + '_mobile') : baseSizeKey;
      el.dataset.editField = targetField;
      if (baseSizeKey) el.dataset.editSize = targetSizeKey;
      // ── PAS de manipulation du transform pour les éléments sans slider de taille
      // (video_social_card et video_toggle : kind="size-only" mais sans data-edit-size).
      // Sans ce skip, on écrasait leur transform CSS naturel (ex: translateY(-50%) pour
      // .video-social-card) par un transform inline vide → la card se "déplaçait"
      // verticalement de manière imprévisible au toggle device ou au load.
      if (!baseSizeKey) {
        // Le cache dataset.scale reste invalidé pour cohérence avec les autres.
        delete el.dataset.scale;
        return;
      }
      // ── Détermine les valeurs PC / mobile pour appliquer le scale visuel.
      // La SOURCE DE VÉRITÉ reste data-edit-size-current (desktop) et
      // data-edit-size-current-mobile (mobile). Ces attrs sont maintenus à jour par
      // le handler `parent-update-style` à chaque save → on n'a PAS besoin d'un
      // backup `editSizeCurrentDesktopOriginal` (qui était figé à la valeur initiale
      // et causait "slider revient à 100% au retour Desktop alors qu'on avait sauvé 130%").
      var desktopValue = parseInt(el.dataset.editSizeCurrent || '100', 10);
      var mobileValue  = el.dataset.editSizeCurrentMobile
        ? parseInt(el.dataset.editSizeCurrentMobile, 10)
        : 100;
      var effectiveScale = (addSuffix ? mobileValue : desktopValue) / 100;
      // Applique le scale visuel au inline style.transform en conservant le translate
      // existant. CRITIQUE : sans ça, le scale desktop (1.3 par ex.) émis inline par
      // PHP restait appliqué en mobile (où la valeur effective est 100%), faisant
      // l'illusion que mobile et desktop sont liés visuellement.
      var currentTransform = el.style.transform || '';
      var withoutScale = currentTransform.replace(/\s*scale\([^)]*\)/g, '').trim();
      if (Math.abs(effectiveScale - 1) > 0.001) {
        el.style.transform = (withoutScale ? withoutScale + ' ' : '') + 'scale(' + effectiveScale + ')';
        el.style.transformOrigin = 'left top';
      } else {
        el.style.transform = withoutScale;
      }
      // Le cache dataset.scale est invalidé pour que getElementScale recalcule au
      // prochain drag (sinon il garderait la valeur du device précédent).
      delete el.dataset.scale;
    });
  }
  // Synchronisation initiale (peut être appelée avant que le parent envoie set-device).
  if (document.readyState !== 'loading') {
    __syncFieldNamesToDevice();
  } else {
    document.addEventListener('DOMContentLoaded', __syncFieldNamesToDevice);
  }
  window.__syncFieldNamesToDevice = __syncFieldNamesToDevice;

  function __writeAnchorAttrs(el, anchorX, anchorY, offsetX, offsetY) {
    var field = el.dataset.editField || '';
    // Lit le device DEPUIS LE PARENT (DOM) plutôt que la variable cachée — robuste
    // contre les courses postMessage. Voir __detectEditorDevice ci-dessus.
    var dev = __detectEditorDevice();
    var useMobileKey = (__TWIN_HTML_FIELDS.indexOf(field) === -1)
                    && (dev === 'mobile');
    if (useMobileKey) {
      el.dataset.anchorXMobile = anchorX;
      el.dataset.anchorYMobile = anchorY;
      el.dataset.offsetXMobile = offsetX;
      el.dataset.offsetYMobile = offsetY;
      delete el.dataset.posTopMobile;
      delete el.dataset.posLeftMobile;
    } else {
      el.dataset.anchorX = anchorX;
      el.dataset.anchorY = anchorY;
      el.dataset.offsetX = offsetX;
      el.dataset.offsetY = offsetY;
      delete el.dataset.posTop;
      delete el.dataset.posLeft;
    }
  }
  window.__writeAnchorAttrs = __writeAnchorAttrs;

  function migrateLegacyHeroGeometry() {
    if (typeof window.__applyHeroPositionPct === 'function') {
      window.__applyHeroPositionPct();
    }
    var migratableFields = [
      'video_toggle', 'video_social_card',
      'badge_fee', 'badge_km',
      'hero_timer',
      'titleAccueil', 'titleAccueil_mobile',
      'subtitle_accueil', 'subtitle_accueil_mobile'
    ];
    var migratedCount = 0;
    document.querySelectorAll('[data-edit-section="hero"][data-edit-field]').forEach(function(el) {
      var field = el.dataset.editField;
      if (migratableFields.indexOf(field) === -1) return;
      var inlineTransform = el.style.transform || '';
      var hasLegacyPct = !!(el.dataset.posTop && el.dataset.posLeft);
      var hasLegacyTranslate = /translate\(/.test(inlineTransform);
      var hasOldResponsiveAnchor = !!(el.dataset.anchorX && el.dataset.anchorY && el.dataset.offsetX && el.dataset.offsetY);

      var card = el.closest('.demo-card');
      if (!card) return;
      var pr = card.getBoundingClientRect();
      var er = el.getBoundingClientRect();
      if (pr.width <= 0 || pr.height <= 0 || er.width <= 0 || er.height <= 0) return;

      var anchorX = 'left';
      var anchorY = ((er.top + er.bottom) / 2 - pr.top < pr.height / 2) ? 'top' : 'bottom';
      var offsetX = er.left - pr.left;
      var offsetY = anchorY === 'bottom' ? (pr.bottom - er.bottom) : (er.top - pr.top);
      var hasStableAnchor = el.dataset.anchorX === anchorX
        && el.dataset.anchorY === anchorY
        && el.dataset.offsetX
        && el.dataset.offsetY
        && !hasLegacyPct
        && el.dataset.needsGeometryMigration !== '1';
      if (hasStableAnchor && !hasLegacyTranslate) return;
      var scale = parseFloat(el.dataset.scale) || 1;
      var scaleMatch = inlineTransform.match(/scale\(([\d.]+)\)/);
      if (scaleMatch) scale = parseFloat(scaleMatch[1]) || scale;
      if (el.dataset.editSizeCurrent && scale === 1) {
        var sizeScale = parseInt(el.dataset.editSizeCurrent, 10) / 100;
        if (sizeScale && Math.abs(sizeScale - 1) > 0.001) scale = sizeScale;
      }

      __writeAnchorAttrs(el, anchorX, anchorY, offsetX, offsetY);
      delete el.dataset.needsGeometryMigration;

      parent.postMessage({
        type: 'editor-save-geometry',
        field: field,
        device: __detectEditorDevice(),
        geometry: {
          x: 0,
          y: 0,
          w: el.offsetWidth,
          h: el.offsetHeight,
          scale: scale,
          anchorX: anchorX,
          anchorY: anchorY,
          offsetX: offsetX,
          offsetY: offsetY
        }
      }, '*');
      migratedCount++;
    });
    return migratedCount;
  }
  // Exposé pour pouvoir être déclenché depuis le parent (bouton "Migrer positions").
  // ATTENTION : ne PAS appeler automatiquement au load — cela créerait un draft
  // ("Modifications non publiées") à chaque ouverture de l'éditeur, même quand
  // rien n'a réellement été modifié. La migration ne tourne que sur clic explicite.
  window.__migrateLegacyHeroGeometry = migrateLegacyHeroGeometry;

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

  /* ⚠️ LA HAUTEUR DOIT AUSSI SUIVRE QUAND LE CONTENU RACCOURCIT.
     Elle n'était renvoyée qu'au chargement et au redimensionnement de la
     FENÊTRE. Or le contenu change tout seul : une section passée en carte,
     un bloc masqué, une image qui finit de charger. L'iframe gardait alors
     sa hauteur d'avant et laissait une grande zone vide sous le pied de
     page — sans que rien ne la déclenche à nouveau.

     ResizeObserver sur <body> couvre tous ces cas d'un coup, et passe par le
     même envoi débouncé : pas de boucle avec l'ajustement du parent. */
  if (window.ResizeObserver) {
    try {
      new ResizeObserver(debouncedSend).observe(document.body);
    } catch (e) { /* navigateur trop ancien : on garde load + resize */ }
  }

  // Récupère les infos d'un élément data-edit-field.
  // sizeCurrent reflète la valeur du DEVICE courant — TOTALEMENT indépendant :
  // - En mobile : on lit UNIQUEMENT data-edit-size-current-mobile. Si absent, valeur 100%
  //   (PAS de fallback sur la taille desktop, sinon l'admin voyait "100% sur slider mais
  //    élément plus grand" car la taille desktop fuyait visuellement et numériquement).
  // - En desktop : on lit data-edit-size-current.
  // EXCEPTION reg_bar.* : le CSS de la card inscriptions hérite volontairement de la
  // taille desktop en mobile (var(--rb-scale-m, var(--rb-scale, 1))). Tant qu'aucune
  // taille mobile n'a été sauvée, la valeur EFFECTIVE en mobile est donc la valeur
  // desktop — le slider doit l'afficher, sinon "100% au slider mais élément plus
  // grand" et saut visuel au premier mouvement.
  function getEditPayload(ed, type) {
    var src = '';
    if (ed.tagName === 'IMG') src = ed.getAttribute('src') || '';
    else if (ed.querySelector('source')) src = ed.querySelector('source').getAttribute('src') || '';
    var isMobileView = window.innerWidth < 1040;
    var isRbSize = (ed.dataset.editSize || '').indexOf('reg_bar.') === 0;
    var sizeCurrentRaw = isMobileView
      ? (ed.dataset.editSizeCurrentMobile || (isRbSize ? (ed.dataset.editSizeCurrent || '100') : '100'))
      : (ed.dataset.editSizeCurrent       || '100');
    return {
      type: type,
      field: ed.dataset.editField,
      kind: ed.dataset.editKind,
      section: ed.dataset.editSection,
      sizeKey: ed.dataset.editSize || null,
      sizeCurrent: parseInt(sizeCurrentRaw, 10),
      currentValue: (ed.dataset.editKind === 'tinymce') ? ed.innerHTML
                     : (ed.dataset.editKind === 'text') ? ed.textContent.trim()
                     : src.split('/').pop()
    };
  }

  // SINGLE CLICK : sélectionne, montre le slider de taille dans la sidebar parent.
  // On garde la référence au dernier élément sélectionné pour pouvoir ré-émettre
  // un `editor-select-edit` quand le device toggle change (sinon le slider de la
  // sidebar reste sur la valeur de l'ancien device et l'admin croit que sa
  // modification ne prend pas effet).
  var __lastEditField = null;
  document.addEventListener('click', function(e) {
    var ed = e.target.closest('[data-edit-field]');
    if (ed) {
      e.preventDefault(); e.stopPropagation();
      __lastEditField = ed;
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

    /* Le pied de page d abord : il vit HORS du layout, aucun data-editor-row-id
       ne le couvre, et sans ce test un clic dessus ne sélectionnait rien. */
    if (e.target.closest('[data-editor-footer]')) {
      e.preventDefault();
      parent.postMessage({ type: 'editor-click-footer' }, '*');
      return;
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
  // les actions sur l'élément ciblé (modifier, supprimer, masquer, extraire, etc.).
  // Si le clic est sur un ÉLÉMENT HERO (data-edit-field d'une section "hero"), on
  // envoie en plus le mode d'ancrage courant pour permettre au parent d'afficher
  // les options "Centré X / Centré Y / Centré 2D / Libre".
  document.addEventListener('contextmenu', function(e) {
    var heroEl = e.target.closest('[data-edit-field][data-edit-section="hero"]');
    var col = e.target.closest('[data-editor-col-idx]');
    var row = e.target.closest('[data-editor-row-id]');
    if (!heroEl && !col && !row) return;
    e.preventDefault();
    var payload = {
      type: 'editor-contextmenu',
      // Position dans l'iframe (le parent ajoutera l'offset de l'iframe)
      x: e.clientX,
      y: e.clientY,
      rowId: row ? row.dataset.editorRowId : null,
      colIdx: col ? parseInt(col.dataset.editorColIdx, 10) : null,
      sectionType: col ? col.dataset.editorSectionType : null,
      sectionId: col ? col.dataset.editorSectionId : null
    };
    if (heroEl) {
      var deviceMobile = (typeof window.__getEditorDevice === 'function')
        ? window.__getEditorDevice() === 'mobile'
        : (window.innerWidth < 1040);
      var currentMode = (deviceMobile && heroEl.dataset.axisModeMobile)
        ? heroEl.dataset.axisModeMobile
        : (heroEl.dataset.axisMode || 'free');
      payload.heroField   = heroEl.dataset.editField;
      payload.heroDevice  = deviceMobile ? 'mobile' : 'desktop';
      payload.heroMode    = currentMode;
    }
    parent.postMessage(payload, '*');
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
      ed.style.outline = '2px solid var(--primary, #f42182)';
      ed.style.background = 'color-mix(in srgb, var(--primary, #f42182) 8%, transparent)';
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
      parent.postMessage({
        type: 'editor-save-geometry',
        field: field,
        device: __detectEditorDevice(),
        geometry: geom
      }, '*');
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
      // Device-aware : en mode mobile, on lit UNIQUEMENT la taille mobile dédiée
      // (data-size-mobile / data-edit-size-current-mobile). Si elle n'existe pas, on
      // retourne 1 (taille CSS naturelle) — on NE retombe PAS sur la taille desktop.
      // Sinon, drag/resize en mode mobile réappliquait le scale DESKTOP et l'élément
      // semblait "rétrécir" alors que mobile n'a pas de réglage explicite.
      var deviceMobile = (typeof window.__getEditorDevice === 'function')
        ? window.__getEditorDevice() === 'mobile'
        : (window.innerWidth < 1040);
      if (deviceMobile) {
        var mob = el.dataset.sizeMobile || el.dataset.editSizeCurrentMobile;
        if (mob) {
          var smz = parseInt(mob, 10) / 100;
          if (smz && smz !== 1) { el.dataset.scale = smz; return smz; }
        }
        return 1; // pas de taille mobile → taille CSS naturelle, sans fallback desktop
      }
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

    function readTranslateFromTransform(transformValue) {
      if (!transformValue || transformValue === 'none') return null;
      var direct = transformValue.match(/translate\(\s*([-\d.]+)px\s*,\s*([-\d.]+)px\s*\)/);
      if (direct) return { x: parseFloat(direct[1]) || 0, y: parseFloat(direct[2]) || 0 };
      var matrix = transformValue.match(/^matrix\((.+)\)$/);
      if (matrix) {
        var parts = matrix[1].split(',').map(function(v) { return parseFloat(v.trim()) || 0; });
        return { x: parts[4] || 0, y: parts[5] || 0 };
      }
      var matrix3d = transformValue.match(/^matrix3d\((.+)\)$/);
      if (matrix3d) {
        var parts3d = matrix3d[1].split(',').map(function(v) { return parseFloat(v.trim()) || 0; });
        return { x: parts3d[12] || 0, y: parts3d[13] || 0 };
      }
      return null;
    }

    // Liste des champs qui sont des BOUTONS d'action → édition simple (textarea sidebar),
    // PAS de drag libre ni resize 4-coins. Sinon le clic ferait apparaître les
    // poignées de redimensionnement ce que l'utilisateur ne veut pas pour ces boutons.
    // `hero.cta_register` (bouton "Je m'inscris") A ÉTÉ RETIRÉ pour le rendre draggable
    // dans le hero. On le met dans NO_RESIZE_FIELDS pour ne pas avoir de poignées de resize
    // (le bouton garde sa taille naturelle CSS).
    var NO_DRAG_FIELDS = ['reg_bar.btn_check', 'partners.btn_submit'];
    // Champs draggables MAIS sans resize 4-coins (le slider de taille suffit, et le resize
    // libre déformerait le badge circulaire en ovale).
    var NO_RESIZE_FIELDS = ['badge_fee', 'badge_km', 'video_toggle', 'video_social_card', 'hero.cta_register'];
    var draggables = document.querySelectorAll('[data-edit-field][data-edit-section="hero"], [data-edit-field][data-edit-section="partners"]');
    draggables.forEach(function(el) {
      if (el.dataset.editKind === 'video') return;
      if (NO_DRAG_FIELDS.indexOf(el.dataset.editField) !== -1) return;
      // Ne force 'relative' QUE si l'élément est en static. Sinon on préserve le positionnement
      // CSS (ex: .video-toggle et .video-social-card sont absolus → ne pas écraser).
      var __computedPos = (window.getComputedStyle ? window.getComputedStyle(el).position : '');
      if (!el.style.position && __computedPos === 'static') el.style.position = 'relative';
      el.style.touchAction = 'none';
      // Initialise dataset.x/y depuis le transform inline rendu par PHP (geometry sauvée).
      // Sinon, après reload, le slider de taille écraserait la position en remettant translate(0,0).
      if (!el.dataset.x) {
        var __initTranslate = readTranslateFromTransform(el.style.transform || '');
        if (!__initTranslate && window.getComputedStyle) {
          __initTranslate = readTranslateFromTransform(window.getComputedStyle(el).transform || '');
        }
        el.dataset.x = __initTranslate ? String(__initTranslate.x) : '0';
        el.dataset.y = __initTranslate ? String(__initTranslate.y) : '0';
      }

      // Lit le mode d'ancrage actif (device-aware) pour contraindre le drag.
      function getActiveAxisMode(elNode) {
        var deviceMobile = (typeof window.__getEditorDevice === 'function')
          ? window.__getEditorDevice() === 'mobile'
          : (window.innerWidth < 1040);
        var mob = elNode.dataset.axisModeMobile;
        var dsk = elNode.dataset.axisMode;
        var m = deviceMobile && mob ? mob : (dsk || 'free');
        return (m === 'centerX' || m === 'centerY' || m === 'center') ? m : 'free';
      }
      var inter = interact(el)
        .draggable({
          listeners: {
            move: function(event) {
              var mode = getActiveAxisMode(event.target);
              // En mode 'center' : aucun mouvement (l'élément reste figé au centre 2D).
              if (mode === 'center') return;
              var dx = (mode === 'centerX') ? 0 : event.dx; // axe X bloqué si centerX
              var dy = (mode === 'centerY') ? 0 : event.dy; // axe Y bloqué si centerY
              var x = (parseFloat(event.target.dataset.x) || 0) + dx;
              var y = (parseFloat(event.target.dataset.y) || 0) + dy;
              applyTransform(event.target, x, y);
              event.target.dataset.x = x;
              event.target.dataset.y = y;
              // Snap actif UNIQUEMENT en mouvement lent (recherche de précision).
              // En mouvement rapide, déplacement 100% libre — pas de blocage.
              var snap = computeAlignment(event.target, event.target.getBoundingClientRect());
              var slow = (event.speed || 0) < 250; // px/sec
              if (slow && (snap.dx || snap.dy)) {
                // Snap respecte aussi la contrainte d'axe.
                var snapDx = (mode === 'centerX') ? 0 : snap.dx;
                var snapDy = (mode === 'centerY') ? 0 : snap.dy;
                var nx = x + snapDx, ny = y + snapDy;
                applyTransform(event.target, nx, ny);
                event.target.dataset.x = nx;
                event.target.dataset.y = ny;
              }
            },
            end: function(event) {
              clearGuides();
              var field = event.target.dataset.editField;
              if (!field) return;
              var geom = {
                x: parseFloat(event.target.dataset.x) || 0,
                y: parseFloat(event.target.dataset.y) || 0,
                w: event.target.offsetWidth,
                h: event.target.offsetHeight,
                scale: parseFloat(event.target.dataset.scale) || 1
              };
              // Tous les éléments draggables du Hero stockent leur position en ANCRE+OFFSET PX
              // depuis la gauche et depuis le haut/bas selon la position dans les settings
              // → position visuelle FIGÉE à pixel près. Plus de décalage entre l'iframe
              // étroite de l'éditeur et la page live large (problème des % qui scalaient avec
              // la largeur du conteneur alors que le titre ne grandit pas proportionnellement).
              // NB : on NE bake PAS en top/left CSS — les badges sont dans .demo-badges, leur
              // ancêtre positionné n'est pas .demo-card. Le runtime (applyHeroPositionPct)
              // calcule le bon transform translate à partir des data-anchor-*/data-offset-*.
              var PCT_FIELDS = [
                'video_toggle', 'video_social_card',
                'badge_fee', 'badge_km',
                'hero_timer',
                'titleAccueil', 'titleAccueil_mobile',
                'subtitle_accueil', 'subtitle_accueil_mobile',
                'hero.cta_register'
              ];
              if (PCT_FIELDS.indexOf(field) !== -1) {
                var parentEl = event.target.closest('.demo-card');
                if (parentEl) {
                  var pr = parentEl.getBoundingClientRect();
                  var er = event.target.getBoundingClientRect();
                  if (pr.width > 0 && pr.height > 0) {
                    // ANCRAGE AU COIN LE PLUS PROCHE en pixels. Quand la card change
                    // de taille (vh / breakpoints), l'élément reste à la MÊME DISTANCE
                    // de SON coin de référence. Ex : élément à 5 px du bord droit et
                    //  15 px du haut → on sauve anchorX=right, offsetX=5, anchorY=top,
                    //  offsetY=15. Reste collé au coin haut-droit quoiqu'il arrive.
                    var elCenterX = (er.left + er.right)  / 2;
                    var elCenterY = (er.top  + er.bottom) / 2;
                    var cardCenterX = pr.left + pr.width  / 2;
                    var cardCenterY = pr.top  + pr.height / 2;
                    var anchorX = (elCenterX > cardCenterX) ? 'right' : 'left';
                    var anchorY = (elCenterY > cardCenterY) ? 'bottom' : 'top';
                    var offsetX = (anchorX === 'right')
                      ? (pr.right - er.right)
                      : (er.left  - pr.left);
                    var offsetY = (anchorY === 'bottom')
                      ? (pr.bottom - er.bottom)
                      : (er.top    - pr.top);
                    // Écrit dans le bon set (anchor* desktop OU anchor*Mobile mobile)
                    // selon le device courant ET selon si le champ a un jumeau HTML.
                    __writeAnchorAttrs(event.target, anchorX, anchorY, offsetX, offsetY);
                    geom.anchorX = anchorX;
                    geom.anchorY = anchorY;
                    geom.offsetX = offsetX;
                    geom.offsetY = offsetY;
                    // Purge des anciens modèles (% legacy + translate px) pour basculer
                    // proprement vers le nouveau modèle ancré.
                    delete geom.topPct; delete geom.leftPct;
                    geom.x = 0;
                    geom.y = 0;
                  }
                }
              }
              // Conserve le mode d'ancrage actif (centerX/Y/center/free) lors du save :
              // sinon, dès que l'admin drag, on perdrait le mode et l'élément retournerait
              // en 'free' au reload, ce qui défait la propriété "rester centré" demandée.
              var activeMode = getActiveAxisMode(event.target);
              if (activeMode !== 'free') geom.mode = activeMode;
              postGeometry(field, geom);
            }
          }
        });

      if (NO_RESIZE_FIELDS.indexOf(el.dataset.editField) !== -1) return;
      inter
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
              var resizeGeom = {
                x: parseFloat(event.target.dataset.x) || 0,
                y: parseFloat(event.target.dataset.y) || 0,
                w: event.target.offsetWidth,
                h: event.target.offsetHeight,
                scale: parseFloat(event.target.dataset.scale) || 1
              };
              // Recalcule l'ancre+offset après resize : la position visuelle peut avoir bougé
              // (resize depuis le coin haut-gauche déplace le top/left). Sans ce recalcul, on
              // sauverait seulement x/y/w/h et la position figée serait perdue.
              var parentEl = event.target.closest('.demo-card');
              if (parentEl) {
                var pr = parentEl.getBoundingClientRect();
                var er = event.target.getBoundingClientRect();
                if (pr.width > 0 && pr.height > 0) {
                  var anchorX = 'left';
                  var anchorY = (er.top + er.bottom) / 2 - pr.top < pr.height / 2 ? 'top' : 'bottom';
                  resizeGeom.anchorX = anchorX;
                  resizeGeom.anchorY = anchorY;
                  resizeGeom.offsetX = er.left - pr.left;
                  resizeGeom.offsetY = anchorY === 'bottom' ? (pr.bottom - er.bottom) : (er.top - pr.top);
                  __writeAnchorAttrs(event.target, resizeGeom.anchorX, resizeGeom.anchorY,
                                                  resizeGeom.offsetX, resizeGeom.offsetY);
                }
              }
              postGeometry(field, resizeGeom);
            }
          }
        });
    });
  }

  // Reconstruit le src de l'iframe Google Maps de la section "Retrouver le départ"
  // à partir des data-attributs de la section (adresse prioritaire, sinon
  // coordonnées, sinon Forbach par défaut).
  function __refreshStartPointMap() {
    var sec = document.querySelector('.start-point-section');
    var frame = document.querySelector('.start-point-map-frame');
    if (!sec || !frame) return;
    var addr  = (sec.dataset.spAddress || '').trim();
    var coord = (sec.dataset.spCoords  || '').trim();
    var dest  = addr !== '' ? addr : (coord !== '' ? coord : 'Forbach, France');
    frame.src = 'https://maps.google.com/maps?q=' + encodeURIComponent(dest)
              + '&z=15&hl=fr&output=embed';
  }

  // Réception de commandes du parent (pour scroll-to-section, highlight, update DOM sans reload, etc.)
  window.addEventListener('message', function(e) {
    if (!e.data || typeof e.data !== 'object') return;

    // Bascule Desktop / Mobile : le parent (settings) nous signale quel device l'admin
    // édite actuellement. Toutes les sauvegardes suivantes seront routées vers la clé
    // correspondante (suffixe _mobile côté PHP).
    if (e.data.type === 'editor-set-device') {
      __currentEditorDevice = (e.data.device === 'mobile') ? 'mobile' : 'desktop';
      // Renomme les data-edit-field / data-edit-size selon le nouveau device.
      // Ex: hero_timer → hero_timer_mobile en mobile. Permet à la sidebar parent
      // d'afficher le bon nom (séparation claire mobile vs PC comme subtitle_accueil).
      if (typeof __syncFieldNamesToDevice === 'function') __syncFieldNamesToDevice();
      // Invalide le cache `dataset.scale` sur tous les éléments hero : il avait été
      // calé via getElementScale() au passage précédent (donc avec la valeur du device
      // précédent). Sans invalidation, le drag/resize en mobile garderait le scale
      // desktop hérité — c'est ce qui faisait "rétrécir" le timer au drag mobile alors
      // qu'aucune taille mobile n'était définie.
      document.querySelectorAll('[data-edit-section="hero"][data-edit-field]').forEach(function(el){
        delete el.dataset.scale;
      });
      // Réapplique les positions plusieurs fois pour couvrir le timing : l'iframe
      // vient juste de changer de largeur côté parent, et le reflow interne (window
      // .innerWidth, breakpoints CSS) peut ne pas être encore propagé. Sans ces
      // retries, applyHeroPositionPct utilise les attributs du mauvais device.
      function reapply() {
        if (typeof window.__applyHeroPositionPct === 'function') {
          window.__applyHeroPositionPct();
        }
        // Re-poste aussi la structure (rects des rows) au parent : après un switch
        // device, le reflow change la largeur/les positions des rows mais PAS forcément
        // la hauteur du document → debouncedSend (filtré sur la hauteur) ne se déclenche
        // pas, et les overlays/traits pointillés gardent les positions de l'ancien layout
        // (d'où le chevauchement). Appel direct = contourne ce filtre.
        if (typeof sendLayoutStructure === 'function') sendLayoutStructure();
      }
      reapply();
      setTimeout(reapply, 50);
      setTimeout(reapply, 200);
      setTimeout(reapply, 500);
      // Reapply plus tardifs : la transition CSS de l'iframe (max-width .2s) peut ne pas
      // être finie à 500ms si le browser ralentit (animations queue, throttling). Sans ces
      // reapply, video_toggle au toggle Mobile gardait sa position desktop tant qu'on ne
      // refresh pas. À 800/1200ms on a la garantie que le layout est stable.
      setTimeout(reapply, 800);
      setTimeout(reapply, 1200);
      // Re-publie l'élément sélectionné pour que le slider de la sidebar parent se
      // rafraîchisse avec la valeur du NOUVEAU device (sinon il reste sur l'ancienne
      // valeur et l'admin croit que sa modif ne prend pas effet). On attend que le
      // reflow soit propagé pour que getEditPayload lise le bon innerWidth.
      if (__lastEditField && document.body.contains(__lastEditField)) {
        setTimeout(function(){
          if (__lastEditField && document.body.contains(__lastEditField)) {
            try {
              parent.postMessage(getEditPayload(__lastEditField, 'editor-select-edit'), '*');
            } catch(err) {}
          }
        }, 250);
      }
      return;
    }

    // Change le mode d'ancrage (axis-mode) d'un élément hero pour le device demandé.
    // Le mode est stocké sur le dataset puis persistéé via le flux save_accueil_geometry
    // standard (en réutilisant les anchor/offset courants, juste avec mode ajouté).
    if (e.data.type === 'parent-set-axis-mode' && e.data.field) {
      var modeField  = e.data.field;
      var modeDev    = (e.data.device === 'mobile') ? 'mobile' : 'desktop';
      var newMode    = e.data.mode;
      if (!newMode || ['free','centerX','centerY','center'].indexOf(newMode) === -1) {
        return;
      }
      var modeEls = document.querySelectorAll('[data-edit-field="' + modeField + '"]');
      modeEls.forEach(function(el){
        // Dataset device-aware : titleAccueil/subtitle ont leur HTML séparé (mobile vs pc)
        // donc on écrit toujours sur dataset.axisMode pour eux (pas de variante mobile).
        var twin = ['titleAccueil','titleAccueil_mobile','subtitle_accueil','subtitle_accueil_mobile'];
        var useMobileKey = (twin.indexOf(modeField) === -1) && (modeDev === 'mobile');
        if (newMode === 'free') {
          // 'free' = retour au comportement libre → on supprime la clé pour économiser
          // les attributs émis et faire un rendu plus propre.
          if (useMobileKey) delete el.dataset.axisModeMobile;
          else              delete el.dataset.axisMode;
        } else {
          if (useMobileKey) el.dataset.axisModeMobile = newMode;
          else              el.dataset.axisMode       = newMode;
        }
        // Construit la géométrie courante de l'élément pour la persister avec le mode.
        // On lit les anchor/offset existants (du bon device) pour les conserver.
        var dsetAnchorX = useMobileKey ? el.dataset.anchorXMobile : el.dataset.anchorX;
        var dsetAnchorY = useMobileKey ? el.dataset.anchorYMobile : el.dataset.anchorY;
        var dsetOffsetX = useMobileKey ? el.dataset.offsetXMobile : el.dataset.offsetX;
        var dsetOffsetY = useMobileKey ? el.dataset.offsetYMobile : el.dataset.offsetY;
        var geomPayload = {
          x: 0, y: 0,
          w: el.offsetWidth,
          h: el.offsetHeight,
          scale: parseFloat(el.dataset.scale) || 1
        };
        if (dsetAnchorX && dsetAnchorY) {
          geomPayload.anchorX = dsetAnchorX;
          geomPayload.anchorY = dsetAnchorY;
          geomPayload.offsetX = parseFloat(dsetOffsetX) || 0;
          geomPayload.offsetY = parseFloat(dsetOffsetY) || 0;
        }
        if (newMode !== 'free') geomPayload.mode = newMode;
        // Persist : passe par le canal save_accueil_geometry standard.
        parent.postMessage({
          type: 'editor-save-geometry',
          field: modeField,
          device: modeDev,
          geometry: geomPayload
        }, '*');
      });
      // Réapplique la position : le nouveau mode entre en effet visuellement.
      if (typeof window.__applyHeroPositionPct === 'function') {
        window.__applyHeroPositionPct();
      }
      return;
    }

    // Migration one-shot : convertit les positions legacy (% ou translate px) en
    // ancre+offset px. Le parent reçoit le compteur via 'editor-migrate-done'.
    if (e.data.type === 'editor-migrate-legacy') {
      var count = 0;
      try {
        if (typeof window.__migrateLegacyHeroGeometry === 'function') {
          count = window.__migrateLegacyHeroGeometry() || 0;
        }
      } catch (err) {
        count = 0;
      }
      parent.postMessage({ type: 'editor-migrate-done', count: count }, '*');
      return;
    }

    // Édition déclenchée depuis la sidebar parent (clic sur un bouton "Modifier ce texte")
    // → simule un dblclick sur l'élément correspondant pour réutiliser le flow existant
    // (édition inline pour text simple, modal pour tinymce/image/video, etc.)
    // Cas size-only (hero_timer, reg_bar.value…) : le dblclick est ignoré par design
    // (rien à éditer en texte) → on émet une SÉLECTION à la place, ce qui ouvre le
    // slider de taille dans la sidebar — sinon le bouton de la liste était sans effet.
    if (e.data.type === 'parent-trigger-edit' && e.data.field) {
      var target = document.querySelector('[data-edit-field="' + e.data.field + '"]');
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (target.dataset.editKind === 'size-only') {
          __lastEditField = target;
          parent.postMessage(getEditPayload(target, 'editor-select-edit'), '*');
        } else {
          var evt = new MouseEvent('dblclick', { bubbles: true, cancelable: true, view: window });
          target.dispatchEvent(evt);
        }
      }
      return;
    }

    // Reset géométrie : retire les styles inline et le dataset.x/y/scale pour un champ.
    // DEVICE-AWARE : si data.device === 'mobile', on ne supprime QUE les attrs *-mobile
    // (la position desktop persiste). En desktop, on ne supprime QUE les attrs desktop.
    // Cas particulier : si le champ finit déjà par `_mobile` (élément HTML séparé pour
    // titleAccueil_mobile / subtitle_accueil_mobile), on supprime tous les attrs de cet
    // élément — il n'y a pas de double set sur lui.
    if (e.data.type === 'parent-reset-geometry') {
      var resetDevice = (e.data.device === 'mobile') ? 'mobile' : 'desktop';
      var fieldName   = e.data.field;
      var hasMobileSuffix = /_mobile$/.test(fieldName);
      // En mode mobile sur un champ desktop (badges, timer, etc.), on cible le DOM
      // de `field` (élément unique) mais on ne touche QU'aux attrs *-mobile.
      // En mode desktop sur un champ desktop, idem mais sur les attrs sans suffixe.
      // Sur un champ déjà *_mobile (élément HTML séparé), on traite comme reset complet.
      document.querySelectorAll('[data-edit-field="' + fieldName + '"]').forEach(function(el) {
        var resetMobileAttrs   = hasMobileSuffix || resetDevice === 'mobile';
        var resetDesktopAttrs  = hasMobileSuffix || resetDevice === 'desktop';
        // Les styles inline (transform/position/etc.) reflètent le device actuellement
        // appliqué par applyHeroPositionPct. On les nettoie dès qu'on touche ce device,
        // applyHeroPositionPct les réappliquera depuis les attrs restants (ou fallback CSS).
        if ((resetDevice === 'mobile' && window.innerWidth < 1040)
         || (resetDevice === 'desktop' && window.innerWidth >= 1040)
         || hasMobileSuffix) {
          el.style.transform = '';
          el.style.width = '';
          el.style.height = '';
          el.style.transformOrigin = '';
          el.style.top = '';
          el.style.left = '';
          el.style.right = '';
          el.style.bottom = '';
          el.style.marginLeft = '';
          el.style.marginTop = '';
          el.style.position = '';
          delete el.dataset.x;
          delete el.dataset.y;
          delete el.dataset.scale;
        }
        if (resetDesktopAttrs) {
          delete el.dataset.anchorX;
          delete el.dataset.anchorY;
          delete el.dataset.offsetX;
          delete el.dataset.offsetY;
          delete el.dataset.posTop;
          delete el.dataset.posLeft;
          // Mode d'ancrage desktop : sans ça, un mode 'centerX' persisté forçait
          // applyHeroPositionPct à recentrer la card même après reset → la card
          // se déplaçait au lieu de revenir à sa position CSS d'origine.
          delete el.dataset.axisMode;
          // Pour hero_timer et hero.cta_register : on les avait déplacés dans .demo-card
          // (via appendChild) pour casser le contexte du panel. Au reset, on les remet
          // dans leur parent d'origine pour qu'ils retrouvent leur position CSS naturelle.
          if (el.dataset.originalParent) {
            var orig = document.getElementById(el.dataset.originalParent);
            if (orig && el.parentNode !== orig) {
              orig.appendChild(el);
            }
            delete el.dataset.originalParent;
          }
        }
        if (resetMobileAttrs) {
          delete el.dataset.anchorXMobile;
          delete el.dataset.anchorYMobile;
          delete el.dataset.offsetXMobile;
          delete el.dataset.offsetYMobile;
          delete el.dataset.posTopMobile;
          delete el.dataset.posLeftMobile;
          delete el.dataset.axisModeMobile;
        }
      });
      // Réapplique la position depuis les attrs restants (selon viewport courant).
      if (typeof window.__applyHeroPositionPct === 'function') {
        window.__applyHeroPositionPct();
      }
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

    // Le parent demande un recalcul de la structure du layout (rects des lignes).
    // Utilisé quand une dimension change DANS l'iframe sans event resize natif
    // (ex : slider hauteur de la carte "Retrouver le départ") → les pointillés
    // de l'éditeur (overlays) suivent la nouvelle hauteur en direct.
    if (e.data.type === 'editor-request-layout') {
      sendLayoutStructure();
      return;
    }

    // Section "Retrouver le départ" : le parent (sidebar) a changé l'adresse ou les
    // coordonnées → on met à jour les data-attributs et on recharge la carte.
    if (e.data.type === 'parent-start-point-update') {
      var spSec = document.querySelector('.start-point-section');
      if (spSec) {
        spSec.dataset.spAddress = e.data.address || '';
        spSec.dataset.spCoords  = e.data.coords  || '';
      }
      __refreshStartPointMap();
      return;
    }

    // Mise à jour d'un style sans reload (taille, alignement).
    // Les clés `*_size_mobile` sont appliquées UNIQUEMENT si le viewport est mobile
    // (innerWidth < 1040). Dans tous les cas, on met aussi à jour `data-size-mobile`
    // pour que applyHeroPositionPct lise la bonne valeur sur resize.
    if (e.data.type === 'parent-update-style') {
      var key = e.data.key;
      var val = e.data.value;
      // Carte "Retrouver le départ" : dimensions appliquées via CSS variables sur
      // la section. height en px, width en %. Les clés *_mobile alimentent les
      // variables *-mobile lues par le @media (max-width:1040px).
      if (key.indexOf('start_point_map_') === 0) {
        var spSec2 = document.querySelector('.start-point-section');
        if (spSec2) {
          var spVarMap = {
            'start_point_map_height':        '--sp-map-h',
            'start_point_map_height_mobile': '--sp-map-h-mobile',
            'start_point_map_width':         '--sp-map-w',
            'start_point_map_width_mobile':  '--sp-map-w-mobile'
          };
          var spVar = spVarMap[key];
          if (spVar) {
            var spUnit = key.indexOf('width') !== -1 ? '%' : 'px';
            spSec2.style.setProperty(spVar, parseInt(val, 10) + spUnit);
          }
        }
        // La hauteur de la carte a changé → la ligne du layout change de taille :
        // on renvoie la structure au parent pour qu'il repositionne le contour
        // pointillé de la section (sinon il reste figé à l'ancienne hauteur).
        requestAnimationFrame(function() { sendLayoutStructure(); });
        return;
      }
      // Tailles des textes de la card inscriptions (reg_bar.*_size[_mobile]) :
      // appliquées via les CSS vars --rb-scale / --rb-scale-m (cf. accueil.css),
      // jamais via font-size inline (qui écraserait les baselines responsive).
      // L'élément est ciblé par data-edit-size (la clé de taille est partagée
      // entre variantes urgence/solidaire, indépendante de data-edit-field).
      // --rb-scale-m peut être posée même en vue desktop : elle n'a d'effet que
      // sous le @media mobile, donc pas besoin du report "shouldApplyVisually".
      if (/^reg_bar\..+_size(_mobile)?$/.test(key)) {
        var rbMobile = /_mobile$/.test(key);
        var rbBase = rbMobile ? key.replace(/_mobile$/, '') : key;
        var rbVal = parseInt(val, 10) || 100;
        document.querySelectorAll('[data-edit-size="' + rbBase + '"]').forEach(function(el) {
          if (rbMobile) {
            // Sync data-edit-size-current-mobile : sans ça le slider "oublierait"
            // la valeur à la prochaine sélection (même piège que pour le hero).
            el.dataset.editSizeCurrentMobile = String(rbVal);
            el.style.setProperty('--rb-scale-m', rbVal / 100);
          } else {
            el.dataset.editSizeCurrent = String(rbVal);
            el.style.setProperty('--rb-scale', rbVal / 100);
          }
        });
        return;
      }
      var isMobileKey  = /_size_mobile$/.test(key);
      var isMobileView = window.innerWidth < 1040;
      // Pour les clés mobile, on stocke aussi la valeur dans data-size-mobile (lue par
      // applyHeroPositionPct au resize). Si on n'est pas en mobile view, on évite
      // d'écraser le style desktop courant — l'effet sera visible à la prochaine bascule.
      var baseKey = isMobileKey ? key.replace(/_mobile$/, '') : key;
      var shouldApplyVisually = !isMobileKey || isMobileView;
      // CRITIQUE : on met à jour `data-edit-size-current` (desktop) ou
      // `data-edit-size-current-mobile` (mobile) pour que la sidebar affiche la BONNE
      // valeur à la prochaine sélection/toggle device. Sans ça, le slider montrait
      // "100%" en revenant sur PC alors qu'on avait sauvé 130% : la valeur "live"
      // restait coincée dans la BDD/PHP-rendered attr d'origine.
      var fieldBase = null;
      if (baseKey === 'hero_timer_size')         fieldBase = 'hero_timer';
      else if (baseKey === 'badge_fee_size')     fieldBase = 'badge_fee';
      else if (baseKey === 'badge_km_size')      fieldBase = 'badge_km';
      else if (baseKey === 'titleAccueil_size')  fieldBase = 'titleAccueil';
      else if (baseKey === 'subtitle_accueil_size') fieldBase = 'subtitle_accueil';
      if (fieldBase) {
        // Cible TOUS les éléments portant ce field, qu'il soit suffixé ou non par
        // __syncFieldNamesToDevice (data-edit-field='hero_timer' OU 'hero_timer_mobile').
        var matchSelector = '[data-edit-field="' + fieldBase + '"], [data-edit-field="' + fieldBase + '_mobile"]';
        document.querySelectorAll(matchSelector).forEach(function(el){
          if (isMobileKey) {
            el.dataset.editSizeCurrentMobile = String(parseInt(val,10));
            el.dataset.sizeMobile             = String(parseInt(val,10));
          } else {
            el.dataset.editSizeCurrent = String(parseInt(val,10));
          }
        });
      }
      if (!shouldApplyVisually) {
        return; // valeur stockée, application différée à la bascule de viewport
      }
      if (baseKey === 'titleAccueil_size') {
        document.querySelectorAll('.demo-kicker').forEach(function(el) {
          el.style.fontSize = (parseInt(val,10)/100) + 'em';
        });
      } else if (baseKey === 'subtitle_accueil_size') {
        document.querySelectorAll('.demo-desc').forEach(function(el) {
          el.style.fontSize = (parseInt(val,10)/100) + 'em';
        });
      } else if (baseKey === 'hero_timer_size') {
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
      } else if (baseKey === 'badge_fee_size' || baseKey === 'badge_km_size') {
        // Scale d'un badge → tout le texte interne suit automatiquement.
        var badgeField = (baseKey === 'badge_fee_size') ? 'badge_fee' : 'badge_km';
        var b = document.querySelector('[data-edit-field="' + badgeField + '"]');
        if (b) {
          var bs = parseInt(val,10)/100;
          b.dataset.scale = bs;
          var bx = parseFloat(b.dataset.x) || 0;
          var by = parseFloat(b.dataset.y) || 0;
          var bt = 'translate(' + bx + 'px, ' + by + 'px)';
          if (bs !== 1) bt += ' scale(' + bs + ')';
          b.style.transform = bt;
          b.style.transformOrigin = 'left top';
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
        el.style.outline = '3px solid var(--primary, #f42182)';
        el.style.outlineOffset = '4px';
      }
    }
  });
})();
</script>
<?php endif; ?>

</body>
</html>
