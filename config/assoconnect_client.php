<?php
/**
 * assoconnect_client.php — Client PHP d'export AssoConnect (sans navigateur).
 *
 * Reproduit, en HTTP pur (cURL), la séquence que fait le navigateur :
 *   1. GET  page de connexion  → récupère cookies + jetons du formulaire.
 *   2. POST identifiants (userMail / userPassword + jetons _form/_origin/_formMulti/ssoToken).
 *   3. GET  liste des inscrits → extrait vendorId, dealId, userInfoDatas.
 *   4. POST /collect/registrants/export  (payload identique à exportXLS() du site)
 *      → renvoie le .xlsx (octets bruts), strictement identique à l'export manuel.
 *
 * Validé : l'export produit est identique octet pour octet (hors horodatage interne)
 * à l'export manuel « Exporter toutes les colonnes ».
 *
 * Aucune dépendance : cURL + DOMDocument (présents partout). Les identifiants ne
 * sont jamais journalisés. Lève une RuntimeException claire à chaque échec.
 */

final class AssoConnectClient
{
    private string $cookieFile;
    private int $timeout;

    public function __construct(int $timeout = 60)
    {
        $this->timeout    = $timeout;
        $this->cookieFile = (string) tempnam(sys_get_temp_dir(), 'acck');
    }

    public function __destruct()
    {
        if ($this->cookieFile !== '' && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    /**
     * Connexion + export. @return string Octets du .xlsx. @throws RuntimeException.
     */
    public function exportXlsx(string $loginUrl, string $registrantsUrl, string $email, string $password): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException("L'extension cURL de PHP est requise mais absente.");
        }

        // 1. Page de connexion → formulaire + jetons
        $g = $this->request($loginUrl);
        if ($g['errno'] || $g['code'] >= 400) {
            throw new RuntimeException("Page de connexion injoignable (HTTP {$g['code']}).");
        }
        [$action, $fields] = $this->parseLoginForm($g['body']);
        if ($fields === null) {
            throw new RuntimeException('Formulaire de connexion AssoConnect introuvable (le site a peut-être changé).');
        }

        // 2. Envoi des identifiants
        $fields['userMail']     = $email;
        $fields['userPassword'] = $password;
        $postUrl = $this->absolutize($action, $loginUrl);
        $p = $this->request($postUrl, $fields);
        if ($p['errno'] || $p['code'] >= 400) {
            throw new RuntimeException("Échec de l'envoi des identifiants (HTTP {$p['code']}).");
        }
        // Toujours sur /login ou champ password encore présent → identifiants refusés
        if (stripos($p['final'], '/login') !== false || stripos($p['body'], 'userPassword') !== false) {
            throw new RuntimeException('Identifiants AssoConnect refusés (email/mot de passe incorrects, ou vérification supplémentaire demandée).');
        }

        // 3. Liste des inscrits
        $l = $this->request($registrantsUrl);
        if ($l['errno'] || $l['code'] >= 400 || stripos($l['final'], '/login') !== false) {
            throw new RuntimeException("Liste des inscrits inaccessible (HTTP {$l['code']}). Vérifiez l'URL de la liste.");
        }
        $body = $l['body'];

        // 4. Variables du payload d'export (cf. exportXLS() côté AssoConnect)
        $vendorId = preg_match('/vendorId\s*[:=]\s*(\d+)/', $body, $mv) ? $mv[1] : null;
        $dealId   = preg_match('/dealId\s*[:=]\s*(\d+)/', $body, $md) ? $md[1] : null;
        $userInfo = $this->extractJsAssign($body, 'userInfoDatas');
        if ($vendorId === null || $dealId === null || $userInfo === null) {
            throw new RuntimeException('Paramètres d\'export introuvables dans la page (vendorId/dealId/userInfoDatas).');
        }
        $dec = json_decode($userInfo, true);
        if ($dec !== null) {
            $userInfo = json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // searchData = $.extend(true,{},{dealId, search, paymentFilter, methodFilter, saleStatus})
        // Sans filtre = exporter TOUT → champs vides. (Validé : variante "chaînes vides".)
        $di = (int) $dealId;
        $searchData = json_encode(
            ['dealId' => $di, 'search' => '', 'paymentFilter' => '', 'methodFilter' => '', 'saleStatus' => ''],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $exportUrl = $this->absolutize('/collect/registrants/export', $registrantsUrl);
        $origin    = parse_url($registrantsUrl, PHP_URL_SCHEME) . '://' . parse_url($registrantsUrl, PHP_URL_HOST);
        $headers   = [
            'Referer: ' . $registrantsUrl,
            'Origin: ' . $origin,
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, */*',
        ];
        $payload = [
            'vendorId'      => $vendorId,
            'display'       => 'XLS-XLSX',
            'userInfoDatas' => $userInfo,
            'dealId'        => $dealId,
            'primary'       => 'id',
            'searchData'    => $searchData,
        ];

        $d = $this->request($exportUrl, $payload, $headers);
        if ($d['errno'] || $d['code'] >= 400 || !$this->isXlsx($d['body'], $d['ctype'])) {
            $preview = trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($d['body'])), 0, 160));
            throw new RuntimeException("Export refusé (HTTP {$d['code']}). " . $preview);
        }
        return $d['body'];
    }

    // ───────────────────────── Internes ─────────────────────────

    /** Requête cURL avec session (cookies conservés entre appels). */
    private function request(string $url, ?array $post = null, array $extraHeaders = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 8,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => array_merge(['Accept-Language: fr-FR,fr;q=0.9'], $extraHeaders),
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw     = curl_exec($ch);
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $info = [
            'errno' => curl_errno($ch),
            'code'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'final' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'ctype' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'body'  => $raw !== false ? substr($raw, $hdrSize) : '',
        ];
        curl_close($ch);
        return $info;
    }

    /** Isole le formulaire de connexion (celui qui contient un champ password). */
    private function parseLoginForm(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $fields = null; $action = '';
        if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html)) {
            foreach ($dom->getElementsByTagName('form') as $form) {
                $f = []; $hasPass = false;
                foreach (['input', 'select', 'textarea'] as $tag) {
                    foreach ($form->getElementsByTagName($tag) as $el) {
                        $n = $el->getAttribute('name');
                        if (strtolower($el->getAttribute('type')) === 'password') $hasPass = true;
                        if ($n === '') continue;
                        $f[$n] = $el->getAttribute('value');
                    }
                }
                if ($hasPass) { $fields = $f; $action = $form->getAttribute('action'); break; }
            }
        }
        libxml_clear_errors();
        return [$action, $fields];
    }

    /** Résout une URL d'action relative en URL absolue. */
    private function absolutize(string $action, string $base): string
    {
        if ($action === '') return $base;
        if (preg_match('#^https?://#i', $action)) return $action;
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($action[0] === '/') return $root . $action;
        return $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $action;
    }

    /** Lit la valeur d'une affectation JS « var X = {…} » (littéral équilibré). */
    private function extractJsAssign(string $body, string $var): ?string
    {
        if (!preg_match('/(?:var\s+)?' . preg_quote($var, '/') . '\s*=\s*/', $body, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return $this->balanced($body, $m[0][1] + strlen($m[0][0]));
    }

    /** Extrait un littéral {…} ou […] équilibré (en ignorant les chaînes). */
    private function balanced(string $s, int $i): ?string
    {
        $n = strlen($s);
        while ($i < $n && ctype_space($s[$i])) $i++;
        if ($i >= $n || ($s[$i] !== '{' && $s[$i] !== '[')) return null;
        $open = $s[$i]; $close = $open === '{' ? '}' : ']'; $depth = 0; $start = $i; $inStr = false; $q = '';
        for (; $i < $n; $i++) {
            $ch = $s[$i];
            if ($inStr) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === $q) $inStr = false;
            } else {
                if ($ch === '"' || $ch === "'") { $inStr = true; $q = $ch; }
                elseif ($ch === $open) $depth++;
                elseif ($ch === $close) { $depth--; if ($depth === 0) return substr($s, $start, $i - $start + 1); }
            }
        }
        return null;
    }

    /** xlsx = archive ZIP → octets magiques "PK\x03\x04". */
    private function isXlsx(string $data, string $ctype): bool
    {
        if (strncmp($data, "PK\x03\x04", 4) === 0) return true;
        return stripos($ctype, 'spreadsheet') !== false || stripos($ctype, 'officedocument') !== false;
    }
}
