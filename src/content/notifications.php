<?php
/**
 * notifications.php — Messages poussés vers l'application mobile.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * CE N'EST PAS UN SYSTÈME DE PUSH, ET C'EST VOULU.
 *
 * Il n'y a ici ni Firebase, ni APNs, ni compte Google/Apple à créer, ni jeton
 * d'appareil à collecter chez un tiers. L'application INTERROGE le serveur
 * (`GET /api/v1/notifications`) quand elle s'ouvre et lors de son réveil, puis
 * affiche localement ce qu'elle n'a pas encore vu.
 *
 * Pourquoi ce choix :
 *   • Aucune donnée ne part chez un tiers. Un vrai push suppose de déclarer
 *     chaque appareil auprès de Google ou d'Apple — c'est-à-dire d'exporter la
 *     liste des porteurs de l'application.
 *   • Rien à renouveler. Les clés de service expirent, les consoles changent
 *     d'interface ; une association qui organise UNE course par an ne doit pas
 *     découvrir la veille que son push ne marche plus.
 *   • Le besoin réel est un rappel la veille et le matin, pas une notification
 *     à la seconde.
 *
 * ⚠️ LA LIMITE DOIT ÊTRE DITE : une notification écrite maintenant n'arrive pas
 * instantanément. Elle arrive au prochain réveil de l'application. Pour une
 * annonce urgente le jour J, le mail et l'affichage sur place restent le moyen
 * sûr — l'écran d'administration le rappelle.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * AUCUN DESTINATAIRE NOMMÉ
 *
 * Une notification vise une ÉDITION (`annee`), donc ses inscrits. Il n'y a pas
 * de liste de participants ciblés : ce serait une donnée personnelle de plus à
 * protéger, à purger et à justifier, pour un besoin que « tous les inscrits de
 * l'année » couvre déjà entièrement.
 */

require_once __DIR__ . '/../core/config.php';

const NOTIF_TYPES = ['info', 'course', 'urgent'];

/**
 * Notifications visibles par un coureur pour une édition donnée.
 *
 * Filtrage : active, publiée (ou sans date de publication), non expirée.
 * `annee IS NULL` = valable pour toutes les éditions — typiquement une
 * information sur l'association elle-même.
 *
 * @param int|null $depuisId ne renvoyer que ce qui est plus récent, pour que
 *                 l'application n'affiche pas deux fois la même chose.
 */
function notif_pourCoureur(PDO $pdo, ?int $annee, ?int $depuisId = null): array
{
    // `afficher_dans_app = 1` : un message envoyé UNIQUEMENT en notification du
    // téléphone n'a pas à encombrer la boîte de réception.
    $sql = 'SELECT id, annee, type, titre, message, epingle, publie_at, expire_at
              FROM app_notifications
             WHERE active = 1
               AND afficher_dans_app = 1
               AND (publie_at IS NULL OR publie_at <= NOW())
               AND (expire_at IS NULL OR expire_at > NOW())
               AND (annee IS NULL' . ($annee !== null ? ' OR annee = ?' : '') . ')';
    $args = [];
    if ($annee !== null) $args[] = $annee;

    if ($depuisId !== null && $depuisId > 0) {
        // ⚠️ LES ÉPINGLÉES ÉCHAPPENT AU FILTRE. Elles portent les informations
        // pratiques qu'on relit la veille — heure de rendez-vous, parking. Les
        // masquer parce qu'elles ont déjà été vues une fois viderait la seule
        // page où l'on va justement les rechercher.
        $sql .= ' AND (id > ? OR epingle = 1)';
        $args[] = $depuisId;
    }
    $sql .= ' ORDER BY epingle DESC, COALESCE(publie_at, created_at) DESC, id DESC LIMIT 50';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // Table absente (migration non jouée) : pas de notification, pas
        // d'erreur. L'application doit continuer de fonctionner sans.
        error_log('[NOTIF] lecture : ' . $e->getMessage());
        return [];
    }
}

/** Toutes les notifications, pour l'administration. */
function notif_toutes(PDO $pdo, int $limite = 100): array
{
    try {
        $st = $pdo->prepare('SELECT * FROM app_notifications
                              ORDER BY COALESCE(publie_at, created_at) DESC, id DESC
                              LIMIT ' . max(1, min(500, $limite)));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function notif_une(PDO $pdo, int $id): ?array
{
    try {
        $st = $pdo->prepare('SELECT * FROM app_notifications WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Crée ou modifie une notification.
 *
 * @return array{ok: bool, id?: int, erreur?: string}
 */
function notif_enregistrer(PDO $pdo, array $v, ?int $id = null, ?int $auteur = null): array
{
    $titre   = trim((string) ($v['titre'] ?? ''));
    $message = trim((string) ($v['message'] ?? ''));
    if ($titre === '')   return ['ok' => false, 'erreur' => 'Le titre est obligatoire.'];
    if ($message === '') return ['ok' => false, 'erreur' => 'Le message est obligatoire.'];

    $type = (string) ($v['type'] ?? 'info');
    if (!in_array($type, NOTIF_TYPES, true)) $type = 'info';

    $annee   = ($v['annee'] ?? '') === '' ? null : (int) $v['annee'];
    $publie  = notif_dateOuNull($v['publie_at'] ?? null);
    $expire  = notif_dateOuNull($v['expire_at'] ?? null);

    // ⚠️ Une notification qui expire avant d'être publiée ne s'affiche JAMAIS,
    // et rien à l'écran ne le laisserait deviner : elle apparaîtrait dans la
    // liste, l'air parfaitement valide. On refuse plutôt que de laisser croire.
    if ($publie !== null && $expire !== null && $expire <= $publie) {
        return ['ok' => false,
                'erreur' => "La date de fin doit être postérieure à la date de publication — "
                          . "sinon la notification n'apparaîtrait jamais."];
    }

    $champs = [
        'annee'             => $annee,
        'type'              => $type,
        'afficher_dans_app' => !empty($v['afficher_dans_app']) ? 1 : 0,
        'titre'             => mb_substr($titre, 0, 120),
        'message'           => $message,
        'publie_at'         => $publie,
        'expire_at'         => $expire,
        'epingle'           => !empty($v['epingle']) ? 1 : 0,
    ];

    /* ⚠️ `active` N'EST PLUS DEMANDÉ À LA CRÉATION, et c'est délibéré : créer un
       message, c'est l'activer. La case n'apportait qu'une occasion de créer
       quelque chose d'invisible sans le vouloir. Elle reste modifiable après
       coup, par le bouton pause de la liste — le geste « je retire ça de
       l'affichage » est réel, celui de « je crée un message éteint » ne l'est
       pas. */
    if ($id === null) {
        $champs['active'] = 1;
    } elseif (array_key_exists('active', $v)) {
        $champs['active'] = !empty($v['active']) ? 1 : 0;
    }

    try {
        if ($id === null) {
            $champs['cree_par'] = $auteur;
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($champs)));
            $vals = implode(', ', array_fill(0, count($champs), '?'));
            $pdo->prepare("INSERT INTO app_notifications ($cols) VALUES ($vals)")
                ->execute(array_values($champs));
            return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
        }
        $maj = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($champs)));
        $args = array_values($champs);
        $args[] = $id;
        $pdo->prepare("UPDATE app_notifications SET $maj WHERE id = ?")->execute($args);
        return ['ok' => true, 'id' => $id];
    } catch (\Throwable $e) {
        error_log('[NOTIF] enregistrement : ' . $e->getMessage());
        return ['ok' => false,
                'erreur' => "Enregistrement impossible. La table est-elle créée ? Lancez update.php."];
    }
}

/**
 * Envoie une notification existante sur les téléphones.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * C'EST UNE ACTION, PAS UN RÉGLAGE.
 *
 * On écrit le message, on l'enregistre, puis on appuie sur « Envoyer ». Il n'y a
 * donc aucune ambiguïté sur le moment où les téléphones sonnent : c'est celui du
 * clic. La date et le nombre d'appareils touchés sont écrits dans la ligne —
 * sans cette trace, on ne saurait pas si l'envoi a déjà eu lieu, et on
 * renverrait « par précaution ».
 *
 * @return array{ok: bool, envoyes: int, echecs: int, erreur?: string}
 */
function notif_envoyerPush(PDO $pdo, int $id): array
{
    require_once __DIR__ . '/push.php';

    $n = notif_une($pdo, $id);
    if ($n === null) {
        return ['ok' => false, 'envoyes' => 0, 'echecs' => 0,
                'erreur' => 'Notification introuvable.'];
    }

    $r = push_envoyer(
        $pdo,
        (string) $n['titre'],
        (string) $n['message'],
        $n['annee'] === null ? null : (int) $n['annee'],
        // L'application s'en sert pour ouvrir le bon message et ne pas le
        // réafficher comme s'il était nouveau.
        ['notification_id' => (string) $n['id'], 'type' => (string) $n['type']]
    );

    // ⚠️ LA TRACE EST ÉCRITE MÊME SI L'ENVOI EST PARTIEL. Sur mille appareils,
    // il y en a toujours quelques-uns d'injoignables ; noter « jamais envoyée »
    // pousserait à renvoyer, et à faire sonner deux fois ceux qui l'ont reçue.
    if ($r['envoyes'] > 0) {
        try {
            $pdo->prepare('UPDATE app_notifications SET envoye_at = NOW(), envoye_a = ?
                            WHERE id = ?')->execute([$r['envoyes'], $id]);
        } catch (\Throwable $e) {
            error_log('[NOTIF] trace envoi : ' . $e->getMessage());
        }
    }

    return ['ok' => $r['ok'], 'envoyes' => $r['envoyes'], 'echecs' => $r['echecs'],
            'erreur' => $r['erreur'] ?? null];
}

function notif_supprimer(PDO $pdo, int $id): bool
{
    try {
        return $pdo->prepare('DELETE FROM app_notifications WHERE id = ?')
                   ->execute([$id]);
    } catch (\Throwable $e) {
        return false;
    }
}

function notif_basculer(PDO $pdo, int $id): bool
{
    try {
        return $pdo->prepare('UPDATE app_notifications SET active = 1 - active WHERE id = ?')
                   ->execute([$id]);
    } catch (\Throwable $e) {
        return false;
    }
}

/** « 2026-10-03T18:00 » → « 2026-10-03 18:00:00 », ou null. */
function notif_dateOuNull(mixed $v): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') return null;
    $t = strtotime($s);
    return $t === false ? null : date('Y-m-d H:i:s', $t);
}

/**
 * État d'une notification, en une phrase, pour la liste d'administration.
 *
 * ⚠️ « Active » NE VEUT PAS DIRE « visible ». Une notification active mais
 * publiée demain, ou expirée hier, n'apparaît chez personne. Afficher un simple
 * badge « active » laisserait croire qu'elle est partie.
 */
function notif_etat(array $n): array
{
    if ((int) $n['active'] !== 1) {
        return ['libelle' => 'En pause', 'couleur' => 'secondary'];
    }
    if ((int) ($n['afficher_dans_app'] ?? 1) !== 1) {
        // Elle n'est pas dans la boîte : son seul effet possible était le push.
        return !empty($n['envoye_at'])
            ? ['libelle' => 'Envoyée', 'couleur' => 'success']
            : ['libelle' => 'Rien à faire', 'couleur' => 'warning'];
    }
    $now = time();
    if (!empty($n['publie_at']) && strtotime((string) $n['publie_at']) > $now) {
        return ['libelle' => 'Programmée', 'couleur' => 'info'];
    }
    if (!empty($n['expire_at']) && strtotime((string) $n['expire_at']) <= $now) {
        return ['libelle' => 'Terminée', 'couleur' => 'secondary'];
    }
    return ['libelle' => 'Visible', 'couleur' => 'success'];
}

/**
 * Réglages de l'application mobile lus par l'API et l'écran Applications.
 */
function notif_reglages(PDO $pdo): array
{
    try {
        $r = $pdo->query('SELECT app_notifications_actives, app_reveil_avant_min,
                                 app_version_minimale, app_store_url_ios, app_store_url_android
                            FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $r = [];
    }
    return [
        'notifications_actives' => (int) ($r['app_notifications_actives'] ?? 1) === 1,
        'reveil_avant_min'      => (int) ($r['app_reveil_avant_min'] ?? 120),
        'version_minimale'      => (string) ($r['app_version_minimale'] ?? '1.0.0'),
        'store_ios'             => $r['app_store_url_ios'] ?? null,
        'store_android'         => $r['app_store_url_android'] ?? null,
    ];
}
