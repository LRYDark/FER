<?php
// Vérifier si l'installation est nécessaire
if (!file_exists(__DIR__ . '/config/.env')) {
    header('Location: install.php');
    exit;
}

// Redirige vers une autre page
header("Location: public/register.php");
exit;
?>
