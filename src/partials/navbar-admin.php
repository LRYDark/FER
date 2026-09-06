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
    'applications.php'   => 'Applications',
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
// Lecture partagée avec le thème et le reste de la page : une seule requête.
$c = settingRow($pdo)['theme_primary_color'] ?? null;
if ($c && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) $jrSitePrimary = $c;

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
  // Shell v2.1 : le fond de page est BLANC (les réglages sont à plat dessus),
  // et non le gris bleuté de l'ancienne mise en page à cartes flottantes.
  d.style.backgroundColor = dark ? '#05070D' : '#FFFFFF';
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
<link rel="stylesheet" href="../<?= $jrV('css/admin-shell.css') ?>">
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
 * href peut contenir ?tab= : l'item est actif si page + tab correspondent.
 *
 * ⚠️ CHAQUE ITEM DOIT DÉCLARER EXACTEMENT LE DROIT QUE SA PAGE EXIGE, pas un
 * droit approchant. Une page gardée par `requirePage('stats')` se déclare
 * ['page' => 'stats'] ; ['roles' => [...]] est réservé aux trois écrans que le
 * serveur garde VRAIMENT par rôle — saisie.php et utilisateurs.php, qui
 * appellent requireRole(), et rgpd.php, qui teste currentRole() à la main.
 *
 * POURQUOI : une liste de rôles écrite ici est une SECONDE règle d'accès, qui
 * dérive de celle du serveur dès qu'un droit est accordé à la main dans
 * Utilisateurs & Droits. C'est arrivé : « Statistiques » portait
 * ['roles' => ['admin','user','viewer']] alors que stats.php exige
 * canAccessPage('stats'). Un compte `saisie` à qui on avait donné la page
 * pouvait l'ouvrir, la recherche la lui proposait — mais l'onglet « Pilotage »
 * restait invisible, donc la page était inatteignable au menu. L'inverse est
 * tout aussi faux : retirer `stats` à un rôle laissait l'entrée au menu, et
 * cliquer renvoyait au tableau de bord.
 *
 * Un onglet du haut s'affiche dès qu'AU MOINS UNE de ses entrées est visible :
 * corriger le droit de l'entrée suffit à faire réapparaître sa section.
 * docs/test-integrite.php (§ 17 bis) compare ces droits à ceux des pages.    */
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

/* ── Rangement : PAR OBJET SUR LEQUEL ON TRAVAILLE ────────────────────────────
 * L'ancien classement mélangeait deux logiques : « c'est du contenu » d'un
 * côté, « c'est un réglage » de l'autre. On se retrouvait avec la
 * personnalisation du site dans Réglages et la page d'accueil dans Contenu,
 * alors que les deux habillent la même chose — le site public. Un écran
 * qu'on cherche dans deux endroits est un écran qu'on ne trouve pas.
 *
 * La règle est maintenant : on range selon CE QU'ON MODIFIE, jamais selon la
 * nature technique de l'écran. Le site public d'un côté, les inscrits de
 * l'autre, la journée de course, les mails, les chiffres, la machine.
 *
 * Conséquence assumée : les onglets de setting.php sont éclatés dans quatre
 * sections. C'est voulu — le fait qu'ils vivent tous dans le même fichier PHP
 * n'intéresse personne d'autre que nous. */
$navSections = [
    /* Le travail quotidien avant la course. */
    'Inscriptions' => [
        ['saisie.php',           'Saisie',            $ico['plus'],   ['roles' => ['saisie']]],
        ['comptes-coureurs.php', 'Comptes coureurs',  $ico['users2'], ['page' => 'dashboard', 'action' => 'dashboard.participants']],
        ['transferts.php',       'Transferts',        $ico['send'],   ['page' => 'dashboard', 'action' => 'dashboard.transfers']],
        // Ouverture, tarifs, messages affichés : c'est le robinet des
        // inscriptions, pas un réglage de site. Il vient donc ici.
        ['setting.php?tab=inscription', 'Inscription', $ico['form'],  ['page' => 'setting', 'action' => 'settings.tab.inscription']],
        // Les champs du formulaire : on les modifie en pensant aux inscrits.
        ['setting.php?tab=formulaire',  'Formulaire',  $ico['form'],  ['page' => 'setting', 'action' => 'settings.tab.formulaire']],
        // AssoConnect importe des INSCRITS. Rangé dans Réglages, on le
        // cherchait dans Inscriptions.
        ['setting.php?tab=import_auto', 'AssoConnect', $ico['sync'],  ['page' => 'setting', 'action' => 'settings.tab.import_auto']],
    ],

    /* Les écrans qu'on ouvre le jour de la course, ou pour le préparer. */
    'Jour J' => [
        ['setting.php?tab=course', 'Course',          $ico['timeline'], ['page' => 'setting', 'action' => 'settings.tab.course']],
        ['qr_code.php',            'QR Codes',        $ico['qr'],       ['page' => 'qr_code']],
        ['tshirt-access.php',      'Accès bénévoles', $ico['tshirt'],   ['page' => 'tshirt_access']],
        ['resultats.php',          'Résultats',       $ico['timeline'], ['page' => 'dashboard', 'action' => 'dashboard.transfers']],
        // Notifications et réveil avant la course : un écran du jour J, pas un
        // réglage qu'on pose une fois.
        ['applications.php',       'Applications',    $ico['chat'],     ['page' => 'setting']],
    ],

    /* Tout ce que le visiteur voit. C'EST ICI QUE SE RÈGLE LE PROBLÈME :
     * la page d'accueil et la personnalisation habillent la même chose,
     * elles se rangent donc au même endroit. */
    'Site public' => [
        ['setting.php?tab=accueil',        "Page d'accueil",   $ico['home'],     ['page' => 'setting', 'action' => 'settings.tab.accueil']],
        ['news.php',                       'Actualités',       $ico['news'],     ['page' => 'news']],
        ['albums.php',                     'Albums photos',    $ico['albums'],   ['page' => 'albums']],
        ['partners.php',                   'Partenaires',      $ico['partners'], ['page' => 'partners']],
        ['timeline.php',                   'Timeline',         $ico['timeline'], ['page' => 'timeline']],
        ['setting.php?tab=parcours',       'Parcours',         $ico['map'],      ['page' => 'setting', 'action' => 'settings.tab.parcours']],
        ['setting.php?tab=reglementation', 'Réglementation',   $ico['gavel'],    ['page' => 'setting', 'action' => 'settings.tab.reglementation']],
        ['setting.php?tab=legal',          'Pages légales',    $ico['logs'],     ['page' => 'setting', 'action' => 'settings.tab.legal']],
        ['assistant.php',                  'Assistant / FAQ',  $ico['chat'],     ['page' => 'assistant']],
        // Logos, couleurs, typographie : l'habillage du site public.
        ['setting.php?tab=personnalisation', 'Personnalisation', $ico['palette'], ['page' => 'setting', 'action' => 'settings.tab.personnalisation']],
    ],

    'Emails' => [
        ['mail-settings.php?pane=envoi',         'Envoi de mail',      $ico['send'],     ['page' => 'mail-settings', 'action' => 'mail.send']],
        ['mail-settings.php?pane=template',      'Template & contenu', $ico['template'], ['page' => 'mail-settings', 'action' => 'mail.write']],
        ['mail-settings.php?pane=google',        'Fournisseur',        $ico['mail'],     ['page' => 'mail-settings', 'action' => 'mail.write']],
        ['mail-settings.php?pane=notifications', 'Notifications',      $ico['bell'],     ['page' => 'mail-settings', 'action' => 'mail.write']],
        ['mail-settings.php?pane=newsletter',    'Abonnés newsletter', $ico['users2'],   ['page' => 'mail-settings', 'action' => 'mail.newsletter']],
    ],

    'Pilotage' => [
        ['stats.php',      'Statistiques', $ico['stats'], ['page' => 'stats']],
        ['page_stats.php', 'Visites',      $ico['eye'],   ['page' => 'page_stats']],
    ],

    /* La machine : qui entre, ce qui est tracé, ce qu'on efface, ce qu'on
     * expose. « API » et « Maintenance » sont des réglages d'installation,
     * pas des réglages de course — ils rejoignent leurs semblables. */
    'Système' => [
        ['utilisateurs.php',            'Utilisateurs & Droits', $ico['users2'], ['roles' => ['admin']]],
        ['connexions.php',              'Connexions',            $ico['login'],  ['page' => 'connexions']],
        ['logs.php',                    'Logs',                  $ico['logs'],   ['page' => 'logs']],
        ['rgpd.php',                    'Données personnelles',  $ico['shield'] ?? $ico['logs'], ['page' => 'dashboard', 'roles' => ['admin']]],
        ['setting.php?tab=api',         'API',                   $ico['plug'],   ['page' => 'setting', 'action' => 'settings.tab.api']],
        ['setting.php?tab=maintenance', 'Maintenance',           $ico['wrench'], ['page' => 'setting', 'action' => 'settings.tab.maintenance']],
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

<!-- ═══════ SHELL v2.1 — onglets en haut, sous-menu flottant à gauche ═══════
     ⚠️ CLASSES .oc-* ET NON .jr-shell / .jr-nav / .jr-main.
     Ces trois-là habillent AUSSI public/espace-coureur/_layout-haut.php :
     les réécrire aurait changé l'espace coureur du site public.
     Habillage : css/admin-shell.css. -->
<div class="oc-shell" id="oc-app-container">

  <?php
  /* ── Pré-calcul de la navigation ───────────────────────────────────────────
   * Les SECTIONS de $navSections deviennent les onglets de la barre du haut ;
   * leurs entrées deviennent le sous-menu de gauche. On ne garde que ce que
   * l'utilisateur a le droit de voir, et on repère la section active.        */
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

  // « Tableau de bord » : onglet à part entière, sans sous-menu.
  $dashVisible = canAccessPage('dashboard');
  $dashHref    = 'dashboard.php';
  $dashLabel   = 'Tableau de bord';
  $dashActive  = ($currentPage === 'dashboard.php');
  if ($userRole === 'saisie') {
      $dashLabel  = 'Mes inscriptions';
      $dashHref   = 'saisie.php?tab=inscriptions';
      $dashActive = ($currentPage === 'saisie.php' && $saisieTab === 'inscriptions');
  }

  /* Aucune entrée active (page hors menu) : on ouvre quand même la première
   * section, sinon la colonne de gauche est vide sans qu'on sache pourquoi. */
  if ($activeSection === null && !$dashActive && !empty($renderSections)) {
      $activeSection = array_key_first($renderSections);
  }
  $subItems = ($activeSection !== null && !$dashActive) ? ($renderSections[$activeSection] ?? []) : [];

  /* « Mise à jour BDD » rejoint le sous-menu « Système » : elle
   * vivait en bas de l'ancienne barre latérale, qui n'existe plus. */
  $showUpdateLink = ($userRole === 'admin' && file_exists(dirname(__DIR__, 2) . '/update.php'));

  /* Cible d'un onglet = sa PREMIÈRE entrée visible. Cliquer « Réglages » doit
   * ouvrir un écran, pas une page vide. */
  $ocTabHref = static function (array $items): string {
      return $items ? $items[0][0] : '#';
  };

  $ocInitial = mb_strtoupper(mb_substr($userName !== '' ? $userName : 'U', 0, 1));

  /* ═══ Garde-fou « MODE TEST » (catch-all des mails) ═══
   * Calculé ICI, avant le rendu, parce qu'il s'affiche dans le sous-menu.
   *
   * POURQUOI IL DOIT RESTER VISIBLE : le risque n'est pas que le garde-fou
   * échoue — c'est de croire qu'on travaille sur la recette alors qu'on est
   * en production, ou l'inverse. Quelqu'un qui déclenche un envoi groupé en
   * pensant écrire à 300 inscrits doit voir tout de suite que rien ne partira.
   *
   * Réservé aux administrateurs : c'est une information d'environnement, pas
   * un message destiné aux rôles de saisie ou de consultation. */
  $ocCatchall = null;
  $ocCatchallTexte = '';
  if (!function_exists('mailCatchallStatus')) {
      $mgFile = dirname(__DIR__) . '/mail/mail_guard.php';
      if (is_file($mgFile)) { try { require_once $mgFile; } catch (\Throwable $e) {} }
  }
  if (function_exists('mailCatchallStatus') && currentRole() === 'admin') {
      $etat = mailCatchallStatus();
      if (!empty($etat['actif'])) {
          $ocCatchall = $etat;
          $ocCatchallTexte = $etat['bloquant']
              ? 'Aucune adresse valide : aucun mail ne part.'
              : 'Tous les mails partent vers ' . $etat['adresse'] . ', jamais aux inscrits.';
      }
  }
  ?>

  <!-- ═══════ BARRE DU HAUT ═══════ -->
  <header class="oc-top">
    <?php /* Le burger ouvre TOUTE la navigation (src/partials/mobile-nav.php),
             pas seulement le sous-menu de la section courante : sous 861 px les
             onglets du haut sont masqués, ils vivent dans le tiroir. Il est donc
             rendu sur chaque écran — y compris le tableau de bord, qui n'a pas
             de sous-menu mais a bien des sections à atteindre.
             Seule exception : un compte qui n'a accès à RIEN — le tiroir
             serait vide, et un bouton qui n'ouvre rien vaut moins que pas de
             bouton du tout. */ ?>
    <?php if ($dashVisible || !empty($renderSections)): ?>
      <button class="oc-burger" id="ocBurger" type="button" aria-label="Ouvrir le menu">
        <span></span><span></span><span></span>
      </button>
    <?php endif; ?>

    <a class="oc-brand" href="<?= htmlspecialchars($dashHref) ?>" title="Forbach en Rose">
      <?php if ($jrLogo): ?>
        <img src="<?= htmlspecialchars($jrLogo) ?>" alt="Forbach en Rose">
      <?php else: ?>
        <span class="name">Forbach en Rose</span>
      <?php endif; ?>
    </a>

    <nav class="oc-tabs">
      <?php if ($dashVisible): ?>
        <a class="<?= $dashActive ? 'is-active' : '' ?>" href="<?= htmlspecialchars($dashHref) ?>"><?= htmlspecialchars($dashLabel) ?></a>
      <?php endif; ?>
      <?php foreach ($renderSections as $sectionLabel => $visible): ?>
        <a class="<?= (!$dashActive && $sectionLabel === $activeSection) ? 'is-active' : '' ?>"
           href="<?= htmlspecialchars($ocTabHref($visible)) ?>"><?= htmlspecialchars($sectionLabel) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="oc-topright">
      <?php /* La recherche perd sa colonne : elle passe ici, et ses résultats
               tombent en menu déroulant (css/admin-shell.css). Elle a besoin de
               $jrCanSee, défini plus haut dans ce fichier. */ ?>
      <div class="oc-search"><?php include __DIR__ . '/recherche-admin.php'; ?></div>

      <button class="oc-user" id="ocAvatarBtn" type="button" title="<?= htmlspecialchars($userEmail) ?>">
        <span class="ava"><?= htmlspecialchars($ocInitial) ?></span>
        <span class="who">Bonjour <?= htmlspecialchars($userName) ?></span>
        <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
      </button>

      <!-- ═══════ MENU DU COMPTE ═══════
           Tout ce qui touche au compte tient ici, et NULLE PART AILLEURS.
           Le bloc utilisateur et la déconnexion du bas de l'ancienne barre
           latérale disaient deux fois la même chose. -->
      <div class="oc-usermenu" id="ocDropdown">
        <div class="head">
          <span class="ava"><?= htmlspecialchars($ocInitial) ?></span>
          <span class="id">
            <span class="name"><?= htmlspecialchars($userName) ?></span>
            <span class="mail"><?= htmlspecialchars($userEmail) ?></span>
          </span>
          <span class="role"><?= htmlspecialchars(ucfirst((string) $userRole)) ?></span>
        </div>
        <hr>

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

        <a href="#" id="ocProfileLink" data-pf-tab="password">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Mon compte</span>
        </a>
        <a href="#" class="oc-profile-link" data-pf-tab="auth-methods">
          <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>Mot de passe &amp; sécurité</span>
        </a>
        <a href="#" class="oc-profile-link" data-pf-tab="appearance">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 3v18"/></svg>
          <span>Apparence</span>
        </a>

        <hr>
        <a href="#" id="ocLogoutLink" class="is-danger">
          <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Déconnexion</span>
        </a>
        <div class="ver">Version 2.0.0</div>
      </div>
    </div>
  </header>

  <?php
  /* ═══ MENU DES PETITS ÉCRANS ═══
   * Les onglets du haut ET les sous-menus, réunis en un seul tiroir. On
   * réutilise exactement les listes déjà filtrées par les droits
   * ($renderSections) : une seconde liste écrite à la main finirait par
   * proposer un écran interdit, ou par en cacher un autorisé. */
  $mnTitre  = 'Navigation';
  $mnTop    = [];
  $mnGroups = [];
  if ($dashVisible) {
      $mnTop[] = ['href' => $dashHref, 'label' => $dashLabel, 'svg' => $ico['dashboard'], 'active' => $dashActive];
  }
  foreach ($renderSections as $sectionLabel => $visible) {
      $entrees = [];
      foreach ($visible as [$href, $label, $icon, $isActive]) {
          $e = ['href' => $href, 'label' => $label, 'svg' => $icon, 'active' => $isActive];
          // Pastille « Accès bénévoles » : même compteur que le sous-menu, tenu
          // à jour par le même script (sélection par classe, pas par id — deux
          // éléments ne peuvent pas porter le même id).
          if (strpos($href, 'tshirt-access.php') === 0) {
              $e['badge'] = (int) ($tshirtPendingCount ?? 0);
              $e['badgeClass'] = ' js-tshirt-badge';
          }
          $entrees[] = $e;
      }
      if ($showUpdateLink && $sectionLabel === 'Système') {
          $entrees[] = ['href' => '../update.php', 'label' => 'Mise à jour BDD', 'svg' => $ico['download'], 'active' => false];
      }
      $mnGroups[] = [
          'label' => $sectionLabel,
          // La section de l'écran courant s'ouvre d'emblée : on doit voir où
          // l'on se trouve avant de choisir où aller.
          'open'  => (!$dashActive && $sectionLabel === $activeSection),
          'items' => $entrees,
      ];
  }
  include __DIR__ . '/mobile-nav.php';
  ?>

  <div class="oc-body<?= empty($subItems) ? ' is-wide' : '' ?>">

    <?php if (!empty($subItems)): ?>
    <!-- ═══════ SOUS-MENU (seul élément flottant de la page) ═══════ -->
    <aside class="oc-sub" id="oc-sidebar">
      <div class="title"><?= htmlspecialchars((string) $activeSection) ?></div>

      <?php foreach ($subItems as [$href, $label, $icon, $isActive]): ?>
        <a class="item<?= $isActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>">
          <svg viewBox="0 0 24 24"><?= $icon ?></svg>
          <span><?= htmlspecialchars($label) ?></span>
          <?php if (strpos($href, 'tshirt-access.php') === 0): ?>
            <span class="jr-badge js-tshirt-badge<?= empty($tshirtPendingCount) ? ' d-none' : '' ?>" id="tshirtPendingBadge"><?= (int) ($tshirtPendingCount ?? 0) ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>

      <?php if ($showUpdateLink && $activeSection === 'Système'): ?>
        <a class="item" href="../update.php">
          <svg viewBox="0 0 24 24"><?= $ico['download'] ?></svg>
          <span>Mise à jour BDD</span>
        </a>
      <?php endif; ?>

      <?php /* Le garde-fou des mails se range au bas du sous-menu. En bandeau
               pleine largeur, il repoussait le titre de CHAQUE écran pour
               répéter la même phrase ; ici il reste sous les yeux sans manger
               le haut de la page. */ ?>
      <?php if ($ocCatchall): ?>
        <a class="oc-testmode<?= $ocCatchall['bloquant'] ? ' is-bloquant' : '' ?>"
           href="mail-settings.php?tab=google" title="<?= htmlspecialchars($ocCatchallTexte) ?>">
          <i class="bi bi-<?= $ocCatchall['bloquant'] ? 'shield-fill-exclamation' : 'cone-striped' ?>"></i>
          <span class="lab">Mode test</span>
          <span class="det"><?= htmlspecialchars($ocCatchallTexte) ?></span>
        </a>
      <?php endif; ?>
    </aside>
    <?php endif; ?>

  <?php // Rafraîchissement live de la pastille « Accès bénévoles » ?>
  <?php if (canDoAction('tshirt_access.approve')): ?>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  (function(){
    /* Sélection par CLASSE : la pastille existe en deux exemplaires depuis le
       menu mobile — celle du sous-menu et celle du tiroir. Un getElementById
       n'en aurait rafraîchi qu'une, et c'est justement celle du tiroir qui est
       visible sur téléphone. */
    var badges = document.querySelectorAll('.js-tshirt-badge');
    if (!badges.length) return;
    function refresh(){
      fetch('../admin-api.php?route=tshirt-admin', {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(res){
          if (!res || !res.ok) return;
          var n = (res.pending || []).length;
          badges.forEach(function(badge){
            badge.textContent = n;
            badge.classList.toggle('d-none', n === 0);
          });
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
  <div class="oc-page" id="oc-content">

    <?php /* ═══ Bandeau « MODE TEST » ═══
       Il vit désormais dans le SOUS-MENU (voir plus haut) : collé en haut du
       contenu, il poussait le titre vers le bas sur tous les écrans alors
       qu'il ne dit qu'une chose, toujours la même. Ici, on ne le rend en
       pleine largeur que sur les écrans SANS sous-menu, où il n'aurait
       nulle part où se ranger.

       ⚠️ ET SUR TÉLÉPHONE, TOUJOURS. Sous 861 px le sous-menu est masqué au
       profit du tiroir : laissé à sa seule place habituelle, l'avertissement
       disparaissait de 21 écrans sur 23 — exactement là où l'on risque le
       plus de croire qu'un envoi est parti aux inscrits. */ ?>
    <?php if (!empty($ocCatchall)): ?>
      <a class="oc-testmode is-large<?= empty($subItems) ? '' : ' is-mobile-only' ?><?= $ocCatchall['bloquant'] ? ' is-bloquant' : '' ?>" href="mail-settings.php?tab=google">
        <i class="bi bi-<?= $ocCatchall['bloquant'] ? 'shield-fill-exclamation' : 'cone-striped' ?>"></i>
        <span class="lab">Mode test</span>
        <span class="det"><?= htmlspecialchars($ocCatchallTexte) ?></span>
      </a>
    <?php endif; ?>

    <?php /* ⚠️ EN-TÊTE DE PAGE RENDU UNIQUEMENT SUR DEMANDE ($pageShowTitle).
             21 des 23 écrans d'administration affichent DÉJÀ leur propre <h1>
             avec son icône. En rendre un ici en plus donnait le titre deux
             fois — « Actualités » puis « Actualités ». Les deux écrans qui
             n'ont pas le leur (Réglages, Assistance) lèvent le drapeau.

             $pageIcon   : nom d'icône Bootstrap, sans le préfixe « bi- ».
             $pageLead   : phrase d'explication sous le titre.
             $pageActions: HTML des actions à droite, déjà échappé par la page. */ ?>
    <?php if (!empty($pageShowTitle)): ?>
    <header class="oc-pagehead">
      <div>
        <h1>
          <?php if (!empty($pageIcon)): ?><i class="bi bi-<?= htmlspecialchars($pageIcon) ?>"></i><?php endif; ?>
          <?= htmlspecialchars($pageSubtitle !== '' ? $pageSubtitle : $pageTitle) ?>
        </h1>
        <?php if (!empty($pageLead)): ?>
          <p class="sub"><?= htmlspecialchars($pageLead) ?></p>
        <?php endif; ?>
      </div>
      <?php if (!empty($pageActions)): ?>
        <div class="acts"><?= $pageActions ?></div>
      <?php endif; ?>
    </header>
    <?php endif; ?>

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
