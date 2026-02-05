<?php 
require '../config/config.php';

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
        $searchMessage = "Merci d'indiquer votre email.";
    } elseif (!filter_var($searchEmail, FILTER_VALIDATE_EMAIL)) {
        $searchStatus = 'warn';
        $searchMessage = "Merci d'indiquer un email valide.";
    } else {
        $stmtSearch = $pdo->prepare(
            'SELECT COUNT(*) AS total FROM registrations WHERE LOWER(email) = LOWER(:email)'
        );
        $stmtSearch->execute(['email' => $searchEmail]);
        $matchCount = (int)($stmtSearch->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        if ($matchCount > 0) {
            $searchStatus = 'success';
            $searchMessage = "Merci, votre inscription est bien enregistrée.";
        } else {
            $searchStatus = 'danger';
            $searchMessage = "Vous n'êtes pas encore inscrit(e).";
        }
    }
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
$date_course = $data['date_course'] ?? null;
$date_formatted = $date_course ? date('Y-m-d\TH:i:s', strtotime($date_course)) : '2026-07-05T09:00:00';
$picture_partner = $data['picture_partner'] ?? ''; 
$picture_accueil = $data['picture_accueil'] ?? ''; 
$footer_text = $data['footer'] ?? null;  

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
    $stmtActus = $pdo->prepare('SELECT id, title_article as title, img_article, date_publication FROM news ORDER BY date_publication DESC LIMIT 5');
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

$link_cancer = $data['link_cancer'] ?? null;
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Accueil - Forbach en Rose</title>

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
      justify-content:space-between;
      gap: 14px;
      width: 100%;
    }

    .menu{
      list-style:none;
      display:flex;
      align-items:center;
      gap: 8px;
      margin:0;
      padding:0;
    }

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
      margin-left: auto;
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
      left: 50%;
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
      width: 900px;
      max-width: calc(100vw - 40px);
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
        width: 720px;
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
    /* ===== MOBILE BOTTOM BAR (Vimeo style with glassmorphism) ===== */
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
    .mobile-bottom-inner{
      display: flex;
      align-items: center;
      justify-content: center;
      background: #ffffff;
      border: none;
      border-radius: 16px;
      padding: 5px 4px;
      gap: 0;
      pointer-events: auto;
      transition: all .35s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 8px 18px rgba(0,0,0,.18);
      flex: 1;
      min-height: 56px;
      position: relative;
      overflow: hidden;
      isolation: isolate;
    }
    .mobile-bottom-inner::before{
      display: none;
    }
    .mobile-bottom-inner > *{
      position: relative;
      z-index: 1;
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
    .mobile-bottom-btn:hover,
    .mobile-bottom-btn:active{
      background: rgba(15,23,42,.08);
      color: #0f172a;
    }
    .mobile-bottom-btn svg{
      width: 21px;
      height: 21px;
      opacity: .9;
    }
    .mobile-bottom-btn span{
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    /* Wrapper for bar + CTA */
    .mobile-bottom-wrapper{
      display: flex;
      align-items: stretch;
      justify-content: center;
      gap: 8px;
      transition: gap .35s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* CTA button (Inscription) - OUTSIDE the bar, same height */
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
      transition: all .35s cubic-bezier(0.4, 0, 0.2, 1);
      opacity: 1;
      width: auto;
      padding: 0 20px;
      overflow: hidden;
    }
    .mobile-bottom-cta:hover{
      background: var(--pink-dark);
    }
    /* Menu open: make bar + CTA a single solid block (no blur) */
    .mobile-bottom-bar.menu-open .mobile-bottom-wrapper{
      gap: 0;
      background: #ffffff;
      border-radius: 16px;
      padding: 5px 4px;
      overflow: hidden;
      box-shadow: 0 8px 20px rgba(0,0,0,.16);
    }
    .mobile-bottom-bar.menu-open .mobile-bottom-inner{
      border-top-right-radius: 0;
      border-bottom-right-radius: 0;
      background: transparent;
      box-shadow: none;
      backdrop-filter: none;
      -webkit-backdrop-filter: none;
      padding: 0;
    }
    .mobile-bottom-bar.menu-open .mobile-bottom-inner::before{
      display: none;
    }
    .mobile-bottom-bar.menu-open .mobile-bottom-cta{
      opacity: 1;
      width: auto;
      padding: 0 20px;
      border-top-left-radius: 0;
      border-bottom-left-radius: 0;
      background: transparent;
      color: #0f172a;
      box-shadow: none;
      border-left: 1px solid rgba(15,23,42,.08);
    }

    /* ===== MOBILE MENU POPUP (Vimeo floating card style) ===== */
    .mobile-menu-backdrop{
      position: fixed;
      inset: 0;
      z-index: 10000;
      background: rgba(0, 0, 0, 0.4);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      display: none;
      opacity: 0;
      transition: opacity .25s ease;
    }
    .mobile-menu-backdrop.open{
      display: block;
      opacity: 1;
    }
    .mobile-menu-popup{
      position: fixed;
      bottom: 90px;
      left: 12px;
      right: 12px;
      z-index: 10001;
      background: rgba(30, 30, 36, 0.97);
      backdrop-filter: blur(30px) saturate(180%);
      -webkit-backdrop-filter: blur(30px) saturate(180%);
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 24px;
      max-height: calc(100vh - 180px);
      overflow: hidden;
      display: none;
      flex-direction: column;
      opacity: 0;
      transform: translateY(20px) scale(0.97);
      transition: all .3s cubic-bezier(0.34, 1.56, 0.64, 1);
      box-shadow: 0 25px 80px rgba(0,0,0,.5), 0 10px 30px rgba(0,0,0,.3);
    }
    .mobile-menu-popup.open{
      display: flex;
      opacity: 1;
      transform: translateY(0) scale(1);
    }
    .mobile-menu-header{
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 20px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      flex-shrink: 0;
    }
    .mobile-menu-title{
      color: #fff;
      font-size: 15px;
      font-weight: 600;
      letter-spacing: .01em;
    }
    .mobile-menu-close{
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: rgba(255,255,255,.1);
      border: none;
      color: rgba(255,255,255,.7);
      font-size: 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .2s ease;
    }
    .mobile-menu-close:hover{
      background: rgba(255,255,255,.18);
      color: #fff;
    }
    .mobile-menu-body{
      flex: 1;
      overflow-y: auto;
      padding: 8px 12px;
      -webkit-overflow-scrolling: touch;
    }
    .mobile-menu-nav{
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    /* Menu item with accordion */
    .mobile-menu-item{
      border-radius: 12px;
      overflow: hidden;
    }
    .mobile-menu-trigger{
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      padding: 14px 12px;
      background: transparent;
      border: none;
      color: #fff;
      font-size: 15px;
      font-weight: 500;
      cursor: pointer;
      text-align: left;
      text-decoration: none;
      border-radius: 12px;
      transition: background .15s ease;
    }
    .mobile-menu-trigger:hover{
      background: rgba(255,255,255,.06);
    }
    .mobile-menu-trigger svg{
      width: 18px;
      height: 18px;
      opacity: .5;
      transition: transform .2s ease;
      flex-shrink: 0;
    }
    .mobile-menu-item[data-open="true"] .mobile-menu-trigger svg{
      transform: rotate(180deg);
    }
    .mobile-menu-icon{
      width: 28px;
      height: 28px;
      background: rgba(255,255,255,.1);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 12px;
      font-size: 14px;
      flex-shrink: 0;
    }
    .mobile-menu-trigger-content{
      display: flex;
      align-items: center;
      flex: 1;
    }
    /* Submenu */
    .mobile-menu-sub{
      display: none;
      padding: 4px 0 8px 40px;
    }
    .mobile-menu-item[data-open="true"] .mobile-menu-sub{
      display: block;
    }
    .mobile-menu-sublink{
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      color: rgba(255,255,255,.65);
      text-decoration: none;
      font-size: 14px;
      border-radius: 10px;
      transition: all .15s ease;
    }
    .mobile-menu-sublink:hover{
      background: rgba(255,255,255,.06);
      color: #fff;
    }
    .mobile-menu-sublink-icon{
      font-size: 16px;
    }
    /* Bottom bar in menu with dark buttons */
    .mobile-menu-footer{
      padding: 12px;
      border-top: 1px solid rgba(255,255,255,.08);
      display: flex;
      gap: 6px;
      flex-shrink: 0;
      background: rgba(0,0,0,.2);
    }
    .mobile-menu-footer-btn{
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 5px;
      padding: 10px 8px;
      background: rgba(255,255,255,.06);
      border: none;
      border-radius: 12px;
      color: rgba(255,255,255,.7);
      font-size: 10px;
      text-decoration: none;
      cursor: pointer;
      transition: all .2s ease;
    }
    .mobile-menu-footer-btn:hover{
      background: rgba(255,255,255,.12);
      color: #fff;
    }
    .mobile-menu-footer-btn svg{
      width: 20px;
      height: 20px;
    }
    /* Simple link style for Tarification */
    .mobile-menu-simple-link{
      display: flex;
      align-items: center;
      padding: 14px 12px;
      color: #fff;
      text-decoration: none;
      font-size: 15px;
      font-weight: 500;
      border-radius: 12px;
      transition: background .15s ease;
    }
    .mobile-menu-simple-link:hover{
      background: rgba(255,255,255,.06);
    }
    .mobile-menu-simple-link .mobile-menu-icon{
      margin-right: 12px;
    }
    .mobile-menu-simple-link svg{
      width: 18px;
      height: 18px;
      opacity: .5;
      margin-left: auto;
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
      background: #ecfdf5; 
      color: #047857;
    }
    .reg-result.warn { 
      background: #fffbeb; 
      color: #b45309;
    }
    .reg-result.danger { 
      background: #fef2f2; 
      color: #dc2626;
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
      
      .reg-form {
        flex-direction: column;
        width: 100%;
      }

      .reg-input{
        width: 100%;
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
      overflow: hidden;
      background: linear-gradient(135deg, #fdf2f8, #fce7f3);
    }

    .t-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform .5s ease;
    }

    .t-item:hover .t-media img {
      transform: scale(1.05);
    }

    .t-media::after {
      content: "";
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 80px;
      background: linear-gradient(to top, rgba(255,255,255,1), transparent);
      pointer-events: none;
    }

    .t-content {
      padding: 20px 24px 24px;
      margin-top: -20px;
      position: relative;
      text-align: left;
      z-index: 2;
    }

    .t-kicker {
      display: inline-block;
      font-size: 11px;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: var(--pink);
      font-weight: 800;
      margin-bottom: 8px;
      padding: 6px 12px;
      background: linear-gradient(135deg, #fdf2f8, #fce7f3);
      border-radius: 100px;
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
      background: #f1f5f9;
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


    /* ===== FOOTER ===== */
    .site-footer{
      width: 100vw;
      position: relative;
      left: 50%;
      right: 50%;
      margin-left: -50vw;
      margin-right: -50vw;
      background: #0f172a;
      color: #ffffff;
      padding: 80px 0 0;
      margin-top: 0;\r\n      border-top: 1px solid rgba(255,255,255,.08);
    }
    
    .footer-container{
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 var(--side-pad);
    }
    
    .footer-content{
      display: grid;
      grid-template-columns: 2fr 1fr 1fr;
      gap: 60px;
      padding-bottom: 60px;
      border-bottom: 1px solid rgba(255,255,255,.1);
    }
    
    .footer-left{
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    
    .footer-brand{
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .footer-logo{
      width: 48px;
      height: 48px;
      background: var(--pink);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .footer-logo-icon{
      font-size: 28px;
    }
    
    .footer-brand-name{
      font-size: 24px;
      font-weight: 700;
      color: #ffffff;
    }
    
    .footer-tagline{
      font-size: 16px;
      color: rgba(255,255,255,.7);
      margin: 0;
      max-width: 400px;
      line-height: 1.5;
    }
    
    .footer-center{
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    
    .footer-title{
      font-size: 18px;
      font-weight: 600;
      color: #ffffff;
      margin: 0;
    }
    
    .footer-socials{
      display: flex;
      gap: 12px;
    }
    
    .social-link{
      width: 48px;
      height: 48px;
      background: rgba(255,255,255,.08);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      transition: all .2s ease;
      text-decoration: none;
    }
    
    .social-link:hover{
      background: var(--pink);
      transform: translateY(-3px);
      box-shadow: 0 8px 20px rgba(236,72,153,.3);
    }
    
    .social-link svg{
      width: 24px;
      height: 24px;
    }
    
    .footer-right{
      display: flex;
      align-items: flex-start;
      justify-content: flex-end;
    }
    
    .footer-contact-btn{
      padding: 14px 28px;
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
    
    .footer-contact-btn:hover{
      background: var(--pink-dark);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(236,72,153,.35);
      gap: 12px;
    }
    
    .footer-contact-btn svg{
      transition: transform .2s ease;
    }
    
    .footer-contact-btn:hover svg{
      transform: translateX(4px);
    }
    
    .footer-bottom{
      padding: 30px 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 20px;
    }
    
    .footer-copyright{
      font-size: 14px;
      color: rgba(255,255,255,.6);
      margin: 0;
    }
    
    .footer-links{
      display: flex;
      gap: 12px;
      align-items: center;
    }
    
    .footer-links a{
      font-size: 14px;
      color: rgba(255,255,255,.7);
      text-decoration: none;
      transition: color .2s ease;
    }
    
    .footer-links a:hover{
      color: var(--pink);
    }
    
    .footer-separator{
      color: rgba(255,255,255,.3);
      font-size: 14px;
    }
    
    @media (max-width: 980px){
      .site-footer{
        padding: 60px 0 0;
        margin-top: 0;\r\n      border-top: 1px solid rgba(255,255,255,.08);
      }
      
      .footer-content{
        grid-template-columns: 1fr;
        gap: 40px;
        padding-bottom: 40px;
      }
      
      .footer-right{
        justify-content: flex-start;
      }
      
      .footer-contact-btn{
        width: 100%;
        justify-content: center;
      }
      
      .footer-bottom{
        flex-direction: column;
        align-items: flex-start;
        padding: 20px 0;
      }
      
      .footer-links{
        flex-wrap: wrap;
      }
    }
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
      padding: 100px var(--side-pad);
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 80px;
      align-items: center;
    }
    
    .community-image{
      position: relative;
    }
    
    .community-image img{
      width: 100%;
      height: auto;
      display: block;
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,.4);
    }
    
    .community-content{
      color: #ffffff;
    }
    
    .community-title{
      font-size: clamp(32px, 4vw, 52px);
      font-weight: 700;
      line-height: 1.1;
      margin: 0 0 24px 0;
      letter-spacing: -0.02em;
    }
    
    .community-text{
      font-size: 18px;
      line-height: 1.6;
      color: rgba(255,255,255,.85);
      margin: 0 0 32px 0;
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
      padding: 64px var(--side-pad) 72px;
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
      border: 1px solid rgba(255,255,255,.16);
      color: #ffffff;
      text-decoration: none;
      overflow: hidden;
      min-height: 220px;
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
        gap: 60px;
        padding: 80px var(--side-pad);
      }
      
      .community-title{
        font-size: 32px;
      }
      
      .community-text{
        font-size: 16px;
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

</style>
</head>

<body>

  <!-- NAV -->
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

      <!-- Desktop links -->
      <nav id="nav-links" class="links" aria-label="Navigation principale">
        <ul class="menu">
          <li class="item"><a class="link" href="accueil.php">Accueil</a></li>
          
          <!-- Menu Actualités -->
          <li class="item" data-menu="actualites">
            <button class="trigger" type="button" aria-haspopup="true" aria-expanded="false">
              Actualités
              <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>

            <div class="mega" role="menu" aria-hidden="true">
              <div class="mega-grid">
                <!-- Colonne gauche : liens -->
                <div class="mega-content">
                  <div class="mega-section">
                    <div class="mega-title">Dernières actualités</div>
                    <ul class="mega-list">
                      <?php if (!empty($actualites)): ?>
                        <?php foreach ($actualites as $actu): ?>
                          <li>
                            <a class="mega-link" href="actualite.php?id=<?= $actu['id'] ?>">
                              <span class="micon">📰</span>
                              <div class="mega-link-content">
                                <div class="mtitle"><?= htmlspecialchars($actu['title']) ?></div>
                              </div>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <li>
                          <a class="mega-link" href="actualites.php">
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
                  <a href="actualites.php" class="mega-featured-link">
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
              Photos
              <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>

            <div class="mega" role="menu" aria-hidden="true">
              <div class="mega-grid">
                <div class="mega-content">
                  <div class="mega-section">
                    <div class="mega-title">Albums photos</div>
                    <ul class="mega-list">
                      <?php if (!empty($galeries)): ?>
                        <?php foreach ($galeries as $galerie): ?>
                          <li>
                            <a class="mega-link" href="photos.php?year_id=<?= $galerie['id'] ?>">
                              <span class="micon">📸</span>
                              <div class="mega-link-content">
                                <div class="mtitle"><?= htmlspecialchars($galerie['title']) ?> (<?= $galerie['year'] ?>)</div>
                              </div>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <li>
                          <a class="mega-link" href="photos.php">
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
                  <a href="photos.php" class="mega-featured-link">
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
              Partenaires
              <svg class="chev" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="rgba(15,23,42,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>

            <div class="mega" role="menu" aria-hidden="true">
              <div class="mega-grid">
                <div class="mega-content">
                  <div class="mega-section">
                    <div class="mega-title">Nos partenaires</div>
                    <ul class="mega-list">
                      <?php if (!empty($partenaires)): ?>
                        <?php foreach ($partenaires as $part): ?>
                          <li>
                            <a class="mega-link" href="partenaires.php?year_id=<?= $part['id'] ?>">
                              <span class="micon">🤝</span>
                              <div class="mega-link-content">
                                <div class="mtitle"><?= htmlspecialchars($part['title']) ?> (<?= $part['year'] ?>)</div>
                              </div>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <li>
                          <a class="mega-link" href="partenaires.php">
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
                  <a href="partenaires.php" class="mega-featured-link">
                    Voir tout
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                </div>
              </div>
            </div>
          </li>
        </ul>

        <div class="cta">
          <a class="btn pink" href="register.php">Inscription<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
        </div>
      </nav>
    </div>
  </header>

  <!-- ===== MOBILE HEADER (Vimeo style) ===== -->
  <header class="mobile-header" id="mobileHeader">
    <a class="brand" href="accueil.php">
      <img class="brand-logo" src="../files/_logos/logo_fer_rose.png" alt="Forbach en Rose">
    </a>
  </header>

  <!-- ===== MOBILE BOTTOM BAR (Vimeo style) ===== -->
  <div class="mobile-bottom-bar" id="mobileBottomBar">
    <div class="mobile-bottom-wrapper">
      <div class="mobile-bottom-inner">
        <button class="mobile-bottom-btn" id="mobileMenuBtn" aria-label="Menu">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
          <span>Menu</span>
        </button>
        <a class="mobile-bottom-btn" href="accueil.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9,22 9,12 15,12 15,22"></polyline>
          </svg>
          <span>Accueil</span>
        </a>
        <a class="mobile-bottom-btn" href="parcours.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polygon points="10,8 16,12 10,16 10,8"></polygon>
          </svg>
          <span>Parcours</span>
        </a>
      </div>
      <a class="mobile-bottom-cta" href="register.php">Inscription</a>
    </div>
  </div>

  <!-- ===== MOBILE MENU BACKDROP ===== -->
  <div class="mobile-menu-backdrop" id="mobileMenuBackdrop"></div>

  <!-- ===== MOBILE MENU POPUP (Vimeo floating card style) ===== -->
  <div class="mobile-menu-popup" id="mobileMenuPopup" aria-hidden="true">
    <div class="mobile-menu-header">
      <span class="mobile-menu-title">Menu</span>
      <button class="mobile-menu-close" id="mobileMenuClose" aria-label="Fermer">✕</button>
    </div>
    
    <div class="mobile-menu-body">
      <nav class="mobile-menu-nav">
        <!-- Actualités -->
        <div class="mobile-menu-item" data-open="false">
          <button class="mobile-menu-trigger">
            <div class="mobile-menu-trigger-content">
              <span class="mobile-menu-icon">📰</span>
              Actualités
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M6 9l6 6 6-6"/>
            </svg>
          </button>
          <div class="mobile-menu-sub">
            <?php if (!empty($actualites)): ?>
              <?php foreach ($actualites as $actu): ?>
                <a class="mobile-menu-sublink" href="actualite.php?id=<?= $actu['id'] ?>">
                  <span class="mobile-menu-sublink-icon">📄</span>
                  <?= htmlspecialchars($actu['title']) ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
            <a class="mobile-menu-sublink" href="actualites.php">
              <span class="mobile-menu-sublink-icon">→</span>
              Voir toutes les actualités
            </a>
          </div>
        </div>

        <!-- Photos -->
        <div class="mobile-menu-item" data-open="false">
          <button class="mobile-menu-trigger">
            <div class="mobile-menu-trigger-content">
              <span class="mobile-menu-icon">📸</span>
              Photos
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M6 9l6 6 6-6"/>
            </svg>
          </button>
          <div class="mobile-menu-sub">
            <?php if (!empty($galeries)): ?>
              <?php foreach ($galeries as $galerie): ?>
                <a class="mobile-menu-sublink" href="photos.php?year_id=<?= $galerie['id'] ?>">
                  <span class="mobile-menu-sublink-icon">🖼️</span>
                  <?= htmlspecialchars($galerie['title']) ?> (<?= $galerie['year'] ?>)
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
            <a class="mobile-menu-sublink" href="photos.php">
              <span class="mobile-menu-sublink-icon">→</span>
              Voir tous les albums
            </a>
          </div>
        </div>

        <!-- Partenaires -->
        <div class="mobile-menu-item" data-open="false">
          <button class="mobile-menu-trigger">
            <div class="mobile-menu-trigger-content">
              <span class="mobile-menu-icon">🤝</span>
              Partenaires
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M6 9l6 6 6-6"/>
            </svg>
          </button>
          <div class="mobile-menu-sub">
            <?php if (!empty($partenaires)): ?>
              <?php foreach ($partenaires as $part): ?>
                <a class="mobile-menu-sublink" href="partenaires.php?year_id=<?= $part['id'] ?>">
                  <span class="mobile-menu-sublink-icon">🏢</span>
                  <?= htmlspecialchars($part['title']) ?> (<?= $part['year'] ?>)
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
            <a class="mobile-menu-sublink" href="partenaires.php">
              <span class="mobile-menu-sublink-icon">→</span>
              Voir tous les partenaires
            </a>
          </div>
        </div>

        <!-- Tarification (simple link) -->
        <a class="mobile-menu-simple-link" href="register.php">
          <span class="mobile-menu-icon">🏷️</span>
          Tarification
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 18l6-6-6-6"/>
          </svg>
        </a>
      </nav>
    </div>

    <div class="mobile-menu-footer">
      <a class="mobile-menu-footer-btn" href="accueil.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
          <polyline points="9,22 9,12 15,12 15,22"></polyline>
        </svg>
        <span>Accueil</span>
      </a>
      <a class="mobile-menu-footer-btn" href="parcours.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"></circle>
          <polygon points="10,8 16,12 10,16 10,8"></polygon>
        </svg>
        <span>Parcours</span>
      </a>
      <?php if (!empty($link_instagram)): ?>
      <a class="mobile-menu-footer-btn" href="<?= htmlspecialchars($link_instagram) ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
          <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
          <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
        </svg>
        <span>Instagram</span>
      </a>
      <?php endif; ?>
    </div>
  </div>

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
              <div class="demo-kicker">FORBACH EN ROSE</div>
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
              <a class="cta-pink" href="#">Je m’inscris →</a>
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
      <div class="community-container">
        <div class="community-image">
          <img src="../files/_pictures/<?= htmlspecialchars($picture_partner) ?>" alt="Nos partenaires - Forbach en Rose">
        </div>
        
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

<section class="reg-bar" aria-label="Inscriptions">
      <div class="reg-card">
        <div class="reg-count">
          <div class="reg-kicker">Déjà inscrits</div>
          <div class="reg-value"><?= number_format((int)$count, 0, ',', ' ') ?></div>
        </div>

        <div class="reg-search">
          <div class="reg-title">Vérifier mon inscription</div>
          <form class="reg-form" method="get" action="accueil.php">
            <input type="hidden" name="check_registration" value="1">
            <input class="reg-input" type="email" name="search_email" placeholder="Votre adresse email"
                  value="<?= htmlspecialchars($searchEmail) ?>" autocomplete="email" required>
            <button class="reg-submit" type="submit">Vérifier →</button>
          </form>

          <?php if ($searchMessage !== ''): ?>
            <p class="reg-result <?= htmlspecialchars($searchStatus) ?>" aria-live="polite">
              <?= htmlspecialchars($searchMessage) ?>
            </p>
          <?php else: ?>
            <p class="reg-hint">Saisissez l'email utilisé lors de votre inscription.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>
    
<!-- TIMELINE (below video) -->
    <div class="timeline-wrap">
      <section class="timeline" aria-label="Timeline">
        <div class="timeline-head">
          <h2 class="timeline-title">Historique</h2>
          <p class="timeline-sub">Bilan annuel des montants collectés lors de la course solidaire.</p>
        </div>

        <div class="timeline-track">
          <!-- SVG S-Curve -->
          <svg class="timeline-svg" viewBox="0 0 200 800" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="gradient-line" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" stop-color="#fce7f3" />
                <stop offset="50%" stop-color="#ec4899" />
                <stop offset="100%" stop-color="#db2777" />
              </linearGradient>
            </defs>
            <path
              class="timeline-path"
              d="M 100 0 C 100 80, 190 120, 190 200 S 10 280, 10 400 S 190 480, 190 600 S 10 680, 10 800"
            />
          </svg>

          <div class="timeline-items">
            <div class="t-item left">
              <span class="t-dot" aria-hidden="true"></span>
              <article class="t-card">
                <div class="t-media">
                  <img src="../files/_pictures/img_6873979ef27ef6.61742701.jpg" alt="Édition 2024">
                </div>
                <div class="t-content">
                  <div class="t-kicker">ZEVENT 2024</div>
                  <div class="t-amount">10 145 881 €</div>
                  <div class="t-meta">
                    <span class="t-pill">05–08 sept.</span>
                    <span class="t-pill">Les Bureaux du Cœur</span>
                    <span class="t-pill">Solidarité Paysans</span>
                  </div>
                </div>
              </article>
            </div>

            <div class="t-item right">
              <span class="t-dot" aria-hidden="true"></span>
              <article class="t-card">
                <div class="t-media">
                  <img src="../files/_pictures/img_6873979ef35a25.30199021.png" alt="Inscriptions">
                </div>
                <div class="t-content">
                  <div class="t-kicker">INSCRIPTIONS</div>
                  <div class="t-amount">Déjà 16 inscrits</div>
                  <div class="t-meta">
                    <span class="t-pill">Objectif: 200</span>
                    <span class="t-pill">Course + marche</span>
                    <span class="t-pill">Dons reversés</span>
                  </div>
                </div>
              </article>
            </div>

            <div class="t-item left">
              <span class="t-dot" aria-hidden="true"></span>
              <article class="t-card">
                <div class="t-media">
                  <img src="../files/_pictures/img_687397ae324891.78948445.jpg" alt="Jour J">
                </div>
                <div class="t-content">
                  <div class="t-kicker">JOUR J</div>
                  <div class="t-amount">05 juillet 2026</div>
                  <div class="t-meta">
                    <span class="t-pill">Départ 09:00</span>
                    <span class="t-pill">Parcours découverte</span>
                    <span class="t-pill">Accueil participants</span>
                  </div>
                </div>
              </article>
            </div>

            <div class="t-item right">
              <span class="t-dot" aria-hidden="true"></span>
              <article class="t-card">
                <div class="t-media">
                  <img src="../files/_pictures/ob_75a84d_img-0002.jpg" alt="Après course">
                </div>
                <div class="t-content">
                  <div class="t-kicker">APRÈS-COURSE</div>
                  <div class="t-amount">Remise des dons</div>
                  <div class="t-meta">
                    <span class="t-pill">Merci aux participants</span>
                    <span class="t-pill">Merci aux partenaires</span>
                  </div>
                </div>
              </article>
            </div>
          </div>
        </div>
      </section>
    </div>

  </main>


  

<!-- NEWS BAND (latest news) -->
  <section class="news-band" aria-label="Dernières actualités">
    <div class="news-band-container">
      <div class="news-band-head">
        <h3 class="news-band-title">Dernières actualités</h3>
        <a class="news-band-link" href="actualites.php">Voir tout</a>
      </div>
      <div class="news-grid">
        <?php $news_cards = array_slice($actualites, 0, 4); ?>
        <?php if (!empty($news_cards)): ?>
          <?php foreach ($news_cards as $actu): ?>
            <?php
              $imgFile = $actu['img_article'] ?? '';
              $imgPath = '../files/_news/' . $imgFile;
              $hasImage = !empty($imgFile) && is_file($imgPath);
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
            <a class="news-card" href="actualite.php?id=<?= $actu['id'] ?>">
              <?php if ($hasImage): ?>
                <div class="news-media">
                  <img src="<?= htmlspecialchars($imgPath) ?>" alt="<?= htmlspecialchars($actu['title']) ?>">
                </div>
              <?php endif; ?>
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

<!-- FOOTER -->
  <footer class="site-footer">
    <div class="footer-container">
      <div class="footer-content">
        <div class="footer-left">
          <div class="footer-brand">
            <div class="footer-logo">
              <span class="footer-logo-icon">🏃</span>
            </div>
            <span class="footer-brand-name">Forbach en Rose</span>
          </div>
          <p class="footer-tagline">Courir ensemble pour la lutte contre le cancer</p>
        </div>
        
        <div class="footer-center">
          <h3 class="footer-title">Suivez-nous</h3>
          <div class="footer-socials">
            <a href="https://facebook.com" target="_blank" rel="noopener" class="social-link" aria-label="Facebook">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
              </svg>
            </a>
            <a href="https://instagram.com" target="_blank" rel="noopener" class="social-link" aria-label="Instagram">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
              </svg>
            </a>
            <a href="https://twitter.com" target="_blank" rel="noopener" class="social-link" aria-label="Twitter">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
              </svg>
            </a>
            <a href="https://youtube.com" target="_blank" rel="noopener" class="social-link" aria-label="YouTube">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
              </svg>
            </a>
          </div>
        </div>
        
        <div class="footer-right">
          <a href="contact.php" class="footer-contact-btn">
            Contactez-nous
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
        </div>
      </div>
      
      <div class="footer-bottom">
        <p class="footer-copyright">
          © 2026 Forbach en Rose. Tous droits réservés. | Association loi 1901
        </p>
        <div class="footer-links">
          <a href="mentions-legales.php">Mentions légales</a>
          <span class="footer-separator">•</span>
          <a href="politique-confidentialite.php">Politique de confidentialité</a>
        </div>
      </div>
    </div>
  </footer>

  <script>
    // ===== Mega menu style Engine - centré et simple =====
    (function(){
      const overlay = document.getElementById('megaOverlay');
      const items = Array.from(document.querySelectorAll('.item[data-menu]'));
      const isMobile = () => window.matchMedia('(max-width: 980px)').matches;

      let currentItem = null;
      let enterTimer = null;
      let leaveTimer = null;

      function switchToMenu(newItem){
        if(currentItem === newItem) return;

        if(currentItem){
          currentItem.dataset.open = 'false';
        }

        currentItem = newItem;
        newItem.dataset.open = 'true';

        if(overlay && !overlay.classList.contains('active')){
          overlay.classList.add('active');
        }
      }

      function closeAllMenus(){
        if(!currentItem) return;

        currentItem.dataset.open = 'false';
        currentItem = null;

        if(overlay) overlay.classList.remove('active');
      }

      items.forEach(item => {
        const trigger = item.querySelector('.trigger');
        const mega = item.querySelector('.mega');
        if(!trigger || !mega) return;

        item.addEventListener('mouseenter', () => {
          if(isMobile()) return;

          clearTimeout(leaveTimer);
          clearTimeout(enterTimer);

          if(currentItem){
            switchToMenu(item);
          } else {
            enterTimer = setTimeout(() => {
              switchToMenu(item);
            }, 100);
          }
        });

        item.addEventListener('mouseleave', () => {
          if(isMobile()) return;

          clearTimeout(enterTimer);
          clearTimeout(leaveTimer);
          leaveTimer = setTimeout(closeAllMenus, 200);
        });

        mega.addEventListener('mouseenter', () => {
          if(isMobile()) return;
          clearTimeout(leaveTimer);
        });

        mega.addEventListener('mouseleave', () => {
          if(isMobile()) return;
          clearTimeout(leaveTimer);
          leaveTimer = setTimeout(closeAllMenus, 200);
        });

        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          if(isMobile()) return;

          if(currentItem === item){
            closeAllMenus();
          } else {
            switchToMenu(item);
          }
        });
      });

      if(overlay){
        overlay.addEventListener('click', closeAllMenus);
      }

      document.addEventListener('click', (e) => {
        if(isMobile()) return;

        let inside = false;
        items.forEach(item => {
          if(item.contains(e.target)) inside = true;
          const mega = item.querySelector('.mega');
          if(mega && mega.contains(e.target)) inside = true;
        });

        if(!inside) closeAllMenus();
      });

      document.addEventListener('keydown', (e) => {
        if(e.key === 'Escape') closeAllMenus();
      });

      window.addEventListener('resize', closeAllMenus);
    })();

    // ===== NEW MOBILE MENU SYSTEM (Vimeo style) =====
    (function(){
      const mobileHeader = document.getElementById('mobileHeader');
      const mobileBottomBar = document.getElementById('mobileBottomBar');
      const mobileMenuBtn = document.getElementById('mobileMenuBtn');
      const mobileMenuBackdrop = document.getElementById('mobileMenuBackdrop');
      const mobileMenuPopup = document.getElementById('mobileMenuPopup');
      const mobileMenuClose = document.getElementById('mobileMenuClose');
      
      if(!mobileHeader || !mobileBottomBar) return;
      
      function isMobile(){ return window.matchMedia('(max-width: 980px)').matches; }
      
      let lastScrollY = 0;
      const scrollThreshold = 80;
      
      // Handle scroll - hide/show header and transform bottom bar
      function handleMobileScroll(){
        if(!isMobile()) return;
        
        const currentScrollY = window.scrollY;
        
        if(currentScrollY > scrollThreshold){
          mobileHeader.classList.add('hidden');
          mobileBottomBar.classList.add('header-hidden');
        } else {
          mobileHeader.classList.remove('hidden');
          mobileBottomBar.classList.remove('header-hidden');
        }
        
        lastScrollY = currentScrollY;
      }
      
      // Throttled scroll handler
      let ticking = false;
      window.addEventListener('scroll', () => {
        if(!ticking){
          window.requestAnimationFrame(() => {
            handleMobileScroll();
            ticking = false;
          });
          ticking = true;
        }
      });
      
      // Initial check
      handleMobileScroll();
      
      // Open menu popup
      function openMobileMenu(){
        mobileMenuBackdrop.classList.add('open');
        mobileMenuPopup.classList.add('open');
        mobileMenuPopup.setAttribute('aria-hidden', 'false');
        mobileBottomBar.classList.add('menu-open');
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
      }
      
      // Close menu popup
      function closeMobileMenu(){
        mobileMenuBackdrop.classList.remove('open');
        mobileMenuPopup.classList.remove('open');
        mobileMenuPopup.setAttribute('aria-hidden', 'true');
        mobileBottomBar.classList.remove('menu-open');
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
      }
      
      // Menu button click
      if(mobileMenuBtn){
        mobileMenuBtn.addEventListener('click', openMobileMenu);
      }
      
      // Close button click
      if(mobileMenuClose){
        mobileMenuClose.addEventListener('click', closeMobileMenu);
      }
      
      // Click on backdrop to close
      if(mobileMenuBackdrop){
        mobileMenuBackdrop.addEventListener('click', closeMobileMenu);
      }
      
      // Escape key
      document.addEventListener('keydown', (e) => {
        if(e.key === 'Escape' && mobileMenuPopup.classList.contains('open')){
          closeMobileMenu();
        }
      });
      
      // Resize handler
      window.addEventListener('resize', () => {
        if(!isMobile()){
          closeMobileMenu();
          mobileHeader.classList.remove('hidden');
          mobileBottomBar.classList.remove('header-hidden');
        }
        handleMobileScroll();
      });
      
      // Accordion for menu items
      const menuItems = document.querySelectorAll('.mobile-menu-item');
      menuItems.forEach(item => {
        const trigger = item.querySelector('.mobile-menu-trigger');
        if(trigger){
          trigger.addEventListener('click', () => {
            const isOpen = item.dataset.open === 'true';
            // Close all other items
            menuItems.forEach(other => {
              if(other !== item) other.dataset.open = 'false';
            });
            // Toggle current
            item.dataset.open = isOpen ? 'false' : 'true';
          });
        }
      });
      
      // Close menu when clicking a link
      const menuLinks = mobileMenuPopup.querySelectorAll('a');
      menuLinks.forEach(link => {
        link.addEventListener('click', () => {
          setTimeout(closeMobileMenu, 100);
        });
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

        const bottomInner = bottomBar.querySelector('.mobile-bottom-inner') || bottomBar;
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

  <script>
    // ===== Gestion nav fixe -> flottante au scroll =====
    (function(){
      let lastScroll = 0;
      const scrollThreshold = 50; // Pixel de scroll avant transition
      const isMobile = () => window.matchMedia('(max-width: 980px)').matches;

      function handleNavScroll() {
        if (isMobile()) {
          document.body.classList.remove('nav-scrolled');
          return;
        }
        const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
        if (currentScroll > scrollThreshold) {
          document.body.classList.add('nav-scrolled');
        } else {
          document.body.classList.remove('nav-scrolled');
        }
        
        lastScroll = currentScroll;
      }

      // Écouter le scroll avec throttle pour performance
      let ticking = false;
      window.addEventListener('scroll', function() {
        if (!ticking) {
          window.requestAnimationFrame(function() {
            handleNavScroll();
            ticking = false;
          });
          ticking = true;
        }
      });

      // Vérifier au chargement
      handleNavScroll();

      window.addEventListener('resize', handleNavScroll);
    })();
  </script>

</body>
</html>
