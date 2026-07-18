<?php
/**
 * Navbar Data - Charge les données nécessaires pour la navbar moderne
 * Ce fichier doit être inclus AVANT navbar-modern.php
 */

// Vérifier si $pdo existe
if (!isset($pdo)) {
    require_once __DIR__ . '/../core/config.php';
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
    $navbar_logo = $data['navbar_logo'] ?? 'logo_fer_rose.png';
    $footer_logo = $data['footer_logo'] ?? 'logo_blanc.png';
} catch (PDOException $e) {
    // Valeurs par défaut si erreur
    $link_instagram = null;
    $link_facebook = null;
    $link_twitter = null;
    $link_youtube = null;
    $link_cancer = null;
    $navbar_logo = 'logo_fer_rose.png';
    $footer_logo = 'logo_blanc.png';
}

// Vérifier si la colonne created_at existe
$hasCreatedAt_photos = false;
$hasCreatedAt_partners = false;
try { $pdo->query("SELECT created_at FROM photo_years LIMIT 0"); $hasCreatedAt_photos = true; } catch (PDOException $e) {}
try { $pdo->query("SELECT created_at FROM partners_years LIMIT 0"); $hasCreatedAt_partners = true; } catch (PDOException $e) {}

// Récupération des années photos pour le menu (uniquement publiées)
try {
    $photosCols = "id, year, title" . ($hasCreatedAt_photos ? ", created_at" : "");
    $stmtPhotos = $pdo->prepare("SELECT $photosCols FROM photo_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC LIMIT 10");
    $stmtPhotos->execute();
    $galeries = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $galeries = [];
}

// Récupération des actualités pour le menu (uniquement publiées)
try {
    // desc_article est inclus pour permettre le fallback "1ère image du contenu"
    // dans la section "Dernières actualités" de l'accueil (cf. renderAccueilSection_news).
    $stmtActus = $pdo->prepare("SELECT id, title_article as title, img_article, desc_article, date_publication FROM news WHERE deleted_at IS NULL AND status = 'published' ORDER BY date_publication DESC LIMIT 10");
    $stmtActus->execute();
    $actualites = $stmtActus->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $actualites = [];
}

// Récupération des années partenaires pour le menu (uniquement publiées)
try {
    $partnersCols = "id, year, title" . ($hasCreatedAt_partners ? ", created_at" : "");
    $stmtPartners = $pdo->prepare("SELECT $partnersCols FROM partners_years WHERE deleted_at IS NULL AND status = 'published' ORDER BY year DESC LIMIT 10");
    $stmtPartners->execute();
    $partenaires = $stmtPartners->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $partenaires = [];
}

// Compter les éléments récents (moins de 7 jours)
$sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

$newActuCount = 0;
$newActuIds = [];
foreach ($actualites as $actu) {
    if (!empty($actu['date_publication']) && $actu['date_publication'] >= $sevenDaysAgo) {
        $newActuCount++;
        $newActuIds[] = $actu['id'];
    }
}

$newPhotosCount = 0;
$newPhotosIds = [];
foreach ($galeries as $gal) {
    if (!empty($gal['created_at']) && $gal['created_at'] >= $sevenDaysAgo) {
        $newPhotosCount++;
        $newPhotosIds[] = $gal['id'];
    }
}

$newPartenairesCount = 0;
$newPartenairesIds = [];
foreach ($partenaires as $part) {
    if (!empty($part['created_at']) && $part['created_at'] >= $sevenDaysAgo) {
        $newPartenairesCount++;
        $newPartenairesIds[] = $part['id'];
    }
}

// Demandes d'accès « Remise T-shirts » en attente (pastille navbar).
// Uniquement pour la campagne courante ouverte, ET seulement pour les utilisateurs
// autorisés à valider les demandes (droit tshirt_access.approve).
$tshirtPendingCount = 0;
if (function_exists('canDoAction') && canDoAction('tshirt_access.approve')) {
    try {
        $cfgT = $pdo->query("SELECT enabled, campaign_token, expires_at FROM tshirt_access WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($cfgT && !empty($cfgT['enabled']) && !empty($cfgT['campaign_token'])
            && (empty($cfgT['expires_at']) || strtotime($cfgT['expires_at']) >= time())) {
            $stT = $pdo->prepare("SELECT COUNT(*) FROM tshirt_access_sessions WHERE campaign_token = ? AND status = 'pending'");
            $stT->execute([$cfgT['campaign_token']]);
            $tshirtPendingCount = (int) $stT->fetchColumn();
        }
    } catch (\Throwable $e) { $tshirtPendingCount = 0; }
}

// Détecter si plus de 5 éléments pour afficher en 2 colonnes
$actualites_cols2 = count($actualites) > 5;
$galeries_cols2 = count($galeries) > 5;
$partenaires_cols2 = count($partenaires) > 5;
