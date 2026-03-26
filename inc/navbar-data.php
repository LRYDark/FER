<?php
/**
 * Navbar Data - Charge les données nécessaires pour la navbar moderne
 * Ce fichier doit être inclus AVANT navbar-modern.php
 */

// Vérifier si $pdo existe
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/config.php';
}

// Récupération des paramètres globaux
try {
    $stmt = $pdo->prepare('SELECT * FROM setting WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => 1]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Variables globales pour la navbar et le footer
    $link_instagram = $data['link_instagram'] ?? null;
    $link_facebook = $data['link_facebook'] ?? null;
    $link_twitter = $data['link_twitter'] ?? null;
    $link_youtube = $data['link_youtube'] ?? null;
    $link_cancer = $data['link_cancer'] ?? null;
} catch (PDOException $e) {
    // Valeurs par défaut si erreur
    $link_instagram = null;
    $link_facebook = null;
    $link_twitter = null;
    $link_youtube = null;
    $link_cancer = null;
}

// Récupération des années photos pour le menu (uniquement publiées)
try {
    $stmtPhotos = $pdo->prepare("SELECT id, year, title FROM photo_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC LIMIT 10");
    $stmtPhotos->execute();
    $galeries = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $galeries = [];
}

// Récupération des actualités pour le menu (uniquement publiées)
try {
    $stmtActus = $pdo->prepare("SELECT id, title_article as title, img_article, date_publication FROM news WHERE deleted_at IS NULL AND status = 'published' ORDER BY date_publication DESC LIMIT 10");
    $stmtActus->execute();
    $actualites = $stmtActus->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $actualites = [];
}

// Récupération des années partenaires pour le menu (uniquement publiées)
try {
    $stmtPartners = $pdo->prepare("SELECT id, year, title FROM partners_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC LIMIT 10");
    $stmtPartners->execute();
    $partenaires = $stmtPartners->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $partenaires = [];
}

// Détecter si plus de 5 éléments pour afficher en 2 colonnes
$actualites_cols2 = count($actualites) > 5;
$galeries_cols2 = count($galeries) > 5;
$partenaires_cols2 = count($partenaires) > 5;
