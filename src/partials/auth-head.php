<?php
/**
 * <head> commun des pages d'authentification (layout jr-theme).
 * À inclure dans <head> après <title>. Suppose la page À LA RACINE du site
 * (login.php, reset-password.php, change-password.php, install.php, update.php).
 *
 * Accent par défaut = couleur primaire du site public (Réglages → Thème) ;
 * theme.js applique ensuite l'éventuelle préférence mémorisée de l'utilisateur
 * (localStorage, miroir de son profil admin).
 */
$authPrimary = '#db2777';
try {
    if (isset($pdo)) {
        $c = $pdo->query('SELECT theme_primary_color FROM setting WHERE id = 1 LIMIT 1')->fetchColumn();
        if ($c && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) $authPrimary = $c;
    }
} catch (\Throwable $e) {}
$authAccent = function_exists('jr_accent_vars_from_hex') ? jr_accent_vars_from_hex($authPrimary) : null;
if (!$authAccent) {
    // Repli (install.php : config.php pas encore chargé) — rose FER pré-dérivé
    $authAccent = ['#db2777', '#bc2266', '#ffffff', '#e668a0', '#ea82b0', '#ffffff'];
}

// Thème DÉDIÉ aux pages de connexion (séparé de l'admin). Si l'utilisateur est
// connecté (update.php, change-password), on lit sa préférence login_theme en
// base ; sinon (login, reset : pré-auth) le script client lira le localStorage.
$authLoginTheme = null;
try {
    if (isset($pdo) && isset($_SESSION['uid'])) {
        $st = $pdo->prepare('SELECT ui_prefs FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $raw = $st->fetchColumn();
        if ($raw) {
            $p = json_decode($raw, true);
            if (is_array($p) && in_array($p['login_theme'] ?? '', ['light', 'dark', 'system'], true)) {
                $authLoginTheme = $p['login_theme'];
            }
        }
    }
} catch (\Throwable $e) {}
?>
<?php // ?v=mtime : anti-cache
/* Préfixe des ressources. Vide par défaut : les pages qui incluent ce fichier
   sont à la racine (login.php, install.php, update.php…) et rien ne change pour
   elles. Une page plus profonde — l'espace coureur, dans public/espace-coureur/ —
   pose $authBase = '../../' AVANT l'include pour que css/ et js/ soient trouvés. */
$authBase = $authBase ?? '';
$authV = function (string $rel) use ($authBase) { $p = dirname(__DIR__, 2) . '/' . $rel; return $authBase . $rel . '?v=' . (@filemtime($p) ?: '1'); };
?>
<script src="<?= $authBase ?>js/theme.js"></script>
<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
/* Applique le thème des pages de connexion (indépendant de l'admin), APRÈS
   theme.js qui aurait posé le thème admin. Anti-flash : fond immédiat. */
(function () {
  var d = document.documentElement;
  var t = <?= $authLoginTheme !== null ? json_encode($authLoginTheme) : 'null' ?>;
  if (t === null) { try { t = localStorage.getItem('jr-login-theme'); } catch (e) {} }
  if (t !== 'dark' && t !== 'system') t = 'light';
  d.setAttribute('data-theme', t);
  var dark = t === 'dark' || (t === 'system' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  d.style.backgroundColor = dark ? '#05070D' : '#E9EDF4';
})();
</script>
<link rel="stylesheet" href="<?= $authV('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= $authV('css/base.css') ?>">
<link rel="stylesheet" href="<?= $authV('css/components.css') ?>">
<link rel="stylesheet" href="<?= $authV('css/app.css') ?>">
<link rel="stylesheet" href="<?= $authV('css/auth.css') ?>">
<?php if ($authAccent): ?>
<style>
:root {
  --accent-l: <?= $authAccent[0] ?>; --accent-l-strong: <?= $authAccent[1] ?>; --accent-l-ink: <?= $authAccent[2] ?>;
  --accent-d: <?= $authAccent[3] ?>; --accent-d-strong: <?= $authAccent[4] ?>; --accent-d-ink: <?= $authAccent[5] ?>;
}
</style>
<?php endif; ?>
