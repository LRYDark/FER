<?php
require '../config/config.php';
requireRole(['admin']);
$role = currentRole();
require 'navbar-data.php';
require '../config/tracker.php';

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
} else {
    $dailyVisits = getDailyVisits($pdo, $period);
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
<?php include 'navbar-admin.php'; ?>

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
        <select class="month-select" onchange="window.location.href='?period=month&y='+this.value.split('-')[0]+'&m='+this.value.split('-')[1]">
            <?php foreach ($dropdownOptions as $opt): ?>
                <option value="<?= $opt['y'] ?>-<?= $opt['m'] ?>" <?= ($opt['y'] == $selYear && $opt['m'] == $selMonth) ? 'selected' : '' ?>>
                    <?= $opt['label'] ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($activeStats['unique_visitors']) ?></div>
            <div class="stat-label"><i class="bi bi-people me-1"></i>Visiteurs uniques</div>
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
            <?php
            $ratio = $activeStats['unique_visitors'] > 0
                ? round($activeStats['total_visits'] / $activeStats['unique_visitors'], 1)
                : 0;
            ?>
            <div class="stat-value"><?= $ratio ?></div>
            <div class="stat-label"><i class="bi bi-bar-chart me-1"></i>Vues / Visiteur</div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="card mb-4" style="border:1px solid #f0e8eb; border-radius:12px;">
    <div class="card-body">
        <h6 class="mb-3" style="color:#5f4b52; font-weight:600;">
            <i class="bi bi-graph-up me-1"></i>Visites par jour – <?= $period === 'month' ? $monthNames[$selMonth] . ' ' . $selYear : $periodLabels[$period] ?>
        </h6>
        <div class="chart-container">
            <canvas id="visitsChart"></canvas>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Top pages -->
    <div class="col-lg-7">
        <div class="card" style="border:1px solid #f0e8eb; border-radius:12px;">
            <div class="card-body">
                <h6 class="mb-3" style="color:#5f4b52; font-weight:600;">
                    <i class="bi bi-file-earmark-text me-1"></i>Top 10 pages
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
                                        echo htmlspecialchars($parsed['path'] ?? $page['page_url']);
                                        ?>
                                    </td>
                                    <td class="text-end fw-semibold"><?= number_format($page['visits']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top referers -->
    <div class="col-lg-5">
        <div class="card" style="border:1px solid #f0e8eb; border-radius:12px;">
            <div class="card-body">
                <h6 class="mb-3" style="color:#5f4b52; font-weight:600;">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Top 5 referents
                </h6>
                <?php if (empty($activeStats['top_referers'])): ?>
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
                                <?php foreach ($activeStats['top_referers'] as $ref): ?>
                                <tr>
                                    <td class="text-truncate" style="max-width:300px;" title="<?= htmlspecialchars($ref['referer']) ?>">
                                        <?php
                                        $parsedRef = parse_url($ref['referer']);
                                        echo htmlspecialchars($parsedRef['host'] ?? $ref['referer']);
                                        ?>
                                    </td>
                                    <td class="text-end fw-semibold"><?= number_format($ref['visits']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dailyData = <?= json_encode($dailyVisits, JSON_FORCE_OBJECT) ?>;
    const labels = Object.keys(dailyData);
    const values = Object.values(dailyData);

    if (labels.length === 0) {
        document.getElementById('visitsChart').parentNode.innerHTML =
            '<p class="text-muted text-center py-5">Aucune donnee pour cette periode.</p>';
        return;
    }

    // Format labels for display
    const displayLabels = labels.map(function(d) {
        const parts = d.split('-');
        return parts[2] + '/' + parts[1];
    });

    new Chart(document.getElementById('visitsChart'), {
        type: 'bar',
        data: {
            labels: displayLabels,
            datasets: [{
                label: 'Visites',
                data: values,
                backgroundColor: 'rgba(196, 87, 122, 0.6)',
                borderColor: 'rgba(196, 87, 122, 1)',
                borderWidth: 1,
                borderRadius: 4,
                maxBarThickness: 40,
            }]
        },
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
                    ticks: {
                        precision: 0,
                        color: '#9e8a92',
                        font: { size: 12 }
                    },
                    grid: { color: '#f0e8eb' }
                },
                x: {
                    ticks: {
                        color: '#9e8a92',
                        font: { size: 11 },
                        maxRotation: 45,
                    },
                    grid: { display: false }
                }
            }
        }
    });
});
</script>

<?php include 'admin-footer.php'; ?>
</body>
</html>
