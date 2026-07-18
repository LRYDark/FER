<?php
/**
 * theme.php — Génère les CSS variables du thème depuis la BDD
 * Inclure ce fichier dans le <head> ou juste après le <body> de chaque page.
 * Usage : <?php include __DIR__ . '/../src/content/theme.php'; ?> (admin)
 *         <?php include __DIR__ . '/src/content/theme.php'; ?> (public root)
 */

// ── Charger les settings si pas déjà fait ──
if (!isset($pdo)) return;

if (!isset($__themeLoaded)) {
    $__themeLoaded = true;

    // Lire les colonnes thème (avec fallback si colonnes absentes)
    $__theme = [
        'primary'   => '#db2777',
        'secondary' => '#0f172a',
        'radius'    => 12,
        'font'      => 'Inter',
    ];

    $__theme['dark_primary']   = '#f472b6';
    $__theme['dark_secondary'] = '#e2e8f0';

    try {
        $__stmt = $pdo->query("SELECT theme_primary_color, theme_secondary_color, theme_dark_primary_color, theme_dark_secondary_color, theme_border_radius, theme_font_family FROM setting WHERE id = 1 LIMIT 1");
        $__row = $__stmt->fetch(PDO::FETCH_ASSOC);
        if ($__row) {
            $__theme['primary']        = $__row['theme_primary_color']        ?: '#db2777';
            $__theme['secondary']      = $__row['theme_secondary_color']      ?: '#0f172a';
            $__theme['dark_primary']   = $__row['theme_dark_primary_color']   ?? '#f472b6';
            $__theme['dark_secondary'] = $__row['theme_dark_secondary_color'] ?? '#e2e8f0';
            $__theme['radius']         = (int)($__row['theme_border_radius']  ?? 12);
            $__theme['font']           = $__row['theme_font_family']          ?: 'Inter';
        }
    } catch (PDOException $e) {
        // Colonnes pas encore créées, on utilise les defaults
    }

    /**
     * Calcule la luminance relative d'une couleur hex
     * Retourne une valeur entre 0 (noir) et 1 (blanc)
     */
    function themeRelativeLuminance(string $hex): float {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        // Linearize sRGB
        $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Assombrit une couleur hex d'un facteur (0-1)
     */
    function themeDarken(string $hex, float $factor = 0.15): string {
        $hex = ltrim($hex, '#');
        $r = max(0, (int)(hexdec(substr($hex, 0, 2)) * (1 - $factor)));
        $g = max(0, (int)(hexdec(substr($hex, 2, 2)) * (1 - $factor)));
        $b = max(0, (int)(hexdec(substr($hex, 4, 2)) * (1 - $factor)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Éclaircit une couleur hex d'un facteur (0-1)
     */
    function themeLighten(string $hex, float $factor = 0.9): string {
        $hex = ltrim($hex, '#');
        $r = min(255, (int)(hexdec(substr($hex, 0, 2)) + (255 - hexdec(substr($hex, 0, 2))) * $factor));
        $g = min(255, (int)(hexdec(substr($hex, 2, 2)) + (255 - hexdec(substr($hex, 2, 2))) * $factor));
        $b = min(255, (int)(hexdec(substr($hex, 4, 2)) + (255 - hexdec(substr($hex, 4, 2))) * $factor));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    // Calculer les couleurs dérivées — Light
    $__primaryText   = themeRelativeLuminance($__theme['primary'])   > 0.4 ? '#1e293b' : '#ffffff';
    $__secondaryText = themeRelativeLuminance($__theme['secondary']) > 0.4 ? '#1e293b' : '#ffffff';
    $__primaryHover  = themeDarken($__theme['primary'], 0.15);
    $__secondaryHover = themeDarken($__theme['secondary'], 0.15);
    $__primaryLight  = themeLighten($__theme['primary'], 0.85);
    $__secondaryLight = themeLighten($__theme['secondary'], 0.85);

    // Calculer les couleurs dérivées — Dark
    $__darkPrimaryText   = themeRelativeLuminance($__theme['dark_primary'])   > 0.4 ? '#1e293b' : '#ffffff';
    $__darkSecondaryText = themeRelativeLuminance($__theme['dark_secondary']) > 0.4 ? '#1e293b' : '#ffffff';
    $__darkPrimaryHover  = themeLighten($__theme['dark_primary'], 0.2);
    $__darkSecondaryHover = themeLighten($__theme['dark_secondary'], 0.2);
    $__darkPrimaryLight  = themeDarken($__theme['dark_primary'], 0.7);
    $__darkSecondaryLight = themeDarken($__theme['dark_secondary'], 0.7);

    // Map des polices
    $__fontMap = [
        'system-ui'         => "system-ui, -apple-system, 'Segoe UI', sans-serif",
        'Inter'             => "'Inter', sans-serif",
        'Poppins'           => "'Poppins', sans-serif",
        'Roboto'            => "'Roboto', sans-serif",
        'Open Sans'         => "'Open Sans', sans-serif",
        'Montserrat'        => "'Montserrat', sans-serif",
        'Lato'              => "'Lato', sans-serif",
        'Nunito'            => "'Nunito', sans-serif",
        'Raleway'           => "'Raleway', sans-serif",
        'Source Sans 3'     => "'Source Sans 3', sans-serif",
        'Work Sans'         => "'Work Sans', sans-serif",
        'DM Sans'           => "'DM Sans', sans-serif",
        'Outfit'            => "'Outfit', sans-serif",
        'Plus Jakarta Sans' => "'Plus Jakarta Sans', sans-serif",
        'Manrope'           => "'Manrope', sans-serif",
        'Figtree'           => "'Figtree', sans-serif",
        'Quicksand'         => "'Quicksand', sans-serif",
        'Cabin'             => "'Cabin', sans-serif",
        'Rubik'             => "'Rubik', sans-serif",
        'Karla'             => "'Karla', sans-serif",
    ];
    // Ajouter les fonts custom du dossier fonts/
    $__customFonts = getCustomFonts();
    foreach ($__customFonts as $__cfName => $__cfPath) {
        $__fontMap[$__cfName] = "'" . $__cfName . "', sans-serif";
    }
    $__fontStack = $__fontMap[$__theme['font']] ?? $__fontMap['system-ui'];
    $__isCustomFont = isset($__customFonts[$__theme['font']]);
    $__needsGoogleFont = !$__isCustomFont && ($__theme['font'] !== 'system-ui');
}

// ── Output ──
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?= getTinyMceGoogleFontsUrl() ?>" rel="stylesheet">
<?php if (!empty($__customFonts)):
  $__prefix = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/public/') !== false || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/inc/') !== false) ? '../' : '';
  $__formatMap = ['otf' => 'opentype', 'woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype'];
?>
<style>
<?php foreach ($__customFonts as $__cfName => $__cfPath):
  $__ext = pathinfo($__cfPath, PATHINFO_EXTENSION);
  $__format = $__formatMap[$__ext] ?? 'truetype';
?>
@font-face {
  font-family: '<?= htmlspecialchars($__cfName) ?>';
  src: url('<?= $__prefix . htmlspecialchars($__cfPath) ?>') format('<?= $__format ?>');
  font-weight: normal;
  font-style: normal;
  font-display: swap;
}
<?php endforeach; ?>
</style>
<?php endif; ?>
<style id="theme-vars">
:root {
  --primary: <?= $__theme['primary'] ?>;
  --primary-hover: <?= $__primaryHover ?>;
  --primary-text: <?= $__primaryText ?>;
  --primary-light: <?= $__primaryLight ?>;
  --secondary: <?= $__theme['secondary'] ?>;
  --secondary-hover: <?= $__secondaryHover ?>;
  --secondary-text: <?= $__secondaryText ?>;
  --secondary-light: <?= $__secondaryLight ?>;
  --radius: <?= $__theme['radius'] ?>px;
  --radius-sm: <?= max(0, $__theme['radius'] - 4) ?>px;
  --radius-lg: <?= $__theme['radius'] > 0 ? $__theme['radius'] + 4 : 0 ?>px;
  --font-family: <?= $__fontStack ?>;
}
<?php if (empty($themeVarsOnly)) : /* ── Overrides composants : SITE PUBLIC uniquement.
   L'admin (navbar-admin définit $themeVarsOnly = true) ne reçoit QUE les
   variables ci-dessus — sinon ces !important (boutons, liens, radius, police)
   écraseraient le thème jr-theme de l'administration. ── */ ?>

/* ═══ Global font ═══ */
body, .form-control, .form-select, .btn, .nav-link, .modal-content, .card {
  font-family: var(--font-family) !important;
}

/* ═══ Primary color overrides ═══ */
.btn-rose,
.btn-rose:focus {
  background: linear-gradient(135deg, var(--primary), var(--primary-hover)) !important;
  color: var(--primary-text) !important;
  border: none !important;
}
.btn-rose:hover {
  background: linear-gradient(135deg, var(--primary-hover), var(--primary-hover)) !important;
  color: var(--primary-text) !important;
}

/* ═══ Secondary color (Bootstrap btn-primary) overrides ═══ */
.btn-primary {
  background-color: var(--secondary) !important;
  border-color: var(--secondary) !important;
  color: var(--secondary-text) !important;
}
.btn-primary:hover, .btn-primary:focus, .btn-primary:active {
  background-color: var(--secondary-hover) !important;
  border-color: var(--secondary-hover) !important;
  color: var(--secondary-text) !important;
}
.btn-outline-primary {
  color: var(--secondary) !important;
  border-color: var(--secondary) !important;
}
.btn-outline-primary:hover, .btn-outline-primary:active {
  background-color: var(--secondary) !important;
  color: var(--secondary-text) !important;
}

/* ═══ Accent colors (tabs, links, badges) ═══ */
.settings-tabs .nav-link.active { border-bottom-color: var(--primary) !important; }
.filter-tabs a.active { border-bottom-color: var(--primary) !important; }
.form-check-input:checked { background-color: var(--primary) !important; border-color: var(--primary) !important; }
a { color: var(--secondary); }
a:hover { color: var(--secondary-hover); }

/* ═══ Border radius overrides ═══ */
.card, .setting-card, .stat-card, .modal-content { border-radius: var(--radius) !important; }
.btn { border-radius: var(--radius) !important; }
.form-control, .form-select { border-radius: var(--radius-sm) !important; }
.badge { border-radius: var(--radius-sm) !important; }

/* Public pages — cards */
.info-card, .partner-card, .year-card, .article-card, .album-card,
.gallery-item, .mosaic-item, .news-card, .tl-card, .timebox,
.reg-card, .video-social-card, .social-btn, .nav-card,
.slider-item, .hero-card, .iframe-container { border-radius: var(--radius) !important; }

/* Public pages — images in cards */
.article-card-img, .ncard-img, .partner-card-image, .album-card-media,
.album-card-media-inner, .mega-featured-img, .parcours-image img,
.section-divider img, .masonry-img, .info-modal-img { border-radius: var(--radius) !important; }

/* Public pages — inputs & search */
.search-box, .searchbox-input, .input-search, .reg-input,
.partner-email-input, .comment-form-textarea, .contact-form input,
.contact-form textarea, .contact-submit,
.news-search-bar input, .news-search-clear { border-radius: var(--radius-sm) !important; }

/* Public pages — misc */
.search-tag, .action-tag, .tl-pill, .tl-kicker, .back-btn,
.view-toggle, .pgbtn, .comment-submit, .mega-link,
.mobile-menu-item, .mobile-menu-link, .mobile-nav-link,
.reply-form-inline, .mention-list, .notification-badge-link,
.breadcrumb-link, .floating-label-tooltip { border-radius: var(--radius-sm) !important; }

/* Public pages — larger radius */
.mega, .mega-featured, .info-modal, .year-card::after,
.lightbox-img-wrap img, .iframe-icon { border-radius: var(--radius-lg) !important; }

/* Compound radius */
.partner-card-image-wrapper, .t-media, .t-media-inner { border-radius: 0 0 var(--radius) var(--radius) !important; }

/* ═══ Auto-contrast: text on secondary backgrounds ═══ */
.community-section, .community-section *,
.news-band, .news-band *,
.site-footer, .site-footer * {
  color: var(--secondary-text, #ffffff) !important;
}
/* Boutons primaires dans sections secondaires gardent leur propre contraste */
.community-section .partner-submit,
.community-section .btn.pink,
.site-footer .footer-contact-btn,
.site-footer .footer-contact-btn:hover,
.community-section .partner-submit:hover,
.community-section .btn.pink:hover { color: var(--primary-text, #ffffff) !important; }
/* Liens accent dans news band */
.news-band .news-band-link,
.news-band .news-cta { color: var(--primary, #db2777) !important; }
.news-band .news-band-link:hover,
.news-band .news-cta:hover { color: var(--primary-hover) !important; }
/* SVG dans boutons primaires */
.community-section .partner-submit svg,
.community-section .partner-submit svg path,
.community-section .btn.pink svg,
.community-section .btn.pink svg path,
.site-footer .footer-contact-btn svg,
.site-footer .footer-contact-btn svg path { color: var(--primary-text, #ffffff) !important; }
/* Opacité pour textes secondaires */
.community-text, .partner-note, .form-note {
  opacity: .85;
}
.footer-tagline { opacity: .45 !important; }
.footer-title { opacity: .6 !important; }
.footer-copyright { opacity: .6 !important; }
.footer-links a { opacity: .7 !important; }
.footer-links a:hover { opacity: 1 !important; }
.footer-separator { opacity: .3 !important; }


/* ═══ Dark mode — cards & elements on dark background ═══ */
body.dark-theme .partner-card,
body.dark-theme .year-card,
body.dark-theme .article-card,
body.dark-theme .info-modal,
body.dark-theme .gallery-item,
body.dark-theme .mosaic-item,
body.dark-theme .view-toggle { background: #1e293b !important; border-color: #334155 !important; color: var(--secondary-text) !important; }
body.dark-theme .album-card { background: transparent !important; border-color: transparent !important; }
body.dark-theme .album-card-creator { background: #2d1f3d !important; color: var(--primary) !important; border-color: #16171d !important; box-shadow: 0 0 0 1px #16171d !important; }
body.dark-theme .ncard { background: transparent !important; border-color: transparent !important; }
body.dark-theme .ncard:hover { background: rgba(255,255,255,.06) !important; }
body.dark-theme .ncard-img { background: rgba(255,255,255,.08) !important; }
body.dark-theme .partner-card-title,
body.dark-theme .album-card-title,
body.dark-theme .ncard-title,
body.dark-theme .article-card-title { color: #e2e8f0 !important; }
body.dark-theme .partner-card-desc,
body.dark-theme .album-card-meta,
body.dark-theme .ncard-meta,
body.dark-theme .ncard-date { color: #94a3b8 !important; }
body.dark-theme .search-box,
body.dark-theme .news-search-bar input,
body.dark-theme .searchbox-input,
body.dark-theme .comment-form-textarea,
body.dark-theme .reply-form-inline textarea { background: #0f172a !important; color: #e2e8f0 !important; border-color: #334155 !important; }
/* back-btn toujours en secondary */
.back-btn { background: var(--secondary) !important; color: var(--secondary-text) !important; }
.back-btn:hover { background: var(--primary) !important; color: var(--primary-text) !important; }

/* ═══ Dark mode (via body.dark-theme toggle) ═══ */
body.dark-theme {
  --primary: <?= $__theme['dark_primary'] ?>;
  --primary-hover: <?= $__darkPrimaryHover ?>;
  --primary-text: <?= $__darkPrimaryText ?>;
  --primary-light: <?= $__darkPrimaryLight ?>;
  --secondary: <?= $__theme['dark_secondary'] ?>;
  --secondary-hover: <?= $__darkSecondaryHover ?>;
  --secondary-text: <?= $__darkSecondaryText ?>;
  --secondary-light: <?= $__darkSecondaryLight ?>;
}
<?php endif; // $themeVarsOnly ?>
</style>
