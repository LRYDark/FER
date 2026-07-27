<?php
/**
 * Admin Layout v2 — habillage jr-theme (style MERIDIAN)
 * Structure : .jr-shell > aside.jr-nav (sidebar) + main.jr-main (contenu)
 * Ouvre le contenu (#oc-content) — fermer avec admin-footer.php.
 *
 * Thème par utilisateur : users.ui_prefs (admin_theme / admin_accent /
 * admin_accent_custom / admin_font), appliqué via data-theme / data-accent
 * sur <html> (tokens jr-theme). Accent par défaut : couleur primaire du
 * site public (Réglages → Thème du site), le rose FER.
 */

$currentPage = basename($_SERVER['PHP_SELF']);
// Onglet actif : la page peut le fournir via $navActiveTab (utile après un POST,
// où l'URL ne contient pas ?tab=) ; sinon on lit l'URL.
$currentTab  = $navActiveTab ?? ($_GET['tab'] ?? ($_GET['pane'] ?? ''));

$pageTitles = [
    'dashboard.php'     => 'Tableau de bord',
    'utilisateurs.php'  => 'Utilisateurs & Droits',
    'setting.php'       => 'Réglages',
    'mail-settings.php' => 'Emails',
    'albums.php'        => 'Albums photos',
    'partners.php'      => 'Partenaires',
    'news.php'          => 'Actualités',
    'stats.php'         => 'Statistiques',
    'qr_code.php'       => 'QR Codes',
    'saisie.php'        => 'Saisie',
    'timeline.php'      => 'Timeline',
    'tshirt-access.php' => 'Accès bénévoles',
    'connexions.php'    => 'Connexions',
    'logs.php'          => 'Logs',
    'page_stats.php'    => 'Visites',
    'api-doc.php'        => "Documentation de l'API",
    'api-doc-mobile.php' => "Documentation de l'API mobile",
    'rgpd.php'           => 'Données personnelles',
    'resultats.php'      => 'Résultats',
    // ⚠️ Ces deux écrans (lot 4) manquaient à la liste : leur titre s'affichait
    // « Administration ». $pageTitle défini dans la page elle-même ne sert à
    // rien — c'est CE tableau qui l'écrase, quelques lignes plus bas.
    'comptes-coureurs.php' => 'Comptes coureurs',
    'transferts.php'       => "Transferts d'inscription",
    'assistant.php'     => 'Assistance',
];
$pageTitle = $pageTitles[$currentPage] ?? 'Administration';

// Sous-titres selon l'onglet actif (deep-links de la sidebar)
$tabTitles = [
    'setting.php' => [
        'personnalisation' => 'Personnalisation',
        'accueil'          => "Page d'accueil",
        'inscription'      => 'Inscription',
        'import_auto'      => 'AssoConnect',
        'parcours'         => 'Parcours',
        'reglementation'   => 'Réglementation',
        'legal'            => 'Pages légales',
        'formulaire'       => 'Formulaire',
        'api'              => 'API',
        'maintenance'      => 'Maintenance',
    ],
    'mail-settings.php' => [
        'envoi'         => 'Envoi de mail',
        'template'      => 'Template & contenu',
        'google'        => 'Fournisseur (Gmail / SMTP)',
        'notifications' => 'Notifications',
        'newsletter'    => 'Abonnés newsletter',
    ],
];
$pageSubtitle = $tabTitles[$currentPage][$currentTab] ?? '';

// User info
$userEmail = $_SESSION['email'] ?? '';
$userRole  = $role ?? currentRole();
$userName  = $userEmail !== '' ? explode('@', $userEmail)[0] : ucfirst((string) $userRole);

/* ── Préférences d'apparence par utilisateur (users.ui_prefs) ─────────────── */
if (!function_exists('jr_admin_ui_prefs')) {
    function jr_admin_ui_prefs(PDO $pdo): array
    {
        static $prefs = null;
        if ($prefs !== null) return $prefs;
        $prefs = [];
        try {
            $uid = $_SESSION['uid'] ?? null;
            if ($uid) {
                $st = $pdo->prepare('SELECT ui_prefs FROM users WHERE id = ?');
                $st->execute([$uid]);
                $raw = $st->fetchColumn();
                if ($raw) { $dec = json_decode($raw, true); if (is_array($dec)) $prefs = $dec; }
            }
        } catch (\Throwable $e) { /* colonne absente → défauts */ }
        return $prefs;
    }

}
/* jr_accent_vars_from_hex() est définie dans src/core/config.php */

$jrPrefs      = jr_admin_ui_prefs($pdo);
$jrTheme      = in_array($jrPrefs['admin_theme'] ?? '', ['light', 'dark', 'system'], true) ? $jrPrefs['admin_theme'] : 'light';
$jrLoginTheme = in_array($jrPrefs['login_theme'] ?? '', ['light', 'dark', 'system'], true) ? $jrPrefs['login_theme'] : 'light';
$jrAccent     = $jrPrefs['admin_accent'] ?? 'rose';
$jrFont       = $jrPrefs['admin_font']   ?? 'inter';

// Couleur du preset « rose » = couleur primaire du site public (défaut FER)
$jrSitePrimary = '#db2777';
try {
    $c = $pdo->query('SELECT theme_primary_color FROM setting WHERE id = 1 LIMIT 1')->fetchColumn();
    if ($c && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) $jrSitePrimary = $c;
} catch (\Throwable $e) {}

$jrAccentAttr = '';        // data-accent (presets tokens.css)
$jrAccentCss  = null;      // override des 6 variables (rose / custom)
if ($jrAccent === 'custom' && !empty($jrPrefs['admin_accent_custom'])) {
    $jrAccentCss = jr_accent_vars_from_hex((string) $jrPrefs['admin_accent_custom']);
    if (!$jrAccentCss) { $jrAccent = 'rose'; }
}
if ($jrAccent === 'rose' || ($jrAccent === 'custom' && !$jrAccentCss)) {
    $jrAccentCss = jr_accent_vars_from_hex($jrSitePrimary);
} elseif (in_array($jrAccent, ['blue', 'teal', 'violet', 'emerald'], true)) {
    $jrAccentAttr = $jrAccent === 'blue' ? '' : $jrAccent; // bleu = défaut tokens (pas d'attribut)
}

// Police admin (indépendante du site public) — même catalogue que le Thème du site
$jrFonts = jr_admin_fonts(); // nom => [stack, google, customPath]
// Compat anciens slugs (premières versions du profil)
$jrFontLegacy = ['inter' => 'Inter', 'system' => 'system-ui', 'poppins' => 'Poppins', 'roboto' => 'Roboto', 'open-sans' => 'Open Sans', 'montserrat' => 'Montserrat', 'nunito' => 'Nunito'];
if (isset($jrFontLegacy[$jrFont])) $jrFont = $jrFontLegacy[$jrFont];
if (!array_key_exists($jrFont, $jrFonts)) $jrFont = 'Inter';
?>

<?php
$themeVarsOnly = true; // admin : theme.php n'émet QUE les variables --primary/--radius…
include __DIR__ . '/../content/theme.php';
?>

<!-- ═══════ jr-theme (habillage admin) ═══════ -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function () {
  var d = document.documentElement;
  d.setAttribute('data-theme', <?= json_encode($jrTheme) ?>);
  // Anti-flash : fond posé IMMÉDIATEMENT (avant l'arrivée des CSS du thème),
  // sinon le fond blanc de Bootstrap apparaît un instant en mode sombre.
  var dark = <?= json_encode($jrTheme === 'dark') ?> ||
    (<?= json_encode($jrTheme === 'system') ?> && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  d.style.backgroundColor = dark ? '#05070D' : '#E9EDF4';
  <?php if ($jrAccentAttr !== ''): ?>d.setAttribute('data-accent', <?= json_encode($jrAccentAttr) ?>);<?php endif; ?>
  // Miroir localStorage → les pages login/install (pré-auth) suivent le dernier choix
  try {
    localStorage.setItem('jr-theme', <?= json_encode($jrTheme) ?>);
    localStorage.setItem('jr-login-theme', <?= json_encode($jrLoginTheme) ?>); // thème dédié aux pages de connexion
    <?php if ($jrAccentCss): ?>
    localStorage.setItem('jr-accent', 'custom');
    localStorage.setItem('jr-accent-custom', <?= json_encode($jrAccentCss[0]) ?>);
    <?php else: ?>
    localStorage.setItem('jr-accent', <?= json_encode($jrAccentAttr === '' ? '' : $jrAccentAttr) ?>);
    localStorage.removeItem('jr-accent-custom');
    <?php endif; ?>
  } catch (e) {}
})();
</script>
<?php // ?v=mtime : anti-cache — toute modification des CSS est rechargée immédiatement
$jrV = function (string $rel) { $p = dirname(__DIR__, 2) . '/' . $rel; return $rel . '?v=' . (@filemtime($p) ?: '1'); };
?>
<link rel="stylesheet" href="../<?= $jrV('css/tokens.css') ?>">
<link rel="stylesheet" href="../<?= $jrV('css/base.css') ?>">
<link rel="stylesheet" href="../<?= $jrV('css/admin.css') ?>">
<?php if ($jrFonts[$jrFont][1]): // Google Font ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?= str_replace(' ', '+', $jrFont) ?>:wght@400;500;600;700&display=swap">
<?php endif; ?>
<style id="jr-user-vars">
<?php if ($jrAccentCss): ?>
:root {
  --accent-l: <?= $jrAccentCss[0] ?>; --accent-l-strong: <?= $jrAccentCss[1] ?>; --accent-l-ink: <?= $jrAccentCss[2] ?>;
  --accent-d: <?= $jrAccentCss[3] ?>; --accent-d-strong: <?= $jrAccentCss[4] ?>; --accent-d-ink: <?= $jrAccentCss[5] ?>;
}
<?php endif; ?>
<?php if ($jrFonts[$jrFont][2]): // Police custom du dossier fonts/ : @font-face
    $__fmtMap = ['otf' => 'opentype', 'woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype'];
    $__ext = strtolower(pathinfo($jrFonts[$jrFont][2], PATHINFO_EXTENSION));
?>
@font-face { font-family: "<?= addslashes($jrFont) ?>"; src: url("../<?= addslashes($jrFonts[$jrFont][2]) ?>") format("<?= $__fmtMap[$__ext] ?? 'truetype' ?>"); font-display: swap; }
<?php endif; ?>
<?php if ($jrFont !== 'Inter'): ?>
body { font-family: <?= $jrFonts[$jrFont][0] ?>; }
<?php endif; ?>
</style>

<?php
/* ── Définition de la sidebar : sections → items ──────────────────────────────
 * access : ['page' => cléPage] (canAccessPage) — ['roles' => [...]] (rôle dur)
 *          + optionnel 'action' => permission canDoAction supplémentaire.
 * href peut contenir ?tab= : l'item est actif si page + tab correspondent.   */
$ico = [
    'dashboard' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    'stats'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'eye'       => '<path d="M1 12 C 1 12, 5 4, 12 4 S 23 12, 23 12 S 19 20, 12 20 S 1 12, 1 12 Z"/><circle cx="12" cy="12" r="3"/>',
    'plus'      => '<path d="M12 5v14M5 12h14"/>',
    'qr'        => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    'tshirt'    => '<path d="M16 3l5 3-2 4-2-1v11a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V9L5 10 3 6l5-3"/><path d="M9 3a3 3 0 0 0 6 0"/>',
    'news'      => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>',
    'albums'    => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    'partners'  => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    'timeline'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'home'      => '<path d="M3 12l9-9 9 9"/><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/>',
    'send'      => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
    'template'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'mail'      => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'users2'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'palette'   => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
    'form'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    'map'       => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
    'gavel'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'plug'      => '<path d="M9 2v6"/><path d="M15 2v6"/><path d="M12 17v5"/><path d="M5 8h14a1 1 0 0 1 1 1v3a5 5 0 0 1-5 5h-6a5 5 0 0 1-5-5V9a1 1 0 0 1 1-1z"/>',
    'sync'      => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
    'login'     => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
    'logs'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'wrench'    => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'chat'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
];

$navSections = [
    'Pilotage' => [
        ['stats.php',      'Statistiques',    $ico['stats'],     ['roles' => ['admin', 'user', 'viewer']]],
        ['page_stats.php', 'Visites',         $ico['eye'],       ['page' => 'page_stats']],
    ],
    'Inscriptions' => [
        ['saisie.php',        'Saisie',          $ico['plus'],   ['roles' => ['saisie']]],
        ['qr_code.php',       'QR Codes',        $ico['qr'],     ['page' => 'qr_code']],
        ['tshirt-access.php', 'Accès bénévoles', $ico['tshirt'], ['roles' => ['admin']]],
        // Espace coureur (lot 4) : ces deux écrans exposent les adresses et les
        // appareils des coureurs — d'où une permission dédiée, décochée par
        // défaut pour les rôles de consultation.
        ['comptes-coureurs.php', 'Comptes coureurs', $ico['users2'], ['page' => 'dashboard', 'action' => 'dashboard.participants']],
        ['transferts.php',       'Transferts',       $ico['send'],   ['page' => 'dashboard', 'action' => 'dashboard.transfers']],
        // Chronométrage : consulter, corriger, valider. Écran indispensable le
        // jour où quelqu'un conteste son temps — il montre TOUTES les détections
        // reçues, balise et GPS, et laquelle a servi au calcul.
        ['resultats.php',        'Résultats',        $ico['timeline'], ['page' => 'dashboard', 'action' => 'dashboard.transfers']],
    ],
    'Contenu' => [
        ['news.php',     'Actualités',    $ico['news'],     ['page' => 'news']],
        ['albums.php',   'Albums photos', $ico['albums'],   ['page' => 'albums']],
        ['partners.php', 'Partenaires',   $ico['partners'], ['page' => 'partners']],
        ['timeline.php', 'Timeline',      $ico['timeline'], ['page' => 'timeline']],
        ['setting.php?tab=accueil', "Page d'accueil", $ico['home'], ['page' => 'setting', 'action' => 'settings.tab.accueil']],
        ['assistant.php', 'Assistant / FAQ', $ico['chat'], ['page' => 'assistant']],
    ],
    'Emails' => [
        ['mail-settings.php?pane=envoi',         'Envoi de mail',      $ico['send'],     ['page' => 'mail-settings', 'action' => 'mail.send']],
        ['mail-settings.php?pane=template',      'Template & contenu', $ico['template'], ['page' => 'mail-settings', 'action' => 'mail.write']],
        ['mail-settings.php?pane=google',        'Fournisseur',        $ico['mail'],     ['page' => 'mail-settings', 'action' => 'mail.write']],
        ['mail-settings.php?pane=notifications', 'Notifications',      $ico['bell'],     ['page' => 'mail-settings', 'action' => 'mail.write']],
        ['mail-settings.php?pane=newsletter',    'Abonnés newsletter', $ico['users2'],   ['page' => 'mail-settings', 'action' => 'mail.newsletter']],
    ],
    'Réglages' => [
        ['setting.php?tab=personnalisation', 'Personnalisation', $ico['palette'], ['page' => 'setting', 'action' => 'settings.tab.personnalisation']],
        ['setting.php?tab=inscription',      'Inscription',      $ico['form'],    ['page' => 'setting', 'action' => 'settings.tab.inscription']],
        ['setting.php?tab=import_auto',      'AssoConnect',      $ico['sync'],    ['page' => 'setting', 'action' => 'settings.tab.import_auto']],
        ['setting.php?tab=parcours',         'Parcours',         $ico['map'],     ['page' => 'setting', 'action' => 'settings.tab.parcours']],
        ['setting.php?tab=reglementation',   'Réglementation',   $ico['gavel'],   ['page' => 'setting', 'action' => 'settings.tab.reglementation']],
        ['setting.php?tab=legal',            'Pages légales',    $ico['logs'],    ['page' => 'setting', 'action' => 'settings.tab.legal']],
        ['setting.php?tab=formulaire',       'Formulaire',       $ico['form'],    ['page' => 'setting', 'action' => 'settings.tab.formulaire']],
        ['setting.php?tab=api',              'API',              $ico['plug'],    ['page' => 'setting', 'action' => 'settings.tab.api']],
    ],
    'Sécurité & système' => [
        ['utilisateurs.php', 'Utilisateurs & Droits', $ico['users2'], ['roles' => ['admin']]],
        ['connexions.php',   'Connexions',            $ico['login'],  ['page' => 'connexions']],
        ['logs.php',         'Logs',                  $ico['logs'],   ['page' => 'logs']],
        // Conservation et effacement (lot 7) : réservé aux super-administrateurs.
        // Effacer définitivement des données n'est pas une opération courante,
        // et il faut pouvoir dire qui l'a déclenchée.
        ['rgpd.php',         'Données personnelles',  $ico['shield'] ?? $ico['logs'], ['roles' => ['admin']]],
        ['setting.php?tab=maintenance', 'Maintenance', $ico['wrench'], ['page' => 'setting', 'action' => 'settings.tab.maintenance']],
    ],
];

/** Un item est-il visible pour l'utilisateur courant ? */
$jrCanSee = function (array $access) use ($userRole): bool {
    if (isset($access['roles']) && !in_array($userRole, $access['roles'], true)) return false;
    if (isset($access['page']) && !canAccessPage($access['page'])) return false;
    if (isset($access['action']) && !canDoAction($access['action'])) return false;
    return true;
};

/** Un item est-il actif (page + éventuel ?tab=/?pane=) ? */
$jrIsActive = function (string $href) use ($currentPage, $currentTab): bool {
    $parts = explode('?', $href, 2);
    if (basename($parts[0]) !== $currentPage) return false;
    if (!isset($parts[1])) {
        // Lien sans tab : actif seulement si la page n'a pas de tab profilé dans la sidebar
        return !in_array($currentPage, ['setting.php', 'mail-settings.php'], true) || $currentTab === '';
    }
    parse_str($parts[1], $q);
    $want = $q['tab'] ?? ($q['pane'] ?? '');
    return $want === $currentTab;
};

// Logo (rose sur fond clair)
$jrLogo = null;
foreach (['logo_fer_rose.png', 'logo.png'] as $lf) {
    if (file_exists(dirname(__DIR__, 2) . '/files/_logos/' . $lf)) { $jrLogo = '../files/_logos/' . $lf; break; }
}

// Rôle saisie : « Tableau de bord » devient « Mes inscriptions »
$saisieTab = ($currentPage === 'saisie.php' && ($_GET['tab'] ?? '') === 'inscriptions') ? 'inscriptions' : 'formulaire';
?>

<!-- ═══════ SHELL ═══════ -->
<div class="jr-shell" id="oc-app-container">

  <!-- ═══════ SIDEBAR ═══════ -->
  <aside class="jr-nav" id="oc-sidebar">
    <a class="jr-brand" href="dashboard.php">
      <?php if ($jrLogo): ?><img src="<?= htmlspecialchars($jrLogo) ?>" alt=""><?php endif; ?>
      <span class="name">Forbach en Rose</span>
    </a>

    <nav>
      <?php // ── « Tableau de bord » : seul, tout en haut, hors sections ── ?>
      <?php if (canAccessPage('dashboard')): ?>
        <?php
        $dashHref = 'dashboard.php'; $dashLabel = 'Tableau de bord';
        $dashActive = ($currentPage === 'dashboard.php');
        if ($userRole === 'saisie') {
            $dashLabel = 'Mes inscriptions';
            $dashHref  = 'saisie.php?tab=inscriptions';
            $dashActive = ($currentPage === 'saisie.php' && $saisieTab === 'inscriptions');
        }
        ?>
        <a class="item is-top<?= $dashActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($dashHref) ?>">
          <svg viewBox="0 0 24 24"><?= $ico['dashboard'] ?></svg>
          <span><?= htmlspecialchars($dashLabel) ?></span>
        </a>
      <?php endif; ?>

      <?php // ── Sections en ACCORDÉON : une seule ouverte (celle de la page active) ── ?>
      <?php
      // Pré-calcul : items visibles + section active
      $renderSections = [];
      $activeSection  = null;
      foreach ($navSections as $sectionLabel => $items) {
          $visible = [];
          foreach ($items as [$href, $label, $icon, $access]) {
              if (!$jrCanSee($access)) continue;
              $isActive = $jrIsActive($href);
              if ($href === 'saisie.php' && $userRole === 'saisie') {
                  $isActive = ($currentPage === 'saisie.php' && $saisieTab === 'formulaire');
              }
              $visible[] = [$href, $label, $icon, $isActive];
              if ($isActive && $activeSection === null) $activeSection = $sectionLabel;
          }
          if (!empty($visible)) $renderSections[$sectionLabel] = $visible;
      }
      // Aucune page active dans les groupes (ex : dashboard) → premier groupe ouvert
      if ($activeSection === null && !empty($renderSections)) {
          $activeSection = array_key_first($renderSections);
      }
      ?>
      <?php foreach ($renderSections as $sectionLabel => $visible): ?>
        <div class="nav-group<?= $sectionLabel === $activeSection ? ' open' : '' ?>">
          <button type="button" class="section section-toggle">
            <span><?= htmlspecialchars($sectionLabel) ?></span>
            <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="group-items">
            <div class="group-items-inner">
              <?php foreach ($visible as [$href, $label, $icon, $isActive]): ?>
                <a class="item<?= $isActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>">
                  <svg viewBox="0 0 24 24"><?= $icon ?></svg>
                  <span><?= htmlspecialchars($label) ?></span>
                  <?php if (strpos($href, 'tshirt-access.php') === 0): ?>
                    <span class="jr-badge<?= empty($tshirtPendingCount) ? ' d-none' : '' ?>" id="tshirtPendingBadge"><?= (int) ($tshirtPendingCount ?? 0) ?></span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($userRole === 'admin' && file_exists(dirname(__DIR__, 2) . '/update.php')): ?>
        <a class="item is-update mt-auto-update" href="../update.php">
          <svg viewBox="0 0 24 24"><?= $ico['download'] ?></svg>
          <span>Mise à jour BDD</span>
        </a>
      <?php endif; ?>
    </nav>

    <!-- ═══════ Bloc utilisateur (bas de sidebar) ═══════ -->
    <footer>
      <div class="jr-usermenu" id="ocDropdown">
        <?php if ($currentPage === 'dashboard.php' && !empty($canTshirtMode)): ?>
        <a href="#" id="ocModeToggle">
          <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <span>Remise T-shirts</span>
        </a>
        <a href="../public/remise-tshirts.php" id="btnScanQR">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="3" height="3"/><line x1="21" y1="14" x2="21" y2="21"/><line x1="14" y1="21" x2="21" y2="21"/></svg>
          <span>Scanner QR</span>
        </a>
        <hr>
        <?php endif; ?>
        <a href="#" id="ocProfileLink">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Mon profil</span>
        </a>
        <hr>
        <a href="#" id="ocLogoutLink" class="is-danger">
          <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Déconnexion</span>
        </a>
      </div>

      <button class="jr-userblock" id="ocAvatarBtn" type="button" title="<?= htmlspecialchars($userEmail) ?>">
        <span class="who">
          <span class="name"><?= htmlspecialchars($userName) ?></span>
          <span class="role"><?= htmlspecialchars(ucfirst((string) $userRole)) ?></span>
        </span>
        <span class="jr-logout" id="ocLogoutQuick" title="Déconnexion" role="button">
          <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </span>
      </button>
      <div class="jr-version">Version 2.0.0</div>
    </footer>
  </aside>

  <?php // Rafraîchissement live de la pastille « Accès bénévoles » ?>
  <?php if (canDoAction('tshirt_access.approve')): ?>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  (function(){
    var badge = document.getElementById('tshirtPendingBadge');
    if (!badge) return;
    function refresh(){
      fetch('../admin-api.php?route=tshirt-admin', {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(res){
          if (!res || !res.ok) return;
          var n = (res.pending || []).length;
          badge.textContent = n;
          badge.classList.toggle('d-none', n === 0);
        }).catch(function(){});
    }
    setInterval(refresh, 30000);
  })();
  </script>
  <?php endif; ?>

  <!-- ═══════ CONTENU (fermé dans admin-footer.php) ═══════
       NB : volontairement une <div> et PAS <main> — certaines pages admin
       (Réglages) chargent css/accueil.css pour l'aperçu du site public, qui
       style l'élément main (centrage, largeurs) et polluerait l'admin. -->
  <div class="jr-main" id="oc-content">

    <?php
    /* ═══ Bandeau « MODE TEST » — garde-fou catch-all des mails (P1) ═══
     * Affiché en permanence, sur toutes les pages d'administration, dès que le
     * garde-fou est actif.
     *
     * POURQUOI : le risque résiduel n'est pas que le garde-fou échoue — il est
     * de croire qu'on travaille sur la recette alors qu'on est en production, ou
     * l'inverse. Quelqu'un qui déclenche un envoi groupé en pensant écrire à
     * 300 inscrits doit voir immédiatement que rien ne partira. */
    if (!function_exists('mailCatchallStatus')) {
        $mgFile = dirname(__DIR__) . '/mail/mail_guard.php';
        if (is_file($mgFile)) { try { require_once $mgFile; } catch (\Throwable $e) {} }
    }
    // Réservé aux administrateurs : c'est une information d'environnement, pas
    // un message destiné aux rôles de saisie ou de consultation.
    if (function_exists('mailCatchallStatus') && currentRole() === 'admin'):
        $ocCatchall = mailCatchallStatus();
        if ($ocCatchall['actif']):
    ?>
      <?php /* Carte plutôt que bande pleine largeur : le bandeau collé au bord
               supérieur écrasait le titre de la page et donnait un air d'alerte
               système en panne. Ici, il se pose dans le flux comme les autres
               cartes, garde sa couleur d'avertissement, et reste impossible à
               manquer — c'est tout ce qu'on lui demande. */ ?>
      <div class="jr-testmode<?= $ocCatchall['bloquant'] ? ' is-bloquant' : '' ?>">
        <span class="jr-testmode-badge">
          <i class="bi bi-<?= $ocCatchall['bloquant'] ? 'shield-fill-exclamation' : 'cone-striped' ?>"></i>
          Mode test
        </span>
        <span class="jr-testmode-texte">
          <?php if ($ocCatchall['bloquant']): ?>
            Garde-fou actif <strong>sans adresse valide</strong> :
            <strong>aucun mail ne part</strong>.
          <?php else: ?>
            Tous les mails sortants partent vers
            <strong><?= htmlspecialchars($ocCatchall['adresse']) ?></strong> —
            jamais aux inscrits.
          <?php endif; ?>
        </span>
        <?php if (canAccessPage('mail-settings')): ?>
          <a class="jr-testmode-lien" href="mail-settings.php?tab=google">
            Modifier <i class="bi bi-arrow-right-short"></i>
          </a>
        <?php endif; ?>
      </div>
      <style>
        .jr-testmode {
          display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
          margin: 0 0 1.25rem; padding: .7rem 1rem;
          border-radius: 12px; line-height: 1.5; font-size: .875rem;
          /* Teinte douce plutôt qu'aplat saturé : l'information doit se voir,
             pas hurler à chaque chargement de page. */
          background: color-mix(in srgb, var(--warn, #fd7e14) 12%, var(--card, #fff));
          border: 1px solid color-mix(in srgb, var(--warn, #fd7e14) 35%, transparent);
          color: var(--ink, #0f172a);
        }
        .jr-testmode.is-bloquant {
          background: color-mix(in srgb, var(--danger, #dc3545) 12%, var(--card, #fff));
          border-color: color-mix(in srgb, var(--danger, #dc3545) 40%, transparent);
        }
        .jr-testmode-badge {
          display: inline-flex; align-items: center; gap: .4rem; flex: none;
          padding: .25rem .6rem; border-radius: 999px;
          background: var(--warn, #fd7e14); color: #fff;
          font-size: .74rem; font-weight: 700; letter-spacing: .04em;
          text-transform: uppercase; white-space: nowrap;
        }
        .jr-testmode.is-bloquant .jr-testmode-badge { background: var(--danger, #dc3545); }
        .jr-testmode-texte { flex: 1 1 260px; min-width: 0; }
        .jr-testmode-texte strong { font-weight: 650; }
        .jr-testmode-lien {
          flex: none; display: inline-flex; align-items: center;
          color: inherit; text-decoration: none; font-weight: 600;
          padding: .25rem .6rem; border-radius: 8px;
          border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
          transition: background .15s ease;
        }
        .jr-testmode-lien:hover { background: color-mix(in srgb, currentColor 8%, transparent); }
        @media (max-width: 575px) {
          .jr-testmode { font-size: .82rem; padding: .6rem .75rem; }
        }
      </style>
    <?php
        endif;
    endif;
    ?>

    <header class="jr-topbar">
      <div style="display:flex;align-items:center;gap:12px">
        <button class="jr-burger" id="ocBurger" type="button" aria-label="Menu">
          <span></span><span></span><span></span>
        </button>
        <div class="crumbs">
          <h1><?= htmlspecialchars($pageSubtitle !== '' ? $pageSubtitle : $pageTitle) ?></h1>
        </div>
      </div>
    </header>

<?php include __DIR__ . '/profile-modal.php'; ?>

<!-- Mobile overlay -->
<div class="oc-overlay" id="ocOverlay"></div>

<!-- Étiquette flottante ColReorder (nom de colonne pendant le drag) -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function () {
  var grabbed = null, startX = 0, startY = 0, armed = false, label = null, name = '';
  function ensureLabel() {
    if (!label) { label = document.createElement('div'); label.className = 'dtcr-drag-label'; document.body.appendChild(label); }
    return label;
  }
  document.addEventListener('mousedown', function (e) {
    var th = e.target.closest && e.target.closest('th');
    if (!th || !th.closest('table.fer-table') || !th.closest('thead')) return;
    if (e.target.closest('.col-filter-btn, .col-resize, a, button, input, select')) return;
    grabbed = th; startX = e.clientX; startY = e.clientY; armed = true;
    name = (th.textContent || '').trim();
  }, true);
  document.addEventListener('mousemove', function (e) {
    if (!armed || !grabbed) return;
    if (Math.abs(e.clientX - startX) > 4 || Math.abs(e.clientY - startY) > 4) {
      var l = ensureLabel();
      l.textContent = name;
      l.style.left = (e.clientX + 14) + 'px';
      l.style.top  = (e.clientY + 14) + 'px';
      l.classList.add('show');
    }
  });
  document.addEventListener('mouseup', function () {
    if (label) label.classList.remove('show');
    grabbed = null; armed = false;
  });
})();
</script>
