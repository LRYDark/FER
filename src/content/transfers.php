<?php
/**
 * transfers.php — Transfert d'inscription entre coureurs (lot 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LE BESOIN
 * Plusieurs personnes sont souvent inscrites sous une seule adresse : un parent
 * inscrit toute la famille. Si l'un d'eux veut son propre espace et son propre
 * chronométrage, son inscription doit basculer sur SA adresse.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DOUBLE OPT-IN, ET POURQUOI
 * Le titulaire demande, la cible accepte. Sans l'accord de la cible, n'importe
 * qui pourrait pousser une inscription — donc une obligation de paiement et une
 * présence attendue — vers l'adresse d'un tiers.
 *
 * ⚠️ L'ACCEPTATION NE SE FAIT JAMAIS SUR UN SIMPLE GET. Le lien du mail ouvre
 * une page de confirmation ; c'est un POST qui accepte. Les antivirus de
 * messagerie et les proxys d'entreprise visitent les liens des mails entrants :
 * une acceptation sur GET serait déclenchée par un robot, à l'insu de tous.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PORTÉE
 * Seule l'ÉDITION ACTIVE est transférable : une course déjà courue ne se
 * transfère pas. Conséquence utile — un transfert n'écrit jamais dans une table
 * d'archive, qui reste en lecture seule.
 *
 * Toutes les fonctions sont préfixées `xfer_`.
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/registrations_resolver.php';
require_once __DIR__ . '/../auth/participant_auth.php';
require_once __DIR__ . '/content-log.php';

/** Statuts possibles d'un transfert. */
const XFER_STATUTS = ['en_attente', 'accepte', 'annule', 'expire'];

/**
 * Marque « expire » les transferts en attente dont le délai est écoulé.
 * Déclenché à chaque consultation : pas de tâche planifiée à installer.
 * La comparaison se fait EN SQL — comparer en PHP une date produite par MySQL
 * supposerait que les deux fuseaux coïncident.
 */
function xfer_purge(PDO $pdo): void
{
    try {
        $pdo->exec("UPDATE registration_transfers
                       SET statut = 'expire'
                     WHERE statut = 'en_attente' AND expires_at < NOW()");
    } catch (\Throwable $e) { /* table absente : rien à purger */ }
}

/**
 * Date limite de transfert d'une édition, et son dépassement.
 *
 * Priorité à `editions.transferts_deadline` ; à défaut, elle est dérivée de la
 * date de course moins le délai configuré (`transferts_deadline_defaut_h`).
 *
 * ⚠️ LE DÉPASSEMENT EST ÉVALUÉ EN SQL, par MySQL lui-même. Comparer en PHP
 * (strtotime($deadline) < time()) obligerait le fuseau de PHP et celui de la
 * connexion MySQL à coïncider exactement. C'est vrai aujourd'hui — config.php
 * aligne les deux sur UTC+2 — mais toute divergence future ouvrirait ou
 * fermerait les transferts avec deux heures d'écart, sans le moindre message.
 *
 * @return array{deadline: ?string, passee: bool}
 */
function xfer_deadlineInfo(PDO $pdo, int $annee): array
{
    try {
        $st = $pdo->prepare(
            'SELECT d.deadline, (d.deadline IS NOT NULL AND d.deadline < NOW()) AS passee
               FROM (SELECT COALESCE(
                        e.transferts_deadline,
                        CASE WHEN e.date_course IS NOT NULL
                             THEN DATE_SUB(e.date_course, INTERVAL COALESCE(s.transferts_deadline_defaut_h, 24) HOUR)
                        END) AS deadline
                       FROM editions e
                       LEFT JOIN setting s ON s.id = 1
                      WHERE e.annee = ? LIMIT 1) AS d'
        );
        $st->execute([$annee]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['deadline' => $row['deadline'], 'passee' => (int) $row['passee'] === 1];
        }
    } catch (\Throwable $e) { /* colonnes absentes : aucune limite */ }
    return ['deadline' => null, 'passee' => false];
}

/** Date limite affichable, ou null si aucune n'est fixée. */
function xfer_deadline(PDO $pdo, int $annee): ?string
{
    return xfer_deadlineInfo($pdo, $annee)['deadline'];
}

/** La date limite est-elle dépassée ? */
function xfer_deadlinePassee(PDO $pdo, int $annee): bool
{
    return xfer_deadlineInfo($pdo, $annee)['passee'];
}

/**
 * Transfert en attente sur une inscription, ou null.
 * @param bool $verrou pose un SELECT … FOR UPDATE (à l'intérieur d'une transaction)
 */
function xfer_enAttente(PDO $pdo, int $annee, string $no, bool $verrou = false): ?array
{
    $sql = "SELECT * FROM registration_transfers
             WHERE annee = ? AND inscription_no = ? AND statut = 'en_attente'
             ORDER BY id DESC LIMIT 1" . ($verrou ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$annee, $no]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Crée une demande de transfert.
 *
 * ⚠️ « UN SEUL TRANSFERT EN ATTENTE PAR INSCRIPTION » n'est pas exprimable par
 * un index MySQL (pas d'index unique partiel). La règle est donc tenue par le
 * code : le contrôle se fait dans la MÊME transaction que l'insertion, avec un
 * SELECT … FOR UPDATE. Un simple SELECT puis INSERT laisserait passer deux
 * demandes concurrentes — deux onglets, deux mails, une pagaille.
 *
 * @return array{ok: bool, erreur?: string, token?: string, id?: int}
 */
function xfer_creer(PDO $pdo, int $participantId, int $annee, string $no, string $emailCible): array
{
    $emailCible = fer_normalizeEmail($emailCible);
    if ($emailCible === '' || !filter_var($emailCible, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erreur' => "Adresse email invalide."];
    }
    if (!pauth_owns($pdo, $participantId, $annee, $no)) {
        return ['ok' => false, 'erreur' => "Cette inscription n'est pas rattachée à votre compte."];
    }
    if ($annee !== regres_activeYear($pdo)) {
        return ['ok' => false, 'erreur' => "Seule l'édition en cours peut être transférée : la course de $annee a déjà eu lieu."];
    }
    if (xfer_deadlinePassee($pdo, $annee)) {
        return ['ok' => false, 'erreur' => "La date limite de transfert est dépassée pour cette édition."];
    }

    $inscription = regres_find($pdo, $annee, $no);
    if ($inscription === null) {
        return ['ok' => false, 'erreur' => "Inscription introuvable."];
    }
    if (fer_normalizeEmail((string) $inscription['email']) === $emailCible) {
        return ['ok' => false, 'erreur' => "Cette inscription utilise déjà cette adresse."];
    }

    $jours = (int) ($pdo->query('SELECT transferts_expiration_jours FROM setting WHERE id = 1')->fetchColumn() ?: 7);
    $token = bin2hex(random_bytes(32));

    try {
        $pdo->beginTransaction();

        if (xfer_enAttente($pdo, $annee, $no, true) !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'erreur' => "Un transfert est déjà en attente sur cette inscription. Annulez-le d'abord."];
        }

        $pdo->prepare(
            'INSERT INTO registration_transfers
                (annee, inscription_no, email_source, email_cible, token_hash, demande_par, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
        )->execute([
            $annee, $no,
            // Les adresses sont chiffrées comme partout ailleurs : cette table ne
            // doit pas devenir la seule où elles seraient lisibles en clair.
            encrypt(fer_normalizeEmail((string) $inscription['email'])),
            encrypt($emailCible),
            hash('sha256', $token),
            $participantId,
            max(1, $jours),
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[XFER] création : ' . $e->getMessage());
        return ['ok' => false, 'erreur' => "La demande n'a pas pu être enregistrée. Réessayez."];
    }

    logContentAction($pdo, 'transfert', 'create', $id, "Transfert $annee/$no demandé", 'registration_transfer');
    return ['ok' => true, 'token' => $token, 'id' => $id];
}

/** Retrouve un transfert par son jeton (haché en base). */
function xfer_parToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM registration_transfers WHERE token_hash = ? LIMIT 1');
    $st->execute([hash('sha256', $token)]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $t['email_source'] = decrypt($t['email_source']);
    $t['email_cible']  = decrypt($t['email_cible']);
    return $t;
}

/**
 * Accepte un transfert. C'est l'opération sensible du lot.
 *
 * ⚠️ TROIS ÉCRITURES, ET LES TROIS COMPTENT — dans une seule transaction :
 *   1. l'adresse de l'inscription passe à la cible ;
 *   2. le compte cible est créé s'il n'existe pas ;
 *   3. le RATTACHEMENT bascule sur ce compte.
 * Oublier la troisième produit un bug silencieux : la cible ne verrait pas
 * l'inscription et le titulaire d'origine continuerait de la voir.
 *
 * @return array{ok: bool, erreur?: string, participant_id?: int}
 */
function xfer_accepter(PDO $pdo, string $token): array
{
    xfer_purge($pdo);
    $t = xfer_parToken($pdo, $token);
    if ($t === null) return ['ok' => false, 'erreur' => "Ce lien n'est pas valable."];
    return xfer_appliquer($pdo, $t);
}

/**
 * Acceptation FORCÉE par un administrateur, sans jeton.
 *
 * Le jeton en clair n'existe nulle part — seul son SHA-256 est en base — un
 * administrateur ne peut donc pas « rejouer » le lien du destinataire. Ce
 * chemin sert le jour de la course : quelqu'un se présente, son transfert n'a
 * pas été confirmé, et il faut trancher sans accès à sa boîte mail.
 */
function xfer_accepterParId(PDO $pdo, int $id): array
{
    xfer_purge($pdo);
    $st = $pdo->prepare('SELECT * FROM registration_transfers WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return ['ok' => false, 'erreur' => "Transfert introuvable."];
    $t['email_source'] = decrypt($t['email_source']);
    $t['email_cible']  = decrypt($t['email_cible']);
    return xfer_appliquer($pdo, $t, true);
}

/**
 * Applique un transfert déjà chargé. Cœur partagé entre l'acceptation par le
 * destinataire et le forçage par l'administration : une seule implémentation
 * des trois écritures, donc aucun risque qu'un chemin en oublie une.
 *
 * @param bool $forcage ignore la date limite — c'est justement l'intérêt du
 *                      forçage, trancher un cas particulier hors délai.
 */
function xfer_appliquer(PDO $pdo, array $t, bool $forcage = false): array
{
    if ($t['statut'] === 'accepte')        return ['ok' => false, 'erreur' => "Ce transfert a déjà été accepté."];
    if ($t['statut'] === 'annule')         return ['ok' => false, 'erreur' => "Ce transfert a été annulé par son auteur."];
    if ($t['statut'] === 'expire')         return ['ok' => false, 'erreur' => "Ce transfert a expiré. Demandez-en un nouveau."];
    if ($t['statut'] !== 'en_attente')     return ['ok' => false, 'erreur' => "Ce transfert n'est plus en attente."];

    $annee = (int) $t['annee'];
    $no    = (string) $t['inscription_no'];

    if ($annee !== regres_activeYear($pdo)) {
        return ['ok' => false, 'erreur' => "L'édition concernée est close : le transfert n'est plus possible."];
    }
    if (!$forcage && xfer_deadlinePassee($pdo, $annee)) {
        return ['ok' => false, 'erreur' => "La date limite de transfert est dépassée."];
    }

    $cible = (string) $t['email_cible'];

    try {
        $pdo->beginTransaction();

        // 1. L'adresse de l'inscription. Édition active uniquement, donc la
        //    table `registrations` — jamais une archive.
        $pdo->prepare('UPDATE registrations SET email = ? WHERE inscription_no = ?')
            ->execute([encrypt($cible), $no]);

        // 2. Le compte cible. S'il n'existe pas, on le crée : la personne pourra
        //    se connecter avec son code dès qu'elle le voudra.
        $participant = pauth_findByEmail($pdo, $cible);
        if ($participant === null) {
            $pdo->prepare('INSERT INTO participants (email_chiffre, email_hmac) VALUES (?, ?)')
                ->execute([encrypt($cible), fer_emailHmac($cible)]);
            $participantId = (int) $pdo->lastInsertId();
        } else {
            $participantId = (int) $participant['id'];
        }

        // 3. Le rattachement. INSERT … ON DUPLICATE : l'inscription peut déjà
        //    être revendiquée par le titulaire, c'est justement ce qu'on change.
        $pdo->prepare(
            "INSERT INTO participant_registrations (participant_id, annee, inscription_no, origine)
             VALUES (?, ?, ?, 'transfert')
             ON DUPLICATE KEY UPDATE participant_id = VALUES(participant_id),
                                     origine = 'transfert',
                                     revendique_at = NOW()"
        )->execute([$participantId, $annee, $no]);

        $pdo->prepare("UPDATE registration_transfers SET statut = 'accepte', accepte_at = NOW() WHERE id = ?")
            ->execute([(int) $t['id']]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[XFER] acceptation : ' . $e->getMessage());
        return ['ok' => false, 'erreur' => "Le transfert n'a pas pu être finalisé. Réessayez."];
    }

    logContentAction($pdo, 'transfert', 'accept', (int) $t['id'],
        "Transfert $annee/$no accepté" . ($forcage ? ' (forcé par l\'administration)' : ''),
        'registration_transfer');
    return ['ok' => true, 'participant_id' => $participantId];
}

/**
 * Annule un transfert en attente.
 * @param int|null $participantId titulaire qui annule ; null = annulation par un administrateur
 */
function xfer_annuler(PDO $pdo, int $id, ?int $participantId = null): array
{
    $sql    = "UPDATE registration_transfers SET statut = 'annule', annule_at = NOW()
                WHERE id = ? AND statut = 'en_attente'";
    $params = [$id];
    if ($participantId !== null) { $sql .= ' AND demande_par = ?'; $params[] = $participantId; }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    if ($st->rowCount() === 0) {
        return ['ok' => false, 'erreur' => "Transfert introuvable, déjà traité, ou demandé par quelqu'un d'autre."];
    }

    logContentAction($pdo, 'transfert', 'cancel', $id,
        'Transfert annulé' . ($participantId === null ? ' (administration)' : ''), 'registration_transfer');
    return ['ok' => true];
}

/* ═══════════════════════════════ Mails ══════════════════════════════════ */

/** Charge sendMail() à la demande. */
function xfer_mailPret(): bool
{
    if (!function_exists('sendMail')) {
        $f = __DIR__ . '/../mail/googleMail.php';
        if (is_file($f)) { try { require_once $f; } catch (\Throwable $e) {} }
    }
    return function_exists('sendMail');
}

/**
 * Mail à la CIBLE : le lien de confirmation.
 *
 * Un lien est ici acceptable, contrairement au mail de connexion : son jeton ne
 * donne pas l'accès à un compte, il ne permet que d'accepter un transfert DÉJÀ
 * déterminé — vers cette adresse et aucune autre. Et il n'accepte rien tout
 * seul : il ouvre une page de confirmation.
 */
function xfer_mailCible(PDO $pdo, array $t, string $token, string $lienBase): bool
{
    if (!xfer_mailPret()) return false;
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $insc = regres_find($pdo, (int) $t['annee'], (string) $t['inscription_no']);
    $qui  = $insc ? trim(($insc['prenom'] ?? '') . ' ' . ($insc['nom'] ?? '')) : '';
    $lien = $lienBase . '?token=' . urlencode($token);

    $corps = '<p>Une inscription à Forbach en Rose vous est proposée'
        . ($qui !== '' ? ' au nom de <strong>' . $h($qui) . '</strong>' : '') . '.</p>'
        . '<p>Elle est actuellement rattachée à ' . $h($t['email_source'])
        . ', qui souhaite vous la transférer — vous aurez alors votre propre espace coureur '
        . 'et, le jour venu, votre propre chronométrage.</p>'
        . '<p style="text-align:center;margin:24px 0">'
        . '<a href="' . $h($lien) . '" style="display:inline-block;background:#F42182;color:#fff;'
        . 'text-decoration:none;font-weight:700;font-size:15px;padding:13px 28px;border-radius:10px">'
        . 'Voir la demande</a></p>'
        . '<p>Rien n\'est fait tant que vous n\'avez pas confirmé sur la page. '
        . 'La demande expire dans quelques jours.</p>'
        . '<p style="color:#64748b;font-size:13px">Si cette demande ne vous concerne pas, '
        . 'ignorez ce message : sans votre confirmation, rien ne change.</p>';

    try {
        return (bool) sendMail($t['email_cible'], 'Une inscription vous est proposée – Forbach en Rose',
            "Transfert d'inscription", $corps, null, null, 'info', null, 'test');
    } catch (\Throwable $e) {
        error_log('[XFER] mail cible : ' . $e->getMessage());
        return false;
    }
}

/** Mail au TITULAIRE : information et possibilité d'annuler. */
function xfer_mailSource(PDO $pdo, array $t): bool
{
    if (!xfer_mailPret()) return false;
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $corps = '<p>Vous avez demandé le transfert de l\'inscription n° '
        . '<strong>' . $h($t['inscription_no']) . '</strong> vers '
        . '<strong>' . $h($t['email_cible']) . '</strong>.</p>'
        . '<p>Cette personne doit confirmer depuis son mail. Tant qu\'elle ne l\'a pas fait, '
        . 'vous pouvez annuler la demande depuis votre espace coureur.</p>'
        . '<p><strong>Une fois le transfert accepté, cette inscription n\'apparaîtra plus '
        . 'dans votre espace.</strong></p>'
        . '<p style="color:#64748b;font-size:13px">Vous n\'êtes pas à l\'origine de cette demande ? '
        . 'Annulez-la depuis votre espace coureur et prévenez l\'organisation.</p>';

    try {
        return (bool) sendMail($t['email_source'], 'Transfert d\'inscription en attente – Forbach en Rose',
            'Transfert en attente', $corps, null, null, 'info', null, 'test');
    } catch (\Throwable $e) {
        error_log('[XFER] mail source : ' . $e->getMessage());
        return false;
    }
}

/** Mails de confirmation, aux deux parties, une fois le transfert accepté. */
function xfer_mailsConfirmation(PDO $pdo, array $t): void
{
    if (!xfer_mailPret()) return;
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $no = $h($t['inscription_no']);

    try {
        sendMail($t['email_cible'], 'Inscription transférée – Forbach en Rose', 'Transfert accepté',
            '<p>L\'inscription n° <strong>' . $no . '</strong> est désormais la vôtre.</p>'
            . '<p>Connectez-vous à votre espace coureur avec cette adresse pour retrouver '
            . 'votre QR code et, le jour venu, vos résultats.</p>',
            null, null, 'info', null, 'test');

        sendMail($t['email_source'], 'Transfert effectué – Forbach en Rose', 'Transfert accepté',
            '<p>L\'inscription n° <strong>' . $no . '</strong> a été transférée à '
            . '<strong>' . $h($t['email_cible']) . '</strong>.</p>'
            . '<p>Elle n\'apparaît plus dans votre espace coureur. '
            . 'La personne concernée en a désormais la charge.</p>',
            null, null, 'info', null, 'test');
    } catch (\Throwable $e) {
        error_log('[XFER] mails de confirmation : ' . $e->getMessage());
    }
}

/** URL absolue de la page d'acceptation. */
function xfer_lienBase(): string
{
    $base = function_exists('getAppBaseUrl') ? getAppBaseUrl() : '';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $base . $dir . '/transfert.php';
}
