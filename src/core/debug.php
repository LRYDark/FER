<?php
/**
 * debug.php — Mode debug « à la GLPI ».
 *
 * Objectifs :
 *   - TOUJOURS journaliser le maximum (toutes les erreurs PHP) dans
 *     storage/logs/php-error.log, sans JAMAIS les afficher au visiteur.
 *   - Quand le mode debug est ACTIF (setting.debogage = 1) ET que l'utilisateur
 *     est admin sur une page HTML : afficher une barre de debug en bas de page
 *     (requêtes SQL + temps + nombre + mémoire) et les erreurs PHP en cartes rouges.
 *
 * Rien n'est affiché sur les réponses API/JSON, les redirections, les
 * téléchargements ou en CLI — la barre ne sort que sur les pages admin HTML.
 *
 * Chargé très tôt par src/core/config.php (avant la création de $pdo) pour que le
 * profileur de requêtes (DebugPDO/DebugPDOStatement) soit disponible.
 */

/** Statement PDO chronométré : mesure chaque execute() de requête préparée. */
class DebugPDOStatement extends PDOStatement
{
    protected function __construct() {}

    #[\ReturnTypeWillChange]
    public function execute(?array $params = null): bool
    {
        if (!DebugBar::$enabled) return parent::execute($params);
        $t0 = microtime(true);
        $ok = parent::execute($params);
        DebugBar::logQuery($this->queryString, microtime(true) - $t0, @$this->rowCount(), $params);
        return $ok;
    }
}

/** PDO chronométré : mesure query() et exec() (requêtes directes). */
class DebugPDO extends PDO
{
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        if (!DebugBar::$enabled) {
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
        $t0 = microtime(true);
        $r  = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        DebugBar::logQuery($query, microtime(true) - $t0, ($r instanceof PDOStatement ? @$r->rowCount() : 0));
        return $r;
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false
    {
        if (!DebugBar::$enabled) return parent::exec($statement);
        $t0 = microtime(true);
        $r  = parent::exec($statement);
        DebugBar::logQuery($statement, microtime(true) - $t0, is_int($r) ? $r : 0);
        return $r;
    }
}

/** Collecteur + rendu de la barre de debug. */
class DebugBar
{
    public static bool  $enabled  = false;  // reflète setting.debogage (affichage + profiling)
    public static array $queries  = [];
    public static array $errors   = [];
    private static bool $rendered = false;

    public static function logQuery(string $sql, float $sec, int $rows = 0, $params = null): void
    {
        if (!self::$enabled) return;
        self::$queries[] = ['sql' => trim($sql), 'ms' => $sec * 1000, 'rows' => (int) $rows];
    }

    public static function logError(string $type, string $msg, string $file, int $line): void
    {
        if (!self::$enabled) return;
        self::$errors[] = ['type' => $type, 'msg' => $msg, 'file' => $file, 'line' => (int) $line];
    }

    /** Libellé lisible d'un niveau d'erreur PHP. */
    public static function levelLabel(int $errno): string
    {
        return [
            E_ERROR => 'ERROR', E_WARNING => 'WARNING', E_PARSE => 'PARSE', E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR', E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR', E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR', E_USER_WARNING => 'USER_WARNING', E_USER_NOTICE => 'USER_NOTICE',
            E_RECOVERABLE_ERROR => 'RECOVERABLE', E_DEPRECATED => 'DEPRECATED', E_USER_DEPRECATED => 'USER_DEPRECATED',
        ][$errno] ?? ('E_' . $errno);
    }

    /** Rendu conditionnel (garde-fous) appelé au shutdown. */
    public static function maybeRender(): void
    {
        if (self::$rendered || !self::$enabled) return;
        if (PHP_SAPI === 'cli') return;
        // Admin uniquement.
        if (($_SESSION['role'] ?? null) !== 'admin') return;
        // Jamais sur l'API/JSON, une redirection, un téléchargement ou un contenu non-HTML.
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') return;
        // Uniquement sur une vraie navigation de page (pas un fetch/XHR/sous-ressource) :
        // évite d'injecter la barre dans une réponse récupérée en JavaScript.
        $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
        if ($dest !== '' && $dest !== 'document') return;
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') return;
        foreach (headers_list() as $h) {
            $hl = strtolower($h);
            if (str_starts_with($hl, 'location:')) return;
            if (str_starts_with($hl, 'content-disposition: attachment')) return;
            if (str_starts_with($hl, 'content-type:') && !str_contains($hl, 'text/html')) return;
        }
        self::render();
    }

    /** Masque récursivement les valeurs sensibles d'un tableau de globals. */
    private static function maskArr($arr): array
    {
        $out = [];
        foreach ((array) $arr as $k => $v) {
            if (is_array($v))  { $out[$k] = self::maskArr($v); continue; }
            if (is_object($v)) { $out[$k] = '(object ' . get_class($v) . ')'; continue; }
            if (preg_match('/pass|secret|_key|apikey|authorization|_enc$|private/i', (string) $k)) {
                $out[$k] = '••• masqué';
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** Limite mémoire PHP en Mo (0 = illimité). */
    private static function memLimitMib(): float
    {
        $l = ini_get('memory_limit');
        if ($l === false || $l === '-1') return 0;
        $u = strtoupper(substr((string) $l, -1));
        $n = (float) $l;
        if ($u === 'G') $n *= 1024; elseif ($u === 'K') $n /= 1024;
        return $n;
    }

    /**
     * Panneau de debug type GLPI : barre résumé + panneau à onglets
     * (Résumé / SQL / Globals / Erreurs), redimensionnable en hauteur.
     * Styles inline (CSP style 'unsafe-inline') + un script nonce (CSP script).
     */
    public static function render(): void
    {
        if (self::$rendered) return;
        self::$rendered = true;

        $nonce   = $GLOBALS['csp_nonce'] ?? '';
        $reqTime = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000 : 0;
        $memNow  = memory_get_usage(true) / 1048576;
        $memPeak = memory_get_peak_usage(true) / 1048576;
        $memLim  = self::memLimitMib();
        $limTxt  = $memLim > 0 ? (' / ' . number_format($memLim, 0) . ' Mo') : '';
        $nbQ     = count(self::$queries);
        $sumQ    = array_sum(array_column(self::$queries, 'ms'));
        $nbE     = count(self::$errors);
        $esc     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        // Erreurs (cartes rouges).
        $errHtml = '';
        foreach (self::$errors as $e) {
            $errHtml .= '<div class="ferdbg-err"><span class="ferdbg-err-t">' . $esc($e['type']) . '</span> '
                     . $esc($e['msg']) . '<div class="ferdbg-err-loc">' . $esc($e['file']) . ':' . (int) $e['line'] . '</div></div>';
        }
        if ($errHtml === '') $errHtml = '<div style="color:#9ca3af">Aucune erreur PHP sur cette page. 👍</div>';

        // Requêtes SQL.
        $qHtml = '';
        foreach (self::$queries as $i => $q) {
            $slow = $q['ms'] >= 20 ? ' ferdbg-slow' : '';
            $qHtml .= '<tr><td>' . ($i + 1) . '</td>'
                   . '<td class="ferdbg-sql">' . $esc($q['sql']) . '</td>'
                   . '<td class="ferdbg-num' . $slow . '">' . number_format($q['ms'], 2) . ' ms</td>'
                   . '<td class="ferdbg-num">' . (int) $q['rows'] . '</td></tr>';
        }
        if ($qHtml === '') $qHtml = '<tr><td colspan="4" style="text-align:center;color:#9ca3af">Aucune requête profilée</td></tr>';

        // Globals (masqués) → embarqués pour le sous-onglet Globals.
        $globals = [
            'SESSION' => self::maskArr($_SESSION ?? []),
            'GET'     => self::maskArr($_GET ?? []),
            'POST'    => self::maskArr($_POST ?? []),
            'COOKIE'  => self::maskArr($_COOKIE ?? []),
            'SERVER'  => self::maskArr($_SERVER ?? []),
        ];
        $globalsJson = json_encode($globals, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($globalsJson === false) $globalsJson = '{}';

        $badgeErr = $nbE > 0 ? '<span class="ferdbg-badge ferdbg-b-err">⚠ ' . $nbE . '</span>' : '';
        $cell = fn($lbl, $val) => '<div class="ferdbg-kv"><div class="ferdbg-k">' . $lbl . '</div><div class="ferdbg-v">' . $val . '</div></div>';

        // ── Styles ──
        echo '<style>'
           . '#ferdbg,#ferdbg *{box-sizing:border-box}'
           . '#ferdbg{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;font:12px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#e5e7eb}'
           . '#ferdbg .ferdbg-bar{display:flex;align-items:center;gap:12px;background:#0f172a;padding:6px 14px;cursor:pointer;user-select:none}'
           . '#ferdbg .ferdbg-resize{height:6px;background:#f42182;cursor:ns-resize}'
           . '#ferdbg .ferdbg-resize:hover{background:#fb5ca0}'
           . '#ferdbg .ferdbg-bar b{color:#f9a8d4}'
           . '#ferdbg .ferdbg-badge{background:#1e293b;border-radius:6px;padding:2px 8px;white-space:nowrap}'
           . '#ferdbg .ferdbg-b-err{background:#7f1d1d;color:#fecaca}'
           . '#ferdbg .ferdbg-sp{margin-left:auto;color:#94a3b8}'
           . '#ferdbg .ferdbg-panel{display:none;height:42vh;background:#0b1220;border-top:1px solid #1e293b}'
           . '#ferdbg.open .ferdbg-panel{display:flex;flex-direction:column}'
           . '#ferdbg.open .ferdbg-sp::after{content:"▼"}#ferdbg .ferdbg-sp::after{content:"▲"}'
           . '#ferdbg .ferdbg-tabs{display:flex;gap:2px;padding:10px 12px 0;border-bottom:1px solid #1e293b}'
           . '#ferdbg .ferdbg-tabs button{background:none;border:none;color:#94a3b8;padding:6px 14px;border-radius:8px 8px 0 0;cursor:pointer;font:inherit}'
           . '#ferdbg .ferdbg-tabs button.on{background:#1e293b;color:#f9a8d4;font-weight:700}'
           . '#ferdbg .ferdbg-body{flex:1;overflow:auto;padding:12px 14px}'
           . '#ferdbg .ferdbg-h{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#f9a8d4;margin:2px 0 8px}'
           . '#ferdbg .ferdbg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:18px}'
           . '#ferdbg .ferdbg-k{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8}'
           . '#ferdbg .ferdbg-v{font-size:15px;color:#e5e7eb}'
           . '#ferdbg .ferdbg-err{background:#450a0a;border:1px solid #b91c1c;border-radius:8px;padding:8px 12px;margin-bottom:8px;color:#fecaca}'
           . '#ferdbg .ferdbg-err-t{display:inline-block;background:#b91c1c;color:#fff;border-radius:4px;padding:0 6px;font-weight:700;margin-right:6px}'
           . '#ferdbg .ferdbg-err-loc{color:#fca5a5;font-size:11px;margin-top:2px}'
           . '#ferdbg table{width:100%;border-collapse:collapse}'
           . '#ferdbg th,#ferdbg td{border-bottom:1px solid #1e293b;padding:4px 8px;text-align:left;vertical-align:top}'
           . '#ferdbg th{color:#94a3b8;position:sticky;top:0;background:#0b1220}'
           . '#ferdbg .ferdbg-sql{font-family:ui-monospace,Consolas,monospace;color:#cbd5e1;white-space:pre-wrap;word-break:break-word}'
           . '#ferdbg .ferdbg-num{text-align:right;white-space:nowrap;color:#93c5fd}'
           . '#ferdbg .ferdbg-slow{color:#fca5a5;font-weight:700}'
           . '#ferdbg .ferdbg-gsub{display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap}'
           . '#ferdbg .ferdbg-gsub button{background:#1e293b;border:none;color:#cbd5e1;padding:3px 12px;border-radius:20px;cursor:pointer;font:inherit}'
           . '#ferdbg .ferdbg-gsub button.on{background:#f42182;color:#fff}'
           . '#ferdbg pre{margin:0;font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#cbd5e1;white-space:pre-wrap;word-break:break-word}'
           . '</style>';

        // ── Markup ──
        echo '<div id="ferdbg">'
           . '<div class="ferdbg-resize" title="Glisser pour redimensionner"></div>'
           . '<div class="ferdbg-bar">'
           . '<span>🐛 <b>DEBUG</b></span>'
           . '<span class="ferdbg-badge">⏱ ' . number_format($reqTime, 0) . ' ms</span>'
           . '<span class="ferdbg-badge">🧠 ' . number_format($memPeak, 1) . ' Mo' . $limTxt . '</span>'
           . '<span class="ferdbg-badge">🗄 ' . $nbQ . ' req · ' . number_format($sumQ, 1) . ' ms</span>'
           . $badgeErr
           . '<span class="ferdbg-sp"></span>'
           . '</div>'
           . '<div class="ferdbg-panel">'
           . '<div class="ferdbg-tabs">'
           . '<button data-tab="sum" class="on">Résumé</button>'
           . '<button data-tab="sql">SQL (' . $nbQ . ')</button>'
           . '<button data-tab="glob">Globals</button>'
           . '<button data-tab="err">Erreurs' . ($nbE ? ' (' . $nbE . ')' : '') . '</button>'
           . '</div>'
           . '<div class="ferdbg-body">'
           // Résumé
           . '<div data-pane="sum">'
           . '<div class="ferdbg-h">Performance serveur</div><div class="ferdbg-grid">'
           . $cell('Temps d\'exécution', number_format($reqTime, 0) . ' ms')
           . $cell('Mémoire utilisée', number_format($memNow, 2) . ' Mo' . $limTxt)
           . $cell('Pic mémoire', number_format($memPeak, 2) . ' Mo' . $limTxt)
           . $cell('Requêtes SQL', $nbQ . ' · ' . number_format($sumQ, 1) . ' ms')
           . '</div>'
           . '<div class="ferdbg-h">Performance client</div><div class="ferdbg-grid">'
           . $cell('First paint', '<span id="ferdbg-cp-fp">—</span>')
           . $cell('DOM interactif', '<span id="ferdbg-cp-int">—</span>')
           . $cell('DOM complet', '<span id="ferdbg-cp-dom">—</span>')
           . $cell('Ressources', '<span id="ferdbg-cp-res">—</span>')
           . $cell('Poids ressources', '<span id="ferdbg-cp-ressize">—</span>')
           . $cell('JS heap', '<span id="ferdbg-cp-heap">—</span>')
           . '</div></div>'
           // SQL
           . '<div data-pane="sql" hidden><table><thead><tr><th style="width:36px">#</th><th>Requête SQL</th><th style="width:90px;text-align:right">Temps</th><th style="width:60px;text-align:right">Lignes</th></tr></thead><tbody>' . $qHtml . '</tbody></table></div>'
           // Globals
           . '<div data-pane="glob" hidden>'
           . '<div class="ferdbg-gsub">'
           . '<button data-g="SESSION" class="on">SESSION</button>'
           . '<button data-g="SERVER">SERVER</button>'
           . '<button data-g="GET">GET</button>'
           . '<button data-g="POST">POST</button>'
           . '<button data-g="COOKIE">COOKIE</button>'
           . '</div><pre id="ferdbg-globals-pre"></pre></div>'
           // Erreurs
           . '<div data-pane="err" hidden>' . $errHtml . '</div>'
           . '</div></div></div>';

        // ── Script (nonce CSP) : toggle, onglets, redimensionnement, perf client ──
        echo '<script nonce="' . $esc($nonce) . '">(function(){'
           . 'var root=document.getElementById("ferdbg");if(!root)return;'
           . 'var bar=root.querySelector(".ferdbg-bar"),panel=root.querySelector(".ferdbg-panel");'
           . 'try{var h=localStorage.getItem("ferdbg_h");if(h)panel.style.height=h;}catch(e){}'
           // Perf client : lue au load (DOM interactif/complet non dispos avant) + à chaque ouverture.
           . 'function S(id,v){var el=root.querySelector("#ferdbg-cp-"+id);if(el)el.textContent=v;}'
           . 'function upd(){try{var nav=performance.getEntriesByType("navigation")[0];'
           . 'var pa=performance.getEntriesByType("paint")||[];'
           . 'var fp=pa.filter(function(p){return p.name==="first-paint";})[0]||pa.filter(function(p){return p.name==="first-contentful-paint";})[0];'
           . 'if(nav){if(nav.domInteractive)S("int",Math.round(nav.domInteractive)+" ms");if(nav.domComplete)S("dom",Math.round(nav.domComplete)+" ms");}'
           . 'if(fp)S("fp",Math.round(fp.startTime)+" ms");'
           . 'var res=performance.getEntriesByType("resource")||[];var tot=res.reduce(function(s,r){return s+(r.transferSize||0);},0);'
           . 'S("res",res.length);S("ressize",(tot/1048576).toFixed(2)+" Mo");'
           . 'if(performance.memory)S("heap",(performance.memory.usedJSHeapSize/1048576).toFixed(1)+" Mo");}catch(e){}}'
           . 'upd();if(document.readyState!=="complete")window.addEventListener("load",upd);'
           // Ouverture / fermeture du panneau (clic sur la barre) + refresh perf à l'ouverture.
           . 'bar.addEventListener("click",function(){root.classList.toggle("open");if(root.classList.contains("open"))upd();});'
           // Onglets.
           . 'root.querySelectorAll(".ferdbg-tabs button").forEach(function(b){b.addEventListener("click",function(){'
           . 'root.querySelectorAll(".ferdbg-tabs button").forEach(function(x){x.classList.remove("on");});b.classList.add("on");'
           . 'var t=b.getAttribute("data-tab");root.querySelectorAll("[data-pane]").forEach(function(p){p.hidden=(p.getAttribute("data-pane")!==t);});});});'
           // Sous-onglets Globals.
           . 'var G=' . $globalsJson . ';var pre=root.querySelector("#ferdbg-globals-pre");'
           . 'function showG(k){try{pre.textContent=JSON.stringify(G[k]||{},null,2);}catch(e){pre.textContent="";}}'
           . 'root.querySelectorAll(".ferdbg-gsub button").forEach(function(b){b.addEventListener("click",function(){'
           . 'root.querySelectorAll(".ferdbg-gsub button").forEach(function(x){x.classList.remove("on");});b.classList.add("on");showG(b.getAttribute("data-g"));});});'
           . 'showG("SESSION");'
           // Redimensionnement par le trait rose (ouvre le panneau si fermé).
           . 'var rz=root.querySelector(".ferdbg-resize"),drag=false,sy=0,sh=0;'
           . 'if(rz){rz.addEventListener("mousedown",function(e){e.preventDefault();if(!root.classList.contains("open"))root.classList.add("open");drag=true;sy=e.clientY;sh=panel.offsetHeight;});'
           . 'document.addEventListener("mousemove",function(e){if(!drag)return;var nh=Math.max(120,Math.min(window.innerHeight-50,sh+(sy-e.clientY)));panel.style.height=nh+"px";});'
           . 'document.addEventListener("mouseup",function(){if(drag){drag=false;try{localStorage.setItem("ferdbg_h",panel.style.height);}catch(e){}}});}'
           . '})();</script>';
    }
}

/**
 * Gestionnaire d'erreurs : respecte l'opérateur @ (suppression) et les niveaux
 * désactivés ; collecte pour la barre si debug actif ; renvoie false pour laisser
 * PHP journaliser normalement (log_errors = 1).
 */
function fer_error_handler(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
{
    if (!(error_reporting() & $errno)) return false; // @ ou niveau masqué → ignoré
    DebugBar::logError(DebugBar::levelLabel($errno), $errstr, $errfile, $errline);
    return false; // PHP journalise l'erreur (php-error.log)
}

/** Capture les erreurs fatales (non catchables par set_error_handler) + rend la barre. */
function fer_shutdown_handler(): void
{
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        DebugBar::logError('FATAL', $e['message'], $e['file'] ?? '', (int) ($e['line'] ?? 0));
    }
    DebugBar::maybeRender();
}
