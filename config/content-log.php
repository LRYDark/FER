<?php
/**
 * Journal d'activité des contenus (albums, partenaires, actualités, timeline).
 *
 * Trace, pour chaque page de contenu : création, modification, mise en
 * corbeille, restauration et suppression définitive d'un élément, avec
 * l'auteur de l'action et l'horodatage.
 *
 * Table : `content_logs`.
 * Affiché via l'onglet « Logs » des pages concernées, sous réserve du droit
 * général `content.logs.view` (cf. permCatalog() dans config.php).
 */

if (!function_exists('logContentAction')) {

/**
 * Enregistre une action sur un contenu.
 *
 * @param string   $contentType 'albums' | 'partners' | 'news' | 'timeline'
 * @param string   $action      'create' | 'edit' | 'trash' | 'restore' | 'delete'
 * @param int|null $entityId    Identifiant de l'élément (null si déjà supprimé)
 * @param string   $entityTitle Libellé lisible (capturé au moment de l'action)
 * @param string   $entityType  Sous-type libre ('item', 'article', 'année', 'album'…)
 */
function logContentAction(PDO $pdo, string $contentType, string $action, ?int $entityId, string $entityTitle, string $entityType = ''): void
{
    try {
        $title = trim($entityTitle);
        if ($title === '') $title = '(sans titre)';
        $stmt = $pdo->prepare(
            'INSERT INTO content_logs (content_type, entity_type, entity_id, entity_title, action, user_id, user_email)
             VALUES (:ct, :et, :eid, :title, :action, :uid, :email)'
        );
        $stmt->execute([
            'ct'     => $contentType,
            'et'     => $entityType !== '' ? $entityType : null,
            'eid'    => $entityId,
            'title'  => mb_substr($title, 0, 255),
            'action' => $action,
            'uid'    => function_exists('currentUserId') ? currentUserId() : ($_SESSION['uid'] ?? null),
            'email'  => $_SESSION['email'] ?? '',
        ]);
        // Bornage : on conserve les 3000 dernières entrées (toutes pages confondues).
        $pdo->exec('DELETE FROM content_logs WHERE id NOT IN (SELECT id FROM (SELECT id FROM content_logs ORDER BY id DESC LIMIT 3000) AS t)');
    } catch (\Throwable $e) {
        error_log('[CONTENT_LOG] ' . $e->getMessage());
    }
}

/** Derniers logs d'un type de contenu (retourne [] si la table n'existe pas). */
function fetchContentLogs(PDO $pdo, string $contentType, int $limit = 300): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM content_logs WHERE content_type = :ct ORDER BY created_at DESC, id DESC LIMIT ' . max(1, (int)$limit)
        );
        $stmt->execute(['ct' => $contentType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/** Libellé + classe Bootstrap + icône pour une action. */
function contentLogActionMeta(string $action): array
{
    switch ($action) {
        case 'create':  return ['Création',              'success',          'bi-plus-circle'];
        case 'edit':    return ['Modification',          'primary',          'bi-pencil'];
        case 'trash':   return ['Mise en corbeille',     'warning text-dark', 'bi-trash3'];
        case 'restore': return ['Restauration',          'info text-dark',   'bi-arrow-counterclockwise'];
        case 'delete':  return ['Suppression définitive', 'danger',          'bi-x-octagon'];
        default:        return [ucfirst($action),        'secondary',        'bi-dot'];
    }
}

/** Rend le tableau HTML complet des logs (onglet « Logs »). */
function renderContentLogs(array $logs): string
{
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    if (empty($logs)) {
        return '<div class="text-center text-muted py-5">'
             . '<i class="bi bi-clipboard-data" style="font-size:3rem;"></i>'
             . '<p class="mt-2 mb-0">Aucune activité enregistrée pour le moment.</p></div>';
    }
    $out  = '<div class="table-responsive"><table class="table table-hover align-middle mb-0">'
          . '<thead><tr>'
          . '<th>Date</th><th>Action</th><th>Élément</th><th>Type</th><th>Par</th>'
          . '</tr></thead><tbody>';
    foreach ($logs as $l) {
        [$label, $cls, $icon] = contentLogActionMeta((string)($l['action'] ?? ''));
        $date  = !empty($l['created_at']) ? date('d/m/Y à H:i', strtotime($l['created_at'])) : '—';
        $who   = trim((string)($l['user_email'] ?? ''));
        if ($who === '') $who = !empty($l['user_id']) ? ('Utilisateur #' . (int)$l['user_id']) : 'Système';
        $title = trim((string)($l['entity_title'] ?? '')); if ($title === '') $title = '(sans titre)';
        $idTxt = !empty($l['entity_id']) ? ' <span class="text-muted small">#' . (int)$l['entity_id'] . '</span>' : '';
        $out  .= '<tr>'
               . '<td class="text-nowrap small">' . $h($date) . '</td>'
               . '<td><span class="badge bg-' . $cls . '"><i class="bi ' . $icon . ' me-1"></i>' . $h($label) . '</span></td>'
               . '<td>' . $h($title) . $idTxt . '</td>'
               . '<td class="small text-muted">' . $h((string)($l['entity_type'] ?? '')) . '</td>'
               . '<td class="small">' . $h($who) . '</td>'
               . '</tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

} // function_exists guard
