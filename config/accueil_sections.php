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
        'reg_bar.title_search'    => ['label' => 'Compteur — titre recherche', 'default' => 'Vérifier mon inscription'],
        'reg_bar.placeholder'     => ['label' => 'Compteur — placeholder email', 'default' => 'Votre adresse email'],
        'reg_bar.btn_check'       => ['label' => 'Compteur — bouton vérifier', 'default' => 'Vérifier →'],
        'reg_bar.hint'            => ['label' => 'Compteur — hint', 'default' => "Saisissez l'email utilisé lors de votre inscription."],
        'partners.title'          => ['label' => 'Partenaires — titre', 'default' => 'Rejoignez le clan de nos partenaires engagés'],
        'partners.text'           => ['label' => 'Partenaires — paragraphe', 'default' => "Chaque année, des entreprises et associations locales s'associent à Forbach en Rose pour soutenir la lutte contre le cancer. En devenant partenaire, vous contribuez directement à la réussite de cet événement caritatif et affichez votre engagement solidaire auprès de notre communauté."],
        'partners.btn_submit'     => ['label' => 'Partenaires — bouton', 'default' => 'Devenir partenaire'],
        'partners.placeholder'    => ['label' => 'Partenaires — placeholder', 'default' => 'Votre email professionnel'],
        'partners.note'           => ['label' => 'Partenaires — note', 'default' => 'Nous vous recontacterons dans les plus brefs délais pour discuter des modalités de partenariat.'],
        'timeline.title'          => ['label' => 'Timeline — titre', 'default' => 'Historique'],
        'news.title'              => ['label' => 'Actualités — titre', 'default' => 'Dernières actualités'],
        'news.link_all'           => ['label' => 'Actualités — bouton voir toutes', 'default' => 'Voir toutes les actualités'],
        'hero.cta_register'       => ['label' => 'Hero — bouton inscription', 'default' => "Je m'inscris →"],
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
        'styles'                  => $styles,
        'texts'                   => $texts,
        'geometry'                => $geometry,
    ];
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
    $videoExists      = $videoFile !== '' && is_file(__DIR__ . '/../files/' . $videoFile);
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
    $btnCheck    = getAccueilText($ctx, 'reg_bar.btn_check',      'Vérifier →');
    $hint        = getAccueilText($ctx, 'reg_bar.hint',           "Saisissez l'email utilisé lors de votre inscription.");
    ?>
    <section class="reg-bar" id="reg-bar" aria-label="Inscriptions">
      <div class="reg-card">
        <?php if ($count === 0 && $accueil_active === 0): ?>
        <div class="reg-count">
          <div class="reg-kicker">Inscriptions</div>
          <div class="reg-value" style="font-size:1.2rem;">Fermées</div>
        </div>
        <div class="reg-search">
          <div class="reg-title" style="display:flex;align-items:center;justify-content:center;gap:6px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg><span>Inscriptions actuellement fermées</span></div>
          <?php if ($autoOpen && $now < $autoOpen): ?>
            <p style="margin-top:10px;font-size:.95rem;color:#b5366b;display:flex;align-items:center;justify-content:center;gap:5px;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span>Ouverture le <strong><?= $autoOpen->format('d/m/Y') ?></strong> à <strong><?= $autoOpen->format('H\hi') ?></strong></span>
            </p>
          <?php else: ?>
            <p style="margin-top:10px;font-size:.95rem;color:#64748b;">Merci de votre compréhension.</p>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="reg-count">
          <div class="reg-kicker" style="<?= getAccueilAlignStyle($ctx, 'reg_bar.kicker_open') ?>" <?= $editable ? 'data-edit-field="reg_bar.kicker_open" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($kickerOpen) ?></div>
          <div class="reg-value"><?= number_format($count, 0, ',', ' ') ?></div>
        </div>
        <div class="reg-search">
          <div class="reg-title" style="<?= getAccueilAlignStyle($ctx, 'reg_bar.title_search') ?>" <?= $editable ? 'data-edit-field="reg_bar.title_search" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($titleSearch) ?></div>
          <form class="reg-form" method="get" action="accueil#reg-bar">
            <input type="hidden" name="check_registration" value="1">
            <input class="reg-input" type="email" name="search_email" placeholder="<?= htmlspecialchars($placeholder) ?>"
                  value="<?= htmlspecialchars($searchEmail) ?>" autocomplete="email" required>
            <button class="reg-submit" type="submit" <?= $editable ? 'data-edit-field="reg_bar.btn_check" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($btnCheck) ?></button>
          </form>
          <p id="regResult" class="reg-result <?= htmlspecialchars($searchStatus) ?>" aria-live="polite"
             style="<?= $searchMessage !== '' ? '' : 'display:none;' ?>"><?= htmlspecialchars($searchMessage) ?></p>
          <p id="regHint" class="reg-hint" style="<?= $searchMessage !== '' ? 'display:none;' : '' ?>"
             <?= $editable ? 'data-edit-field="reg_bar.hint" data-edit-kind="text" data-edit-section="reg_bar"' : '' ?>><?= htmlspecialchars($hint) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
}

function renderAccueilSection_partners(array $ctx): void {
    $picture_partner = (string)($ctx['picture_partner'] ?? '');
    $hasImage = $picture_partner !== '' && is_file(__DIR__ . '/../files/_pictures/' . $picture_partner);
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
                  <?php if (!empty($ti['image']) && is_file(__DIR__ . '/../files/_TimeLine/' . $ti['image'])):
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
              $hasImage = false; $imgSrc = '';
              if ($cardStyle !== 'simple' && !empty($actu['img_article'])) {
                $imgPath = '../files/_news/' . $actu['img_article'];
                if (is_file($imgPath)) { $hasImage = true; $imgSrc = $imgPath; }
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
 * Dispatcher unique. Render la section selon son type.
 */
function renderAccueilSection(array $section, array $ctx): void
{
    $type = $section['type'] ?? '';
    switch ($type) {
        case 'hero':     renderAccueilSection_hero($ctx);     break;
        case 'reg_bar':  renderAccueilSection_reg_bar($ctx);  break;
        case 'partners': renderAccueilSection_partners($ctx); break;
        case 'timeline': renderAccueilSection_timeline($ctx); break;
        case 'news':     renderAccueilSection_news($ctx);     break;
        case 'custom':   renderAccueilSection_custom($section, $ctx); break;
    }
}
