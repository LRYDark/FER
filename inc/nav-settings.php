<?php
    $currentPage = basename($_SERVER['PHP_SELF']); // Ex: dashboard.php

    $pageTitles = [
        'dashboard.php' => 'Tableau de bord',
        'setting.php'   => 'Réglages',
        'albums.php'    => 'Albums',
        'partners.php'  => 'Partenaires',
        ];

    // Définir le titre ou fallback par défaut
    $pageTitle = $pageTitles[$currentPage] ?? 'Page';
?>

<link href="../css/nav-settings.css" rel="stylesheet">
<!-- ═════════ HEADER ═════════ -->
<header class="hero position-relative">
  <!-- Bouton burger visible uniquement en mobile -->
  <button class="btn btn-outline-light d-lg-none position-absolute" style="top:.6rem;right:.6rem"
          data-bs-toggle="offcanvas" data-bs-target="#menuMobile">&#9776;</button>

  <!-- Contenu principal -->
  <div class="hero-inner text-center">
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
    <p class="mb-0">Gestion des inscriptions – Rôle : <strong><?= htmlspecialchars($role) ?></strong></p>

    <!-- BARRE ACTIONS – version desktop uniquement -->
    <div class="actions-flottantes-desktop d-none d-lg-flex">
      <a href="dashboard.php" class="btn-action <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">Tableau de bord</a>
      <a href="setting.php" class="btn-action <?= $currentPage == 'setting.php' ? 'active' : '' ?>">Réglages</a>
      <a href="albums.php" class="btn-action <?= $currentPage == 'albums.php' ? 'active' : '' ?>">Albums</a>
      <a href="partners.php" class="btn-action <?= $currentPage == 'partners.php' ? 'active' : '' ?>">Partenaires</a>
    </div>

    <div class="top-actions">
      <a id="logout" href="#" class="btn btn-outline-light">Déconnexion</a>
    </div>
  </div>
</header>

<!-- ═════════ OFFCANVAS MOBILE ═════════ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="menuMobile">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title mb-0">Menu</h5>
    <button class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body p-0">
    <ul class="list-group list-group-flush">
      <li class="list-group-item small text-muted fw-semibold">Actions rapides</li>
      <li class="list-group-item d-flex align-items-center p-3">
        <i class="bi bi-speedometer2 me-2 text-rose"></i>
        <a id="dashboard" href="dashboard.php" class="btn btn-link text-start p-0 flex-grow-1">Tableau de bord</a>
        <a id="setting" href="setting.php" class="btn btn-link text-start p-0 flex-grow-1">Réglages</a>
        <a id="partners" href="partners.php" class="btn btn-link text-start p-0 flex-grow-1">Partenaires</a>
      </li>
    </ul>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js"></script>
<script>
/* ══ LOGOUT ════ */
$('#logout, #logout_m').on('click',e=>{
  e.preventDefault();
  fetch('../config/api.php?route=logout').then(()=>location='../login.php');
});
</script>