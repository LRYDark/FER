<?php
/**
 * Page Visit Tracker
 * Tracks page visits and provides statistics functions.
 */

// Ensure $pdo is available
if (!isset($pdo)) {
    require_once __DIR__ . '/config.php';
}

/**
 * Record a page visit with anonymized IP.
 */
function trackPageVisit()
{
    global $pdo;
    if (!$pdo) return;

    // Build page URL
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $pageUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');

    // Anonymize IP: zero the last octet (IPv4) or last group (IPv6)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = preg_replace('/\.\d+$/', '.0', $ip);
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $ip = preg_replace('/:[^:]+$/', ':0', $ip);
    }

    $userAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $referer   = mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);

    try {
        $stmt = $pdo->prepare('INSERT INTO page_visits (page_url, visitor_ip, user_agent, referer) VALUES (:url, :ip, :ua, :ref)');
        $stmt->execute([
            'url' => mb_substr($pageUrl, 0, 500),
            'ip'  => $ip,
            'ua'  => $userAgent ?: null,
            'ref' => $referer ?: null,
        ]);
    } catch (PDOException $e) {
        error_log('Tracker insert failed: ' . $e->getMessage());
    }
}

/**
 * Get visit statistics for a given period.
 *
 * @param PDO    $pdo
 * @param string $period  'today', 'month', or 'year'
 * @return array  [total_visits, unique_visitors, top_pages, top_referers]
 */
function getVisitStats(PDO $pdo, string $period, ?int $year = null, ?int $month = null): array
{
    try {
        if ($year && $month) {
            $where = "YEAR(visited_at) = $year AND MONTH(visited_at) = $month";
        } elseif ($period === 'yesterday') {
            $where = "DATE(visited_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } else {
            switch ($period) {
                case 'today':
                    $where = "DATE(visited_at) = CURDATE()";
                    break;
                case 'month':
                    $where = "YEAR(visited_at) = YEAR(CURDATE()) AND MONTH(visited_at) = MONTH(CURDATE())";
                    break;
                case 'year':
                    $where = "YEAR(visited_at) = YEAR(CURDATE())";
                    break;
                default:
                    $where = "1=1";
            }
        }

        // Totals
        $row = $pdo->query("SELECT COUNT(*) AS total_visits, COUNT(DISTINCT visitor_ip) AS unique_visitors FROM page_visits WHERE $where")->fetch();

        // Top pages
        $topPages = $pdo->query("SELECT page_url, COUNT(*) AS visits FROM page_visits WHERE $where GROUP BY page_url ORDER BY visits DESC LIMIT 10")->fetchAll();

        // Top referers (exclude empty)
        $topReferers = $pdo->query("SELECT referer, COUNT(*) AS visits FROM page_visits WHERE $where AND referer IS NOT NULL AND referer != '' GROUP BY referer ORDER BY visits DESC LIMIT 5")->fetchAll();

        return [
            'total_visits'    => (int)($row['total_visits'] ?? 0),
            'unique_visitors' => (int)($row['unique_visitors'] ?? 0),
            'top_pages'       => $topPages,
            'top_referers'    => $topReferers,
        ];
    } catch (PDOException $e) {
        error_log('Tracker getVisitStats failed: ' . $e->getMessage());
        return [
            'total_visits'    => 0,
            'unique_visitors' => 0,
            'top_pages'       => [],
            'top_referers'    => [],
        ];
    }
}

/**
 * Get daily visit counts for a period (for Chart.js).
 *
 * @param PDO    $pdo
 * @param string $period  'today', 'month', or 'year'
 * @return array  ['2026-03-01' => 12, ...]
 */
/**
 * Get available months that have visit data.
 */
function getAvailableMonths(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT DISTINCT YEAR(visited_at) AS y, MONTH(visited_at) AS m FROM page_visits ORDER BY y DESC, m DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function getDailyVisits(PDO $pdo, string $period, ?int $year = null, ?int $month = null): array
{
    try {
        if ($year && $month) {
            $where = "YEAR(visited_at) = $year AND MONTH(visited_at) = $month";
        } elseif ($period === 'yesterday') {
            $where = "DATE(visited_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } else {
            switch ($period) {
                case 'today':
                    $where = "DATE(visited_at) = CURDATE()";
                    break;
                case 'month':
                    $where = "YEAR(visited_at) = YEAR(CURDATE()) AND MONTH(visited_at) = MONTH(CURDATE())";
                    break;
                case 'year':
                    $where = "YEAR(visited_at) = YEAR(CURDATE())";
                    break;
                default:
                    $where = "1=1";
            }
        }

        $rows = $pdo->query("SELECT DATE(visited_at) AS day, COUNT(*) AS visits FROM page_visits WHERE $where GROUP BY DATE(visited_at) ORDER BY day ASC")->fetchAll();

        $result = [];
        foreach ($rows as $r) {
            $result[$r['day']] = (int)$r['visits'];
        }
        return $result;
    } catch (PDOException $e) {
        error_log('Tracker getDailyVisits failed: ' . $e->getMessage());
        return [];
    }
}
