<?php
    $currentPage = basename($_SERVER['PHP_SELF']); // Ex: dashboard.php

    $pageTitles = [
        'dashboard.php' => 'Tableau de bord',
        'setting.php'   => 'Réglages',
        'albums.php'    => 'Albums',
        'partners.php'  => 'Partenaires',
        'news.php'  => 'Actualités',
        'stats.php'  => 'Statistiques',
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
      <?php if($role==='admin'): ?>
        <a href="setting.php" class="btn-action <?= $currentPage == 'setting.php' ? 'active' : '' ?>">Réglages</a>
        <a href="albums.php" class="btn-action <?= $currentPage == 'albums.php' ? 'active' : '' ?>">Albums</a>
        <a href="partners.php" class="btn-action <?= $currentPage == 'partners.php' ? 'active' : '' ?>">Partenaires</a>
        <a href="news.php" class="btn-action <?= $currentPage == 'news.php' ? 'active' : '' ?>">Actualités</a>
        <a href="stats.php" class="btn-action <?= $currentPage == 'stats.php' ? 'active' : '' ?>">Statistiques</a>
      <?php endif; ?>
    </div>

    <div class="top-actions">
        <?php if($currentPage == 'dashboard.php'): ?>
            <button id="modeTS" class="btn btn-outline-light">Remise T-shirts</button>
        <?php endif; ?>
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
        <li class="list-group-item small text-muted fw-semibold">Mode</li>
            <?php if($currentPage == 'dashboard.php'): ?>
                <li class="list-group-item d-flex align-items-center p-3">
                    <i class="bi bi-gear me-2 "></i>
                    <button id="modeTS" class="btn btn-link text-start p-0 flex-grow-1">Mode standard</button>
                </li>
            <?php endif; ?>
        <li class="list-group-item small text-muted fw-semibold">Actions rapides</li>
            <li class="list-group-item d-flex align-items-center p-3">
                <i class="bi bi-gear me-2 "></i>
                <a id="dashboard" href="dashboard.php" class="btn btn-link text-start p-0 flex-grow-1 <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">Tableau de bord</a>
            </li>
            <li class="list-group-item d-flex align-items-center p-3">
                <i class="bi bi-gear me-2 "></i>
                <a id="setting" href="setting.php" class="btn btn-link text-start p-0 flex-grow-1 <?= $currentPage == 'setting.php' ? 'active' : '' ?>">Réglages</a>
            </li>
            <li class="list-group-item d-flex align-items-center p-3">
                <i class="bi bi-gear me-2 "></i>
                <a id="albums" href="albums.php" class="btn btn-link text-start p-0 flex-grow-1 <?= $currentPage == 'albums.php' ? 'active' : '' ?>">Albums</a>
            </li>
            <li class="list-group-item d-flex align-items-center p-3">
                <i class="bi bi-gear me-2 "></i>
                <a id="partners" href="partners.php" class="btn btn-link text-start p-0 flex-grow-1 <?= $currentPage == 'partners.php' ? 'active' : '' ?>">Partenaires</a>
            </li>
            <li class="list-group-item d-flex align-items-center p-3">
                <i class="bi bi-gear me-2 "></i>
                <a id="news" href="news.php" class="btn btn-link text-start p-0 flex-grow-1 <?= $currentPage == 'news.php' ? 'active' : '' ?>">Actualités</a>
            </li>
            <li class="list-group-item d-flex align-items-center p-3">
                <i class="bi bi-gear me-2 "></i>
                <a id="news" href="stats.php" class="btn btn-link text-start p-0 flex-grow-1 <?= $currentPage == 'stats.php' ? 'active' : '' ?>">Statistiques</a>
            </li>

            <li class="list-group-item d-flex align-items-center p-3">
                <i class="bi bi-box-arrow-right me-2 text-danger"></i>
                <a id="logout_m"  class="btn btn-link text-start p-0 flex-grow-1">Déconnexion</a>
            </li>

            <?php if($currentPage == 'dashboard.php'): ?>
                <li class="list-group-item small text-muted fw-semibold mt-2">Inscriptions</li>

                <?php if($role!=='viewer'): ?>
                <li class="list-group-item px-3">
                    <button class="btn btn-rose w-100" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-circle me-1"></i> Nouvel inscrit
                    </button>
                </li>
                <?php endif; ?>

                <?php if($role==='admin'): ?>
                    <li class="list-group-item px-3">
                        <button class="btn btn-secondary w-100" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Import Excel
                        </button>
                    </li>
                <?php endif; ?>
                <?php if($role==='admin' || $role==='user'): ?>
                    <li class="list-group-item px-3">
                        <button class="btn btn-info w-100 export-excel-btn">Export Excel</button>
                    </li>
                <?php endif; ?>
                <?php if($role==='admin'): ?>
                    <li class="list-group-item px-3">
                        <button class="btn btn-danger w-100 archive-now-btn">Archiver <?= date('Y') ?></button>
                    </li>

                    <script>
                    // Utiliser des classes au lieu d'IDs pour éviter les conflits
                    document.querySelectorAll('.export-excel-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            window.location = '../config/api.php?route=export-excel';
                        });
                    });

                    document.querySelectorAll('.archive-now-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            if (!confirm('Tout archiver et réinitialiser les inscriptions ?')) return;

                            const res = await fetch('../config/api.php?route=archive-current', {
                                method: 'POST',
                                credentials: 'same-origin'
                            });
                            const json = await res.json();
                            if (json.ok) {
                                alert(`✅ ${json.archived} inscription(s) archivées (${json.year}).`);
                                location.reload();
                            } else {
                                alert('Erreur archivage : ' + JSON.stringify(json));
                            }
                        });
                    });
                    </script>
                    <li class="list-group-item px-3 pb-4">
                        <button class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#usersModal">
                        <i class="bi bi-people-fill me-1"></i> Utilisateurs
                        </button>
                    </li>
                <?php endif; ?>
            <?php endif; ?>
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