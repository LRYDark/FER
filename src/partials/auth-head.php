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
?>
<script src="jr-theme/js/theme.js"></script>
<link rel="stylesheet" href="jr-theme/css/tokens.css">
<link rel="stylesheet" href="jr-theme/css/base.css">
<link rel="stylesheet" href="jr-theme/css/components.css">
<link rel="stylesheet" href="jr-theme/css/app.css">
<link rel="stylesheet" href="css/auth.css">
<?php if ($authAccent): ?>
<style>
:root {
  --accent-l: <?= $authAccent[0] ?>; --accent-l-strong: <?= $authAccent[1] ?>; --accent-l-ink: <?= $authAccent[2] ?>;
  --accent-d: <?= $authAccent[3] ?>; --accent-d-strong: <?= $authAccent[4] ?>; --accent-d-ink: <?= $authAccent[5] ?>;
}
</style>
<?php endif; ?>
