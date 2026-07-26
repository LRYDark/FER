<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Garde-fou « catch-all » des envois de mail — Forbach en Rose
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * POURQUOI : la base de test contient de VRAIES adresses d'inscrits. Un simple
 * clic sur « renvoyer le mail d'inscription » depuis un environnement de recette
 * suffirait à écrire à des personnes réelles. Ce fichier intercepte le
 * destinataire au POINT D'ENVOI (pas chez l'appelant) et le remplace par une
 * adresse unique de test.
 *
 * OÙ C'EST BRANCHÉ : `src/mail/googleMail.php`, dans les deux couches d'envoi —
 *   • sendMailSmtp()  → PHPMailer (SMTP)
 *   • sendMail()      → API Gmail (google/apiclient), branche non-SMTP
 * Tous les autres points d'envoi du site (inc/send-mail.php, src/mail/newsletter.php,
 * public/register.php, public/contact.php, public/chatbot-api.php, admin-api.php,
 * src/content/registrations_core.php, inc/mail-settings.php) passent sans exception
 * par l'une de ces deux fonctions : les couvrir toutes les deux couvre le site.
 *
 * CONFIGURATION (config/config.enc, exposée dans $_ENV par FerSecureConfig) :
 *   MAIL_CATCHALL        adresse unique qui reçoit tout (ex. dev@exemple.fr)
 *   MAIL_CATCHALL_ACTIF  1 = actif (défaut), 0 = désactivé (production)
 *
 * ⚠️ DÉFAUT VOLONTAIREMENT « ACTIF » : si la clé MAIL_CATCHALL_ACTIF est absente
 * de la configuration, le garde-fou s'active. Une configuration oubliée doit
 * casser l'envoi, jamais écrire à de vrais inscrits. La production doit poser
 * explicitement MAIL_CATCHALL_ACTIF=0.
 *
 * ⚠️ Si le garde-fou est actif SANS adresse de repli valide, l'envoi est BLOQUÉ
 * (return false côté appelant) au lieu d'être laissé passer.
 *
 * Journal : storage/logs/logs_mail_catchall.log
 */

if (!function_exists('mailCatchallEnabled')) {

/** Journalise une décision du garde-fou. */
function mailCatchallLog(string $message): void
{
    $logDir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    @file_put_contents(
        $logDir . '/logs_mail_catchall.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND
    );
}

/** Lecture d'une clé de configuration ($_ENV alimenté par FerSecureConfig). */
function mailCatchallEnv(string $key): ?string
{
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    $v = getenv($key);
    return ($v === false || $v === '') ? null : (string) $v;
}

/**
 * Le garde-fou est-il actif ?
 * Absent de la configuration → ACTIF (choix fail-safe, cf. en-tête).
 */
function mailCatchallEnabled(): bool
{
    $raw = mailCatchallEnv('MAIL_CATCHALL_ACTIF');
    if ($raw === null) return true;
    return !in_array(strtolower(trim($raw)), ['0', 'off', 'false', 'non', 'no'], true);
}

/** Adresse de redirection, ou '' si absente / invalide. */
function mailCatchallAddress(): string
{
    $addr = trim((string) mailCatchallEnv('MAIL_CATCHALL'));
    return filter_var($addr, FILTER_VALIDATE_EMAIL) ? $addr : '';
}

/**
 * Aplatit le destinataire (string ou array) en liste d'adresses propres.
 * @return string[]
 */
function mailCatchallFlatten($to): array
{
    $list = is_array($to) ? $to : [$to];
    $out  = [];
    foreach ($list as $addr) {
        $addr = trim(str_replace(["\r", "\n", "\0"], '', (string) $addr));
        if ($addr !== '') $out[] = $addr;
    }
    return $out;
}

/**
 * Libellé compact des destinataires réels pour le sujet (3 max + reste).
 * Seules les adresses valides sont recopiées : le sujet ne doit jamais servir de
 * véhicule à une saisie hostile (une valeur du type "a@x.fr\r\nBcc: …" a déjà
 * perdu ses CR/LF dans mailCatchallFlatten, mais on n'affiche pas le reliquat).
 */
function mailCatchallLabel(array $reels): string
{
    if (empty($reels)) return '(aucun destinataire)';
    $shown = array_map(
        static fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : '(adresse invalide)',
        array_slice($reels, 0, 3)
    );
    $label = implode(', ', $shown);
    $rest  = count($reels) - count($shown);
    if ($rest > 0) $label .= ' +' . $rest;
    return $label;
}

/**
 * Applique le garde-fou à un envoi.
 *
 * @return array{to: mixed, subject: string, blocked: bool, error: ?string, applied: bool}
 *         - blocked=true  → l'appelant NE DOIT PAS envoyer et doit retourner false
 *         - applied=true  → le destinataire a été remplacé
 */
function mailCatchallApply($to, string $subject): array
{
    $res = ['to' => $to, 'subject' => $subject, 'blocked' => false, 'error' => null, 'applied' => false];

    if (!mailCatchallEnabled()) {
        return $res;
    }

    $reels    = mailCatchallFlatten($to);
    $catchall = mailCatchallAddress();

    if ($catchall === '') {
        $res['blocked'] = true;
        $res['error']   = 'Garde-fou catch-all actif mais MAIL_CATCHALL absent ou invalide : envoi bloqué.';
        mailCatchallLog('BLOQUE (pas d\'adresse catch-all valide) | reels=' . implode(', ', $reels) . ' | sujet=' . $subject);
        return $res;
    }

    $res['to']      = $catchall;
    $res['applied'] = true;

    // Idempotence : sendMail() peut déléguer à sendMailSmtp(), qui applique aussi
    // le garde-fou. On ne préfixe pas deux fois le même sujet.
    if (strncmp($subject, '[TEST ', 6) !== 0) {
        $res['subject'] = '[TEST → ' . mailCatchallLabel($reels) . '] ' . $subject;
    }

    mailCatchallLog('REDIRIGE | reels=' . implode(', ', $reels) . ' | vers=' . $catchall . ' | sujet=' . $res['subject']);
    return $res;
}

/**
 * État du garde-fou, pour affichage dans l'administration.
 * @return array{actif: bool, adresse: string, configure: bool, bloquant: bool, defaut: bool}
 */
function mailCatchallStatus(): array
{
    $actif   = mailCatchallEnabled();
    $adresse = mailCatchallAddress();
    return [
        'actif'     => $actif,
        'adresse'   => $adresse,
        'configure' => $adresse !== '',
        'bloquant'  => $actif && $adresse === '',
        'defaut'    => mailCatchallEnv('MAIL_CATCHALL_ACTIF') === null,
    ];
}

} // function_exists guard
