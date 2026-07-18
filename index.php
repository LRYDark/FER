<?php
// Vérifier si l'installation est nécessaire
// v2.0.0 : la config vit dans config/config.enc ; un ancien config/.env
// (pré-2.0.0) compte aussi comme installé (migration auto au chargement).
if (!file_exists(__DIR__ . '/config/config.enc') && !file_exists(__DIR__ . '/config/.env')) {
    header('Location: install');
    exit;
}

// Redirige vers une autre page
header("Location: public/accueil");
exit;
?>
