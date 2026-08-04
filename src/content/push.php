<?php
/**
 * push.php — Faire sonner les téléphones (Firebase Cloud Messaging).
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * POURQUOI FIREBASE — ET CE QUI EST VRAIMENT OBLIGATOIRE
 *
 * Aucune application ne peut se réveiller seule : ni un serveur qu'on appelle,
 * ni une connexion maintenue ouverte ne survivent à quelques minutes
 * d'arrière-plan. Il faut passer par le service de notification du système. Mais
 * ce service n'est PAS le même des deux côtés :
 *
 *   • ANDROID — Firebase Cloud Messaging est incontournable en pratique. Google
 *     a supprimé l'ancien GCM, et les solutions tierces (OneSignal, Leanplum…)
 *     repassent toutes par FCM. Seul UnifiedPush y échappe, au prix d'une
 *     application distributrice à installer — impensable pour une course
 *     ouverte au public.
 *
 *   • iPHONE — c'est APNs, le service d'APPLE, qui est obligatoire. Firebase ne
 *     fait que le RELAYER. On pourrait s'adresser directement à APNs, en
 *     HTTP/2, avec un jeton JWT signé par la clé .p8 (la bibliothèque
 *     firebase/php-jwt, déjà présente, sait signer en ES256).
 *
 * ⚠️ NE PAS ÉCRIRE QUE FIREBASE EST « LE SEUL MOYEN ». C'est faux pour iOS, et
 * cette phrase a déjà induit en erreur.
 *
 * Le choix fait ici est celui d'UN SEUL chemin d'envoi plutôt que deux. Le coût
 * d'un second chemin — APNs direct — n'est pas le code, c'est la dépendance à un
 * curl compilé avec HTTP/2 sur l'hébergement mutualisé : s'il manque, les
 * iPhone cessent de recevoir sans que rien ne le signale.
 *
 * ⚠️ CE QUE ÇA IMPLIQUE, ET QU'IL FAUT ASSUMER : avec ce choix, chaque
 * installation — iPhone compris — est déclarée auprès de Google. C'est le prix
 * du chemin unique, pas une fatalité technique.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * AUCUNE NOUVELLE DÉPENDANCE
 *
 * L'API FCM v1 exige un jeton OAuth2 signé par un compte de service. La
 * bibliothèque `google/auth` est DÉJÀ installée (elle sert à Gmail) : on la
 * réutilise telle quelle. L'ancienne « clé serveur » de FCM, plus simple, a été
 * supprimée par Google — elle n'est plus une option.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * ⚠️ LE COMPTE DE SERVICE EST UNE CLÉ PRIVÉE
 *
 * Stocké chiffré par encrypt(). Quiconque le lit peut envoyer des notifications
 * au nom de l'association. Il n'est jamais réaffiché en clair dans
 * l'administration, jamais journalisé, jamais renvoyé par l'API.
 */

require_once __DIR__ . '/../core/config.php';

use Google\Auth\Credentials\ServiceAccountCredentials;

/** Portée OAuth exigée par FCM v1. */
const PUSH_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

/**
 * Configuration Firebase, déchiffrée.
 *
 * @return array{projet: ?string, compte: ?array, pret: bool}
 */
function push_config(PDO $pdo): array
{
    try {
        $r = $pdo->query('SELECT fcm_project_id, fcm_service_account
                            FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return ['projet' => null, 'compte' => null, 'pret' => false];
    }

    $projet = trim((string) ($r['fcm_project_id'] ?? ''));
    $brut   = (string) ($r['fcm_service_account'] ?? '');
    $compte = null;

    if ($brut !== '') {
        $clair = decrypt($brut);
        $json  = json_decode((string) $clair, true);
        if (is_array($json) && isset($json['client_email'], $json['private_key'])) {
            $compte = $json;
        }
    }

    return [
        'projet' => $projet !== '' ? $projet : null,
        'compte' => $compte,
        'pret'   => $projet !== '' && $compte !== null,
    ];
}

/**
 * Enregistre le compte de service.
 *
 * ⚠️ ON VALIDE LE CONTENU AVANT DE CHIFFRER. Un fichier collé de travers
 * (mauvais JSON, ou le fichier `google-services.json` du client au lieu du
 * compte de service) donnerait une configuration qui paraît complète et qui
 * échoue au premier envoi — c'est-à-dire le jour de la course.
 *
 * @return array{ok: bool, erreur?: string, compte?: string, projet?: string}
 */
function push_enregistrerCompte(PDO $pdo, string $jsonBrut): array
{
    $jsonBrut = trim($jsonBrut);
    if ($jsonBrut === '') {
        try {
            $pdo->prepare('UPDATE setting SET fcm_service_account = NULL WHERE id = 1')->execute();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'erreur' => 'Colonne absente : lancez update.php.'];
        }
    }

    $j = json_decode($jsonBrut, true);
    if (!is_array($j)) {
        return ['ok' => false, 'erreur' => "Ce n'est pas du JSON valide."];
    }
    if (($j['type'] ?? '') !== 'service_account') {
        return ['ok' => false,
                'erreur' => "Ce fichier n'est pas un compte de service. Dans la console "
                          . 'Firebase : Paramètres du projet → Comptes de service → '
                          . 'Générer une nouvelle clé privée.'];
    }
    foreach (['client_email', 'private_key', 'project_id'] as $cle) {
        if (empty($j[$cle])) {
            return ['ok' => false, 'erreur' => "Le champ « $cle » manque dans le fichier."];
        }
    }

    try {
        // Le projet est repris du fichier : le saisir à la main à côté ne
        // servirait qu'à créer une occasion de se tromper.
        $pdo->prepare('UPDATE setting SET fcm_service_account = ?, fcm_project_id = ?
                        WHERE id = 1')
            ->execute([encrypt($jsonBrut), (string) $j['project_id']]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'erreur' => 'Enregistrement impossible : lancez update.php.'];
    }

    return ['ok' => true, 'compte' => (string) $j['client_email'],
            'projet' => (string) $j['project_id']];
}

/**
 * Jeton d'accès OAuth2, mis en cache le temps de la requête.
 *
 * Il vaut une heure côté Google ; en redemander un à chaque appareil ajouterait
 * un aller-retour par notification envoyée.
 */
function push_jetonAcces(array $compte): ?string
{
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $creds = new ServiceAccountCredentials(PUSH_SCOPE, $compte);
        $jeton = $creds->fetchAuthToken();
        $cache = $jeton['access_token'] ?? null;
        return $cache;
    } catch (\Throwable $e) {
        error_log('[PUSH] jeton OAuth : ' . $e->getMessage());
        return null;
    }
}

/**
 * Appareils à notifier pour une édition.
 *
 * @param int|null $annee null = tous les comptes actifs, toutes éditions.
 * @return array<int, array{id: int, token: string}>
 */
function push_destinataires(PDO $pdo, ?int $annee): array
{
    /* ⚠️ TROIS CONDITIONS, ET AUCUNE N'EST FACULTATIVE :
       - `revoque_at IS NULL` : un téléphone rendu ou perdu ne doit plus sonner ;
       - `p.is_active = 1`    : un compte désactivé non plus ;
       - `push_token IS NOT NULL` : l'application n'a pas encore enregistré son
         jeton, ou les notifications ont été refusées sur l'appareil. */
    $sql = 'SELECT DISTINCT d.id, d.push_token
              FROM participant_devices d
              JOIN participants p ON p.id = d.participant_id
             WHERE d.revoque_at IS NULL
               AND p.is_active = 1
               AND d.push_token IS NOT NULL
               AND d.push_token <> ""';
    $args = [];

    if ($annee !== null) {
        // Ciblage par édition : seuls les inscrits de l'année concernée.
        $sql .= ' AND EXISTS (SELECT 1 FROM participant_registrations r
                               WHERE r.participant_id = p.id AND r.annee = ?)';
        $args[] = $annee;
    }

    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return array_map(
            fn($r) => ['id' => (int) $r['id'], 'token' => (string) $r['push_token']],
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    } catch (\Throwable $e) {
        error_log('[PUSH] destinataires : ' . $e->getMessage());
        return [];
    }
}

/**
 * Envoie une notification à tous les appareils d'une édition.
 *
 * ⚠️ FCM v1 N'A PAS D'ENVOI GROUPÉ. L'ancien point d'entrée multicast a été
 * retiré : il faut un appel par appareil. C'est pourquoi on plafonne, et
 * pourquoi les échecs sont comptés plutôt que de faire échouer le tout — sur
 * mille téléphones, il y en aura toujours quelques-uns d'injoignables.
 *
 * @param array<string,string> $donnees charge utile lue par l'application
 *        (identifiant du message, action à ouvrir…).
 * @return array{ok: bool, envoyes: int, echecs: int, nettoyes: int, erreur?: string}
 */
function push_envoyer(
    PDO $pdo,
    string $titre,
    string $message,
    ?int $annee = null,
    array $donnees = []
): array {
    $cfg = push_config($pdo);
    if (!$cfg['pret']) {
        return ['ok' => false, 'envoyes' => 0, 'echecs' => 0, 'nettoyes' => 0,
                'erreur' => 'Firebase n\'est pas configuré (écran Applications).'];
    }

    $jeton = push_jetonAcces($cfg['compte']);
    if ($jeton === null) {
        return ['ok' => false, 'envoyes' => 0, 'echecs' => 0, 'nettoyes' => 0,
                'erreur' => 'Authentification Firebase refusée. Le compte de service '
                          . 'est-il toujours valide ?'];
    }

    $cibles = push_destinataires($pdo, $annee);
    if (!$cibles) {
        return ['ok' => true, 'envoyes' => 0, 'echecs' => 0, 'nettoyes' => 0,
                'erreur' => 'Aucun appareil n\'a encore activé les notifications.'];
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($cfg['projet'])
         . '/messages:send';

    $envoyes = 0;
    $echecs  = 0;
    $morts   = [];

    foreach ($cibles as $cible) {
        $charge = [
            'message' => [
                'token'        => $cible['token'],
                'notification' => ['title' => $titre, 'body' => $message],
                // Les données servent à l'application : ouvrir le bon écran,
                // marquer le message comme déjà annoncé.
                'data'         => array_map('strval', $donnees),
                'android'      => [
                    'priority'     => 'high',
                    'notification' => ['channel_id' => 'fer_course'],
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ],
        ];

        [$http, $corps] = push_appel($url, $jeton, $charge);

        if ($http >= 200 && $http < 300) {
            $envoyes++;
            continue;
        }
        $echecs++;

        /* ⚠️ NETTOYAGE DES JETONS MORTS — INDISPENSABLE À LA LONGUE.
           Une application désinstallée laisse un jeton que Google refuse avec
           UNREGISTERED ou NOT_FOUND. Sans ce nettoyage, la liste enfle d'année
           en année, chaque envoi devient plus lent, et le compteur « envoyée à
           N appareils » ment de plus en plus. */
        $err = (string) ($corps['error']['status'] ?? '');
        if ($http === 404 || $err === 'NOT_FOUND' || $err === 'UNREGISTERED'
            || $err === 'INVALID_ARGUMENT') {
            $morts[] = $cible['id'];
        } else {
            error_log('[PUSH] appareil ' . $cible['id'] . ' : HTTP ' . $http . ' '
                    . json_encode($corps['error'] ?? null));
        }
    }

    if ($morts) {
        try {
            $pdo->exec('UPDATE participant_devices SET push_token = NULL, push_maj_at = NOW()
                         WHERE id IN (' . implode(',', array_map('intval', $morts)) . ')');
        } catch (\Throwable $e) {
            error_log('[PUSH] nettoyage : ' . $e->getMessage());
        }
    }

    push_log("envoi « $titre » : $envoyes envoyée(s), $echecs échec(s), "
           . count($morts) . ' jeton(s) nettoyé(s)');

    return ['ok' => $envoyes > 0, 'envoyes' => $envoyes, 'echecs' => $echecs,
            'nettoyes' => count($morts),
            'erreur' => $envoyes === 0 ? 'Aucune notification n\'a pu être remise.' : null];
}

/** Un appel HTTP à FCM. Renvoie [code HTTP, corps décodé]. */
function push_appel(string $url, string $jeton, array $charge): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $jeton,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($charge),
        // Court volontairement : mille appareils à dix secondes d'attente
        // chacun bloqueraient l'écran d'administration presque trois heures.
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $reponse = curl_exec($ch);
    $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$http, json_decode((string) $reponse, true) ?: []];
}

/**
 * Enregistre le jeton d'un appareil.
 *
 * ⚠️ LE MÊME JETON EST RETIRÉ DES AUTRES APPAREILS. Google réattribue un jeton
 * quand une application est réinstallée ou restaurée depuis une sauvegarde :
 * sans cette remise à zéro, deux lignes porteraient le même jeton et la personne
 * recevrait chaque notification en double.
 */
function push_enregistrerJeton(PDO $pdo, int $deviceId, ?string $token): bool
{
    try {
        if ($token === null || trim($token) === '') {
            $pdo->prepare('UPDATE participant_devices SET push_token = NULL, push_maj_at = NOW()
                            WHERE id = ?')->execute([$deviceId]);
            return true;
        }
        $token = mb_substr(trim($token), 0, 255);

        $pdo->prepare('UPDATE participant_devices SET push_token = NULL
                        WHERE push_token = ? AND id <> ?')->execute([$token, $deviceId]);
        $pdo->prepare('UPDATE participant_devices SET push_token = ?, push_maj_at = NOW()
                        WHERE id = ?')->execute([$token, $deviceId]);
        return true;
    } catch (\Throwable $e) {
        error_log('[PUSH] enregistrement jeton : ' . $e->getMessage());
        return false;
    }
}

/** Combien d'appareils sont joignables — affiché avant d'envoyer. */
function push_nbDestinataires(PDO $pdo, ?int $annee): int
{
    return count(push_destinataires($pdo, $annee));
}

/** Journal dédié, visible depuis Journaux système. */
function push_log(string $message): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/logs_push.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}
