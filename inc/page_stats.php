<?php
require '../src/core/config.php';
requirePage('page_stats');
$role = currentRole();
// Cette page est purement consultative — aucune action d'écriture
require __DIR__ . '/../src/partials/navbar-data.php';
require '../src/content/tracker.php';

// Current period (default: today)
$period = $_GET['period'] ?? 'today';
if (!in_array($period, ['today', 'month', 'year'])) {
    $period = 'today';
}

// Month selector (only when period=month)
$selYear  = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
$selMonth = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('n');
if ($selMonth < 1 || $selMonth > 12) $selMonth = (int)date('n');
if ($selYear < 2020 || $selYear > 2100) $selYear = (int)date('Y');

// Get stats
$todayStats = getVisitStats($pdo, 'today');
if ($period === 'month') {
    $monthStats = getVisitStats($pdo, 'custom', $selYear, $selMonth);
} else {
    $monthStats = getVisitStats($pdo, 'month');
}
$yearStats  = getVisitStats($pdo, 'year');

// Active period stats
$activeStats = ${$period . 'Stats'} ?? $todayStats;

// Daily visits for chart
if ($period === 'month') {
    $dailyVisits = getDailyVisits($pdo, 'custom', $selYear, $selMonth);
    $dailyUnique = getDailyUniqueByDevice($pdo, 'custom', $selYear, $selMonth);
} else {
    $dailyVisits = getDailyVisits($pdo, $period);
    $dailyUnique = getDailyUniqueByDevice($pdo, $period);
}

// Period labels
$periodLabels = [
    'today' => "Aujourd'hui",
    'month' => 'Ce mois',
    'year'  => "Cette ann\u{00e9}e",
];

// French month names
$monthNames = ['', 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin',
               'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];

// Dropdown options: only months that have actual data + current month
$dropdownOptions = [];
$availableMonths = getAvailableMonths($pdo);
// Always include current month even if no data yet
$currentY = (int)date('Y');
$currentM = (int)date('n');
$hasCurrentMonth = false;
foreach ($availableMonths as $am) {
    if ((int)$am['y'] === $currentY && (int)$am['m'] === $currentM) $hasCurrentMonth = true;
    $dropdownOptions[] = [
        'y' => (int)$am['y'],
        'm' => (int)$am['m'],
        'label' => $monthNames[(int)$am['m']] . ' ' . $am['y'],
    ];
}
if (!$hasCurrentMonth) {
    array_unshift($dropdownOptions, [
        'y' => $currentY,
        'm' => $currentM,
        'label' => $monthNames[$currentM] . ' ' . $currentY,
    ]);
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statistiques de visites</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.stat-card {
    border: 1px solid #f0e8eb;
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
    background: #fff;
    transition: box-shadow 0.2s;
}
.stat-card:hover {
    box-shadow: 0 2px 12px rgba(196,87,122,.1);
}
.stat-card .stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #880e4f;
}
.stat-card .stat-label {
    font-size: 0.85rem;
    color: #5f4b52;
    margin-top: 0.25rem;
}
.period-tabs .nav-link {
    color: #5f4b52;
    border: 1px solid #f0e8eb;
    border-radius: 8px;
    margin-right: 0.5rem;
    padding: 0.4rem 1rem;
    font-size: 0.9rem;
    font-weight: 500;
}
.period-tabs .nav-link.active {
    background: #fce4ec;
    color: #880e4f;
    border-color: #f8bbd0;
    font-weight: 600;
}
.period-tabs .nav-link:hover:not(.active) {
    background: #faf7f8;
}
.month-select {
    font-size: 14px; font-weight: 600; color: #880e4f; border: 1px solid #f8bbd0;
    border-radius: 8px; padding: 6px 12px; background: #fce4ec; cursor: pointer;
}
.stats-table {
    font-size: 0.9rem;
}
.stats-table thead th {
    background: #faf7f8;
    color: #5f4b52;
    font-weight: 600;
    border-bottom: 2px solid #f0e8eb;
}
.stats-table td {
    color: #1e293b;
    border-bottom: 1px solid #f0e8eb;
}
.chart-container {
    position: relative;
    height: 280px;
    width: 100%;
}
</style>
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<h1 class="mb-3 fw-bold"><i class="bi bi-eye me-2"></i>Visites</h1>

<!-- Period tabs + month dropdown -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <ul class="nav period-tabs mb-0">
        <?php foreach ($periodLabels as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $period === $key ? 'active' : '' ?>" href="?period=<?= $key ?><?= $key === 'month' ? '&y='.$selYear.'&m='.$selMonth : '' ?>"><?= $label ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($period === 'month'): ?>
        <select class="month-select" data-action="month-navigate">
            <?php foreach ($dropdownOptions as $opt): ?>
                <option value="<?= $opt['y'] ?>-<?= $opt['m'] ?>" <?= ($opt['y'] == $selYear && $opt['m'] == $selMonth) ? 'selected' : '' ?>>
                    <?= $opt['label'] ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
</div>

<!-- Summary cards -->
<?php
$ratio = $activeStats['unique_visitors'] > 0
    ? round($activeStats['total_visits'] / $activeStats['unique_visitors'], 1)
    : 0;
$mobilePct = $activeStats['unique_visitors'] > 0
    ? round($activeStats['unique_mobile'] / $activeStats['unique_visitors'] * 100)
    : 0;
$desktopPct = $activeStats['unique_visitors'] > 0
    ? round($activeStats['unique_desktop'] / $activeStats['unique_visitors'] * 100)
    : 0;
?>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card d-flex align-items-center justify-content-center">
            <div class="text-center flex-shrink-0 pe-5">
                <div class="stat-value"><?= number_format($activeStats['unique_visitors']) ?></div>
                <div class="stat-label"><i class="bi bi-people me-1"></i>Visiteurs uniques</div>
            </div>
            <div class="d-flex flex-column gap-2 ps-5" style="border-left:2px solid #f0e8eb">
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size:1.1rem;font-weight:700;color:#16a34a"><?= number_format($activeStats['unique_mobile']) ?></span>
                    <span class="small text-muted"><i class="bi bi-phone"></i> Mobile (<?= $mobilePct ?>%)</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size:1.1rem;font-weight:700;color:#f59e0b"><?= number_format($activeStats['unique_desktop']) ?></span>
                    <span class="small text-muted"><i class="bi bi-pc-display"></i> PC (<?= $desktopPct ?>%)</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($activeStats['total_visits']) ?></div>
            <div class="stat-label"><i class="bi bi-eye me-1"></i>Pages vues</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-value"><?= $ratio ?></div>
            <div class="stat-label"><i class="bi bi-bar-chart me-1"></i>Vues / Visiteur</div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="card mb-4" style="border:1px solid #f0e8eb; border-radius:12px;">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h6 class="mb-0" style="color:#5f4b52; font-weight:600;">
                <i class="bi bi-graph-up me-1"></i>Visites par jour – <?= $period === 'month' ? $monthNames[$selMonth] . ' ' . $selYear : $periodLabels[$period] ?>
            </h6>
            <div class="d-flex gap-3 flex-wrap" id="chartToggles">
                <label class="form-check form-check-inline mb-0" style="cursor:pointer">
                    <input class="form-check-input" type="checkbox" id="tglPageViews" checked style="border-color:#c4577a">
                    <span class="form-check-label small fw-semibold" style="color:#c4577a"><i class="bi bi-eye me-1"></i>Pages vues</span>
                </label>
                <label class="form-check form-check-inline mb-0" style="cursor:pointer">
                    <input class="form-check-input" type="checkbox" id="tglUniqAll" checked style="border-color:#2563eb">
                    <span class="form-check-label small fw-semibold" style="color:#2563eb"><i class="bi bi-people me-1"></i>Visiteurs uniques</span>
                </label>
                <label class="form-check form-check-inline mb-0" style="cursor:pointer">
                    <input class="form-check-input" type="checkbox" id="tglUniqMobile" style="border-color:#16a34a">
                    <span class="form-check-label small fw-semibold" style="color:#16a34a"><i class="bi bi-phone me-1"></i>Mobile</span>
                </label>
                <label class="form-check form-check-inline mb-0" style="cursor:pointer">
                    <input class="form-check-input" type="checkbox" id="tglUniqDesktop" style="border-color:#f59e0b">
                    <span class="form-check-label small fw-semibold" style="color:#f59e0b"><i class="bi bi-pc-display me-1"></i>PC</span>
                </label>
            </div>
        </div>
        <div class="chart-container">
            <canvas id="visitsChart"></canvas>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Top pages -->
    <div class="col-lg-6">
        <div class="card" style="border:1px solid #f0e8eb; border-radius:12px;">
            <div class="card-body">
                <h6 class="mb-3" style="color:#5f4b52; font-weight:600;">
                    <i class="bi bi-file-earmark-text me-1"></i>Top 5 pages
                    <span class="ms-1" style="cursor:help;" data-bs-toggle="tooltip" data-bs-placement="top" title="Les 5 pages les plus visitees sur votre site. Chaque ligne correspond a une URL unique (avec ses parametres). Le nombre indique le total de pages vues sur la periode selectionnee."><i class="bi bi-info-circle text-muted" style="font-size:13px;"></i></span>
                </h6>
                <?php if (empty($activeStats['top_pages'])): ?>
                    <p class="text-muted small">Aucune donnee pour cette periode.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table stats-table mb-0">
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th class="text-end" style="width:100px;">Pages vues</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeStats['top_pages'] as $page): ?>
                                <tr>
                                    <td class="text-truncate" style="max-width:400px;" title="<?= htmlspecialchars($page['page_url']) ?>">
                                        <?php
                                        $parsed = parse_url($page['page_url']);
                                        $displayUrl = $parsed['path'] ?? $page['page_url'];
                                        if (!empty($parsed['query'])) $displayUrl .= '?' . $parsed['query'];
                                        echo htmlspecialchars($displayUrl);
                                        ?>
                                    </td>
                                    <td class="text-end fw-semibold"><?= number_format($page['visits']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if (!empty($activeStats['all_pages'])): ?>
                <div class="text-center mt-3">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalAllPages"><i class="bi bi-eye me-1"></i>Tout voir (<?= count($activeStats['all_pages']) ?>)</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top referers -->
    <div class="col-lg-6">
        <div class="card" style="border:1px solid #f0e8eb; border-radius:12px;">
            <div class="card-body">
                <h6 class="mb-3" style="color:#5f4b52; font-weight:600;">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Top 5 referents
                    <span class="ms-1" style="cursor:help;" data-bs-toggle="tooltip" data-bs-placement="top" title="D'ou viennent vos visiteurs. 'Visite directe' = l'URL a ete tapee dans le navigateur ou ajoutee en favori. Les autres domaines (google.com, facebook.com...) indiquent que le visiteur a clique un lien vers votre site depuis ce site."><i class="bi bi-info-circle text-muted" style="font-size:13px;"></i></span>
                </h6>
                <?php if (empty($activeStats['top_referers'])): ?>
                    <p class="text-muted small">Aucune donnee pour cette periode.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table stats-table mb-0">
                            <thead>
                                <tr>
                                    <th>Domaine</th>
                                    <th class="text-end" style="width:100px;">Pages vues</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeStats['top_referers'] as $ref): ?>
                                <tr>
                                    <td class="text-truncate" style="max-width:300px;">
                                        <?php if ($ref['referer'] === 'Visite directe'): ?>
                                            <span data-bs-toggle="tooltip" data-bs-placement="top" title="Le visiteur a tape votre URL directement dans le navigateur, utilise un favori, ou est arrive sans lien externe." style="cursor:help;border-bottom:1px dashed #94a3b8;"><?= htmlspecialchars($ref['referer']) ?></span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($ref['referer']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-semibold"><?= number_format($ref['visits']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if (!empty($activeStats['all_referers'])): ?>
                <div class="text-center mt-3">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalAllReferers"><i class="bi bi-eye me-1"></i>Tout voir (<?= count($activeStats['all_referers']) ?>)</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Toutes les pages -->
<div class="modal fade" id="modalAllPages" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content" style="border-radius:16px;border:none;">
      <div class="modal-header" style="border-bottom:1px solid #f0e8eb;">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Toutes les pages (<?= count($activeStats['all_pages'] ?? []) ?>)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" class="form-control mb-3" id="searchAllPages" placeholder="Rechercher une page..." style="border-radius:8px;">
        <div class="table-responsive">
          <table class="table stats-table mb-0">
            <thead><tr><th>URL</th><th class="text-end" style="width:100px;">Vues</th></tr></thead>
            <tbody id="tbodyAllPages">
              <?php foreach ($activeStats['all_pages'] ?? [] as $i => $page):
                $parsed = parse_url($page['page_url']);
                $displayUrl = $parsed['path'] ?? $page['page_url'];
                if (!empty($parsed['query'])) $displayUrl .= '?' . $parsed['query'];
              ?>
              <tr><td class="text-truncate" style="max-width:500px;" title="<?= htmlspecialchars($page['page_url']) ?>"><?= htmlspecialchars($displayUrl) ?></td><td class="text-end fw-semibold"><?= number_format($page['visits']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Tous les referents -->
<div class="modal fade" id="modalAllReferers" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content" style="border-radius:16px;border:none;">
      <div class="modal-header" style="border-bottom:1px solid #f0e8eb;">
        <h5 class="modal-title"><i class="bi bi-box-arrow-in-right me-2"></i>Tous les referents (<?= count($activeStats['all_referers'] ?? []) ?>)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" class="form-control mb-3" id="searchAllReferers" placeholder="Rechercher un domaine..." style="border-radius:8px;">
        <div class="table-responsive">
          <table class="table stats-table mb-0">
            <thead><tr><th>Domaine</th><th class="text-end" style="width:100px;">Vues</th></tr></thead>
            <tbody id="tbodyAllReferers">
              <?php foreach ($activeStats['all_referers'] ?? [] as $ref): ?>
              <tr><td class="text-truncate" style="max-width:500px;"><?php if ($ref['referer'] === 'Visite directe'): ?><span data-bs-toggle="tooltip" data-bs-placement="top" title="Le visiteur a tape votre URL directement dans le navigateur, utilise un favori, ou est arrive sans lien externe." style="cursor:help;border-bottom:1px dashed #94a3b8;"><?= htmlspecialchars($ref['referer']) ?></span><?php else: ?><?= htmlspecialchars($ref['referer']) ?><?php endif; ?></td><td class="text-end fw-semibold"><?= number_format($ref['visits']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Chart.js initialization -->
<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Init Bootstrap tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { new bootstrap.Tooltip(el); });

    // Recherche dans les modals stats
    function initStatsSearch(inputId, tbodyId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            var rows = document.getElementById(tbodyId).querySelectorAll('tr');
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
    initStatsSearch('searchAllPages', 'tbodyAllPages');
    initStatsSearch('searchAllReferers', 'tbodyAllReferers');

    // Init tooltips dans les modals à l'ouverture
    ['modalAllPages', 'modalAllReferers'].forEach(function(id) {
        var modal = document.getElementById(id);
        if (modal) modal.addEventListener('shown.bs.modal', function() {
            modal.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { new bootstrap.Tooltip(el); });
        });
    });
    var dailyPageViews = <?= json_encode($dailyVisits, JSON_FORCE_OBJECT) ?>;
    var dailyUniqAll   = <?= json_encode($dailyUnique['all'], JSON_FORCE_OBJECT) ?>;
    var dailyUniqMob   = <?= json_encode($dailyUnique['mobile'], JSON_FORCE_OBJECT) ?>;
    var dailyUniqDesk  = <?= json_encode($dailyUnique['desktop'], JSON_FORCE_OBJECT) ?>;

    // Merge all dates
    var allDates = {};
    [dailyPageViews, dailyUniqAll, dailyUniqMob, dailyUniqDesk].forEach(function(obj) {
        Object.keys(obj).forEach(function(d) { allDates[d] = true; });
    });
    var labels = Object.keys(allDates).sort();

    if (labels.length === 0) {
        document.getElementById('visitsChart').parentNode.innerHTML =
            '<p class="text-muted text-center py-5">Aucune donnee pour cette periode.</p>';
        return;
    }

    var displayLabels = labels.map(function(d) {
        var p = d.split('-');
        return p[2] + '/' + p[1];
    });

    function mapData(obj) {
        return labels.map(function(d) { return obj[d] || 0; });
    }

    var datasets = [
        {
            label: 'Pages vues',
            data: mapData(dailyPageViews),
            backgroundColor: 'rgba(196, 87, 122, 0.6)',
            borderColor: 'rgba(196, 87, 122, 1)',
            borderWidth: 1, borderRadius: 4, maxBarThickness: 40,
            _toggle: 'tglPageViews'
        },
        {
            label: 'Visiteurs uniques',
            data: mapData(dailyUniqAll),
            backgroundColor: 'rgba(37, 99, 235, 0.6)',
            borderColor: 'rgba(37, 99, 235, 1)',
            borderWidth: 1, borderRadius: 4, maxBarThickness: 40,
            _toggle: 'tglUniqAll'
        },
        {
            label: 'Mobile',
            data: mapData(dailyUniqMob),
            backgroundColor: 'rgba(22, 163, 74, 0.6)',
            borderColor: 'rgba(22, 163, 74, 1)',
            borderWidth: 1, borderRadius: 4, maxBarThickness: 40,
            hidden: true,
            _toggle: 'tglUniqMobile'
        },
        {
            label: 'PC',
            data: mapData(dailyUniqDesk),
            backgroundColor: 'rgba(245, 158, 11, 0.6)',
            borderColor: 'rgba(245, 158, 11, 1)',
            borderWidth: 1, borderRadius: 4, maxBarThickness: 40,
            hidden: true,
            _toggle: 'tglUniqDesktop'
        }
    ];

    var chart = new Chart(document.getElementById('visitsChart'), {
        type: 'bar',
        data: { labels: displayLabels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleFont: { size: 13 },
                    bodyFont: { size: 13 },
                    padding: 10,
                    cornerRadius: 8,
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, color: '#9e8a92', font: { size: 12 } },
                    grid: { color: '#f0e8eb' }
                },
                x: {
                    ticks: { color: '#9e8a92', font: { size: 11 }, maxRotation: 45 },
                    grid: { display: false }
                }
            }
        }
    });

    // Toggle checkboxes
    ['tglPageViews', 'tglUniqAll', 'tglUniqMobile', 'tglUniqDesktop'].forEach(function(id, idx) {
        document.getElementById(id).addEventListener('change', function() {
            chart.data.datasets[idx].hidden = !this.checked;
            chart.update();
        });
    });

    // Month dropdown navigation
    var monthSel = document.querySelector('[data-action="month-navigate"]');
    if (monthSel) {
        monthSel.addEventListener('change', function() {
            var parts = this.value.split('-');
            window.location = '?period=month&y=' + parts[0] + '&m=' + parts[1];
        });
    }
});
</script>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
