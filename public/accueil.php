<?php
require '../config/config.php';
require_once '../config/tracker.php';
trackPageVisit();

// Récupération du nombre d'inscrits
$stmtcount = $pdo->prepare('SELECT COUNT(*) AS total FROM registrations');
$stmtcount->execute();
$count = $stmtcount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

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
        $stmtSearch = $pdo->prepare(
            'SELECT COUNT(*) AS total FROM registrations WHERE LOWER(email) = LOWER(:email)'
        );
        $stmtSearch->execute(['email' => $searchEmail]);
        $matchCount = (int)($stmtSearch->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        if ($matchCount > 0) {
            $searchStatus = 'success';
            $searchMessage = "Merci ! Votre inscription est bien enregistrée. Hâte de vous voir le jour J 😊";
        } else {
            $searchStatus = 'danger';
            $searchMessage = "On ne retrouve pas d'inscription avec cet email 😔. Vérifiez l'adresse ou inscrivez‑vous en 1 minute 😁";
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
$stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
$stmt->execute(['id' => 1]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$titleAccueil  = $data['titleAccueil'] ?? '';
$picture = $data['picture'] ?? '';  
$titleColor = $data['title_color'] ?? '#ffffff';
$edition = $data['edition'] ?? '';  
$link_instagram = $data['link_instagram'] ?? null;
$link_facebook = $data['link_facebook'] ?? null; 
$link_twitter = $data['link_twitter'] ?? null;
$link_youtube = $data['link_youtube'] ?? null;
$date_course = $data['date_course'] ?? null;
$date_formatted = $date_course ? date('Y-m-d\TH:i:s', strtotime($date_course)) : '2026-07-05T09:00:00';
$picture_partner = $data['picture_partner'] ?? ''; 
$picture_accueil = $data['picture_accueil'] ?? ''; 

// Récupération des années photos pour le menu
try {
    $stmtPhotos = $pdo->prepare('SELECT id, year, title FROM photo_years ORDER BY year DESC LIMIT 10');
    $stmtPhotos->execute();
    $galeries = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $galeries = [];
}

// Récupération des actualités pour le menu
try {
    $stmtActus = $pdo->prepare('SELECT id, title_article as title, img_article, date_publication FROM news ORDER BY date_publication DESC LIMIT 10');
    $stmtActus->execute();
    $actualites = $stmtActus->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $actualites = [];
}

// Récupération des années partenaires pour le menu
try {
    $stmtPartners = $pdo->prepare('SELECT id, year, title FROM partners_years ORDER BY year DESC LIMIT 10');
    $stmtPartners->execute();
    $partenaires = $stmtPartners->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $partenaires = [];
}

$actualites_cols2 = count($actualites) > 5;
$galeries_cols2 = count($galeries) > 5;
$partenaires_cols2 = count($partenaires) > 5;

$link_cancer = $data['link_cancer'] ?? null;

// Timeline preview mode
$isTimelinePreview = isset($_GET['preview_timeline']) && $_GET['preview_timeline'] == '1';
if ($isTimelinePreview) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
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

  <style>
    :root{
      /* Global / page */
      --page-bg: #ffffff;
      --page-text: #0f172a;          /* slate-900 */
      --page-muted: rgba(15,23,42,.65);

      /* Floating pill (desktop + mobile top bar) */
      --pill-bg: #ffffff;
      --pill-border: rgba(15,23,42,.10);
      --pill-shadow: 0 14px 40px rgba(2,6,23,.12);

      /* Desktop mega menu */
      --mega-bg: #ffffff;
      --mega-border: rgba(15,23,42,.10);
      --mega-shadow: 0 18px 60px rgba(2,6,23,.16);

      /* Mobile drawer panel (WHITE) */
      --drawer-bg: #ffffff;
      --drawer-border: rgba(15,23,42,.12);
      --drawer-shadow: 0 30px 90px rgba(2,6,23,.18);

      /* Hovers */
      --hover: rgba(2,6,23,.06);

      /* Accents */
      --accent: #6d28d9; /* violet */
      --pink: #ec4899;
      --pink-dark: #db2777;
      --mobile-header-bg: rgba(255, 255, 255, 0.82);

      /* Radius */
      --radius-pill: 14px;
      --radius-btn: 14px;
      --radius-panel: 18px;

      /* widths */
      --nav-max: clamp(820px, 74vw, 1500px);
      --side-pad: clamp(16px, 2.2vw, 28px);
      --content-width: min(var(--nav-max), calc(100% - (var(--side-pad) * 2)));
      --nav-space: 0px;
    }

    *{ box-sizing: border-box; }
    body{
      margin:0;
      color: var(--page-text);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: var(--page-bg);
      padding-top: 0;
      transition: none;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    main{
      flex: 1;
    }
    body.nav-scrolled{
      padding-top: var(--nav-space);
    }

    /* ===== Floating Navbar wrapper ===== */
    .floating-nav{
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 9999;
      width: 100%;
      display:flex;
      justify-content:center;
      background: #ffffff;
      border-bottom: 0;
      box-shadow: none;
      pointer-events: auto;
      transition: none;
    }

    .nav-pill{
      pointer-events: auto;
      position: relative;
      z-index: 10000;
      display:flex;
      align-items:center;
      gap:10px;
      padding: 14px 0;
      border-radius: 0;
      background: #ffffff;
      border: 0;
      box-shadow: none;
      backdrop-filter: none;
      -webkit-backdrop-filter: none;
      width: var(--content-width);
      max-width: none;
      transition: none;
    }

    @media (min-width: 981px){
      body.nav-scrolled .floating-nav{
        position: fixed;
        top: 18px;
        left: 0;
        right: 0;
        background: transparent;
        border-bottom: 0;
        box-shadow: none;
        pointer-events: none;
      }
      body.nav-scrolled .nav-pill{
        pointer-events: auto;
        padding: 2px 12px;
        border-radius: var(--radius-pill);
        background: var(--pill-bg);
        border: 1px solid var(--pill-border);
        box-shadow: var(--pill-shadow);
      }
    }

    .brand{
      display:flex;
      align-items:center;
      gap: 8px;
      padding: 0 8px;
      text-decoration:none;
      color: var(--page-text);
      user-select: none;
      white-space: nowrap;
    }
    .brand-logo{
      height: 68px;
      width: auto;
      display: block;
      object-fit: contain;
    }
    .brand .logo{
      width: 30px;
      height: 30px;
      border-radius: 12px;
      display:grid;
      place-items:center;
      background: rgba(2,6,23,.03);
      border: none;
    }
    .brand strong{ font-size: 24px; font-weight: 700; letter-spacing: .02em; }

    .brand-title{
      color: var(--pink);
      font-weight: 900;
      letter-spacing: -0.02em;
    }

    .sr-only{ position:absolute; left:-9999px; }

    .links{
      display:flex;
      align-items:center;
      justify-content:center;
      gap: 14px;
      width: auto;
    }

    .nav-right{
      display:flex;
      align-items:center;
      gap: 12px;
      margin-left: auto;
    }
    .nav-card{
      display:flex;
      align-items:center;
      background: rgba(15,23,42,.04);
      border: none;
      border-radius: 12px;
      padding: 6px 10px;
    }
    body.nav-scrolled .nav-card{
      background: transparent;
      padding: 0;
    }

    .menu{
      list-style:none;
      display:flex;
      align-items:center;
      gap: 8px;
      margin:0;
      padding:0;
    }

    .menu.nav-secondary{
      gap: 6px;
    }
    .menu.nav-secondary .link,
    .menu.nav-secondary .trigger{
      font: 600 14px/1 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      letter-spacing: .01em;
      padding: 8px 12px;
    }
    .nav-icon{
      width: 28px;
      height: 28px;
      border-radius: 8px;
      display: grid;
      place-items: center;
      background: rgba(236,72,153,.12);
      color: var(--pink);
      flex: 0 0 auto;
    }
    .nav-icon svg{
      width: 16px;
      height: 16px;
      stroke: currentColor;
      stroke-width: 2;
      fill: none;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .nav-label{ white-space: nowrap; }

    .item{ position: static; }

    .link, .trigger{
      appearance:none;
      border:0;
      background:transparent;
      color: var(--page-text);
      font: 500 16px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      padding: 10px 12px;
      border-radius: var(--radius-btn);
      cursor:pointer;
      display:flex;
      align-items:center;
      gap: 7px;
      text-decoration:none;
      user-select: none;
      outline: none;
      transition: background .16s ease;
    }
    .link:hover, .trigger:hover{ background: var(--hover); }

    .chev{
      width: 14px;
      height: 14px;
      opacity: .65;
      transition: transform .18s ease;
    }
    .item[data-open="true"] .chev{ transform: rotate(180deg); }

    .cta{
      display:flex;
      align-items:center;
      gap: 8px;
      white-space: nowrap;
    }
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding: 10px 14px;
      border-radius: 999px;
      font: 600 16px/1 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      text-decoration:none;
      border:1px solid rgba(15,23,42,.12);
      color: var(--page-text);
      background: rgba(255,255,255,.65);
      transition: transform .08s ease, background .18s ease, box-shadow .18s ease;
    }
    .btn:hover{
      background: rgba(255,255,255,.92);
      box-shadow: 0 10px 26px rgba(2,6,23,.10);
    }
    .btn.primary{
      background: #0f172a;
      color:#fff;
      border-color:#0f172a;
    }
    .btn.primary:hover{ background:#0b1220; }
    .btn.pink{
      padding: 12px 24px;
      background: var(--pink);
      color: #ffffff;
      border: none;
      border-radius: 12px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all .2s ease;
      white-space: nowrap;
      box-shadow: 0 4px 14px rgba(236,72,153,.25);
    }
    .btn.pink:hover{
      background: var(--pink-dark);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(236,72,153,.35);
      gap: 12px;
    }
    .nav-cta{
      min-height: 56px;
      padding: 0 18px;
      border-radius: 12px;
      font-weight: 800;
      letter-spacing: .02em;
    }
    .nav-cta svg{
      width: 20px;
      height: 20px;
    }

    /* ===== Desktop mega menu (inset) ===== */
    /* ===== Overlay - floute tout l'écran (navbar au-dessus) ===== */
    .mega-overlay{
      position: fixed;
      left: 0;
      right: 0;
      top: 70px; /* Navbar collée en haut */
      bottom: 0;
      z-index: 9997;
      opacity: 0;
      pointer-events: none;
      transition: opacity .3s ease;

      /* Backdrop filter */
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      background: rgba(255, 255, 255, 0.4);
    }

    /* En mode flottant (scrolled), on floute aussi le dessus + les côtés */
    body.nav-scrolled .mega-overlay{
      top: 0;
    }

    .mega-overlay.active{
      opacity: 1;
      pointer-events: auto;
    }

    /* ===== Mega menu - s'ouvre juste sous la navbar ===== */
    .mega{
      position: fixed;
      left: var(--mega-left, 50%);
      top: 82px; /* Juste sous la navbar */
      background: #ffffff;
      border: 1px solid rgba(15,23,42,.10);
      border-radius: 24px;
      box-shadow: 0 24px 80px rgba(2,6,23,.12), 0 8px 32px rgba(2,6,23,.08);
      padding: 40px;
      z-index: 9998;
      opacity: 0;
      visibility: hidden;
      transform: translateX(-50%) translateY(-12px);
      transition: opacity .25s ease, transform .25s ease, visibility 0s .25s;
      width: var(--mega-width, 900px);
      max-width: calc(100vw - 40px);
    }

    .mega.mega--wide{
      --mega-width: 1040px;
    }

    /* En mode scrolled, ajuster la position */
    body.nav-scrolled .mega{
      top: 106px; /* 18px + 76px navbar + 12px gap */
    }

    .item[data-open="true"] > .mega{
      opacity: 1;
      visibility: visible;
      transform: translateX(-50%) translateY(0);
      transition: opacity .25s ease, transform .25s ease, visibility 0s;
    }

    /* PAS de flèche */
    .mega::before{
      display: none;
    }

    /* Grid 2 colonnes : contenu + image */
    .mega-grid{
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 48px;
      align-items: start;
    }

    /* Colonne gauche */
    .mega-content{
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .mega-section{
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .mega-title{
      font-size: 11px;
      letter-spacing: .14em;
      color: rgba(15,23,42,.5);
      text-transform: uppercase;
      margin: 0 0 12px;
      font-weight: 700;
    }

    .mega-list{
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .mega-list.mega-list--2col{
      display: block;
      columns: 2;
      column-gap: 16px;
    }
    .mega-list.mega-list--2col li{
      break-inside: avoid;
      -webkit-column-break-inside: avoid;
      margin-bottom: 4px;
    }
    .mega-list.mega-list--2col .mega-link{
      width: 100%;
    }

    .mega-link{
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 12px;
      border-radius: 12px;
      text-decoration: none;
      color: var(--page-text);
      transition: background .15s ease;
    }

    .mega-link:hover{
      background: rgba(236,72,153,.06);
    }

    .micon{
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: grid;
      place-items: center;
      background: rgba(236,72,153,.08);
      flex: 0 0 auto;
      font-size: 20px;
    }

    .mega-link:hover .micon{
      background: rgba(236,72,153,.14);
    }

    .mega-link-content{
      flex: 1;
      min-width: 0;
    }

    .mtitle{
      font-weight: 600;
      font-size: 14px;
      margin: 0 0 2px;
      color: #0f172a;
      line-height: 1.3;
    }

    .mdesc{
      margin: 0;
      font-size: 12px;
      color: rgba(15,23,42,.55);
      line-height: 1.35;
    }

    /* Colonne droite : image */
    .mega-featured{
      background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%);
      border-radius: 20px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      height: 100%;
      min-height: 320px;
    }

    .mega-featured-img{
      width: 100%;
      height: 200px;
      border-radius: 16px;
      overflow: hidden;
      background: rgba(255,255,255,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 64px;
    }

    .mega-featured-title{
      font-size: 18px;
      font-weight: 700;
      color: #831843;
      margin: 0;
      line-height: 1.3;
    }

    .mega-featured-desc{
      font-size: 14px;
      color: rgba(15,23,42,.7);
      line-height: 1.5;
      margin: 0;
    }

    .mega-featured-link{
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--pink);
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      margin-top: auto;
      transition: gap .2s ease;
    }

    .mega-featured-link:hover{
      gap: 10px;
    }

    /* Responsive */
    @media (max-width: 1100px){
      .mega{
        --mega-width: 720px;
      }
      .mega.mega--wide{
        --mega-width: 860px;
      }
      .mega-grid{
        grid-template-columns: 1fr 280px;
        gap: 32px;
      }
    }

    @media (max-width: 980px){
      .mega{
        display: none !important;
      }
      .mega-overlay{
        display: none !important;
      }
    }

    /* ===== Mobile top bar (burger) - HIDDEN NOW ===== */
    .burger{
      display:none !important;
    }

    /* ===== MOBILE HEADER (Vimeo style) ===== */
    .mobile-header{
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 9998;
      background: var(--mobile-header-bg);
      backdrop-filter: blur(10px) saturate(120%);
      -webkit-backdrop-filter: blur(10px) saturate(120%);
      padding: 12px 16px;
      align-items: center;
      justify-content: space-between;
      border-bottom: 0;
      transition: transform .45s cubic-bezier(0.4, 0, 0.2, 1), opacity .45s ease;
    }
    .mobile-header.hidden{
      transform: translateY(-100%);
      opacity: 0;
      pointer-events: none;
    }
    .mobile-header .brand-logo{
      height: 48px;
      width: auto;
    }
    /* ===== MOBILE BOTTOM BAR — Vimeo unified menu ===== */
    .mobile-bottom-bar{
      display: none;
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 9999;
      padding: 0 10px 10px;
      pointer-events: none;
    }

    /* ---- Outer wrapper: holds nav-row + CTA side by side (closed) ---- */
    .mobile-bottom-wrapper{
      display: flex;
      align-items: stretch;
      gap: 8px;
      pointer-events: auto;
      transition: gap .35s cubic-bezier(.4,0,.2,1);
    }

    /* When menu open: see below */

    /* ---- The main nav block (contains menu panel + action buttons) ---- */
    .mobile-bottom-unified{
      display: flex;
      flex-direction: column;
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 8px 18px rgba(0,0,0,.18);
      overflow: hidden;
      flex: 1;
      min-width: 0;
      transition: border-radius .35s cubic-bezier(.4,0,.2,1),
                  box-shadow .35s cubic-bezier(.4,0,.2,1);
    }

    /* ---- CTA button (Inscription) - separate block when closed ---- */
    .mobile-bottom-cta{
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--pink);
      color: #ffffff;
      border: none;
      border-radius: 16px;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      white-space: nowrap;
      pointer-events: auto;
      box-shadow: 0 8px 18px rgba(0,0,0,.16);
      padding: 0 20px;
      overflow: hidden;
      max-width: 140px;
      opacity: 1;
      transition: max-width .35s cubic-bezier(.4,0,.2,1),
                  opacity .2s ease,
                  padding .35s cubic-bezier(.4,0,.2,1),
                  box-shadow .3s ease,
                  border-radius .3s ease;
    }
    .mobile-bottom-cta:hover{
      background: var(--pink-dark);
    }

    /* ---- When menu is open or closing ---- */
    .mobile-bottom-bar.menu-open .mobile-bottom-wrapper,
    .mobile-bottom-bar.menu-closing .mobile-bottom-wrapper{
      gap: 0;
    }

    .mobile-bottom-bar.menu-open .mobile-bottom-unified,
    .mobile-bottom-bar.menu-closing .mobile-bottom-unified{
      border-radius: 16px;
      box-shadow: 0 20px 80px rgba(0,0,0,.28);
      flex: 1;
    }

    .mobile-bottom-bar.menu-open .mobile-bottom-actions,
    .mobile-bottom-bar.menu-closing .mobile-bottom-actions{
      border-top: 1px solid rgba(15,23,42,.08);
    }

    /* ---- Menu panel (hidden by default, slides in when open) ---- */
    /* ---- Menu panel: slide-based navigation (Vimeo style) ---- */
    .mobile-menu-panel{
      max-height: 0;
      opacity: 0;
      pointer-events: none;
      overflow: hidden;
      transition: max-height .45s cubic-bezier(.4,0,.2,1),
                  opacity .3s ease .05s;
      display: flex;
      flex-direction: column;
    }

    .mobile-bottom-bar.menu-open .mobile-menu-panel{
      max-height: 55vh;
      opacity: 1;
      pointer-events: auto;
    }

    /* ---- Header ---- */
    .mobile-menu-header{
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px 20px 10px;
      flex-shrink: 0;
      position: relative;
      min-height: 48px;
    }
    .mobile-menu-title{
      color: #0f172a;
      font-size: 15px;
      font-weight: 700;
      letter-spacing: -.01em;
      text-align: center;
    }
    .mobile-menu-back{
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(15,23,42,.06);
      border: none;
      color: rgba(15,23,42,.6);
      font-size: 16px;
      cursor: pointer;
      display: none;
      align-items: center;
      justify-content: center;
      transition: all .2s ease;
    }
    .mobile-menu-back:hover{
      background: rgba(15,23,42,.12);
      color: #0f172a;
    }
    .mobile-menu-back.visible{
      display: flex;
    }
    .mobile-menu-close{
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(15,23,42,.06);
      border: none;
      color: rgba(15,23,42,.55);
      font-size: 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .2s ease;
    }
    .mobile-menu-close:hover{
      background: rgba(15,23,42,.12);
      color: #0f172a;
    }

    /* ---- Slide container (holds main view + sub views) ---- */
    .mobile-menu-slides{
      flex: 1;
      overflow: hidden;
      position: relative;
    }

    .mobile-menu-slide{
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      transition: transform .3s cubic-bezier(.4,0,.2,1), opacity .25s ease;
    }

    /* Main view */
    .mobile-menu-slide-main{
      position: relative;
      transform: translateX(0);
      opacity: 1;
    }
    .mobile-menu-slide-main.pushed{
      transform: translateX(-30%);
      opacity: 0;
      pointer-events: none;
    }

    /* Sub view */
    .mobile-menu-slide-sub{
      transform: translateX(100%);
      opacity: 0;
      pointer-events: none;
    }
    .mobile-menu-slide-sub.active{
      transform: translateX(0);
      opacity: 1;
      pointer-events: auto;
    }
    .mobile-menu-slide-sub .mobile-menu-body{
      flex: 1;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }

    /* ---- Menu body (inside each slide) ---- */
    .mobile-menu-body{
      padding: 4px 12px 8px;
      padding-bottom: 70px;
    }
    .mobile-menu-nav{
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .mobile-menu-item{
      border-radius: 12px;
    }
    .mobile-menu-trigger{
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      padding: 14px 12px;
      background: transparent;
      border: none;
      color: #0f172a;
      font-size: 15px;
      font-weight: 500;
      cursor: pointer;
      text-align: left;
      text-decoration: none;
      border-radius: 12px;
      transition: background .15s ease;
    }
    @media (hover: hover) and (pointer: fine){
      .mobile-menu-trigger:hover{
        background: rgba(15,23,42,.05);
      }
    }
    .mobile-menu-trigger svg{
      width: 18px;
      height: 18px;
      opacity: .4;
      flex-shrink: 0;
      color: #0f172a;
    }
    .mobile-menu-icon{
      width: 32px;
      height: 32px;
      background: rgba(236,72,153,.08);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 12px;
      font-size: 15px;
      flex-shrink: 0;
      color: #0f172a;
    }

    .mobile-menu-icon svg{
      width: 18px;
      height: 18px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .mobile-menu-trigger-content{
      display: flex;
      align-items: center;
      flex: 1;
    }

    /* Sub-view links */
    .mobile-menu-sublink{
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 12px;
      color: rgba(15,23,42,.7);
      text-decoration: none;
      font-size: 15px;
      border-radius: 12px;
      transition: all .15s ease;
    }
    .mobile-menu-sublink:hover{
      background: rgba(15,23,42,.05);
      color: #0f172a;
    }

    .mobile-menu-sublink .menu-bullet{
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: var(--pink);
      display: inline-flex;
      flex: 0 0 auto;
      box-shadow: 0 2px 5px rgba(236,72,153,.2);
    }

    /* "See all" link — always at bottom of sub-view */
    .mobile-menu-see-all{
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin: auto 12px 12px;
      padding: 14px 20px;
      background: rgba(15,23,42,.04);
      border-radius: 14px;
      color: #0f172a;
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      transition: background .2s ease;
      flex-shrink: 0;
    }
    .mobile-menu-see-all:hover{
      background: rgba(15,23,42,.08);
    }
    .mobile-menu-see-all svg{
      width: 16px;
      height: 16px;
      opacity: .5;
    }

    /* Footer */
    .mobile-menu-footer{
      padding: 10px 12px;
      border-top: 1px solid rgba(15,23,42,.06);
      display: flex;
      gap: 6px;
      flex-shrink: 0;
    }
    .mobile-menu-footer-btn{
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 5px;
      padding: 10px 8px;
      background: rgba(15,23,42,.04);
      border: none;
      border-radius: 12px;
      color: rgba(15,23,42,.55);
      font-size: 10px;
      text-decoration: none;
      cursor: pointer;
      transition: all .2s ease;
    }
    .mobile-menu-footer-btn:hover{
      background: rgba(15,23,42,.08);
      color: #0f172a;
    }
    .mobile-menu-footer-btn svg{
      width: 20px;
      height: 20px;
    }

    /* Simple link (Tarification) */
    .mobile-menu-simple-link{
      display: flex;
      align-items: center;
      padding: 14px 12px;
      color: #0f172a;
      text-decoration: none;
      font-size: 15px;
      font-weight: 500;
      border-radius: 12px;
      transition: background .15s ease;
    }
    .mobile-menu-simple-link:hover{
      background: rgba(15,23,42,.05);
    }
    .mobile-menu-simple-link .mobile-menu-icon{
      margin-right: 12px;
    }
    .mobile-menu-simple-link svg{
      width: 18px;
      height: 18px;
      opacity: .4;
      margin-left: auto;
    }

    /* ---- Bottom action buttons row ---- */
    .mobile-bottom-actions{
      display: flex;
      align-items: center;
      padding: 5px 4px;
      gap: 0;
      min-height: 58px;
      flex-shrink: 0;
    }
    .mobile-bottom-bar.menu-open .mobile-bottom-actions{
      border-top: 1px solid rgba(15,23,42,.08);
      flex: 1;
    }
    .mobile-bottom-btn{
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 3px;
      padding: 8px 14px;
      background: transparent;
      border: none;
      color: #0f172a;
      font-size: 9px;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      border-radius: 12px;
      transition: all .2s ease;
      flex: 1;
      min-width: 0;
    }

    #mobileMenuBtn{
      background: rgba(15,23,42,.06);
      border-radius: 12px;
      margin: 2px 0 2px 2px;
    }
    .mobile-bottom-btn:hover,
    .mobile-bottom-btn:active{
      background: rgba(15,23,42,.06);
    }
    .mobile-bottom-btn svg{
      width: 21px;
      height: 21px;
      opacity: .85;
    }
    .mobile-bottom-btn span{
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* ---- Inner CTA (inside actions bar, shown only when open) ---- */
    .mobile-bottom-cta-inner{
      display: flex;
      align-items: center;
      justify-content: center;
      background: transparent;
      color: #0f172a;
      border: none;
      border-left: 1px solid rgba(15,23,42,.08);
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      white-space: nowrap;
      overflow: hidden;
      max-width: 0;
      padding: 0;
      opacity: 0;
      pointer-events: none;
      transition: max-width .35s cubic-bezier(.4,0,.2,1),
                  opacity .25s ease,
                  padding .35s cubic-bezier(.4,0,.2,1);
    }
    .mobile-bottom-cta-inner:hover{
      background: rgba(15,23,42,.04);
    }
    .mobile-bottom-bar.menu-open .mobile-bottom-cta-inner,
    .mobile-bottom-bar.menu-closing .mobile-bottom-cta-inner{
      max-width: 140px;
      padding: 18px 20px;
      opacity: 1;
      pointer-events: auto;
    }
    /* Collapse outer CTA when menu is open OR closing */
    .mobile-bottom-bar.menu-open .mobile-bottom-cta,
    .mobile-bottom-bar.menu-closing .mobile-bottom-cta{
      max-width: 0;
      padding: 0;
      opacity: 0;
      box-shadow: none;
      pointer-events: none;
      overflow: hidden;
    }

    /* Menu button icon toggle (hamburger to X) */
    .mobile-bottom-btn .menu-icon-close{ display: none; }
    .mobile-bottom-bar.menu-open #mobileMenuBtn .menu-icon-open,
    .mobile-bottom-bar.menu-closing #mobileMenuBtn .menu-icon-open{ display: none; }
    .mobile-bottom-bar.menu-open #mobileMenuBtn .menu-icon-close,
    .mobile-bottom-bar.menu-closing #mobileMenuBtn .menu-icon-close{ display: block; }

    /* ===== Backdrop ===== */
    .mobile-menu-backdrop{
      position: fixed;
      inset: 0;
      z-index: 9998;
      background: rgba(0, 0, 0, 0.2);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      opacity: 0;
      pointer-events: none;
      transition: opacity .3s ease;
    }
    .mobile-menu-backdrop.open{
      opacity: 1;
      pointer-events: auto;
    }

    /* ===== Show mobile elements only on mobile ===== */
    @media (max-width: 980px){
      .mobile-header{
        display: flex;
      }
      .mobile-bottom-bar{
        display: block;
        left: 50%;
        right: auto;
        transform: translateX(-50%);
        width: var(--content-width);
        padding: 0 0 10px;
      }
      /* Adjust body padding for mobile header */
      body{
        padding-top: 72px;
        padding-bottom: 90px;
      }
      /* Hide desktop nav */
      .floating-nav{
        display: none !important;
      }
      /* Hide old popup elements if any */
      .mobile-menu-popup{
        display: none !important;
      }
    }

    /* Legacy mobile drawer - keep for compatibility but hidden */
    .drawer-backdrop{
      display: none !important;
    }

    /* Legacy styles kept for reference but not used */
    .mnav-section, .mnav-head, .mnav-chevron, .mnav-sub, .mnav-label,
    .mnav-item, .mnav-ico, .mnav-txt, .mnav-title, .mnav-desc, .mnav-link{
      display: none;
    }

    /* ===== Main content ===== */
    main{
      width: var(--content-width);
      margin: 0 auto;
      padding: 20px 0 140px; /* side padding handled by content width */
      text-align:center;
    }
    h1{
      margin: 28px 0 18px;
      font-size: clamp(32px, 5vw, 58px);
      letter-spacing: -0.03em;
      line-height: 1.05;
    }
    p.sub{
      max-width: 960px;
      margin: 0 auto 22px;
      color: var(--page-muted);
      line-height: 1.6;
    }
    /* ===== REGISTRATION CARD - SPLIT DIAGONAL ===== */
    .reg-bar {
      margin: 120px auto 36px;
    }

    .reg-card {
      display: grid;
      grid-template-columns: 1fr 1fr;
      min-height: 140px;
      border-radius: 24px;
      overflow: hidden;
      position: relative;
      background: linear-gradient(135deg, #fdf2f8, #fce7f3);
    }

    .reg-count {
      background: var(--page-text);
      padding: 24px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      position: relative;
      clip-path: polygon(0 0, 100% 0, 85% 100%, 0 100%);
      margin-right: -40px;
      z-index: 2;
    }

    .reg-count .reg-kicker {
      font-size: 11px;
      color: rgba(255,255,255,.5);
      text-transform: uppercase;
      letter-spacing: .12em;
      margin-bottom: 8px;
      font-weight: 600;
    }

    .reg-count .reg-value {
      font-size: 56px;
      font-weight: 900;
      color: #fff;
      line-height: 1;
      letter-spacing: -.03em;
    }

    .reg-count .reg-note {
      display: none; /* Caché dans cette version */
    }

    .reg-search {
      background: transparent;
      padding: 24px 24px 24px 48px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .reg-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--page-text);
      margin: 0 0 10px;
    }

    .reg-form {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .reg-input {
      background: #fff;
      border: none;
      padding: 14px 18px;
      border-radius: 12px;
      font-size: 14px;
      flex: 1 1 auto;
      outline: none;
      box-shadow: 0 2px 8px rgba(0,0,0,.04);
      transition: box-shadow .2s;
    }

    .reg-input::placeholder {
      color: #9ca3af;
    }

    .reg-input:focus {
      box-shadow: 0 0 0 3px rgba(236,72,153,.15);
    }
    .reg-submit {
      background: var(--pink);
      border: none;
      color: #fff;
      padding: 14px 24px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      transition: background .2s;
    }

    .reg-submit:hover {
      background: var(--pink-dark);
    }

    .reg-hint {
      margin: 12px 0 0;
      color: #6b7280;
      font-size: 12px;
    }

    .reg-result {
      margin: 12px 0 0;
      font-size: 13px;
      padding: 12px 16px;
      border-radius: 10px;
      display: inline-block;
      font-weight: 500;
    }

    .reg-result.success { 
      background: #ede9fe; 
      color: #5b21b6;
      border: 1px solid rgba(124,58,237,.25);
    }
    .reg-result.warn { 
      background: #e0f2fe; 
      color: #0c4a6e;
      border: 1px solid rgba(14,116,144,.25);
    }
    .reg-result.danger { 
      background: #fce7f3; 
      color: #9d174d;
      border: 1px solid rgba(236,72,153,.35);
    }

    /* Responsive */
    @media (max-width: 700px) {
      .reg-card { 
        grid-template-columns: 1fr; 
      }
      
      .reg-count {
        clip-path: none;
        margin-right: 0;
        padding: 24px;
        flex-direction: row;
        align-items: center;
        gap: 16px;
      }
      
      .reg-count .reg-kicker { 
        margin-bottom: 0; 
      }
      
      .reg-count .reg-value { 
        font-size: 40px; 
      }
      
      .reg-search { 
        padding: 24px; 
      }
      .reg-title{
        text-align: center;
      }
      
      .reg-form {
        flex-direction: row;
        flex-wrap: nowrap;
        width: 100%;
      }

      .reg-input{
        width: 100%;
        min-width: 0;
      }
      .reg-submit{
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 46px;
        padding: 0 14px;
        font-size: 13px;
      }
    }

    /* ===== VIDEO CARD + COUNTDOWN ===== */
    .demo-wrap{
      margin-left: 100px;
      width: 100%;
      margin: 75px auto 0;
      position: relative;
    }

    /* Extra gap under fixed navbar (not floating) */
    @media (min-width: 981px){
      body:not(.nav-scrolled) .demo-wrap{
        margin-top: 95px;
      }
    }

    .demo-card{
      position: relative;
      width: 100%;
      height: clamp(680px, 70vh, 1040px);
      border-radius: 16px;
      overflow: hidden;
      border: none;
      box-shadow: 0 18px 60px rgba(2,6,23,.14);
      background: #000;
    }

    /* 16" laptops: reduce video height a bit */
    @media (max-width: 1440px) and (min-width: 981px){
      .demo-card{
        height: clamp(560px, 60vh, 900px);
      }
    }

    .demo-video{
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
      object-fit:cover;
      transform: scale(1.02);
      filter: saturate(1.05) contrast(1.02);
    }

    /* Social card on video (right side) */
    .video-social-card{
      position:absolute;
      top: 50%;
      right: 24px;
      transform: translateY(-50%);
      background: #ffffff;
      border-radius: 16px;
      padding: 8px;
      display:flex;
      flex-direction:column;
      gap: 8px;
      box-shadow: 0 14px 34px rgba(2,6,23,.18);
      border: 1px solid rgba(15,23,42,.10);
      z-index: 4;
    }

    .mobile-socials{
      display:none;
      align-items:center;
      gap: 10px;
    }

    .mobile-cta{
      display:none;
      justify-content:center;
      margin: 12px 0 0;
    }

    .social-btn{
      display:flex;
      align-items:center;
      justify-content:center;
      gap: 8px;
      height: auto;
      min-width: 0;
      padding: 6px 8px;
      border-radius: 12px;
      background: #ffffff;
      border: 1px solid rgba(15,23,42,.10);
      text-decoration:none;
      color: #0f172a;
      font-weight: 800;
      font-size: 12px;
    }

    .social-btn:hover{
      background: rgba(2,6,23,.03);
    }

    .social-btn img{
      display:block;
      height: 34px;
      width: auto;
      max-width: 160px;
    }

    .social-btn.ligue img{
      height: 30px;
      max-width: 180px;
    }

    .demo-overlay{
      position:absolute;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
      background: linear-gradient(to bottom, rgba(2,6,23,.45), rgba(2,6,23,.28), rgba(2,6,23,.55));
    }

    .demo-panel{
      width: min(860px, 95%);
      color:#fff;
      text-align:left;
      position: relative;
      padding-left: 4px;    /* ultra left, still safe for radius */
      padding-right: 20px;
      padding-top: 260px;
      padding-bottom: 48px;
    }

    .demo-kicker{
      font-size:12px;
      letter-spacing:.16em;
      text-transform:uppercase;
      opacity:.85;
      margin-bottom:8px;
      font-weight: 800;
    }

    .demo-title{
      font-size:clamp(26px,4vw,56px);
      font-weight:900;
      letter-spacing:-.03em;
      line-height:1.05;
      text-align:center;
      text-shadow:0 10px 40px rgba(0,0,0,.35);
      position:absolute;
      top: 0px; /* max height */
      left: 0;
      right: 0;
      margin: 0;
      padding: 8px 20px 0;
      z-index: 5;
    }

    .demo-desc{
      max-width:640px;
      line-height:1.5;
      margin:0 0 18px;
      opacity:.92;
      text-shadow: 0 10px 40px rgba(0,0,0,.35);
    }

    .countdown-row{
      justify-content:flex-start;
      justify-content:flex-start;
      justify-content:flex-start;

      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin: 10px 0 16px;
    }

    .timebox{
      background:rgba(255,255,255,.95);
      color:#0f172a;
      border-radius:12px;
      padding:10px 14px;
      min-width:110px;
      text-align:center;
      box-shadow:0 10px 28px rgba(0,0,0,.18);
      border: 1px solid rgba(255,255,255,.22);
    }

    .timebox .num{
      font-size:22px;
      font-weight:800;
      letter-spacing: -0.02em;
      line-height: 1;
    }

    .timebox .lbl{
      font-size:11px;
      letter-spacing:.14em;
      text-transform:uppercase;
      opacity:.62;
      font-weight:800;
      margin-top: 4px;
    }

    .actions{
      justify-content:flex-start;
      justify-content:flex-start;
      justify-content:flex-start;

      display:flex;
      gap:12px;
      flex-wrap:wrap;
      align-items:center;
    }

    .cta-pink{
      padding: 16px 32px;
      background: var(--pink);
      border: none;
      border-radius: 12px;
      color: #ffffff;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      transition: all .2s ease;
      white-space: nowrap;
      box-shadow: 0 4px 14px rgba(236,72,153,.25);
    }
    .cta-pink:hover{
      background: var(--pink-dark);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(236,72,153,.35);
      gap: 12px;
    }
    .cta-pink:active{ transform: translateY(0); }

    .cta-pink svg{
      transition: transform .2s ease;
    }
    .cta-pink:hover svg{
      transform: translateX(4px);
    }

    .btn.pink svg{
      transition: transform .2s ease;
    }
    .btn.pink:hover svg{
      transform: translateX(4px);
    }

    .cta-outline{
      background:rgba(255,255,255,.18);
      color:#fff;
      padding:9px 14px;
      border-radius:999px;
      font-weight:800;
      text-decoration:none;
      border:1px solid rgba(255,255,255,.25);
      transition: background .18s ease;
    }
    .cta-outline:hover{ background: rgba(255,255,255,.24); }

    .small-note{
      text-align:left;
      text-align:left;
      text-align:left;

      margin-top:10px;
      font-size:13px;
      opacity:.85;
    }

    /* ===== Responsive: desktop vs mobile ===== */
    @media (max-width: 980px){
      body{ padding-top: 70px; }
      /* Hide desktop nav links/cta, keep brand + burger */
      .links{ display:none; }
      .burger{ display:inline-flex; }
      .nav-pill .nav-right{ display:none; }
      .floating-nav{
        position: sticky;
        top: 0;
        left: 0;
        right: 0;
        width: 100%;
        margin: 0;
        padding: 0;
      }
      .nav-pill{
        width: 100%;
        max-width: none;
        border-radius: 0;
        box-shadow: 0 8px 24px rgba(2,6,23,.08), inset 0 -1px 0 rgba(2,6,23,.06);
        border-left: 0;
        border-right: 0;
        border-top: 0;
        border-bottom: 1px solid rgba(15,23,42,.08);
        background: linear-gradient(180deg, #ffffff 0%, #f6f7f9 100%);
        padding: 14px 16px;
      }
      .nav-pill::after{
        content:"";
        position:absolute;
        left:50%;
        bottom:-6px;
        width:140px;
        height:12px;
        transform: translateX(-50%);
        background: radial-gradient(ellipse at center, rgba(2,6,23,.18), rgba(2,6,23,0));
        opacity:.12;
        filter: blur(6px);
        pointer-events:none;
      }

      .demo-card{ height: 630px; }
      .demo-panel{
      width: min(860px, 95%);
      color:#fff;
      text-align:left;
      position: relative;
      padding-left: 4px;    /* ultra left, still safe for radius */
      padding-right: 20px;
      padding-top: 260px;
      padding-bottom: 48px;
    }

    @media (max-width: 980px){
      .video-social-card{
        position: static;
        transform: none;
        margin: 10px auto 0;
        flex-direction: row;
        flex-wrap: wrap;
        justify-content: center;
        padding: 0;
        gap: 10px;
        background: transparent;
        border: 0;
        box-shadow: none;
      }
      .social-btn{
        min-width: 0;
        height: 44px;
        padding: 6px 10px;
        border-radius: 10px;
      }
      /*.social-btn{
        background: transparent;
        border: 0;
        box-shadow: none;
        padding: 0;
        height: auto;
      }*/
      .social-btn img{
        height: 28px;
        max-width: 120px;
      }
      .social-btn.ligue img{
        height: 26px;
        max-width: 150px;
      }
    }

    /* ===== Ultra-wide screens (27") ===== */
    @media (min-width: 1600px){
      :root{
        --nav-max: clamp(1100px, 90vw, 2200px);
      }
      .demo-card{
        height: clamp(760px, 72vh, 1120px);
      }
    }
      .demo-desc{ margin-left:auto; margin-right:auto; }
      .countdown-row{
      justify-content:flex-start;
 justify-content:center; }
      .actions{
      justify-content:flex-start;
 justify-content:center; }
    }
  

        /* ===== TIMELINE (below video) ===== */
    .timeline-wrap {
      width: 100%;
      max-width: 1200px;
      margin: 40px auto 0;
    }

    .timeline {
      position: relative;
      padding: 22px 16px 18px;
    }

    .timeline-head {
      text-align: center;
      margin-bottom: 60px;
    }

    .timeline-title {
      margin: 0;
      font-size: 42px;
      font-weight: 900;
      letter-spacing: -0.02em;
    }

    .timeline-sub {
      margin: 12px 0 0;
      color: var(--page-muted);
      font-size: 15px;
      line-height: 1.5;
    }

    .timeline-track {
      position: relative;
      padding: 0;
    }

    .timeline-svg {
      position: absolute;
      top: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 100%;
      height: 100%;
      pointer-events: none;
      z-index: 1;
    }

    .timeline-path {
      fill: none;
      stroke: url(#gradient-line);
      stroke-width: 4;
      stroke-linecap: round;
      stroke-dasharray: 2000;
      stroke-dashoffset: 2000;
      animation: none;
    }

    @keyframes drawPath {
      to { stroke-dashoffset: 0; }
    }

    .timeline-items {
      position: relative;
      z-index: 2;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .t-item {
      display: flex;
      align-items: center;
      position: relative;
      padding: 20px 0;
    }

    .t-item.left {
      justify-content: flex-start;
      padding-right: 52%;
    }

    .t-item.right {
      justify-content: flex-end;
      padding-left: 52%;
    }

    .t-dot {
      display: none;
      position: absolute;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: #fff;
      border: 4px solid var(--pink);
      box-shadow: 0 0 0 6px rgba(236,72,153,.15);
      z-index: 10;
      transition: transform .3s ease, box-shadow .3s ease;
    }

    .t-item:hover .t-dot {
      transform: translate(-50%, -50%) scale(1.2);
      box-shadow: 0 0 0 10px rgba(236,72,153,.2);
    }

    @media (min-width: 901px){
      .t-dot{ display: none; }
    }
    .t-card {
      width: 100%;
      max-width: 480px;
      text-align: left;
      border-radius: 24px;
      background: #fff;
      overflow: hidden;
      box-shadow:
        0 4px 6px rgba(0,0,0,.02),
        0 12px 40px rgba(0,0,0,.06);
      transition: transform .3s ease, box-shadow .3s ease;
      border: 1px solid rgba(0,0,0,.04);
    }

    .t-item:hover .t-card {
      transform: translateY(-4px);
      box-shadow:
        0 8px 16px rgba(0,0,0,.04),
        0 24px 60px rgba(0,0,0,.1);
    }

    .t-media {
      position: relative;
      width: 100%;
      height: 180px;
      overflow: visible;
      border-radius: 0 0 16px 16px;
      background: linear-gradient(135deg, #fdf2f8, #fce7f3);
    }

    .t-media-inner {
      width: 100%;
      height: 100%;
      overflow: hidden;
      border-radius: 0 0 16px 16px;
    }

    .t-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .t-kicker {
      position: absolute;
      bottom: 0;
      left: 16px;
      transform: translateY(50%);
      z-index: 3;
      display: inline-block;
      font-size: 11px;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: var(--pink);
      font-weight: 800;
      padding: 6px 12px;
      background: #fce7f3;
      border-radius: 100px;
      margin: 0;
      border: 5px solid #fff;
      box-shadow: 0 0 0 1px #fff;
    }

    .t-content {
      padding: 20px 24px 24px;
      position: relative;
      text-align: left;
      z-index: 2;
    }

    .t-amount {
      font-size: 26px;
      font-weight: 800;
      letter-spacing: -0.02em;
      margin: 0 0 12px;
      color: var(--page-text);
    }

    .t-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .t-pill {
      display: inline-flex;
      align-items: center;
      padding: 8px 14px;
      border-radius: 10px;
      background: #f8fafc;
      border: 1px solid #f1f5f9;
      color: var(--page-muted);
      font-weight: 600;
      font-size: 12px;
      transition: all .2s ease;
    }

    .t-pill:hover {
      background: rgba(15,23,42,.04);
      border-color: #e2e8f0;
    }

    @media (max-width: 900px) {
      .timeline-svg{
        display: block;
        left: 0;
        transform: none;
        width: 100%;
        opacity: 0.8;
      }

      .timeline-path{
        stroke-width: 5;
      }

      .timeline-items{
        padding-left: 0;
        border-left: 0;
        gap: 6px;
      }

      .t-item{
        padding: 12px 0 !important;
      }

      .t-item.left,
      .t-item.right{
        padding-left: 0;
        padding-right: 0;
      }

      .t-item.left{
        justify-content: flex-start;
      }

      .t-item.right{
        justify-content: flex-end;
      }

      .t-dot{
        left: 50%;
        transform: translate(-50%, -50%);
      }

      .t-item:hover .t-dot{
        transform: translate(-50%, -50%) scale(1.2);
      }

      .t-card{
        max-width: 80%;
        width: 80%;
      }
    }

    @media (max-width: 600px) {
      .timeline-title { font-size: 32px; }
      .t-media { height: 140px; }
      .t-content { padding: 16px 20px 20px; }
      .t-amount { font-size: 22px; }
      .t-pill { padding: 6px 10px; font-size: 11px; }
    }

    /* ===== MARQUEE / LOGO STRIP (Vimeo-like) ===== */


    /* ===== COMMUNITY SECTION (Vimeo style) ===== */
    .community-section{
      width: 100vw;
      position: relative;
      left: 50%;
      right: 50%;
      margin-left: -50vw;
      margin-right: -50vw;
      background: #0f172a;
      color: #ffffff;
      padding: 0;
      margin-top: 120px;
      margin-bottom: 0;
      overflow: hidden;
    }
    
    .community-section::before{
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 6px;
      background: var(--pink);
    }
    
    .community-container{
      max-width: 1400px;
      margin: 0 auto;
      padding: 50px var(--side-pad);
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 80px;
      align-items: center;
    }
    
    .community-image{
      position: relative;
    }
    
    .community-image img{
      width: 85%;
      height: auto;
      display: block;
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,.4);
    }
    
    .community-content{
      color: #ffffff;
    }
    
    .community-title{
      font-size: clamp(22px, 2.5vw, 32px);
      font-weight: 700;
      line-height: 1.15;
      margin: 0 0 16px 0;
      letter-spacing: -0.02em;
    }

    .community-text{
      font-size: 15px;
      line-height: 1.6;
      color: rgba(255,255,255,.85);
      margin: 0 0 24px 0;
      max-width: 600px;
    }
    
    .partner-form{
      margin-top: 0;\r\n      border-top: 1px solid rgba(255,255,255,.08);
    }
    
    .form-group{
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
    
    .partner-email-input{
      flex: 1;
      min-width: 280px;
      padding: 16px 20px;
      font-size: 16px;
      background: rgba(255,255,255,.08);
      border: 2px solid rgba(255,255,255,.15);
      border-radius: 12px;
      color: #ffffff;
      outline: none;
      transition: all .2s ease;
    }
    
    .partner-email-input::placeholder{
      color: rgba(255,255,255,.5);
    }
    
    .partner-email-input:focus{
      background: rgba(255,255,255,.12);
      border-color: var(--pink);
      box-shadow: 0 0 0 3px rgba(236,72,153,.15);
    }
    
    .partner-submit{
      padding: 16px 32px;
      background: var(--pink);
      border: none;
      border-radius: 12px;
      color: #ffffff;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all .2s ease;
      white-space: nowrap;
    }
    
    .partner-submit:hover{
      background: var(--pink-dark);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(236,72,153,.35);
      gap: 12px;
    }
    
    .partner-submit svg{
      transition: transform .2s ease;
    }
    
    .partner-submit:hover svg{
      transform: translateX(4px);
    }
    
    .form-note{
      font-size: 14px;
      color: rgba(255,255,255,.6);
      margin: 12px 0 0 0;
      font-style: italic;
    }
    
    
        /* ===== News Band ===== */
    .news-band{
      width: 100vw;
      position: relative;
      left: 50%;
      right: 50%;
      margin-left: -50vw;
      margin-right: -50vw;
      background: #0f172a;
      color: #ffffff;
      padding: 0;
      margin-top: 0;
      margin-bottom: 0;
    }

    .news-band::before{
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 6px;
      background: var(--pink);
    }

    .news-band-container{
      max-width: 1400px;
      margin: 0 auto;
      padding: 40px var(--side-pad) 44px;
    }

    .news-band-head{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      margin-bottom: 14px;
    }

    .news-band-title{
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      color: #ffffff;
    }

    .news-band-link{
      color: var(--pink);
      text-decoration: none;
      font-weight: 700;
    }

    .news-band-link:hover{
      color: var(--pink-dark);
    }

    .news-grid{
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      justify-content: center;
      max-width: 1088px;
      margin: 0 auto;
    }

    .news-card{
      display:flex;
      flex-direction: column;
      border-radius: 16px;
      background: rgba(255,255,255,.08);
      color: #ffffff;
      text-decoration: none;
      overflow: hidden;
      min-height: 140px;
      transition: transform .2s ease, background .2s ease, border-color .2s ease;
    }

    .news-card:hover{
      transform: translateY(-3px);
      background: rgba(255,255,255,.12);
      border-color: rgba(236,72,153,.6);
    }

    .news-media{
      width: 100%;
      height: 190px;
      background: linear-gradient(135deg, #1f2937, #111827);
      overflow: hidden;
      display: none;
    }

    .news-media img{
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .news-body{
      display:flex;
      flex-direction: column;
      gap: 8px;
      padding: 16px;
      min-height: 120px;
    }

    .news-kicker{
      font-size: 10px;
      letter-spacing: .18em;
      text-transform: uppercase;
      color: rgba(255,255,255,.6);
      font-weight: 700;
    }

    .news-title{
      font-size: 14px;
      font-weight: 700;
      color: #ffffff;
      line-height: 1.35;
    }

    .news-date{
      font-size: 12px;
      color: rgba(255,255,255,.65);
    }

    .news-cta{
      margin-top: auto;
      font-size: 12px;
      color: var(--pink);
      font-weight: 700;
    }

    .news-empty{
      padding: 16px;
      border-radius: 16px;
      background: rgba(255,255,255,.06);
      border: 1px dashed rgba(255,255,255,.18);
      color: rgba(255,255,255,.7);
      font-size: 13px;
    }

    @media (max-width: 700px){
      .news-grid{ grid-template-columns: 1fr; }
      .news-media{ height: 150px; }
    }
@media (max-width: 980px){
      .community-section{
        margin-top: 110px;
      }
      
      .community-container{
        grid-template-columns: 1fr;
        gap: 32px;
        padding: 40px var(--side-pad);
      }

      .community-image{
        text-align: center;
      }

      .community-image img{
        width: 92%;
        margin: 0 auto;
      }

      .community-title{
        font-size: 24px;
      }

      .community-text{
        font-size: 15px;
      }
      
      .form-group{
        flex-direction: column;
      }
      
      .partner-email-input{
        min-width: 100%;
      }
      
      .partner-submit{
        width: 100%;
        justify-content: center;
      }

      .reg-bar{
        margin-top: 70px;
      }
    }
  margin-top: 36px !important;
  margin-bottom: 56px !important;
}

.timeline-section{
  margin-top: 32px !important;
}

/* ===== VIDEO FLOATING PILLS (option 3) ===== */
.demo-overlay{
  align-items:flex-end;
  justify-content:flex-start;
}

.demo-panel.video-float{
  width: auto;
  max-width: none;
  padding: 0;
  background: transparent;
  box-shadow: none;
  border: 0;
  color: #fff;
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  gap: 4px;
  transform: translate(35px, -35px);
}

.hero-text{
  display: contents;
}

.countdown-wrap{
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  gap: 2px;
}

.countdown-row{
  width: max-content;
}

.demo-panel.video-float .demo-kicker{
  color: #ffffff;
  opacity: 1;
  margin-bottom: 6px;
  font-size: clamp(28px, 4vw, 52px); /* Taille police pour le titre Forbach en rose */
  font-weight: 900;
  letter-spacing: -0.02em;
  text-transform: none;
  text-shadow: 0 10px 28px rgba(0,0,0,.4);
}

.demo-panel.video-float .demo-desc{
  margin: 0 0 6px;
}

.demo-panel.video-float .timebox{
  background: rgba(255,255,255,.96);
  border: 1px solid rgba(15,23,42,.08);
  box-shadow: 0 10px 28px rgba(2,6,23,.18);
}

.demo-panel.video-float .small-note{
  color: rgba(255,255,255,.85);
  opacity: 1;
  text-shadow: 0 8px 24px rgba(0,0,0,.35);
  margin-top: 0;
  align-self:flex-end;
  text-align:right;
}

.demo-panel.video-float .countdown-row{
  margin: 4px 0 4px;
}

.demo-panel.video-float .actions{
  margin-top: 4px;
}

@media (max-width: 980px){
  .demo-overlay{
    align-items:flex-end;
    justify-content:center;
    padding: 16px;
  }
  main{
    padding-top: 15px;
  }
  .demo-wrap{
    margin-top: 1px;
  }
  .demo-card{
    height: var(--demo-card-height, clamp(555px, calc(64vh - 40px), 775px)) !important;
    box-shadow: none;
  }
  .demo-panel.video-float{
    position: relative;
    height: 100%;
    width: 100%;
    align-self: stretch;
    align-items:center;
    text-align:center;
    justify-content:flex-end;
    gap: 10px;
    transform: none;
    padding: 0;
    padding-bottom: 0;
  }
  .demo-panel.video-float .hero-text{
    display: block;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: min(90%, 520px);
  }
  .demo-panel.video-float .demo-kicker{
    margin-bottom: 6px;
  }
  .demo-panel.video-float .demo-desc{
    margin: 0;
  }
  .countdown-wrap{
    position: absolute;
    left: 0;
    right: 0;
    bottom: -5px;
    align-items: stretch;
    width: 100%;
  }
  .countdown-row{
    width: 100%;
    justify-content: space-between;
    flex-wrap: nowrap;
    gap: 8px;
    margin: 0;
  }
  .demo-panel.video-float .small-note{
    align-self:center;
    text-align:center;
  }
  .demo-panel.video-float .actions{
    display:none;
  }
  .mobile-cta{
    display:flex;
  }
  .timebox{
    flex: 1 1 0;
    min-width: 0;
    padding: 8px 6px;
  }
  .timebox .num{ font-size: 20px; }
  .timebox .lbl{ font-size: 10px; letter-spacing: .12em; }
  .timebox-seconds{ display:none; }
  .actions{ justify-content:center; }
}

/* iPad/tablet: reduce video height a bit */
@media (min-width: 768px) and (max-width: 1024px){
  .demo-card{
    height: clamp(520px, 56vh, 720px);
  }
}

/* Shorter laptop screens: reduce video height a bit */
@media (min-width: 981px) and (max-height: 900px){
  .demo-card{
    height: clamp(600px, 62vh, 960px);
  }
}

/* iPad 10" (force override) */
    @media (min-width: 768px) and (max-width: 1200px){
      .demo-card{
        height: clamp(520px, 56vh, 720px) !important;
      }
    }

    @media (max-width: 980px){
      .mobile-socials{
        display:flex;
        width: 100%;
        justify-content:center;
        padding: 12px 0 4px;
        margin-top: auto;
        border-top: 1px solid rgba(15,23,42,.08);
      }
      .mobile-socials .social-btn{
        height: 36px;
        padding: 6px 10px;
        border-radius: 10px;
      }
      .mobile-socials .social-btn img{
        height: 24px;
        max-width: 120px;
      }
      .mobile-socials .social-btn.ligue img{
        height: 22px;
        max-width: 140px;
      }
      .video-social-card{
        display:none;
      }
    }

    /* ===== DARK THEME OVERRIDES for Accueil ===== */
    body.dark-theme .reg-card{
      background: linear-gradient(135deg, #1a1025, #1e1230);
    }
    body.dark-theme .reg-title{
      color: #ffffff;
    }
    body.dark-theme .reg-input{
      background: rgba(255,255,255,.08);
      color: #ffffff;
      box-shadow: none;
      border: 1px solid rgba(255,255,255,.1);
    }
    body.dark-theme .reg-input::placeholder{
      color: rgba(255,255,255,.4);
    }
    body.dark-theme .reg-input:focus{
      background: rgba(255,255,255,.12);
      border-color: var(--pink);
    }
    body.dark-theme .reg-hint{
      color: rgba(255,255,255,.5);
    }
    body.dark-theme .reg-result.success{
      background: rgba(91,33,182,.2);
      color: #c4b5fd;
      border-color: rgba(124,58,237,.35);
    }
    body.dark-theme .reg-result.warn{
      background: rgba(14,116,144,.15);
      color: #67e8f9;
      border-color: rgba(14,116,144,.35);
    }
    body.dark-theme .reg-result.danger{
      background: rgba(236,72,153,.15);
      color: #f9a8d4;
      border-color: rgba(236,72,153,.35);
    }

    body.dark-theme .t-card{
      background: #1e1f28;
      border-color: transparent;
      box-shadow: none;
    }
    body.dark-theme .t-card:hover{
      box-shadow: none;
    }
    body.dark-theme .t-media{
      background: linear-gradient(135deg, #1a1025, #1e1230);
    }
    body.dark-theme .t-kicker{
      background: #1a1025;
      color: var(--pink);
      border-color: #1e1f28;
      box-shadow: 0 0 0 1px #1e1f28;
    }
    body.dark-theme .t-amount{
      color: #ffffff;
    }
    body.dark-theme .t-pill{
      background: rgba(255,255,255,.06);
      border-color: rgba(255,255,255,.08);
      color: rgba(255,255,255,.65);
    }
    body.dark-theme .t-pill:hover{
      background: rgba(255,255,255,.1);
      border-color: rgba(255,255,255,.15);
    }
    body.dark-theme .t-dot{
      background: #1e1f28;
    }

    body.dark-theme .timeline-title{
      color: #ffffff;
    }
    body.dark-theme .timeline-desc{
      color: rgba(255,255,255,.65);
    }

</style>
</head>

<body>

  <!-- NAV -->
  <!-- Theme: apply saved preference immediately to avoid flash -->
  <script>
  (function(){var t=localStorage.getItem('fer-theme');if(t==='dark')document.body.classList.add('dark-theme');})();
  </script>

  <header class="floating-nav" id="navRoot">
    <div class="mega-overlay" id="megaOverlay"></div>
    <div class="nav-pill">
      <a class="brand" href="#">
        <img class="brand-logo" src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
      </a>

      <button class="burger" id="burgerBtn" aria-expanded="false" aria-controls="mobileDrawer">
        <span class="sr-only">Ouvrir le menu</span>
        <span class="burger-icon" aria-hidden="true"></span>
      </button>

      <div class="nav-right">
        <div class="nav-card">
          <nav id="nav-links" class="links" aria-label="Navigation principale">
            <ul class="menu nav-secondary">
          <li class="item">
            <a class="link" href="accueil">
              <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                  <polyline points="9,22 9,12 15,12 15,22"></polyline>
                </svg>
              </span>
              <span class="nav-label">Accueil</span>
            </a>
          </li>
          <li class="item">
            <a class="link" href="parcours">
              <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <circle cx="12" cy="12" r="10"></circle>
                  <polygon points="10,8 16,12 10,16 10,8"></polygon>
                </svg>
              </span>
              <span class="nav-label">Parcours</span>
            </a>
          </li>
          
          <!-- Menu Actualités -->
          <li class="item" data-menu="actualites">
            <button class="trigger" type="button" aria-haspopup="true" aria-expanded="false">
              <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                  <line x1="7" y1="8" x2="17" y2="8"></line>
                  <line x1="7" y1="12" x2="17" y2="12"></line>
                  <line x1="7" y1="16" x2="14" y2="16"></line>
                </svg>
              </span>
              <span class="nav-label">Actualités</span>
              <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>

            <div class="mega<?= $actualites_cols2 ? ' mega--wide' : '' ?>" role="menu" aria-hidden="true">
              <div class="mega-grid">
                <!-- Colonne gauche : liens -->
                <div class="mega-content">
                  <div class="mega-section">
                    <div class="mega-title">Dernières actualités</div>
                    <ul class="mega-list<?= $actualites_cols2 ? ' mega-list--2col' : '' ?>">
                      <?php if (!empty($actualites)): ?>
                        <?php foreach ($actualites as $actu): ?>
                          <li>
                            <a class="mega-link" href="news?id=<?= $actu['id'] ?>">
                              <span class="micon">📰</span>
                              <div class="mega-link-content">
                                <div class="mtitle"><?= htmlspecialchars($actu['title']) ?></div>
                              </div>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <li>
                          <a class="mega-link" href="news">
                            <span class="micon">📰</span>
                            <div class="mega-link-content">
                              <div class="mtitle">Voir toutes les actualités</div>
                            </div>
                          </a>
                        </li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </div>
                
                <!-- Colonne droite : image -->
                <div class="mega-featured">
                  <div class="mega-featured-img">📰</div>
                  <div class="mega-featured-title">Toutes nos actualités</div>
                  <div class="mega-featured-desc">Restez informés de tous les événements et nouveautés de Forbach en Rose</div>
                  <a href="news" class="mega-featured-link">
                    Voir tout
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                </div>
              </div>
            </div>
          </li>

          <!-- Menu Photos -->
          <li class="item" data-menu="photos">
            <button class="trigger" type="button" aria-haspopup="true" aria-expanded="false">
              <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <path d="M4 7h4l2-2h4l2 2h4v12H4z"></path>
                  <circle cx="12" cy="13" r="3"></circle>
                </svg>
              </span>
              <span class="nav-label">Photos</span>
              <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>

            <div class="mega<?= $galeries_cols2 ? ' mega--wide' : '' ?>" role="menu" aria-hidden="true">
              <div class="mega-grid">
                <div class="mega-content">
                  <div class="mega-section">
                    <div class="mega-title">Albums photos</div>
                    <ul class="mega-list<?= $galeries_cols2 ? ' mega-list--2col' : '' ?>">
                      <?php if (!empty($galeries)): ?>
                        <?php foreach ($galeries as $galerie): ?>
                          <li>
                            <a class="mega-link" href="photos?year_id=<?= $galerie['id'] ?>">
                              <span class="micon">📸</span>
                              <div class="mega-link-content">
                                <div class="mtitle"><?= htmlspecialchars($galerie['title']) ?> (<?= $galerie['year'] ?>)</div>
                              </div>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <li>
                          <a class="mega-link" href="photos">
                            <span class="micon">📸</span>
                            <div class="mega-link-content">
                              <div class="mtitle">Voir tous les albums</div>
                            </div>
                          </a>
                        </li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </div>
                
                <div class="mega-featured">
                  <div class="mega-featured-img">📸</div>
                  <div class="mega-featured-title">Nos albums photos</div>
                  <div class="mega-featured-desc">Découvrez tous les moments forts de Forbach en Rose en images</div>
                  <a href="photos" class="mega-featured-link">
                    Voir tout
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                </div>
              </div>
            </div>
          </li>

          <!-- Menu Partenaires -->
          <li class="item" data-menu="partenaires">
            <button class="trigger" type="button" aria-haspopup="true" aria-expanded="false">
              <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                  <circle cx="9" cy="7" r="4"></circle>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                  <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
              </span>
              <span class="nav-label">Partenaires</span>
              <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>

            <div class="mega<?= $partenaires_cols2 ? ' mega--wide' : '' ?>" role="menu" aria-hidden="true">
              <div class="mega-grid">
                <div class="mega-content">
                  <div class="mega-section">
                    <div class="mega-title">Nos partenaires</div>
                    <ul class="mega-list<?= $partenaires_cols2 ? ' mega-list--2col' : '' ?>">
                      <?php if (!empty($partenaires)): ?>
                        <?php foreach ($partenaires as $part): ?>
                          <li>
                            <a class="mega-link" href="partenaires?year_id=<?= $part['id'] ?>">
                              <span class="micon">🤝</span>
                              <div class="mega-link-content">
                                <div class="mtitle"><?= htmlspecialchars($part['title']) ?> (<?= $part['year'] ?>)</div>
                              </div>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <li>
                          <a class="mega-link" href="partenaires">
                            <span class="micon">🤝</span>
                            <div class="mega-link-content">
                              <div class="mtitle">Voir tous les partenaires</div>
                            </div>
                          </a>
                        </li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </div>
                
                <div class="mega-featured">
                  <div class="mega-featured-img">🤝</div>
                  <div class="mega-featured-title">Nos partenaires</div>
                  <div class="mega-featured-desc">Merci à tous nos partenaires qui soutiennent Forbach en Rose</div>
                  <a href="partenaires" class="mega-featured-link">
                    Voir tout
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                </div>
              </div>
            </div>
          </li>
            </ul>
          </nav>
        </div>
        <div class="cta">
          <a class="btn pink nav-cta" href="register">Inscription<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
        </div>
      </div>
    </div>
  </header>

  <!-- ===== MOBILE HEADER (Vimeo style) ===== -->
  <header class="mobile-header" id="mobileHeader">
    <a class="brand" href="accueil">
      <img class="brand-logo" src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
    </a>
  </header>

  <!-- ===== MOBILE BOTTOM BAR + UNIFIED MENU (Vimeo style) ===== -->
  <div class="mobile-bottom-bar" id="mobileBottomBar">
    <div class="mobile-bottom-wrapper" id="mobileWrapper">
      
      <!-- Main nav block (unified: menu panel + action buttons) -->
      <div class="mobile-bottom-unified" id="mobileUnified">

        <!-- Menu panel (hidden, slides in on open) -->
        <div class="mobile-menu-panel" id="mobileMenuPanel">
          <div class="mobile-menu-header">
            <button class="mobile-menu-back" id="mobileMenuBack" aria-label="Retour">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="18" height="18">
                <path d="M15 18l-6-6 6-6"/>
              </svg>
            </button>
            <span class="mobile-menu-title" id="mobileMenuTitleText">Menu</span>
            <button class="mobile-menu-close" id="mobileMenuClose" aria-label="Fermer">✕</button>
          </div>

          <div class="mobile-menu-slides" id="mobileMenuSlides">
            <!-- MAIN VIEW -->
            <div class="mobile-menu-slide mobile-menu-slide-main" id="slideMain">
              <div class="mobile-menu-body">
                <nav class="mobile-menu-nav">
                  <div class="mobile-menu-item" data-sub="actualites">
                    <button class="mobile-menu-trigger">
                      <div class="mobile-menu-trigger-content">
                        <span class="mobile-menu-icon">
  <svg viewBox="0 0 24 24" aria-hidden="true">
    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
    <line x1="7" y1="8" x2="17" y2="8"></line>
    <line x1="7" y1="12" x2="17" y2="12"></line>
    <line x1="7" y1="16" x2="14" y2="16"></line>
  </svg>
</span>
                        Actualités
                      </div>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                  </div>
                  <div class="mobile-menu-item" data-sub="photos">
                    <button class="mobile-menu-trigger">
                      <div class="mobile-menu-trigger-content">
                        <span class="mobile-menu-icon">
  <svg viewBox="0 0 24 24" aria-hidden="true">
    <path d="M4 7h4l2-2h4l2 2h4v12H4z"></path>
    <circle cx="12" cy="13" r="3"></circle>
  </svg>
</span>
                        Photos
                      </div>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                  </div>
                  <div class="mobile-menu-item" data-sub="partenaires">
                    <button class="mobile-menu-trigger">
                      <div class="mobile-menu-trigger-content">
                        <span class="mobile-menu-icon">
  <svg viewBox="0 0 24 24" aria-hidden="true">
    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
    <circle cx="9" cy="7" r="4"></circle>
    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
  </svg>
</span>
                        Partenaires
                      </div>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                  </div>

                </nav>
              </div>

              <div class="mobile-menu-footer">
                <?php if (!empty($link_facebook)): ?>
                <a class="mobile-menu-footer-btn" href="<?= htmlspecialchars($link_facebook) ?>" target="_blank" rel="noopener">
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                  </svg>
                  <span>Facebook</span>
                </a>
                <?php endif; ?>
                <?php if (!empty($link_instagram)): ?>
                <a class="mobile-menu-footer-btn" href="<?= htmlspecialchars($link_instagram) ?>" target="_blank" rel="noopener">
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                  </svg>
                  <span>Instagram</span>
                </a>
                <?php endif; ?>
                <?php if (!empty($link_cancer)): ?>
                <a class="mobile-menu-footer-btn" href="<?= htmlspecialchars($link_cancer) ?>" target="_blank" rel="noopener" aria-label="Ligue contre le cancer">
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                  </svg>
                  <span>La ligue</span>
                </a>
                <?php endif; ?>
              </div>
            </div>

            <!-- SUB VIEW: Actualités -->
            <div class="mobile-menu-slide mobile-menu-slide-sub" id="slideSub-actualites" data-title="Actualités">
              <div class="mobile-menu-body">
                <?php if (!empty($actualites)): ?>
                  <?php foreach ($actualites as $actu): ?>
                    <a class="mobile-menu-sublink" href="news?id=<?= $actu['id'] ?>">
                      <span class="menu-bullet" aria-hidden="true"></span>
                      <?= htmlspecialchars($actu['title']) ?>
                    </a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <a class="mobile-menu-see-all" href="news">
                Voir toutes les actualités
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
              </a>
            </div>

            <!-- SUB VIEW: Photos -->
            <div class="mobile-menu-slide mobile-menu-slide-sub" id="slideSub-photos" data-title="Photos">
              <div class="mobile-menu-body">
                <?php if (!empty($galeries)): ?>
                  <?php foreach ($galeries as $galerie): ?>
                    <a class="mobile-menu-sublink" href="photos?year_id=<?= $galerie['id'] ?>">
                      <span class="menu-bullet" aria-hidden="true"></span>
                      <?= htmlspecialchars($galerie['title']) ?> (<?= $galerie['year'] ?>)
                    </a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <a class="mobile-menu-see-all" href="photos">
                Voir tous les albums
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
              </a>
            </div>

            <!-- SUB VIEW: Partenaires -->
            <div class="mobile-menu-slide mobile-menu-slide-sub" id="slideSub-partenaires" data-title="Partenaires">
              <div class="mobile-menu-body">
                <?php if (!empty($partenaires)): ?>
                  <?php foreach ($partenaires as $part): ?>
                    <a class="mobile-menu-sublink" href="partenaires?year_id=<?= $part['id'] ?>">
                      <span class="menu-bullet" aria-hidden="true"></span>
                      <?= htmlspecialchars($part['title']) ?> (<?= $part['year'] ?>)
                    </a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <a class="mobile-menu-see-all" href="partenaires">
                Voir tous les partenaires
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
              </a>
            </div>
          </div>
        </div>

        <!-- Bottom action buttons (always visible) -->
        <div class="mobile-bottom-actions">
          <button class="mobile-bottom-btn" id="mobileMenuBtn" aria-label="Menu">
            <svg class="menu-icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="3" y1="6" x2="21" y2="6"></line>
              <line x1="3" y1="12" x2="21" y2="12"></line>
              <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
            <svg class="menu-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
            <span>Menu</span>
          </button>
          <a class="mobile-bottom-btn" href="accueil">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              <polyline points="9,22 9,12 15,12 15,22"></polyline>
            </svg>
            <span>Accueil</span>
          </a>
          <a class="mobile-bottom-btn" href="parcours">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <polygon points="10,8 16,12 10,16 10,8"></polygon>
            </svg>
            <span>Parcours</span>
          </a>
          <!-- Inner CTA: visible only when menu is open -->
          <a class="mobile-bottom-cta-inner" href="register">Inscription</a>
        </div>
      </div>

      <!-- Outer CTA: visible only when menu is closed -->
      <a class="mobile-bottom-cta" href="register">Inscription</a>
    </div>
  </div>

    <!-- ===== MOBILE MENU BACKDROP ===== -->
  <div class="mobile-menu-backdrop" id="mobileMenuBackdrop"></div>

  <!-- PAGE -->
  <main>
    

    <div class="demo-wrap">
      <section class="demo-card" aria-label="Carte vidéo">
        <video class="demo-video" autoplay muted loop playsinline>
          <source src="../files/FER.mp4" type="video/mp4" />
        </video>

        <div class="demo-overlay">
          <div class="demo-panel video-float">
            <div class="hero-text">
              <div class="demo-kicker" style="color: <?= htmlspecialchars($titleColor) ?>;"><?= htmlspecialchars($titleAccueil) ?></div>
              <p class="demo-desc">Course et marche solidaires contre le cancer</strong>.</p>
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
    


    <!-- COMMUNITY SECTION (style Vimeo) -->
    <section class="community-section" aria-label="Devenez partenaire">
      <div class="community-container"<?php if (empty($picture_partner) || !is_file('../files/_pictures/' . $picture_partner)): ?> style="grid-template-columns:1fr;text-align:center;max-width:800px"<?php endif; ?>>
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
          
          <form class="partner-form" action="#" method="POST">
            <div class="form-group">
              <input 
                type="email" 
                name="partner_email" 
                class="partner-email-input" 
                placeholder="Votre email professionnel" 
                required
                aria-label="Email professionnel"
              >
              <button type="submit" class="partner-submit">
                Devenir partenaire
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
            <p class="form-note">Nous vous recontacterons dans les plus brefs délais pour discuter des modalités de partenariat.</p>
          </form>
        </div>
      </div>
    </section>

<section class="reg-bar" id="reg-bar" aria-label="Inscriptions">
      <div class="reg-card">
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
      </div>
    </section>
    
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
                <stop offset="50%" stop-color="#ec4899" />
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
  <script>
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

    // ===== Keep partner band 30px below bottom bar (mobile, no scroll) =====
    (function(){
      const demoCard = document.querySelector('.demo-card');
      const community = document.querySelector('.community-section');
      const bottomBar = document.getElementById('mobileBottomBar');

      if (!demoCard || !community || !bottomBar) return;

      let locked = false;

      function isMobile(){
        return window.matchMedia('(max-width: 980px)').matches;
      }

      function updateHeroHeight(force){
        if (!isMobile()){
          demoCard.style.removeProperty('--demo-card-height');
          locked = false;
          return;
        }
        if (locked && !force) return;

        const bottomInner = bottomBar.querySelector('.mobile-bottom-actions') || bottomBar;
        const bottomRect = bottomInner.getBoundingClientRect();
        if (!bottomRect.height) return;

        const gapBelowBar = 30;
        const targetTop = bottomRect.bottom + gapBelowBar;

        const demoRect = demoCard.getBoundingClientRect();
        const communityRect = community.getBoundingClientRect();
        const gap = Math.max(communityRect.top - demoRect.bottom, 0);

        let nextHeight = targetTop - demoRect.top - gap;
        if (!Number.isFinite(nextHeight)) return;

        const minHeight = 260;
        const maxHeight = window.innerHeight;
        nextHeight = Math.max(minHeight, Math.min(maxHeight, nextHeight));

        demoCard.style.setProperty('--demo-card-height', `${Math.round(nextHeight)}px`);
        locked = true;
      }

      function scheduleUpdate(force){
        requestAnimationFrame(() => requestAnimationFrame(() => updateHeroHeight(force)));
      }

      window.addEventListener('load', () => {
        scheduleUpdate(true);
        setTimeout(() => scheduleUpdate(true), 300);
        setTimeout(() => scheduleUpdate(true), 800);
      });
      window.addEventListener('resize', () => {
        if (!locked) scheduleUpdate(true);
      });
      window.addEventListener('orientationchange', () => {
        locked = false;
        scheduleUpdate(true);
      });
      scheduleUpdate(true);
    })();
  </script>

</body>
</html>
