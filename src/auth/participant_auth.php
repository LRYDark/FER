<?php
/**
 * participant_auth.php — Authentification des comptes coureurs (lot 2).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PRINCIPE : il n'y a PAS de mot de passe. Le seul moyen d'accéder à un compte
 * est un code à 6 chiffres envoyé par mail à l'adresse revendiquée. Le mail est
 * donc la preuve de possession, et la seule.
 *
 * ⛔ Aucun mécanisme de revendication par numéro d'inscription, nom ou date de
 * naissance : ce serait une porte d'entrée sans preuve de possession d'adresse.
 * Une inscription sans email ne donne aucun accès, et c'est assumé.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SÉPARATION D'AVEC L'ADMINISTRATION
 * La session coureur porte un NOM DE COOKIE différent (FERCOUREUR, posé par
 * config.php quand FER_SESSION_COUREUR est défini). Ce sont deux sessions
 * réellement distinctes : un coureur connecté n'a aucun $_SESSION['uid'], donc
 * aucune faille de l'espace coureur ne peut se transformer en accès
 * d'administration. L'isolation est structurelle, pas conventionnelle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DEUX HACHAGES DIFFÉRENTS, VOLONTAIREMENT — ne pas les harmoniser :
 *   • code à 6 chiffres → password_hash() : 10^6 combinaisons seulement, il faut
 *     un hachage LENT. On retrouve la ligne par email_hmac (indexé), puis
 *     password_verify() sur cette seule ligne.
 *   • token d'appareil  → SHA-256 : 256 bits d'entropie, rien à forcer. Il faut
 *     un hachage RAPIDE et déterministe, la recherche se faisant PAR LE HASH.
 *   Lent pour un secret faible, rapide pour un secret fort.
 *
 * Toutes les fonctions sont préfixées `pauth_`.
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/registrations_resolver.php';

/** Clé de session portant l'état du coureur connecté. */
const PAUTH_SESSION_KEY = 'participant';

/** Nom du cookie « se souvenir de moi ». */
const PAUTH_COOKIE = 'fer_coureur';

/* ═══════════════════════════ Réglages ═══════════════════════════════════ */

/**
 * Réglages de l'espace coureur, lus dans `setting` (colonnes créées au lot 1).
 * Les valeurs de repli correspondent aux DEFAULT du schéma : une base pas encore
 * migrée reste fonctionnelle plutôt que de provoquer une erreur fatale.
 */
function pauth_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $defauts = [
        'participant_code_ttl_min'             => 15,
        'participant_code_max_tentatives'      => 5,
        'participant_code_max_par_email_15min' => 3,
        'participant_code_max_par_ip_heure'    => 10,
        'participant_web_remember_jours'       => 30,
        'participant_rgpd_version'             => '1.0',
        'auth_codes_conservation_jours'        => 30,
    ];
    try {
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($defauts)));
        $row  = $pdo->query("SELECT $cols FROM setting WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($defauts as $k => $v) {
            if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) $defauts[$k] = $row[$k];
        }
    } catch (\Throwable $e) {
        // Colonnes absentes (update.php pas encore lancé) : on garde les défauts.
    }
    return $cache = $defauts;
}

/* ═══════════════════════ Comptes participants ═══════════════════════════ */

/**
 * Retrouve un compte par son adresse. La recherche se fait par empreinte HMAC :
 * `email_chiffre` porte un vecteur d'initialisation aléatoire et n'est donc pas
 * comparable en SQL.
 */
function pauth_findByEmail(PDO $pdo, string $email): ?array
{
    $hmac = fer_emailHmac($email);
    if ($hmac === null) return null;
    $st = $pdo->prepare('SELECT * FROM participants WHERE email_hmac = ? LIMIT 1');
    $st->execute([$hmac]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['email'] = decrypt($row['email_chiffre']);   // usage : envoi de mail, affichage
    return $row;
}

/**
 * Crée le compte à la PREMIÈRE connexion réussie, si et seulement si l'adresse
 * correspond à au moins une inscription. Nom et prénom repris de l'inscription
 * la plus récente. Retourne null si aucune inscription ne correspond.
 *
 * ⚠️ N'est jamais appelée avant la validation du code : sans quoi la simple
 * demande d'un code créerait un compte, et l'existence d'un compte deviendrait
 * observable — exactement ce que l'anti-énumération cherche à empêcher.
 */
function pauth_createFromRegistrations(PDO $pdo, string $email): ?array
{
    $inscriptions = regres_findByEmail($pdo, fer_normalizeEmail($email));
    if (!$inscriptions) return null;

    // regres_findByEmail trie les éditions de la plus récente à la plus ancienne.
    $recente = $inscriptions[0];

    $st = $pdo->prepare(
        'INSERT INTO participants (email_chiffre, email_hmac, nom, prenom) VALUES (?, ?, ?, ?)'
    );
    try {
        $st->execute([
            encrypt(fer_normalizeEmail($email)),
            fer_emailHmac($email),
            $recente['nom']    !== null ? mb_substr((string) $recente['nom'], 0, 255)    : null,
            $recente['prenom'] !== null ? mb_substr((string) $recente['prenom'], 0, 255) : null,
        ]);
    } catch (\PDOException $e) {
        // Course entre deux onglets : l'unicité de email_hmac a joué, le compte
        // existe déjà — on le relit plutôt que d'échouer.
        return pauth_findByEmail($pdo, $email);
    }
    return pauth_findByEmail($pdo, $email);
}

/**
 * Rattache au compte les inscriptions correspondant à son adresse.
 *
 * Règle du lot 1 : on cherche d'abord dans `participant_registrations` par
 * participant_id ; ce qui manque est complété depuis regres_findByEmail() avec
 * origine = 'email'.
 *
 * L'index UNIQUE (annee, inscription_no) garantit qu'une inscription n'est
 * jamais revendiquée par deux comptes : l'INSERT IGNORE laisse la revendication
 * la plus ancienne en place — notamment celle issue d'un transfert accepté, qui
 * ne doit pas être défaite par une simple correspondance d'adresse.
 *
 * @return int nombre de rattachements ajoutés
 */
function pauth_syncRegistrations(PDO $pdo, int $participantId, string $email): int
{
    $inscriptions = regres_findByEmail($pdo, fer_normalizeEmail($email));
    if (!$inscriptions) return 0;

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO participant_registrations (participant_id, annee, inscription_no, origine)
         VALUES (?, ?, ?, 'email')"
    );
    $n = 0;
    foreach ($inscriptions as $i) {
        $no = trim((string) $i['inscription_no']);
        if ($no === '') continue;
        $ins->execute([$participantId, (int) $i['annee'], $no]);
        $n += $ins->rowCount();
    }
    return $n;
}

/**
 * Inscriptions rattachées à un compte, toutes éditions confondues, la plus
 * récente en premier. Chaque ligne est résolue dans sa table d'origine
 * (`registrations` ou `registrations_AAAA`) et déjà déchiffrée.
 *
 * Les rattachements dont l'inscription est introuvable sont ignorés plutôt que
 * d'être affichés vides : un numéro modifié à la main côté administration
 * produirait sinon une carte fantôme. update.php?tool=check-integrity les liste.
 *
 * @return array<int, array> lignes enrichies de `_origine` et `_revendique_at`
 */
function pauth_registrations(PDO $pdo, int $participantId): array
{
    $st = $pdo->prepare(
        'SELECT annee, inscription_no, origine, revendique_at
           FROM participant_registrations
          WHERE participant_id = ?
          ORDER BY annee DESC, inscription_no ASC'
    );
    $st->execute([$participantId]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $lien) {
        $row = regres_find($pdo, (int) $lien['annee'], (string) $lien['inscription_no']);
        if ($row === null) continue;
        $row['_origine']       = $lien['origine'];
        $row['_revendique_at'] = $lien['revendique_at'];
        $out[] = $row;
    }
    return $out;
}

/**
 * Ce compte possède-t-il bien cette inscription ?
 *
 * Contrôle d'accès de toutes les pages de détail : on ne se fie JAMAIS au seul
 * couple (annee, inscription_no) fourni dans l'URL, sinon n'importe quel coureur
 * connecté lirait la fiche de n'importe quel autre en changeant un chiffre.
 */
function pauth_owns(PDO $pdo, int $participantId, int $annee, string $inscriptionNo): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM participant_registrations
          WHERE participant_id = ? AND annee = ? AND inscription_no = ? LIMIT 1'
    );
    $st->execute([$participantId, $annee, $inscriptionNo]);
    return (bool) $st->fetchColumn();
}

/* ══════════════════════ Codes à 6 chiffres ══════════════════════════════ */

/**
 * Purge les codes périmés. Déclenchée à chaque demande de code : pas de tâche
 * planifiée à installer, et la table ne grossit pas indéfiniment.
 * Les codes récents sont conservés (traçabilité des tentatives), au-delà de
 * `auth_codes_conservation_jours` ils partent.
 */
function pauth_purgeCodes(PDO $pdo): void
{
    $jours = (int) pauth_settings($pdo)['auth_codes_conservation_jours'];
    try {
        $pdo->prepare('DELETE FROM participant_auth_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')
            ->execute([max(1, $jours)]);
    } catch (\Throwable $e) { /* non bloquant */ }
}

/**
 * Limitation de débit. Deux compteurs indépendants :
 *   - par adresse : empêche le harcèlement d'une boîte mail précise ;
 *   - par IP      : empêche le balayage d'adresses pour en tester l'existence.
 * Le compteur par IP est le seul qui protège contre l'énumération, le compteur
 * par adresse ne servirait à rien face à un attaquant qui change de cible.
 *
 * @return true si la demande est autorisée
 */
function pauth_rateLimitOk(PDO $pdo, string $email, string $ip): bool
{
    $s = pauth_settings($pdo);
    $hmac = fer_emailHmac($email);
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM participant_auth_codes
              WHERE email_hmac = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $st->execute([$hmac]);
        if ((int) $st->fetchColumn() >= (int) $s['participant_code_max_par_email_15min']) return false;

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM participant_auth_codes
              WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $st->execute([$ip]);
        if ((int) $st->fetchColumn() >= (int) $s['participant_code_max_par_ip_heure']) return false;
    } catch (\Throwable $e) {
        return true;   // table absente : on ne bloque pas l'utilisateur
    }
    return true;
}

/**
 * Génère un code à 6 chiffres et l'enregistre.
 *
 * ⚠️ TOUTE demande INVALIDE les codes précédents de cette adresse. Sans cela
 * plusieurs codes valides coexisteraient : surface d'attaque multipliée, et
 * comportement incompréhensible pour l'utilisateur (« j'ai demandé un nouveau
 * code mais l'ancien marche aussi »).
 *
 * @return array{code: string, token: string} le code en clair et le jeton du lien
 */
function pauth_issueCode(PDO $pdo, string $email, string $canal, string $ip): array
{
    $s     = pauth_settings($pdo);
    $hmac  = fer_emailHmac($email);
    $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // Jeton du lien cliquable : dérivé du MÊME enregistrement, donc soumis aux
    // mêmes expiration et consommation que le code saisi à la main.
    $token = bin2hex(random_bytes(32));

    $pdo->prepare('UPDATE participant_auth_codes SET consomme_at = NOW()
                    WHERE email_hmac = ? AND consomme_at IS NULL')->execute([$hmac]);

    $pdo->prepare(
        'INSERT INTO participant_auth_codes (email_hmac, code_hash, canal, expires_at, ip)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
    )->execute([
        $hmac,
        // Le code ET le jeton du lien sont hachés ensemble : une seule ligne, donc
        // une seule expiration, un seul compteur de tentatives, une seule consommation.
        json_encode(['code' => password_hash($code, PASSWORD_DEFAULT), 'token' => hash('sha256', $token)]),
        in_array($canal, ['web', 'app'], true) ? $canal : 'web',
        max(1, (int) $s['participant_code_ttl_min']),
        mb_substr($ip, 0, 45),
    ]);

    return ['code' => $code, 'token' => $token];
}

/**
 * Vérifie un code (ou un jeton de lien) et le consomme.
 *
 * ⚠️ Ne cible QU'UN SEUL enregistrement : le plus récent non consommé et non
 * expiré. Boucler sur plusieurs lignes en testant le secret contre chacune
 * contournerait le compteur de tentatives.
 *
 * @param  string|null $code  code à 6 chiffres saisi
 * @param  string|null $token jeton issu du lien cliquable
 * @return array{ok: bool, raison: string}
 *         raison ∈ ok | aucun | expire | trop_de_tentatives | invalide
 */
function pauth_verifyCode(PDO $pdo, string $email, ?string $code, ?string $token = null): array
{
    $s    = pauth_settings($pdo);
    $hmac = fer_emailHmac($email);
    if ($hmac === null) return ['ok' => false, 'raison' => 'aucun'];

    // ⚠️ L'expiration est évaluée EN SQL, par MySQL lui-même.
    // Comparer en PHP (strtotime($ligne['expires_at']) < time()) obligerait le
    // fuseau de PHP et celui de la connexion MySQL à coïncider exactement. C'est
    // vrai aujourd'hui — config.php aligne les deux sur UTC+2 — mais toute
    // divergence future ferait accepter des codes périmés pendant deux heures,
    // ou refuser des codes valides, sans le moindre message d'erreur.
    $st = $pdo->prepare(
        'SELECT *, (expires_at < NOW()) AS est_expire
           FROM participant_auth_codes
          WHERE email_hmac = ? AND consomme_at IS NULL
          ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$hmac]);
    $ligne = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ligne) return ['ok' => false, 'raison' => 'aucun'];

    if ((int) $ligne['est_expire'] === 1) {
        return ['ok' => false, 'raison' => 'expire'];
    }
    if ((int) $ligne['tentatives'] >= (int) $s['participant_code_max_tentatives']) {
        // Invalidé définitivement : il faut redemander un code.
        $pdo->prepare('UPDATE participant_auth_codes SET consomme_at = NOW() WHERE id = ?')
            ->execute([$ligne['id']]);
        return ['ok' => false, 'raison' => 'trop_de_tentatives'];
    }

    $secrets = json_decode((string) $ligne['code_hash'], true) ?: [];
    $bon = false;
    if ($token !== null && $token !== '' && !empty($secrets['token'])) {
        $bon = hash_equals((string) $secrets['token'], hash('sha256', $token));
    } elseif ($code !== null && $code !== '' && !empty($secrets['code'])) {
        $bon = password_verify($code, (string) $secrets['code']);
    }

    if (!$bon) {
        $pdo->prepare('UPDATE participant_auth_codes SET tentatives = tentatives + 1 WHERE id = ?')
            ->execute([$ligne['id']]);
        $restantes = (int) $s['participant_code_max_tentatives'] - ((int) $ligne['tentatives'] + 1);
        return ['ok' => false, 'raison' => 'invalide', 'restantes' => max(0, $restantes)];
    }

    // Usage unique : consommé dès la première validation réussie.
    $pdo->prepare('UPDATE participant_auth_codes SET consomme_at = NOW() WHERE id = ?')
        ->execute([$ligne['id']]);
    return ['ok' => true, 'raison' => 'ok'];
}

/* ═══════════════════════════ Session ════════════════════════════════════ */

/** Ouvre la session coureur. */
function pauth_login(PDO $pdo, array $participant): void
{
    // Régénération de l'identifiant : sans elle, un identifiant de session
    // obtenu avant la connexion resterait valable après (fixation de session).
    session_regenerate_id(true);

    $_SESSION[PAUTH_SESSION_KEY] = [
        'id'      => (int) $participant['id'],
        'email'   => $participant['email'] ?? '',
        'nom'     => $participant['nom'] ?? '',
        'prenom'  => $participant['prenom'] ?? '',
        'rgpd'    => !empty($participant['rgpd_consent_at']),
        'depuis'  => time(),
    ];
    try {
        $pdo->prepare('UPDATE participants SET derniere_connexion = NOW() WHERE id = ?')
            ->execute([(int) $participant['id']]);
    } catch (\Throwable $e) { /* non bloquant */ }
}

/** Le coureur est-il connecté ? */
function pauth_isLogged(): bool
{
    return !empty($_SESSION[PAUTH_SESSION_KEY]['id']);
}

/** Identifiant du coureur connecté, ou null. */
function pauth_id(): ?int
{
    return pauth_isLogged() ? (int) $_SESSION[PAUTH_SESSION_KEY]['id'] : null;
}

/** Ferme la session coureur et révoque l'appareil courant. */
function pauth_logout(PDO $pdo): void
{
    pauth_revokeCurrentDevice($pdo);
    unset($_SESSION[PAUTH_SESSION_KEY]);
    session_regenerate_id(true);
}

/**
 * Exige une session coureur ; redirige vers la connexion sinon.
 * Tente d'abord la reconnexion par cookie « se souvenir de moi ».
 */
function pauth_require(PDO $pdo, string $retour = ''): void
{
    if (!pauth_isLogged()) pauth_loginFromCookie($pdo);
    if (!pauth_isLogged()) {
        $q = $retour !== '' ? '?retour=' . urlencode($retour) : '';
        header('Location: login.php' . $q);
        exit;
    }
    // Consentement RGPD obligatoire avant tout accès aux données.
    if (empty($_SESSION[PAUTH_SESSION_KEY]['rgpd'])
        && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'consentement.php') {
        header('Location: consentement.php');
        exit;
    }
}

/* ═════════════════════ Appareils de confiance ═══════════════════════════ */

/**
 * Crée un appareil de confiance et pose le cookie.
 *   type 'web' → expire (« se souvenir de moi ») ; type 'app' → sans expiration.
 * Le token n'est jamais stocké en clair : seul son SHA-256 va en base.
 */
function pauth_rememberDevice(PDO $pdo, int $participantId, string $type = 'web'): string
{
    $s      = pauth_settings($pdo);
    $token  = bin2hex(random_bytes(32));
    $jours  = max(1, (int) $s['participant_web_remember_jours']);
    $expire = $type === 'app' ? null : date('Y-m-d H:i:s', time() + $jours * 86400);

    $pdo->prepare(
        'INSERT INTO participant_devices
           (participant_id, token_hash, type, libelle, plateforme, ip_creation, user_agent, derniere_utilisation, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
    )->execute([
        $participantId,
        hash('sha256', $token),
        $type,
        pauth_deviceLabel(),
        mb_substr(pauth_platform(), 0, 60),
        mb_substr(fer_client_ip(), 0, 45),
        mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        $expire,
    ]);

    if ($type === 'web') {
        setcookie(PAUTH_COOKIE, $token, [
            'expires'  => time() + $jours * 86400,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return $token;
}

/** Reconnexion silencieuse depuis le cookie « se souvenir de moi ». */
function pauth_loginFromCookie(PDO $pdo): bool
{
    $token = $_COOKIE[PAUTH_COOKIE] ?? '';
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return false;

    try {
        $st = $pdo->prepare(
            'SELECT d.*, p.id AS pid, p.email_chiffre, p.nom, p.prenom, p.is_active, p.rgpd_consent_at
               FROM participant_devices d
               JOIN participants p ON p.id = d.participant_id
              WHERE d.token_hash = ? AND d.revoque_at IS NULL
                AND (d.expires_at IS NULL OR d.expires_at > NOW())
              LIMIT 1'
        );
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return false;
    }
    if (!$row || (int) $row['is_active'] !== 1) {
        pauth_clearCookie();
        return false;
    }

    $pdo->prepare('UPDATE participant_devices SET derniere_utilisation = NOW() WHERE id = ?')
        ->execute([(int) $row['id']]);

    pauth_login($pdo, [
        'id'              => (int) $row['pid'],
        'email'           => decrypt($row['email_chiffre']),
        'nom'             => $row['nom'],
        'prenom'          => $row['prenom'],
        'rgpd_consent_at' => $row['rgpd_consent_at'],
    ]);
    $_SESSION[PAUTH_SESSION_KEY]['device_id'] = (int) $row['id'];
    return true;
}

/** Révoque l'appareil courant (déconnexion). On ne supprime pas la ligne. */
function pauth_revokeCurrentDevice(PDO $pdo): void
{
    $token = $_COOKIE[PAUTH_COOKIE] ?? '';
    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
        try {
            $pdo->prepare('UPDATE participant_devices SET revoque_at = NOW()
                            WHERE token_hash = ? AND revoque_at IS NULL')
                ->execute([hash('sha256', $token)]);
        } catch (\Throwable $e) { /* non bloquant */ }
    }
    pauth_clearCookie();
}

/** Retire le cookie « se souvenir de moi ». */
function pauth_clearCookie(): void
{
    setcookie(PAUTH_COOKIE, '', [
        'expires' => time() - 3600, 'path' => '/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/** Libellé lisible de l'appareil, déduit du User-Agent. */
function pauth_deviceLabel(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $nav = 'Navigateur';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
              'Safari' => 'Safari', 'Firefox' => 'Firefox'] as $motif => $nom) {
        if (str_contains($ua, $motif)) { $nav = $nom; break; }
    }
    return mb_substr($nav . ' — ' . pauth_platform(), 0, 120);
}

/** Plateforme déduite du User-Agent. */
function pauth_platform(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    foreach (['iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Android' => 'Android',
              'Windows' => 'Windows', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $motif => $nom) {
        if (str_contains($ua, $motif)) return $nom;
    }
    return 'Inconnu';
}

/* ═══════════════════════════ Envoi du mail ══════════════════════════════ */

/**
 * Envoie le code par mail : le code ET un lien cliquable, comme demandé — sur
 * mobile, recopier six chiffres depuis l'application mail est pénible.
 *
 * Le lien porte le jeton à usage unique issu du MÊME enregistrement : cliquer ou
 * saisir revient exactement au même côté sécurité.
 */
function pauth_sendCodeMail(PDO $pdo, string $email, string $code, string $token, string $lienBase): bool
{
    if (!function_exists('sendMail')) {
        require_once __DIR__ . '/../mail/googleMail.php';
    }
    if (!function_exists('sendMail')) {
        error_log('[PAUTH] sendMail indisponible');
        return false;
    }

    $ttl  = (int) pauth_settings($pdo)['participant_code_ttl_min'];
    $lien = $lienBase . '?email=' . urlencode($email) . '&token=' . urlencode($token);
    $h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $corps = '<p>Voici votre code de connexion à votre espace coureur :</p>'
        . '<p style="font-size:32px;font-weight:700;letter-spacing:8px;text-align:center;'
        . 'color:#F42182;margin:20px 0">' . $h($code) . '</p>'
        . '<p style="text-align:center;margin:0 0 20px">'
        . '<a href="' . $h($lien) . '" style="display:inline-block;background:#F42182;color:#fff;'
        . 'text-decoration:none;font-weight:700;font-size:15px;padding:13px 28px;border-radius:10px">'
        . 'Me connecter directement</a></p>'
        . '<p>Ce code est valable <strong>' . $ttl . ' minutes</strong> et ne sert qu\'une fois.</p>'
        . '<p style="color:#64748b;font-size:13px">Si vous n\'avez pas demandé cette connexion, '
        . 'ignorez ce message : personne ne peut accéder à votre espace sans ce code.</p>';

    try {
        return (bool) sendMail(
            $email,
            'Votre code de connexion – Forbach en Rose',
            'Code de connexion',
            $corps,
            null, null, 'info', null, 'code'
        );
    } catch (\Throwable $e) {
        error_log('[PAUTH] envoi du code : ' . $e->getMessage());
        return false;
    }
}
