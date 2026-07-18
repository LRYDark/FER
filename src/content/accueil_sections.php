<?php
/**
 * Rendu des sections de la page d'accueil.
 * Utilisé par public/accueil.php (rendu réel) et inc/setting.php (aperçu éditeur).
 *
 * Toutes les fonctions prennent un tableau $ctx unique avec les données dont elles
 * ont besoin. Cela évite que setting.php ait à recréer toutes les variables locales
 * d'accueil.php.
 */

/**
 * Helper : retourne un texte éditable de l'accueil par clé, avec valeur par défaut.
 */
function getAccueilText(array $ctx, string $key, string $default): string
{
    $texts = $ctx['texts'] ?? [];
    return isset($texts[$key]) && $texts[$key] !== '' ? (string)$texts[$key] : $default;
}

/**
 * Helper : retourne l'alignement texte ('left'|'center'|'right'|'') pour un champ donné.
 * Utilisé pour ajouter un style="text-align:..." sur les textes éditables.
 */
function getAccueilAlignStyle(array $ctx, string $field): string
{
    $styles = $ctx['styles'] ?? [];
    $a = $styles['text_align__' . $field] ?? '';
    return in_array($a, ['left','center','right'], true) ? 'text-align:' . $a . ';' : '';
}

/**
 * Liste de tous les textes éditables avec leur clé + libellé admin + valeur par défaut.
 * Sert à valider côté serveur (whitelist) et à éventuellement lister dans l'UI.
 */
function accueilEditableTexts(): array
{
    return [
        'reg_bar.kicker_open'     => ['label' => 'Compteur — kicker (ouvert)', 'default' => 'Déjà inscrits'],
        // Variantes du bloc gauche de la card inscriptions (option reg_bar.display_style).
        // Le placeholder {count} est remplacé par le nombre d'inscrits au rendu.
        'reg_bar.urgency_title'   => ['label' => 'Compteur — titre (version urgence)', 'default' => 'Inscrivez-vous vite !'],
        'reg_bar.urgency_text'    => ['label' => 'Compteur — texte (version urgence)', 'default' => 'Les 100 premiers inscrits recevront un t-shirt offert 🎁'],
        'reg_bar.moti_title'      => ['label' => 'Compteur — titre (version solidaire)', 'default' => 'Rejoignez le mouvement 💗'],
        'reg_bar.moti_text'       => ['label' => 'Compteur — texte (version solidaire)', 'default' => 'Chaque inscription soutient la lutte contre le cancer. Ensemble, faisons la différence le jour J !'],
        'reg_bar.title_search'    => ['label' => 'Compteur — titre recherche', 'default' => 'Vérifier mon inscription'],
        'reg_bar.placeholder'     => ['label' => 'Compteur — placeholder email', 'default' => 'Votre adresse email'],
        'reg_bar.btn_check'       => ['label' => 'Compteur — bouton vérifier', 'default' => 'Vérifier'],
        'reg_bar.hint'            => ['label' => 'Compteur — hint', 'default' => "Saisissez l'email utilisé lors de votre inscription."],
        'partners.title'          => ['label' => 'Partenaires — titre', 'default' => 'Rejoignez le clan de nos partenaires engagés'],
        'partners.text'           => ['label' => 'Partenaires — paragraphe', 'default' => "Chaque année, des entreprises et associations locales s'associent à Forbach en Rose pour soutenir la lutte contre le cancer. En devenant partenaire, vous contribuez directement à la réussite de cet événement caritatif et affichez votre engagement solidaire auprès de notre communauté."],
        'partners.btn_submit'     => ['label' => 'Partenaires — bouton', 'default' => 'Devenir partenaire'],
        'partners.placeholder'    => ['label' => 'Partenaires — placeholder', 'default' => 'Votre email professionnel'],
        'partners.note'           => ['label' => 'Partenaires — note', 'default' => 'Nous vous recontacterons dans les plus brefs délais pour discuter des modalités de partenariat.'],
        'timeline.title'          => ['label' => 'Timeline — titre', 'default' => 'Historique'],
        'news.title'              => ['label' => 'Actualités — titre', 'default' => 'Dernières actualités'],
        'news.link_all'           => ['label' => 'Actualités — bouton voir toutes', 'default' => 'Voir toutes les actualités'],
        'start_point.title'       => ['label' => 'Départ — titre', 'default' => 'Retrouver le départ'],
        'newsletter.title'        => ['label' => 'Newsletter — titre', 'default' => 'Rester informé'],
        'newsletter.subtitle'     => ['label' => 'Newsletter — sous-titre', 'default' => "c'est déjà un moyen d'agir"],
        'newsletter.intro'        => ['label' => 'Newsletter — texte', 'default' => 'Abonnez-vous à la newsletter de Forbach en Rose.'],
        'newsletter.placeholder'  => ['label' => 'Newsletter — placeholder email', 'default' => 'Votre adresse email'],
        'newsletter.button'       => ['label' => 'Newsletter — bouton', 'default' => "Je m'abonne"],
        'newsletter.consent'      => ['label' => 'Newsletter — texte de consentement', 'default' => "En cliquant sur « Je m'abonne », j'accepte de recevoir des e-mails de l'association et confirme avoir pris connaissance de la politique de confidentialité."],
        'hero.cta_register'       => ['label' => 'Hero — bouton inscription', 'default' => "Je m'inscris →"],
        'badge_fee.tooltip'       => ['label' => 'Hero — tooltip du badge prix', 'default' => 'Entièrement reversé à la Ligue contre le cancer'],
    ];
}

/**
 * Construit le contexte par défaut depuis la BDD pour l'aperçu éditeur.
 * Utilisé par setting.php quand il a besoin d'afficher l'aperçu mais ne dispose
 * pas du même contexte que accueil.php.
 */
function buildAccueilSectionContext(PDO $pdo, array $data, array $actualites = [], bool $useDraft = false): array
{
    // Compteur d'inscriptions
    $count = 0;
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM registrations');
        $count = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {}

    // Timeline
    $timelineItems = [];
    $timelineElements = [];
    try {
        $hasStatus = false;
        try { $pdo->query("SELECT status FROM timeline_items LIMIT 0"); $hasStatus = true; } catch (\Throwable $e) {}
        $sql = $hasStatus
            ? "SELECT * FROM timeline_items WHERE status = 'published' ORDER BY sort_order ASC"
            : "SELECT * FROM timeline_items ORDER BY sort_order ASC";
        $timelineItems = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($timelineItems as $ti) {
            $stmtEl = $pdo->prepare('SELECT label FROM timeline_elements WHERE item_id = ? ORDER BY sort_order ASC');
            $stmtEl->execute([$ti['id']]);
            $timelineElements[$ti['id']] = $stmtEl->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (\Throwable $e) {}

    // Inscriptions ouvertes / fermées
    $accueil_active = !empty($data['accueil_active']) ? 1 : 0;
    $tz = new DateTimeZone('Europe/Paris');
    $now = new DateTime('now', $tz);
    $autoOpen  = !empty($data['registration_auto_open'])  ? new DateTime($data['registration_auto_open'], $tz)  : null;
    $autoClose = !empty($data['registration_auto_close']) ? new DateTime($data['registration_auto_close'], $tz) : null;
    if ($autoOpen && $now >= $autoOpen) { $accueil_active = 1; }
    if ($autoClose && $now >= $autoClose) { $accueil_active = 0; }

    // Helper local : si useDraft, lit la colonne *_draft avec fallback sur la version
    // publiée. Sinon, lit directement la version publiée.
    $pickRaw = function(string $base) use ($data, $useDraft) {
        if ($useDraft) {
            $d = $data[$base . '_draft'] ?? null;
            if (!empty($d)) return $d;
        }
        return $data[$base] ?? null;
    };

    // Styles persistés (taille des éléments du Hero, etc.)
    $styles = [];
    $stylesRaw = $pickRaw('accueil_styles');
    if (!empty($stylesRaw)) {
        $decoded = json_decode($stylesRaw, true);
        if (is_array($decoded)) $styles = $decoded;
    }
    // Textes éditables persistés (overrides des textes hardcodés)
    $texts = [];
    $textsRaw = $pickRaw('accueil_texts');
    if (!empty($textsRaw)) {
        $decoded = json_decode($textsRaw, true);
        if (is_array($decoded)) $texts = $decoded;
    }
    // Géométries persistées (drag libre + resize 4-coins)
    $geometry = [];
    $geomRaw = $pickRaw('accueil_geometry');
    if (!empty($geomRaw)) {
        $decoded = json_decode($geomRaw, true);
        if (is_array($decoded)) $geometry = $decoded;
    }

    return [
        'count'                   => $count,
        'accueil_active'          => $accueil_active,
        'autoOpen'                => $autoOpen,
        'now'                     => $now,
        'searchEmail'             => '',
        'searchMessage'           => '',
        'searchStatus'            => '',
        'picture_partner'         => $data['picture_partner'] ?? '',
        'timelineItems'           => $timelineItems,
        'timelineElements'        => $timelineElements,
        'timelineCount'           => count($timelineItems),
        'isTimelinePreview'       => false,
        'actualites'              => $actualites,
        // Champs Hero
        'titleAccueil'            => $data['titleAccueil'] ?? '',
        'titleAccueil_mobile'     => $data['titleAccueil_mobile'] ?? '',
        'subtitle_accueil'        => $data['subtitle_accueil'] ?? '',
        'subtitle_accueil_mobile' => $data['subtitle_accueil_mobile'] ?? '',
        'video_accueil'           => $data['video_accueil'] ?? 'FER.mp4',
        'registration_fee'        => $data['registration_fee'] ?? 0,
        'course_km'               => $data['course_km'] ?? 7,
        // Section "Retrouver le départ" (colonnes SQL dédiées)
        'start_point_address'     => $data['start_point_address'] ?? '',
        'start_point_coords'      => $data['start_point_coords'] ?? '',
        'styles'                  => $styles,
        'texts'                   => $texts,
        'geometry'                => $geometry,
        // Accès BDD pour les placeholders {count_last}/{count_YYYY} (archives)
        'pdo'                     => $pdo,
    ];
}

/**
 * Helper : nombre d'inscrits d'une année ARCHIVÉE (tables registrations_YYYY créées
 * par la route api archive-current). $year = null → dernière archive disponible.
 * Retourne null si aucune archive (ou erreur SQL) ; résultats mémoïsés par requête.
 */
function accueilArchiveCount(?PDO $pdo, ?int $year = null): ?int
{
    static $cache = [];
    if (!$pdo) return null;
    $key = $year ?? 'last';
    if (array_key_exists($key, $cache)) return $cache[$key];
    $result = null;
    try {
        // Liste des tables d'archives registrations_YYYY (filtrage strict par regex :
        // pas d'injection possible, le nom recomposé ne contient que des chiffres).
        $tables = $pdo->query("SHOW TABLES LIKE 'registrations%'")->fetchAll(PDO::FETCH_COLUMN);
        $years = [];
        foreach ($tables as $t) {
            if (preg_match('/^registrations_(\d{4})$/', (string)$t, $m)) $years[] = (int)$m[1];
        }
        $target = $year !== null ? ($year && in_array($year, $years, true) ? $year : null)
                                 : ($years ? max($years) : null);
        if ($target !== null) {
            $result = (int)$pdo->query("SELECT COUNT(*) FROM `registrations_{$target}`")->fetchColumn();
        }
    } catch (\Throwable $e) {
        $result = null;
    }
    $cache[$key] = $result;
    return $result;
}

/**
 * Helper : retourne le style CSS pour un élément géométrique persisté.
 * Format inline-style à coller dans `style="..."`.
 */
function getAccueilGeometryStyle(array $ctx, string $field): string
{
    $g = $ctx['geometry'][$field] ?? null;
    if (!is_array($g)) return '';
    $css = '';
    if (isset($g['w'])) $css .= 'width:' . (int)$g['w'] . 'px;';
    if (isset($g['h'])) $css .= 'height:' . (int)$g['h'] . 'px;';
    if (isset($g['x']) || isset($g['y'])) {
        $x = (int)($g['x'] ?? 0);
        $y = (int)($g['y'] ?? 0);
        $css .= 'transform:translate(' . $x . 'px,' . $y . 'px);';
    }
    return $css;
}

/**
 * Génère le SVG S-curve de la timeline en fonction du nombre d'items.
 * Dupliqué depuis accueil.php pour pouvoir s'utiliser de manière autonome.
 */
function generateAccueilTimelineSVG(int $count): array
{
    if ($count <= 0) return ['height' => 0, 'path' => ''];
    $segmentHeight = 200;
    $totalHeight = $count * $segmentHeight;
    $path = "M 100 0";
    if ($count >= 1) $path .= " C 100 80, 190 120, 190 200";
    for ($i = 1; $i < $count; $i++) {
        $y1 = ($i * $segmentHeight) + 80;
        $y2 = ($i + 1) * $segmentHeight;
        $path .= ($i % 2 === 1)
            ? " S 10 {$y1}, 10 {$y2}"
            : " S 190 {$y1}, 190 {$y2}";
    }
    return ['height' => $totalHeight, 'path' => $path];
}

/* ============================================================
   RENDU DES SECTIONS
   ============================================================ */

/**
 * Rendu du Hero (vidéo + countdown + CTA + titre/sous-titre).
 * Cette section reste fixée en haut de la page d'accueil et n'est pas dans
 * le layout JSON, mais elle s'affiche dans l'éditeur admin pour permettre
 * son édition via clic.
 */
function renderAccueilSection_hero(array $ctx): void {
    $editable        = !empty($ctx['_editor']);
    $registration_fee = (int)($ctx['registration_fee'] ?? 0);
    $course_km        = (int)($ctx['course_km'] ?? 0);
    $videoFile        = (string)($ctx['video_accueil'] ?? '');
    $videoExists      = $videoFile !== '' && is_file(__DIR__ . '/../../files/' . $videoFile);
    $titleAccueil     = (string)($ctx['titleAccueil'] ?? '');
    $titleAccueil_mobile = (string)($ctx['titleAccueil_mobile'] ?? '');
    $subPC            = (string)($ctx['subtitle_accueil'] ?? '');
    $subMobile        = (string)($ctx['subtitle_accueil_mobile'] ?? '');
    $date_formatted   = (string)($ctx['date_formatted'] ?? '2026-07-05T09:00:00');
    // Styles personnalisés (pourcentages : 100 = taille originale)
    $styles = is_array($ctx['styles'] ?? null) ? $ctx['styles'] : [];
    $sizeSubtitle = (int)($styles['subtitle_accueil_size'] ?? 100);
    $sizeTitle    = (int)($styles['titleAccueil_size']     ?? 100);
    $sizeTimer    = (int)($styles['hero_timer_size']        ?? 100);
    $cssSubtitle  = $sizeSubtitle !== 100 ? 'font-size:' . ($sizeSubtitle / 100) . 'em;' : '';
    $cssTitle     = $sizeTitle    !== 100 ? 'font-size:' . ($sizeTitle    / 100) . 'em;' : '';
    $cssTimer     = $sizeTimer    !== 100 ? 'transform:scale(' . ($sizeTimer / 100) . ');transform-origin:left bottom;' : '';
    ?>
    <div class="demo-wrap">
      <section class="demo-card" aria-label="Carte vidéo">
        <?php if (!empty($registration_fee) || !empty($course_km)): ?>
        <div class="demo-badges">
          <?php if (!empty($course_km)): ?>
          <a href="parcours" class="demo-badge demo-badge--km" style="text-decoration:none;cursor:pointer;">
            <span class="demo-badge-value"><?= $course_km ?> km</span>
            <span class="demo-badge-label">Parcours</span>
          </a>
          <?php endif; ?>
          <?php if (!empty($registration_fee)): ?>
          <div class="demo-badge demo-badge--fee" id="badgeFee">
            <span class="demo-badge-value"><?= $registration_fee ?>€</span>
            <div class="badge-tooltip" id="badgeTooltip">Entièrement reversé à la<br>Ligue contre le cancer</div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($videoExists): ?>
        <video class="demo-video" id="heroVideo"
               <?= $editable ? 'data-edit-field="video_accueil" data-edit-kind="video" data-edit-section="hero"' : 'autoplay muted loop playsinline' ?>>
          <source src="../files/<?= rawurlencode($videoFile) ?>" type="video/mp4" />
        </video>
        <?php elseif ($editable): ?>
        <div class="demo-video"
             data-edit-field="video_accueil" data-edit-kind="video" data-edit-section="hero"
             style="display:flex;align-items:center;justify-content:center;background:#1e293b;color:#fff;cursor:pointer;font-weight:600;">
          <i class="bi bi-camera-video" style="font-size:48px;margin-right:12px;"></i> Cliquer pour ajouter une vidéo
        </div>
        <?php endif; ?>

        <div class="demo-overlay">
          <div class="demo-panel video-float">
            <div class="hero-text">
              <div class="hero-device hero-pc">
                <div class="demo-kicker" style="<?= $cssTitle ?>"
                     <?= $editable ? 'data-edit-field="titleAccueil" data-edit-kind="tinymce" data-edit-section="hero" data-edit-size="titleAccueil_size" data-edit-size-current="' . $sizeTitle . '"' : '' ?>><?= $titleAccueil ?></div>
              </div>
              <div class="hero-device hero-mobile">
                <div class="demo-kicker" style="<?= $cssTitle ?>"
                     <?= $editable ? 'data-edit-field="titleAccueil_mobile" data-edit-kind="tinymce" data-edit-section="hero" data-edit-size="titleAccueil_size" data-edit-size-current="' . $sizeTitle . '"' : '' ?>><?= $titleAccueil_mobile !== '' ? $titleAccueil_mobile : $titleAccueil ?></div>
              </div>
              <?php if ($subPC !== '' || $editable): ?>
                <p class="demo-desc hero-device hero-pc" style="<?= $cssSubtitle ?>"
                   <?= $editable ? 'data-edit-field="subtitle_accueil" data-edit-kind="text" data-edit-section="hero" data-edit-size="subtitle_accueil_size" data-edit-size-current="' . $sizeSubtitle . '"' : '' ?>><?= htmlspecialchars($subPC !== '' ? $subPC : 'Sous-titre PC...') ?></p>
              <?php endif; ?>
              <?php if ($subMobile !== '' || $editable): ?>
                <p class="demo-desc hero-device hero-mobile" style="<?= $cssSubtitle ?>"
                   <?= $editable ? 'data-edit-field="subtitle_accueil_mobile" data-edit-kind="text" data-edit-section="hero" data-edit-size="subtitle_accueil_size" data-edit-size-current="' . $sizeSubtitle . '"' : '' ?>><?= htmlspecialchars($subMobile !== '' ? $subMobile : ($subPC !== '' ? $subPC : 'Sous-titre mobile...')) ?></p>
              <?php endif; ?>
            </div>

            <div class="countdown-wrap" style="<?= $cssTimer ?>"
                 <?= $editable ? 'data-edit-field="hero_timer" data-edit-kind="size-only" data-edit-section="hero" data-edit-size="hero_timer_size" data-edit-size-current="' . $sizeTimer . '"' : '' ?>>
              <div class="countdown-row" aria-label="Compte à rebours">
                <div class="timebox"><div class="num">55</div><div class="lbl">Jours</div></div>
                <div class="timebox"><div class="num">10</div><div class="lbl">Heures</div></div>
                <div class="timebox"><div class="num">06</div><div class="lbl">Minutes</div></div>
                <div class="timebox timebox-seconds"><div class="num">00</div><div class="lbl">Secondes</div></div>
              </div>
            </div>

            <div class="actions">
              <a class="cta-pink" href="register">Je m'inscris →</a>
            </div>
          </div>
        </div>
      </section>
    </div>
    <?php
}

function renderAccueilSection_reg_bar(array $ctx): void {
    $count = (int)$ctx['count'];
    $accueil_active = (int)$ctx['accueil_active'];
    $autoOpen = $ctx['autoOpen'] ?? null;
    $now = $ctx['now'] ?? null;
    $searchEmail = (string)($ctx['searchEmail'] ?? '');
    $searchMessage = (string)($ctx['searchMessage'] ?? '');
    $searchStatus = (string)($ctx['searchStatus'] ?? '');
    $editable = !empty($ctx['_editor']);
    // Textes éditables
    $kickerOpen = getAccueilText($ctx, 'reg_bar.kicker_open',     'Déjà inscrits');
    $titleSearch = getAccueilText($ctx, 'reg_bar.title_search',  'Vérifier mon inscription');
    $placeholder = getAccueilText($ctx, 'reg_bar.placeholder',    'Votre adresse email');
    $btnCheck    = getAccueilText($ctx, 'reg_bar.btn_check',      'Vérifier');
    $hint        = getAccueilText($ctx, 'reg_bar.hint',           "Saisissez l'email utilisé lors de votre inscription.");
    // Version du bloc gauche (option éditeur, comme news.card_style) :
    //   'counter'    → kicker + nombre d'inscrits (par défaut, version historique)
    //   'urgency'    → message d'urgence ("Inscrivez-vous vite", t-shirt offert…)
    //   'motivation' → message solidaire, sans compteur
    $displayStyle = (string)($ctx['styles']['reg_bar.display_style'] ?? 'counter');
    if (!in_array($displayStyle, ['counter', 'urgency', 'motivation'], true)) $displayStyle = 'counter';
    // Source du grand nombre de la version compteur :
    //   'live'    → inscriptions de l'année en cours (défaut)
    //   'archive' → dernière année archivée (tables registrations_YYYY)
    $counterSource = (string)($ctx['styles']['reg_bar.counter_source'] ?? 'live');
    if (!in_array($counterSource, ['live', 'archive'], true)) $counterSource = 'live';
    $pdo = $ctx['pdo'] ?? null;
    $displayCount = $count;
    if ($counterSource === 'archive') {
        // Fallback sur le compteur live si aucune archive n'existe encore.
        $displayCount = accueilArchiveCount($pdo, null) ?? $count;
    }
    // Placeholders dans les textes de la card (kicker + variantes) :
    //   {count}      → inscrits en direct (année en cours)
    //   {count_last} → inscrits de la dernière année archivée
    //   {count_2025} → inscrits d'une année archivée précise
    // En mode éditeur on laisse les placeholders visibles tels quels pour que
    // l'admin les édite sans figer le nombre courant.
    $replaceCount = function (string $s) use ($count, $editable, $pdo): string {
        if ($editable) return $s;
        $s = str_replace('{count}', number_format($count, 0, ',', ' '), $s);
        if (strpos($s, '{count_') !== false) {
            $s = preg_replace_callback('/\{count_(last|\d{4})\}/', function ($m) use ($pdo) {
                $n = accueilArchiveCount($pdo, $m[1] === 'last' ? null : (int)$m[1]);
                return $n === null ? '0' : number_format($n, 0, ',', ' ');
            }, $s);
        }
        return $s;
    };
    if ($displayStyle === 'urgency') {
        $msgTitleKey = 'reg_bar.urgency_title';
        $msgTextKey  = 'reg_bar.urgency_text';
        $msgTitle    = getAccueilText($ctx, $msgTitleKey, 'Inscrivez-vous vite !');
        $msgText     = getAccueilText($ctx, $msgTextKey,  'Les 100 premiers inscrits recevront un t-shirt offert 🎁');
    } elseif ($displayStyle === 'motivation') {
        $msgTitleKey = 'reg_bar.moti_title';
        $msgTextKey  = 'reg_bar.moti_text';
        $msgTitle    = getAccueilText($ctx, $msgTitleKey, 'Rejoignez le mouvement 💗');
        $msgText     = getAccueilText($ctx, $msgTextKey,  'Chaque inscription soutient la lutte contre le cancer. Ensemble, faisons la différence le jour J !');
    }
    // Tailles personnalisées des textes de la card (slider de l'éditeur, 50-300 %).
    // Appliquées via les variables CSS --rb-scale (desktop) / --rb-scale-m (mobile)
    // multipliées par les tailles de base dans accueil.css (calc) — JAMAIS via
    // font-size inline, qui écraserait les baselines responsive (les éléments de
    // cette card sont des nœuds uniques, sans jumeaux PC/mobile comme le Hero).
    // Les clés sont par EMPLACEMENT (msg_title partagé entre urgence/solidaire) :
    // changer de version conserve les tailles réglées.
    $rbSize = function (string $sizeKey) use ($ctx, $editable): array {
        $styles = $ctx['styles'] ?? [];
        $size = (int)($styles[$sizeKey] ?? 100);
        if ($size < 50 || $size > 300) $size = 100;
        $sizeMobile = isset($styles[$sizeKey . '_mobile']) ? (int)$styles[$sizeKey . '_mobile'] : null;
        if ($sizeMobile !== null && ($sizeMobile < 50 || $sizeMobile > 300)) $sizeMobile = null;
        $css = '';
        if ($size !== 100) $css .= '--rb-scale:' . ($size / 100) . ';';
        // ATTENTION : contrairement au desktop (fallback CSS = 1), une valeur mobile
        // sauvée à 100 doit être émise quand même — le fallback CSS mobile est
        // var(--rb-scale, 1) (héritage desktop), donc omettre --rb-scale-m:1 ferait
        // ré-hériter la taille desktop au lieu de figer 100 %.
        if ($sizeMobile !== null) $css .= '--rb-scale-m:' . ($sizeMobile / 100) . ';';
        $attrs = '';
        if ($editable) {
            // data-edit-size déclenche le slider de taille de la sidebar (générique).
            // Convention hero : data-edit-size-current-mobile émis uniquement si une
            // valeur mobile a été explicitement sauvée.
            $attrs = ' data-edit-size="' . $sizeKey . '" data-edit-size-current="' . $size . '"';
            if ($sizeMobile !== null) {
                $attrs .= ' data-edit-size-current-mobile="' . $sizeMobile . '"';
            }
        }
        return [$css, $attrs];
    };
    [$cssRbKicker,   $attrsRbKicker]   = $rbSize('reg_bar.kicker_size');
    [$cssRbValue,    $attrsRbValue]    = $rbSize('reg_bar.value_size');
    [$cssRbMsgTitle, $attrsRbMsgTitle] = $rbSize('reg_bar.msg_title_size');
    [$cssRbMsgText,  $attrsRbMsgText]  = $rbSize('reg_bar.msg_text_size');
    [$cssRbTitleS,   $attrsRbTitleS]   = $rbSize('reg_bar.title_search_size');
    [$cssRbBtn,      $attrsRbBtn]      = $rbSize('reg_bar.btn_check_size');
    [$cssRbHint,     $attrsRbHint]     = $rbSize('reg_bar.hint_size');
    ?>
    <section class="reg-bar" id="reg-bar" aria-label="Inscriptions">
      <div class="reg-card">
        <?php if ($count === 0 && $accueil_active === 0): ?>
        <div class="reg-count">
          <div class="reg-kicker" style="<?= $cssRbKicker ?>">Inscriptions</div>
          <div class="reg-value reg-value--closed" style="<?= $cssRbValue ?>">Fermées</div>
        </div>
        <div class="reg-search">
          <div class="reg-title" style="display:flex;align-items:center;justify-content:center;<?= $cssRbTitleS ?>"><span>Inscriptions actuellement fermées</span></div>
          <?php if ($autoOpen && $now < $autoOpen): ?>
            <p style="margin-top:10px;font-size:1.075rem;color:#b5366b;display:flex;align-items:center;justify-content:center;">
              <span>Ouverture le <strong><?= $autoOpen->format('d/m/Y') ?></strong> à <strong><?= $autoOpen->format('H\hi') ?></strong></span>
            </p>
          <?php else: ?>
            <p style="margin-top:10px;font-size:.95rem;color:#64748b;">Merci de votre compréhension.</p>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <?php if ($displayStyle === 'counter'): ?>
        <div class="reg-count">
          <div class="reg-kicker" style="<?= getAccueilAlignStyle($ctx, 'reg_bar.kicker_open') . $cssRbKicker ?>"<?= $attrsRbKicker ?> <?= $editable ? 'data-edit-field="reg_bar.kicker_open" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($replaceCount($kickerOpen)) ?></div>
          <div class="reg-value" style="<?= $cssRbValue ?>"<?= $attrsRbValue ?> <?= $editable ? 'data-edit-field="reg_bar.value" data-edit-kind="size-only" data-edit-section="reg_bar"' : '' ?>><?= number_format($displayCount, 0, ',', ' ') ?></div>
        </div>
        <?php else: ?>
        <div class="reg-count reg-count--message">
          <div class="reg-msg-title" style="<?= getAccueilAlignStyle($ctx, $msgTitleKey) . $cssRbMsgTitle ?>"<?= $attrsRbMsgTitle ?> <?= $editable ? 'data-edit-field="' . $msgTitleKey . '" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($replaceCount($msgTitle)) ?></div>
          <div class="reg-msg-text" style="<?= getAccueilAlignStyle($ctx, $msgTextKey) . $cssRbMsgText ?>"<?= $attrsRbMsgText ?> <?= $editable ? 'data-edit-field="' . $msgTextKey . '" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($replaceCount($msgText)) ?></div>
        </div>
        <?php endif; ?>
        <div class="reg-search">
          <div class="reg-title" style="<?= getAccueilAlignStyle($ctx, 'reg_bar.title_search') . $cssRbTitleS ?>"<?= $attrsRbTitleS ?> <?= $editable ? 'data-edit-field="reg_bar.title_search" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($titleSearch) ?></div>
          <form class="reg-form" method="get" action="accueil#reg-bar">
            <input type="hidden" name="check_registration" value="1">
            <input class="reg-input" type="email" name="search_email" placeholder="<?= htmlspecialchars($placeholder) ?>"
                  value="<?= htmlspecialchars($searchEmail) ?>" autocomplete="email" required>
            <button class="reg-submit" type="submit" style="<?= $cssRbBtn ?>"<?= $attrsRbBtn ?> <?= $editable ? 'data-edit-field="reg_bar.btn_check" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($btnCheck) ?></button>
          </form>
          <p id="regResult" class="reg-result <?= htmlspecialchars($searchStatus) ?>" aria-live="polite"
             style="<?= $searchMessage !== '' ? '' : 'display:none;' ?>"><?= htmlspecialchars($searchMessage) ?></p>
          <p id="regHint" class="reg-hint" style="<?= ($searchMessage !== '' ? 'display:none;' : '') . $cssRbHint ?>"<?= $attrsRbHint ?>
             <?= $editable ? 'data-edit-field="reg_bar.hint" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($hint) ?></p>
          <?php if (!$editable && $searchMessage !== ''): ?>
          <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
          (function() {
            function bind() {
              var r = document.getElementById('regResult');
              if (!r || r.dataset.autohide) return;
              r.dataset.autohide = '1';
              // Au bout de 5 s : on efface le message de retour (et on réaffiche le hint).
              setTimeout(function() {
                r.style.transition = 'opacity .3s ease';
                r.style.opacity = '0';
                setTimeout(function() {
                  r.style.display = 'none';
                  var h = document.getElementById('regHint');
                  if (h) h.style.display = '';
                }, 300);
              }, 5000);
            }
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', bind);
            } else {
              bind();
            }
          })();
          </script>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
}

function renderAccueilSection_partners(array $ctx): void {
    $picture_partner = (string)($ctx['picture_partner'] ?? '');
    $hasImage = $picture_partner !== '' && is_file(__DIR__ . '/../../files/_pictures/' . $picture_partner);
    $editable = !empty($ctx['_editor']);
    ?>
    <section class="community-section" aria-label="Devenez partenaire">
      <div class="community-container<?= $hasImage ? '' : ' no-partner-img' ?>">
        <?php if ($hasImage):
          $imgGeomCss = getAccueilGeometryStyle($ctx, 'picture_partner');
        ?>
        <div class="community-image">
          <img src="../files/_pictures/<?= htmlspecialchars($picture_partner) ?>" alt="Nos partenaires - Forbach en Rose"
               style="<?= $imgGeomCss ?><?= $imgGeomCss ? 'position:relative;' : '' ?>"
               <?= $editable ? 'data-edit-field="picture_partner" data-edit-kind="image" data-edit-section="partners"' : '' ?>>
        </div>
        <?php elseif ($editable): ?>
        <div class="community-image">
          <div data-edit-field="picture_partner" data-edit-kind="image" data-edit-section="partners"
               style="display:flex;align-items:center;justify-content:center;background:#fce7f3;color:#9d174d;border:2px dashed #F42182;border-radius:8px;height:200px;cursor:pointer;font-weight:600;">
            <i class="bi bi-image" style="font-size:32px;margin-right:8px;"></i> Cliquer pour ajouter une image
          </div>
        </div>
        <?php endif; ?>
        <?php
          $partnersTitle = getAccueilText($ctx, 'partners.title', 'Rejoignez le clan de nos partenaires engagés');
          $partnersText  = getAccueilText($ctx, 'partners.text',  "Chaque année, des entreprises et associations locales s'associent à Forbach en Rose pour soutenir la lutte contre le cancer. En devenant partenaire, vous contribuez directement à la réussite de cet événement caritatif et affichez votre engagement solidaire auprès de notre communauté.");
          $partnersBtn   = getAccueilText($ctx, 'partners.btn_submit', 'Devenir partenaire');
          $partnersPh    = getAccueilText($ctx, 'partners.placeholder', 'Votre email professionnel');
          $partnersNote  = getAccueilText($ctx, 'partners.note',  'Nous vous recontacterons dans les plus brefs délais pour discuter des modalités de partenariat.');
        ?>
        <div class="community-content">
          <h2 class="community-title" style="<?= getAccueilAlignStyle($ctx, 'partners.title') ?>" <?= $editable ? 'data-edit-field="partners.title" data-edit-kind="text" data-edit-section="partners"' : '' ?>><?= htmlspecialchars($partnersTitle) ?></h2>
          <p class="community-text" style="<?= getAccueilAlignStyle($ctx, 'partners.text') ?>" <?= $editable ? 'data-edit-field="partners.text" data-edit-kind="text" data-edit-section="partners"' : '' ?>><?= htmlspecialchars($partnersText) ?></p>
          <form class="partner-form" id="partnerForm">
            <div class="form-group">
              <input type="email" id="partnerEmail" name="partner_email" class="partner-email-input"
                     placeholder="<?= htmlspecialchars($partnersPh) ?>" required aria-label="Email professionnel">
              <button type="submit" class="partner-submit" id="partnerSubmitBtn" <?= $editable ? 'data-edit-field="partners.btn_submit" data-edit-kind="text" data-edit-section="partners"' : '' ?>>
                <span><?= htmlspecialchars($partnersBtn) ?></span>
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M7 14L12 9L7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
            <p class="form-note" <?= $editable ? 'data-edit-field="partners.note" data-edit-kind="text" data-edit-section="partners"' : '' ?>><?= htmlspecialchars($partnersNote) ?></p>
            <div id="partnerResult" style="display:none;margin-top:.75rem;padding:.6rem 1rem;border-radius:.5rem;font-size:.9rem;"></div>
          </form>
        </div>
      </div>
    </section>
    <?php
}

function renderAccueilSection_timeline(array $ctx): void {
    $timelineCount = (int)($ctx['timelineCount'] ?? 0);
    if ($timelineCount <= 0) return;
    $timelineItems = $ctx['timelineItems'] ?? [];
    $timelineElements = $ctx['timelineElements'] ?? [];
    $isPreview = !empty($ctx['isTimelinePreview']);
    $editable = !empty($ctx['_editor']);
    $tlTitle = getAccueilText($ctx, 'timeline.title', 'Historique');
    $svg = generateAccueilTimelineSVG($timelineCount);
    ?>
    <?php if ($isPreview): ?>
    <div style="background:#fd7e14;color:#fff;text-align:center;padding:10px;font-weight:600;font-size:14px;margin:12px auto;border-radius:8px;max-width:1200px;">
      Aperçu Timeline – Les brouillons sont visibles
    </div>
    <?php endif; ?>
    <div class="timeline-wrap">
      <section class="timeline" aria-label="Timeline">
        <div class="timeline-head"><h2 class="timeline-title" style="<?= getAccueilAlignStyle($ctx, 'timeline.title') ?>" <?= $editable ? 'data-edit-field="timeline.title" data-edit-kind="text" data-edit-section="timeline"' : '' ?>><?= htmlspecialchars($tlTitle) ?></h2></div>
        <div class="timeline-track">
          <svg class="timeline-svg" viewBox="0 0 200 <?= $svg['height'] ?>" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="gradient-line" x1="0%" y1="0%" x2="0%" y2="100%">
                <stop offset="0%" stop-color="#fce7f3" />
                <stop offset="50%" stop-color="#F42182" />
                <stop offset="100%" stop-color="#db2777" />
              </linearGradient>
            </defs>
            <path class="timeline-path" d="<?= $svg['path'] ?>" />
          </svg>
          <div class="timeline-items">
            <?php foreach ($timelineItems as $index => $ti):
                if (empty($ti['title']) && empty($ti['content']) && empty($ti['image'])) continue;
                $side = ($index % 2 === 0) ? 'left' : 'right';
                $elements = $timelineElements[$ti['id']] ?? [];
            ?>
            <div class="t-item <?= $side ?>">
              <span class="t-dot" aria-hidden="true"></span>
              <article class="t-card">
                <div class="t-media">
                  <div class="t-media-inner">
                  <?php if (!empty($ti['image']) && is_file(__DIR__ . '/../../files/_TimeLine/' . $ti['image'])):
                    $posRaw = $ti['image_position'] ?? '50% 50% 1';
                    $posParts = preg_split('/\s+/', trim($posRaw));
                    $imgXPct = $posParts[0] ?? '50%';
                    $imgYPct = $posParts[1] ?? '50%';
                    $imgScale = floatval(str_replace('%', '', $posParts[2] ?? '1'));
                    if ($imgScale <= 0) $imgScale = 1;
                    $imgStyle = "object-position:{$imgXPct} {$imgYPct}";
                    if ($imgScale > 1) $imgStyle .= ";--zoom:{$imgScale};transform-origin:{$imgXPct} {$imgYPct}";
                  ?>
                    <img src="../files/_TimeLine/<?= htmlspecialchars($ti['image']) ?>" alt="<?= htmlspecialchars($ti['title']) ?>" style="<?= $imgStyle ?>">
                  <?php endif; ?>
                  </div>
                  <div class="t-kicker"><?= htmlspecialchars($ti['title']) ?></div>
                </div>
                <div class="t-content">
                  <div class="t-amount"><?= htmlspecialchars($ti['content']) ?></div>
                  <?php if (!empty($elements)): ?>
                  <div class="t-meta">
                    <?php foreach ($elements as $label): ?>
                      <span class="t-pill"><?= htmlspecialchars($label) ?></span>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </article>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    </div>
    <?php
}

/**
 * Extrait le `src` de la 1re balise <img> trouvée dans un contenu HTML.
 * Utilisé comme fallback d'image pour les cartes d'actualité (même logique que
 * getFirstContentImage() de public/news.php). Guard function_exists pour éviter
 * un conflit si les deux fichiers sont chargés ensemble.
 */
if (!function_exists('accueilGetFirstContentImage')) {
    function accueilGetFirstContentImage(string $html): ?string {
        if ($html !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }
        return null;
    }
}

function renderAccueilSection_news(array $ctx): void {
    $actualites = $ctx['actualites'] ?? [];
    if (empty($actualites)) return;
    $editable = !empty($ctx['_editor']);
    $newsTitle = getAccueilText($ctx, 'news.title',    'Dernières actualités');
    $newsLink  = getAccueilText($ctx, 'news.link_all', 'Voir toutes les actualités');
    // 3 variantes :
    //   'simple'            → 4 cards minimalistes côte à côte (par défaut)
    //   'with-image'        → 2x2/3x2 cards avec image en haut (style timeline)
    //   'with-image-side'   → 2x2/3x2 cards horizontales (image à gauche, style page actualités)
    $cardStyle = (string)($ctx['styles']['news.card_style'] ?? 'simple');
    if (!in_array($cardStyle, ['simple', 'with-image', 'with-image-side'], true)) $cardStyle = 'simple';
    // Max cards : simple = 4, autres = 6 (pour remplir 3x2 sur grand écran).
    // CSS cache les cards 5-6 sur petit écran (montre 2x2 = 4), montre tout sur grand écran.
    $maxCards = ($cardStyle === 'simple') ? 4 : 6;
    $news_cards = array_slice($actualites, 0, $maxCards);
    $gridClass = 'news-grid';
    if ($cardStyle === 'with-image')      $gridClass .= ' news-grid-2x2';
    if ($cardStyle === 'with-image-side') $gridClass .= ' news-grid-2x2 news-grid-side';
    ?>
    <section class="news-band" aria-label="Dernières actualités">
      <div class="news-band-container">
        <div class="news-band-head">
          <h3 class="news-band-title" style="<?= getAccueilAlignStyle($ctx, 'news.title') ?>" <?= $editable ? 'data-edit-field="news.title" data-edit-kind="text" data-edit-section="news"' : '' ?>><?= htmlspecialchars($newsTitle) ?></h3>
        </div>
        <div class="<?= $gridClass ?>">
          <?php if (!empty($news_cards)): ?>
            <?php foreach ($news_cards as $actu):
              if (empty($actu['title'])) continue;
              $dateLabel = $dateAttr = '';
              if (!empty($actu['date_publication'])) {
                $ts = strtotime($actu['date_publication']);
                if ($ts) { $dateLabel = date('d/m/Y', $ts); $dateAttr = date('Y-m-d', $ts); }
              }
              // Chaîne de fallback identique à la page /news :
              //  1) image dédiée de l'article (img_article)
              //  2) 1re image trouvée dans le contenu HTML (desc_article)
              //  3) placeholder 📰 (si $hasImage reste false)
              $hasImage = false; $imgSrc = '';
              if ($cardStyle !== 'simple') {
                if (!empty($actu['img_article'])) {
                  $imgPath = '../files/_news/' . $actu['img_article'];
                  if (is_file($imgPath)) { $hasImage = true; $imgSrc = $imgPath; }
                }
                if (!$hasImage) {
                  $contentImg = accueilGetFirstContentImage((string)($actu['desc_article'] ?? ''));
                  if ($contentImg) { $hasImage = true; $imgSrc = $contentImg; }
                }
              }
            ?>
              <?php if ($cardStyle === 'with-image-side'): ?>
                <!-- Variant 'with-image-side' : layout horizontal façon page /news
                     (image à gauche, titre + meta + lire à droite). -->
                <a class="news-card-h" href="news?id=<?= $actu['id'] ?>">
                  <div class="news-card-h-img">
                    <?php if ($hasImage): ?>
                      <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($actu['title']) ?>" loading="lazy">
                    <?php else: ?>
                      <div class="news-card-h-placeholder">📰</div>
                    <?php endif; ?>
                  </div>
                  <div class="news-card-h-body">
                    <h3 class="news-card-h-title"><?= htmlspecialchars($actu['title']) ?></h3>
                    <div class="news-card-h-meta">
                      <span class="news-card-h-source">Actualité</span>
                      <?php if ($dateLabel !== ''): ?>
                        <span class="news-card-h-dot">·</span>
                        <time class="news-card-h-date" datetime="<?= htmlspecialchars($dateAttr) ?>"><?= htmlspecialchars($dateLabel) ?></time>
                      <?php endif; ?>
                    </div>
                    <span class="news-card-h-cta">Lire →</span>
                  </div>
                </a>
              <?php else: ?>
                <!-- Variants 'simple' et 'with-image' : structure verticale (cards 'news-card').
                     with-image ajoute un bloc image en haut + déplace la pill Actualité dedans. -->
                <a class="news-card" href="news?id=<?= $actu['id'] ?>">
                  <?php if ($cardStyle === 'with-image'): ?>
                    <div class="news-card-img">
                      <?php if ($hasImage): ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($actu['title']) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="news-card-h-placeholder">📰</div>
                      <?php endif; ?>
                      <span class="news-kicker">Actualité</span>
                    </div>
                  <?php endif; ?>
                  <div class="news-body">
                    <?php if ($cardStyle !== 'with-image'): ?>
                      <span class="news-kicker">Actualité</span>
                    <?php endif; ?>
                    <span class="news-title"><?= htmlspecialchars($actu['title']) ?></span>
                    <?php if ($dateLabel !== ''): ?>
                      <time class="news-date" datetime="<?= htmlspecialchars($dateAttr) ?>"><?= htmlspecialchars($dateLabel) ?></time>
                    <?php endif; ?>
                    <span class="news-cta">Lire →</span>
                  </div>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="news-empty">Aucune actualité pour le moment.</div>
          <?php endif; ?>
        </div>
        <!-- Bouton CTA "Voir toutes les actualités" centré sous les cards
             → utilise .btn-action-primary du système unifié (mêmes couleurs/hover que
             "Vérifier mon inscription", "Devenir partenaire", etc.) → cohérent avec
             les variables du thème (--primary, --primary-hover, etc.) -->
        <div class="news-band-footer" style="<?= getAccueilAlignStyle($ctx, 'news.link_all') ?>">
          <a class="btn-action-primary" href="news" <?= $editable ? 'data-edit-field="news.link_all" data-edit-kind="text" data-edit-section="news"' : '' ?>>
            <?= htmlspecialchars($newsLink) ?>
            <i class="bi bi-arrow-right"></i>
          </a>
        </div>
      </div>
    </section>
    <?php
}

function renderAccueilSection_custom(array $section, array $ctx = []): void {
    $content = (string)($section['content'] ?? '');
    if (trim($content) === '') return;
    $kind = (string)($section['kind'] ?? 'text');
    if ($kind === 'html') {
        // Bloc HTML brut : TOUJOURS wrappé en flex pour que l'alignement (align/valign)
        // soit toujours appliqué, même aux valeurs "défaut" left/top. La colonne parent
        // est stretchée (cf .accueil-col-html en CSS) pour que le bloc remplisse la
        // hauteur de la ligne → alignement vertical visible même en multi-col.
        $align  = (string)($section['align']  ?? 'left');
        $valign = (string)($section['valign'] ?? 'top');
        $justMap = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
        $itemMap = ['top'  => 'flex-start', 'center' => 'center', 'bottom' => 'flex-end'];
        $justify = $justMap[$align]  ?? 'flex-start';
        $items   = $itemMap[$valign] ?? 'flex-start';
        $style = 'display:flex;justify-content:' . $justify . ';align-items:' . $items . ';width:100%;height:100%;min-height:50px;';
        echo '<section class="custom-html-section" style="' . $style . '">' . $content . '</section>';
    } else {
        // Bloc texte : wrapper avec style (border-left rose, padding, etc.)
        ?>
        <section class="custom-content-section">
          <div class="custom-content-inner"><?= $content ?></div>
        </section>
        <?php
    }
}

/**
 * Section "Retrouver le départ" : un titre éditable + une carte Google Maps
 * (embed sans clé API) pointant sur le point de départ de la course.
 *
 * Le point est défini par UNE des deux colonnes SQL de `setting` :
 *   - start_point_address  : une adresse postale ("12 rue des Mines, Forbach")
 *   - start_point_coords   : des coordonnées "lat,lng" ("49.1869,6.8983")
 * L'adresse est prioritaire si les deux sont renseignées.
 *
 * L'adresse / les coordonnées NE s'affichent PAS sur la page : elles s'éditent
 * uniquement dans le panneau Propriétés de l'éditeur (sidebar gauche). Le point
 * se place automatiquement sur la carte selon la valeur saisie.
 *
 * Les dimensions de la carte (hauteur px + largeur %) sont réglables séparément
 * pour desktop et mobile via les clés de style start_point_map_* (accueil_styles).
 *
 * Un clic sur la carte ouvre l'itinéraire Google Maps depuis la position du
 * visiteur (origine déduite automatiquement) vers le point de départ.
 */
function renderAccueilSection_start_point(array $ctx): void {
    $editable = !empty($ctx['_editor']);
    $title   = getAccueilText($ctx, 'start_point.title', 'Retrouver le départ');
    $address = trim((string)($ctx['start_point_address'] ?? ''));
    $coords  = trim((string)($ctx['start_point_coords']  ?? ''));

    // L'adresse est prioritaire ; sinon coordonnées ; sinon défaut (Forbach).
    if ($address !== '') {
        $destination = $address;
    } elseif ($coords !== '') {
        $destination = $coords;
    } else {
        $destination = 'Forbach, France';
    }
    $embedUrl = 'https://maps.google.com/maps?q=' . rawurlencode($destination)
              . '&z=15&hl=fr&output=embed';
    $dirUrl   = 'https://www.google.com/maps/dir/?api=1&destination='
              . rawurlencode($destination);

    // Dimensions de la carte → CSS variables, séparées desktop / mobile.
    $styles = $ctx['styles'] ?? [];
    $mapVarCss = '';
    $mh  = $styles['start_point_map_height']        ?? null;
    $mhM = $styles['start_point_map_height_mobile'] ?? null;
    $mw  = $styles['start_point_map_width']         ?? null;
    $mwM = $styles['start_point_map_width_mobile']  ?? null;
    if (is_numeric($mh))  $mapVarCss .= '--sp-map-h:'        . (int)$mh  . 'px;';
    if (is_numeric($mhM)) $mapVarCss .= '--sp-map-h-mobile:' . (int)$mhM . 'px;';
    if (is_numeric($mw))  $mapVarCss .= '--sp-map-w:'        . (int)$mw  . '%;';
    if (is_numeric($mwM)) $mapVarCss .= '--sp-map-w-mobile:' . (int)$mwM . '%;';
    ?>
    <section class="start-point-section" aria-label="Point de départ"
             <?= $mapVarCss !== '' ? 'style="' . $mapVarCss . '"' : '' ?>
             <?= $editable ? 'data-sp-address="' . htmlspecialchars($address) . '" data-sp-coords="' . htmlspecialchars($coords) . '"' : '' ?>>
      <div class="start-point-container">
        <div class="start-point-head">
          <h2 class="start-point-title" style="<?= getAccueilAlignStyle($ctx, 'start_point.title') ?>"
              <?= $editable ? 'data-edit-field="start_point.title" data-edit-kind="text" data-edit-section="start_point"' : '' ?>><?= htmlspecialchars($title) ?></h2>
        </div>
        <?php if ($editable): ?>
        <div class="start-point-map start-point-map--editor">
          <iframe class="start-point-map-frame" src="<?= htmlspecialchars($embedUrl) ?>"
                  loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                  title="Carte du point de départ"></iframe>
          <span class="start-point-map-overlay" aria-hidden="true">
            <i class="bi bi-geo-alt-fill"></i> Ouvrir l'itinéraire
          </span>
        </div>
        <?php else: ?>
        <a class="start-point-map" href="<?= htmlspecialchars($dirUrl) ?>" target="_blank"
           rel="noopener noreferrer" aria-label="Ouvrir l'itinéraire vers le point de départ">
          <iframe class="start-point-map-frame" src="<?= htmlspecialchars($embedUrl) ?>"
                  loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                  title="Carte du point de départ"></iframe>
          <span class="start-point-map-overlay">
            <i class="bi bi-geo-alt-fill"></i> Ouvrir l'itinéraire
          </span>
        </a>
        <?php endif; ?>
      </div>
    </section>
    <?php
}

/**
 * Section "Rester informé" : formulaire d'abonnement à la newsletter.
 *
 * L'email saisi est enregistré dans `newsletter_subscribers` via l'endpoint
 * public/newsletter.php (AJAX). À la publication d'un article (case cochée côté
 * admin), un mail est envoyé à tous les abonnés.
 *
 * Tous les textes sont éditables ; le style réutilise les variables du thème
 * (--primary, --radius, --font-family…) pour rester cohérent avec le site.
 */
function renderAccueilSection_newsletter(array $ctx): void {
    $editable = !empty($ctx['_editor']);
    $title    = getAccueilText($ctx, 'newsletter.title',       'Rester informé');
    $subtitle = getAccueilText($ctx, 'newsletter.subtitle',    "c'est déjà un moyen d'agir");
    $intro    = getAccueilText($ctx, 'newsletter.intro',       'Abonnez-vous à la newsletter de Forbach en Rose.');
    $ph       = getAccueilText($ctx, 'newsletter.placeholder', 'Votre adresse email');
    $btn      = getAccueilText($ctx, 'newsletter.button',      "Je m'abonne");
    $consent  = getAccueilText($ctx, 'newsletter.consent',     "En cliquant sur « Je m'abonne », j'accepte de recevoir des e-mails de l'association et confirme avoir pris connaissance de la politique de confidentialité.");
    $ed = function($field) use ($editable) {
        return $editable ? ' data-edit-field="' . $field . '" data-edit-kind="text" data-edit-section="newsletter"' : '';
    };
    ?>
    <section class="newsletter-section" aria-label="Newsletter">
      <div class="newsletter-card">
        <div class="newsletter-content">
          <h2 class="newsletter-title" style="<?= getAccueilAlignStyle($ctx, 'newsletter.title') ?>"<?= $ed('newsletter.title') ?>><?= htmlspecialchars($title) ?></h2>
          <p class="newsletter-subtitle" style="<?= getAccueilAlignStyle($ctx, 'newsletter.subtitle') ?>"<?= $ed('newsletter.subtitle') ?>><?= htmlspecialchars($subtitle) ?></p>
          <p class="newsletter-intro" style="<?= getAccueilAlignStyle($ctx, 'newsletter.intro') ?>"<?= $ed('newsletter.intro') ?>><?= htmlspecialchars($intro) ?></p>
          <form class="newsletter-form" id="newsletterForm" method="post" action="newsletter.php" novalidate>
            <div class="newsletter-input-row">
              <input type="email" name="email" class="newsletter-email" placeholder="<?= htmlspecialchars($ph) ?>" autocomplete="email" required>
              <button type="submit" class="newsletter-submit"<?= $ed('newsletter.button') ?>><?= htmlspecialchars($btn) ?></button>
            </div>
            <!-- Champ piège anti-bot : invisible, doit rester vide -->
            <input type="text" name="website" class="newsletter-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="newsletter-consent">
              <input type="checkbox" name="consent" required>
              <span style="<?= getAccueilAlignStyle($ctx, 'newsletter.consent') ?>"<?= $ed('newsletter.consent') ?>><?= htmlspecialchars($consent) ?></span>
            </label>
            <!-- Vérification anti-robot (Turnstile si configuré, sinon question maths) — révélée au 1er clic -->
            <div class="newsletter-captcha" id="nlCaptcha" style="display:none;">
              <div id="nlTsBox" style="display:none;"></div>
              <div id="nlMathBox" style="display:none;">
                <div id="nlCaptchaQuestion" class="nl-captcha-q">Chargement…</div>
                <div class="nl-captcha-row">
                  <input id="nlCaptchaAnswer" type="text" inputmode="numeric" autocomplete="off" placeholder="Votre réponse" class="nl-captcha-input">
                  <button type="button" id="nlCaptchaReload" class="nl-captcha-reload" title="Nouvelle question">&#8635;</button>
                </div>
              </div>
              <div id="nlCaptchaError" class="nl-captcha-error" role="alert"></div>
            </div>
            <input type="hidden" name="turnstile_token" id="nlTsToken">
            <input type="hidden" name="captcha_token"   id="nlCaptchaToken">
            <input type="hidden" name="captcha_answer"  id="nlCaptchaAnswerHidden">
            <div class="newsletter-feedback" id="newsletterFeedback" role="status" aria-live="polite"></div>
          </form>
        </div>
        <div class="newsletter-deco" aria-hidden="true">
          <!-- Ruban de sensibilisation au cancer (SVG fourni). fill=currentColor
               → suit la couleur du thème (--secondary-text), donc blanc sur la carte. -->
          <svg class="newsletter-ribbon" viewBox="0 0 1127 1396"
               xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
            <g transform="translate(0,1396) scale(0.1,-0.1)" fill="currentColor" stroke="none">
              <path d="M3680 12640 c-199 -24 -255 -35 -475 -100 -462 -137 -968 -463 -1255
-810 -278 -337 -537 -1025 -609 -1620 -29 -242 -21 -626 18 -845 21 -118 91
-397 115 -461 8 -22 26 -73 41 -114 23 -66 36 -101 65 -175 185 -475 558
-1105 923 -1560 144 -179 434 -500 645 -713 24 -24 42 -50 40 -57 -2 -8 -577
-583 -1278 -1278 -1076 -1067 -1530 -1526 -1858 -1878 -47 -51 -52 -60 -52
-102 0 -26 1 -47 3 -47 4 0 92 29 132 43 81 30 240 40 406 28 406 -31 678
-144 919 -382 137 -135 274 -375 314 -550 32 -143 37 -182 46 -359 9 -194 20
-250 49 -250 48 0 727 740 1446 1575 132 154 303 351 380 440 77 88 198 228
269 310 125 145 159 183 365 414 207 231 462 481 491 481 9 0 59 -34 111 -76
52 -42 159 -127 239 -190 80 -63 199 -157 265 -210 156 -125 499 -381 661
-494 291 -203 602 -405 814 -530 607 -357 1211 -649 1980 -955 202 -80 455
-174 565 -210 39 -13 81 -28 95 -33 30 -13 346 -113 450 -142 142 -41 534
-129 670 -151 128 -21 422 -58 508 -64 l92 -7 0 55 0 54 -87 7 c-83 6 -140 13
-413 52 -223 32 -545 98 -750 155 -225 63 -839 276 -995 345 -16 7 -77 32
-135 54 -93 37 -178 73 -275 115 -16 8 -58 25 -92 39 -35 15 -86 37 -115 50
-411 186 -544 248 -778 365 -363 182 -627 329 -853 475 -116 75 -660 462 -832
592 -584 441 -743 567 -957 759 -76 68 -81 93 -38 177 46 87 121 207 194 307
139 193 759 1015 786 1044 16 18 21 15 121 -65 57 -46 138 -109 179 -140 41
-31 125 -95 185 -142 164 -128 360 -276 451 -342 45 -33 187 -136 315 -230
617 -451 1178 -818 2064 -1349 703 -422 1177 -683 1726 -952 333 -164 299
-154 298 -90 l-1 52 -306 152 c-169 84 -356 180 -417 214 -60 34 -132 74 -160
89 -98 53 -311 173 -370 210 -33 20 -91 55 -130 77 -146 84 -924 553 -1210
728 -88 55 -206 123 -263 152 -91 47 -352 215 -564 364 -82 58 -419 307 -738
544 -107 80 -229 170 -270 200 -41 29 -147 108 -235 174 -88 66 -202 150 -252
187 -176 126 -263 222 -263 287 0 66 36 136 195 372 328 488 487 763 661 1141
121 263 263 656 308 854 8 36 22 94 30 130 98 418 112 963 35 1330 -46 217
-106 418 -162 540 -8 19 -32 71 -52 115 -113 249 -298 517 -521 756 -467 499
-1077 845 -1738 989 -100 22 -201 44 -224 50 -24 6 -90 15 -145 21 -56 6 -133
14 -172 19 -115 15 -454 9 -625 -10z m825 -125 c338 -39 660 -133 820 -239
207 -137 422 -483 511 -826 71 -271 55 -706 -37 -1055 -43 -161 -155 -483
-219 -630 -9 -22 -41 -96 -70 -165 -173 -408 -389 -786 -789 -1384 -85 -127
-175 -262 -200 -299 -28 -43 -50 -67 -60 -65 -8 2 -96 89 -196 195 -99 105
-247 263 -330 350 -528 560 -1012 1255 -1354 1943 -188 380 -221 501 -221 823
0 350 65 614 217 887 84 149 160 211 358 293 240 98 413 147 605 172 190 25
747 25 965 0z m1191 -373 c473 -270 888 -672 1177 -1139 110 -178 247 -479
283 -618 7 -27 24 -95 39 -150 88 -340 92 -816 11 -1270 -47 -268 -174 -665
-302 -953 -13 -29 -24 -55 -24 -58 0 -12 -165 -334 -235 -459 -142 -254 -414
-682 -588 -925 -18 -25 -54 -76 -82 -115 -79 -113 -271 -369 -392 -525 -62
-80 -146 -187 -185 -239 -40 -52 -120 -153 -177 -225 -109 -138 -139 -177
-290 -370 -167 -214 -241 -305 -495 -611 -343 -414 -354 -427 -915 -1079 -146
-170 -279 -325 -296 -345 -53 -64 -346 -401 -495 -571 -338 -383 -469 -528
-569 -629 -192 -194 -197 -193 -245 54 -27 137 -52 235 -67 265 -4 8 -17 37
-29 63 -56 130 -239 363 -365 467 -211 175 -374 248 -730 330 -57 14 -253 38
-367 46 -78 5 -98 10 -98 21 0 9 330 342 733 741 1404 1393 1922 1919 2202
2242 22 25 67 74 100 110 298 322 798 948 1075 1345 27 39 75 107 107 151 417
586 535 761 731 1090 210 352 446 835 532 1094 11 30 30 87 44 125 142 401
216 885 187 1226 -29 338 -143 620 -342 845 -67 77 -82 101 -75 119 8 22 13
21 142 -53z"/>
            </g>
          </svg>
        </div>
      </div>
    </section>
    <?php if (!$editable): ?>
    <style nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
      .newsletter-captcha { margin:10px 0 4px; }
      .nl-captcha-q { font-weight:700; color:#1e293b; margin-bottom:6px; }
      .nl-captcha-row { display:flex; gap:8px; align-items:stretch; }
      .nl-captcha-input { flex:1; padding:9px 12px; border:1px solid #cbd5e1; border-radius:9px; font-size:15px; outline:none; background:#fff; }
      .nl-captcha-input:focus { border-color:var(--primary,#f42182); box-shadow:0 0 0 3px rgba(219,39,119,.15); }
      .nl-captcha-reload { background:#e2e8f0; border:none; border-radius:9px; padding:0 14px; font-size:16px; color:#334155; cursor:pointer; }
      .nl-captcha-reload:hover { background:#cbd5e1; }
      .nl-captcha-error { color:#b91c1c; font-size:.82rem; margin-top:6px; min-height:1em; }
    </style>
    <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
    (function() {
      var form = document.getElementById('newsletterForm');
      if (!form || form.dataset.bound) return;
      form.dataset.bound = '1';

      var fb  = document.getElementById('newsletterFeedback');
      var btn = form.querySelector('.newsletter-submit');
      var btnDefault = btn ? btn.textContent : "Je m'abonne";
      var fbTimer = null;

      var box       = document.getElementById('nlCaptcha');
      var tsBox     = document.getElementById('nlTsBox');
      var mathBox   = document.getElementById('nlMathBox');
      var qEl       = document.getElementById('nlCaptchaQuestion');
      var aEl       = document.getElementById('nlCaptchaAnswer');
      var aHidden   = document.getElementById('nlCaptchaAnswerHidden');
      var tokHidden = document.getElementById('nlCaptchaToken');
      var tsHidden  = document.getElementById('nlTsToken');
      var errEl     = document.getElementById('nlCaptchaError');
      var reloadBtn = document.getElementById('nlCaptchaReload');

      var mode = null, tsWidgetId = null, tsLoading = null, didFallback = false;
      var captchaShown = false, submitting = false;

      function clearFeedback() { if (fbTimer){clearTimeout(fbTimer);fbTimer=null;} fb.className='newsletter-feedback'; fb.textContent=''; }
      // Succès : le message RESTE affiché (l'utilisateur doit pouvoir le lire).
      // Erreur : disparaît au bout de 7 s.
      function showFeedback(msg, ok) {
        if (fbTimer) { clearTimeout(fbTimer); fbTimer = null; }
        fb.className = 'newsletter-feedback ' + (ok ? 'is-success' : 'is-error');
        fb.textContent = msg;
        if (!ok) fbTimer = setTimeout(clearFeedback, 7000);
      }
      function setCaptchaError(m) { if (errEl) errEl.textContent = m || ''; }

      function ensureTurnstileScript() {
        if (window.turnstile) return Promise.resolve();
        if (tsLoading) return tsLoading;
        tsLoading = new Promise(function(resolve, reject) {
          var s = document.createElement('script');
          s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
          s.async = true; s.defer = true;
          s.onload = function(){ resolve(); };
          s.onerror = function(){ tsLoading = null; reject(new Error('load')); };
          document.head.appendChild(s);
        });
        return tsLoading;
      }

      // Bascule vers le captcha maths UNIQUEMENT si Turnstile ne fonctionne pas
      // (échec de rendu/chargement). Une seule vérification est affichée à la fois.
      function switchToMathFallback() {
        if (didFallback) return; didFallback = true;
        if (tsWidgetId !== null && window.turnstile) { try { window.turnstile.remove(tsWidgetId); } catch(e){} tsWidgetId = null; }
        tsBox.style.display = 'none';
        fetch('../admin-api.php?route=partner-captcha-init&fallback=1')
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j || !j.ok || j.mode !== 'math') throw new Error('fb');
            mode = 'math'; mathBox.style.display = 'block';
            tokHidden.value = j.token; tsHidden.value = ''; qEl.textContent = j.question; if (aEl){ aEl.value=''; aEl.focus(); }
            setCaptchaError('');
          })
          .catch(function(){ setCaptchaError('Vérification indisponible. Réessayez plus tard.'); });
      }

      function initCaptcha() {
        setCaptchaError(''); didFallback = false;
        tsHidden.value = ''; tokHidden.value = ''; aHidden.value = '';
        return fetch('../admin-api.php?route=partner-captcha-init')
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j || !j.ok) throw new Error('init');
            mode = j.mode;
            if (mode === 'turnstile') {
              mathBox.style.display = 'none'; tsBox.style.display = 'block';
              ensureTurnstileScript().then(function(){
                if (tsWidgetId !== null) { try { window.turnstile.remove(tsWidgetId); } catch(e){} tsWidgetId = null; }
                tsWidgetId = window.turnstile.render(tsBox, {
                  sitekey: j.sitekey, theme: 'light',
                  // Validation automatique : dès que Cloudflare valide, on s'abonne directement.
                  callback: function(token){ tsHidden.value = token; setCaptchaError(''); doSubscribe(); },
                  'error-callback':   function(){ tsHidden.value = ''; switchToMathFallback(); },
                  'expired-callback': function(){ tsHidden.value = ''; setCaptchaError('Vérification expirée, refaites-la.'); }
                });
              }).catch(function(){ switchToMathFallback(); });
            } else {
              tsBox.style.display = 'none'; mathBox.style.display = 'block';
              tokHidden.value = j.token; qEl.textContent = j.question; if (aEl) aEl.value = '';
            }
          })
          .catch(function(){ setCaptchaError('Impossible d\'initialiser la vérification. Réessayez.'); });
      }

      function revealCaptcha() {
        captchaShown = true;
        box.style.display = 'block';
        if (btn) btn.textContent = 'Valider mon abonnement';
        initCaptcha();
      }

      function doSubscribe() {
        if (submitting) return;
        if (mode === 'math') {
          aHidden.value = (aEl.value || '').trim();
          if (!aHidden.value) { setCaptchaError('Répondez à la question ci-dessus.'); return; }
        } else {
          if (!tsHidden.value) { setCaptchaError('Complétez la vérification « Je ne suis pas un robot ».'); return; }
        }
        submitting = true; if (btn) btn.disabled = true;
        var fd = new FormData(form);
        fd.append('subscribe_newsletter', '1');
        fetch('newsletter.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (j && j.ok) {
              form.reset();
              box.style.display = 'none'; captchaShown = false; if (btn) btn.textContent = btnDefault;
              showFeedback((j && j.msg) || 'Abonnement confirmé !', true);
            } else {
              showFeedback((j && j.msg) || 'Une erreur est survenue.', false);
              initCaptcha(); // vérification consommée → on en régénère une
            }
          })
          .catch(function(){ showFeedback('Une erreur réseau est survenue. Réessayez plus tard.', false); initCaptcha(); })
          .finally(function(){ submitting = false; if (btn) btn.disabled = false; });
      }

      if (aEl) aEl.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); doSubscribe(); } });
      if (reloadBtn) reloadBtn.addEventListener('click', function(){ initCaptcha(); });

      form.addEventListener('submit', function(e) {
        e.preventDefault();
        var email   = (form.email.value || '').trim();
        var consent = form.consent.checked;
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          showFeedback('Merci de saisir une adresse email valide.', false); return;
        }
        if (!consent) {
          showFeedback('Merci de cocher la case de consentement.', false); return;
        }
        // 1er clic : on révèle le captcha. Clics suivants : on valide + abonne.
        if (!captchaShown) { revealCaptcha(); return; }
        doSubscribe();
      });
    })();
    </script>
    <?php endif;
}

/**
 * Dispatcher unique. Render la section selon son type.
 */
function renderAccueilSection(array $section, array $ctx): void
{
    $type = $section['type'] ?? '';
    switch ($type) {
        case 'hero':        renderAccueilSection_hero($ctx);        break;
        case 'reg_bar':     renderAccueilSection_reg_bar($ctx);     break;
        case 'partners':    renderAccueilSection_partners($ctx);    break;
        case 'timeline':    renderAccueilSection_timeline($ctx);    break;
        case 'news':        renderAccueilSection_news($ctx);        break;
        case 'start_point': renderAccueilSection_start_point($ctx); break;
        case 'newsletter':  renderAccueilSection_newsletter($ctx);  break;
        case 'custom':      renderAccueilSection_custom($section, $ctx); break;
    }
}
