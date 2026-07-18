<?php
/**
 * Helpers pour la mise en page éditable de la page d'accueil.
 *
 * Format V2 : la page est composée d'une liste de LIGNES, chaque ligne contient
 * 1 à 3 COLONNES, chaque colonne contient une SECTION et a une largeur (système
 * Bootstrap 12 colonnes : la somme des widths d'une ligne doit être ≤ 12).
 *
 *   $layout = [
 *     [
 *       'id'      => 'row_xxxx',
 *       'columns' => [
 *         [
 *           'width'   => 12,                       // 12 = pleine largeur, 6 = moitié, etc.
 *           'section' => [
 *             'type'    => 'reg_bar' | 'partners' | 'timeline' | 'news' | 'custom',
 *             'visible' => true | false,
 *             // pour les blocs custom :
 *             'id'      => 'custom_xxxxx',
 *             'title'   => 'Nom interne',
 *             'content' => '<p>HTML...</p>',
 *           ],
 *         ],
 *         // ... autres colonnes éventuelles
 *       ],
 *     ],
 *     // ... autres lignes
 *   ];
 *
 * Le Héro (vidéo + countdown + CTA) n'est jamais dans le layout : il reste
 * fixé en haut.
 */

/**
 * Sections pré-définies disponibles.
 */
function accueilPredefinedSections(): array
{
    return [
        'reg_bar'     => ['label' => "Compteur d'inscrits + recherche", 'icon' => 'bi-people-fill'],
        'partners'    => ['label' => 'Bandeau Partenaires',              'icon' => 'bi-handshake'],
        'timeline'    => ['label' => 'Historique (Timeline)',            'icon' => 'bi-clock-history'],
        'news'        => ['label' => 'Dernières actualités',             'icon' => 'bi-newspaper'],
        'start_point' => ['label' => 'Retrouver le départ (carte)',      'icon' => 'bi-geo-alt-fill'],
        'newsletter'  => ['label' => 'Rester informé (newsletter)',       'icon' => 'bi-envelope-heart'],
    ];
}

/**
 * Largeurs autorisées (toutes les valeurs 1..12 du système Bootstrap 12-col).
 * Permet tous les presets : 2/10, 3/9, 4/8, 5/7, etc. + les classiques.
 */
function accueilAllowedWidths(): array
{
    return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
}

/**
 * Construit une nouvelle ligne avec une seule colonne pleine largeur.
 */
function newLayoutRow(array $section, int $width = 12): array
{
    return [
        'id'      => 'row_' . bin2hex(random_bytes(4)),
        'align'   => 'left',
        'valign'  => 'center',
        'columns' => [
            ['width' => $width, 'section' => $section],
        ],
    ];
}

/**
 * Alignements verticaux autorisés (utile en multi-col quand les colonnes ont des
 * hauteurs différentes : place le contenu en haut/centre/bas de la ligne).
 */
function accueilAllowedValigns(): array
{
    return ['top', 'center', 'bottom'];
}

/**
 * Alignements horizontaux autorisés pour une ligne (utile quand la somme des
 * largeurs < 12 : permet de centrer ou de coller à droite les colonnes).
 */
function accueilAllowedAligns(): array
{
    return ['left', 'center', 'right'];
}

/**
 * Layout par défaut (V2) : 4 lignes pleine largeur, tout visible.
 */
function accueilDefaultLayout(): array
{
    return [
        newLayoutRow(['type' => 'reg_bar',  'visible' => true]),
        newLayoutRow(['type' => 'partners', 'visible' => true]),
        newLayoutRow(['type' => 'timeline', 'visible' => true]),
        newLayoutRow(['type' => 'news',     'visible' => true]),
    ];
}

/**
 * Détecte si un tableau est au format V1 (ancien : sections plates).
 */
function isV1Layout(array $layout): bool
{
    if (empty($layout)) return false;
    $first = reset($layout);
    return is_array($first) && isset($first['type']) && !isset($first['columns']);
}

/**
 * Convertit un layout V1 (sections plates) en V2 (lignes/colonnes).
 */
function migrateV1ToV2(array $v1): array
{
    $v2 = [];
    foreach ($v1 as $section) {
        if (!is_array($section) || empty($section['type'])) continue;
        $v2[] = newLayoutRow($section, 12);
    }
    return $v2;
}

/**
 * Charge le layout courant. Toujours en format V2.
 *
 * Si $useDraft est true, charge la version brouillon (accueil_layout_draft), avec
 * fallback sur la version publiée si le brouillon est vide. Sinon, charge la version
 * publiée (accueil_layout). Utilisé pour distinguer rendering éditeur vs live.
 *
 * Migre transparentement :
 *   - layout vide      → layout V2 par défaut + injection éventuelle des données legacy
 *   - layout V1        → V2 (chaque section devient une ligne pleine largeur)
 */
function loadAccueilLayout(array $data, bool $useDraft = false): array
{
    $raw = null;
    if ($useDraft) {
        $raw = $data['accueil_layout_draft'] ?? null;
        if (!$raw) $raw = $data['accueil_layout'] ?? null;  // fallback : pas de brouillon → publié
    } else {
        $raw = $data['accueil_layout'] ?? null;
    }
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            // Détection format
            if (isV1Layout($decoded)) {
                return normalizeAccueilLayout(migrateV1ToV2($decoded));
            }
            return normalizeAccueilLayout($decoded);
        }
    }

    // Aucun layout enregistré : défaut + migration legacy
    $layout = accueilDefaultLayout();

    $legacyContent  = trim((string)($data['accueil_custom_content']  ?? ''));
    $legacyPosition = (string)($data['accueil_custom_position'] ?? 'off');
    if ($legacyContent !== '' && in_array($legacyPosition, ['after_inscrits', 'after_partners'], true)) {
        $customRow = newLayoutRow([
            'type'    => 'custom',
            'id'      => 'custom_legacy_' . substr(md5($legacyContent), 0, 8),
            'title'   => 'Contenu personnalisé (migré)',
            'content' => $legacyContent,
            'visible' => true,
        ]);
        // after_inscrits = juste après reg_bar (index 0) → insertion à 1
        // after_partners = juste après partners (index 1) → insertion à 2
        $insertAt = ($legacyPosition === 'after_inscrits') ? 1 : 2;
        array_splice($layout, $insertAt, 0, [$customRow]);
    }

    if (!empty($data['accueil_news_before_partners'])) {
        $iPart = $iNews = null;
        foreach ($layout as $i => $r) {
            $t = $r['columns'][0]['section']['type'] ?? null;
            if ($t === 'partners') $iPart = $i;
            if ($t === 'news')     $iNews = $i;
        }
        if ($iPart !== null && $iNews !== null) {
            $tmp = $layout[$iPart];
            $layout[$iPart] = $layout[$iNews];
            $layout[$iNews] = $tmp;
        }
    }

    return $layout;
}

/**
 * Normalise + valide un layout V2 :
 *  - garantit que chaque ligne a un ID et au moins 1 colonne
 *  - widths bornées à la liste autorisée, somme par ligne ≤ 12
 *  - chaque section pré-définie n'apparaît qu'une fois (la première gagne)
 *  - les sections pré-définies absentes sont ajoutées en bas, masquées
 */
function normalizeAccueilLayout(array $layout): array
{
    $allowed     = accueilPredefinedSections();
    $allowedW    = accueilAllowedWidths();
    $seenTypes   = [];
    $clean       = [];

    foreach ($layout as $row) {
        if (!is_array($row) || empty($row['columns']) || !is_array($row['columns'])) continue;
        $columns = [];
        $sumW    = 0;
        foreach ($row['columns'] as $col) {
            if (!is_array($col) || !isset($col['section']) || !is_array($col['section'])) continue;
            $w = (int)($col['width'] ?? 12);
            if (!in_array($w, $allowedW, true)) {
                // Snap à la valeur autorisée la plus proche (descendante)
                rsort($allowedW);
                foreach ($allowedW as $aw) { if ($w >= $aw) { $w = $aw; break; } }
                if (!in_array($w, accueilAllowedWidths(), true)) $w = 12;
                $allowedW = accueilAllowedWidths(); // restaure ordre
            }
            $sec  = $col['section'];
            $type = (string)($sec['type'] ?? '');

            if ($type === 'custom') {
                // kind = 'text' (default, géré par TinyMCE) ou 'html' (code brut HTML/CSS/JS)
                $kind = (string)($sec['kind'] ?? 'text');
                if (!in_array($kind, ['text', 'html'], true)) $kind = 'text';
                // Alignement du contenu (utile surtout pour les blocs html avec un seul élément)
                $secAlign  = (string)($sec['align']  ?? 'left');
                $secValign = (string)($sec['valign'] ?? 'top');
                if (!in_array($secAlign,  ['left', 'center', 'right'],   true)) $secAlign  = 'left';
                if (!in_array($secValign, ['top',  'center', 'bottom'], true)) $secValign = 'top';
                $cleanSec = [
                    'type'    => 'custom',
                    'kind'    => $kind,
                    'align'   => $secAlign,
                    'valign'  => $secValign,
                    'id'      => isset($sec['id']) && $sec['id'] !== '' ? (string)$sec['id'] : ('custom_' . bin2hex(random_bytes(4))),
                    'title'   => (string)($sec['title']  ?? ''),
                    'content' => (string)($sec['content'] ?? ''),
                    'visible' => !empty($sec['visible']),
                ];
            } else {
                if (!isset($allowed[$type])) continue;          // type inconnu
                if (isset($seenTypes[$type])) continue;          // doublon → ignore
                $seenTypes[$type] = true;
                $cleanSec = ['type' => $type, 'visible' => !empty($sec['visible'])];
            }

            $columns[] = ['width' => $w, 'section' => $cleanSec];
            $sumW += $w;
            // Sécurité : si la somme dépasse 12, on rogne le dernier
            if ($sumW > 12) {
                $excess = $sumW - 12;
                $columns[count($columns) - 1]['width'] = max(3, $w - $excess);
                $sumW = 12;
                break; // pas plus de colonnes dans cette ligne
            }
        }
        if (empty($columns)) continue;
        $align = (string)($row['align'] ?? 'left');
        if (!in_array($align, accueilAllowedAligns(), true)) $align = 'left';
        $valign = (string)($row['valign'] ?? 'center');
        if (!in_array($valign, accueilAllowedValigns(), true)) $valign = 'center';
        // Espacement vertical par ligne (en rem, indépendamment haut/bas). null = défaut CSS.
        $spaceTop    = isset($row['spaceTop'])    && $row['spaceTop']    !== '' ? (float)$row['spaceTop']    : null;
        $spaceBottom = isset($row['spaceBottom']) && $row['spaceBottom'] !== '' ? (float)$row['spaceBottom'] : null;
        if ($spaceTop    !== null) $spaceTop    = max(0, min(20, $spaceTop));
        if ($spaceBottom !== null) $spaceBottom = max(0, min(20, $spaceBottom));
        $clean[] = [
            'id'          => isset($row['id']) && $row['id'] !== '' ? (string)$row['id'] : ('row_' . bin2hex(random_bytes(4))),
            'align'       => $align,
            'valign'      => $valign,
            'spaceTop'    => $spaceTop,
            'spaceBottom' => $spaceBottom,
            'columns'     => $columns,
        ];
    }

    // Ajoute les sections pré-définies manquantes (masquées) en bas.
    // ID DÉTERMINISTE ('row_predef_<type>') et NON aléatoire : normalizeAccueilLayout()
    // est appelée séparément par setting.php (pour #ifeLayoutData) et par accueil.php
    // (pour le rendu de l'iframe). Avec un id aléatoire, les deux obtenaient un id
    // différent pour la même section non encore sauvegardée → au clic, selectRow()
    // ne trouvait pas la row dans layoutData et n'affichait aucune propriété.
    foreach ($allowed as $type => $_meta) {
        if (!isset($seenTypes[$type])) {
            // Structure STRICTEMENT identique aux lignes normalisées de la boucle
            // principale (mêmes clés : align, valign, spaceTop, spaceBottom, columns)
            // → la section se comporte exactement comme les autres dans l'éditeur.
            $clean[] = [
                'id'          => 'row_predef_' . $type,
                'align'       => 'left',
                'valign'      => 'center',
                'spaceTop'    => null,
                'spaceBottom' => null,
                'columns'     => [
                    ['width' => 12, 'section' => ['type' => $type, 'visible' => false]],
                ],
            ];
        }
    }

    return $clean;
}

/**
 * Encode et persiste un layout V2 en BDD.
 * Par défaut, écrit dans le BROUILLON (accueil_layout_draft) — c'est l'éditeur qui
 * sauvegarde. Le passage en production se fait via publishAccueilDraft().
 * Pour publier directement (cas legacy / install), passer $toDraft = false.
 */
function saveAccueilLayout(PDO $pdo, array $layout, bool $toDraft = true): void
{
    $normalized = normalizeAccueilLayout($layout);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($toDraft) {
        $pdo->prepare('UPDATE setting SET accueil_layout_draft = :l, accueil_draft_updated_at = NOW() WHERE id = 1')
            ->execute(['l' => $json]);
    } else {
        $pdo->prepare('UPDATE setting SET accueil_layout = :l WHERE id = 1')->execute(['l' => $json]);
    }
}

/**
 * Publie le brouillon : copie tous les champs *_draft → version publiée.
 * Puis vide les colonnes draft + le timestamp (état "rien à publier").
 */
function publishAccueilDraft(PDO $pdo): void
{
    // NULLIF(col, '') : si un draft est '' (chaîne vide) au lieu de NULL, on le
    // traite comme NULL → COALESCE retombe sur la version publiée existante.
    // Sans ce NULLIF, un reset complet d'un champ stocké à '' écrasait toute la
    // production (bug : "video_social_card monte / disparaît après Publier" parce
    // que le draft vide remplaçait silencieusement la prod).
    $pdo->exec("UPDATE setting SET
        accueil_layout   = COALESCE(NULLIF(accueil_layout_draft,   ''), accueil_layout),
        accueil_styles   = COALESCE(NULLIF(accueil_styles_draft,   ''), accueil_styles),
        accueil_texts    = COALESCE(NULLIF(accueil_texts_draft,    ''), accueil_texts),
        accueil_geometry = COALESCE(NULLIF(accueil_geometry_draft, ''), accueil_geometry),
        accueil_layout_draft   = NULL,
        accueil_styles_draft   = NULL,
        accueil_texts_draft    = NULL,
        accueil_geometry_draft = NULL,
        accueil_draft_updated_at = NULL
      WHERE id = 1");
}

/**
 * Annule les modifications du brouillon : vide les colonnes draft. Au prochain load
 * en mode éditeur, le fallback retombe sur la version publiée.
 */
function discardAccueilDraft(PDO $pdo): void
{
    $pdo->exec("UPDATE setting SET
        accueil_layout_draft   = NULL,
        accueil_styles_draft   = NULL,
        accueil_texts_draft    = NULL,
        accueil_geometry_draft = NULL,
        accueil_draft_updated_at = NULL
      WHERE id = 1");
}

/**
 * Indique si un brouillon existe (différent du publié).
 */
function hasAccueilDraft(array $data): bool
{
    return !empty($data['accueil_draft_updated_at']);
}
