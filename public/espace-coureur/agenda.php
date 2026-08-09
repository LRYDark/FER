<?php
/**
 * agenda.php — L'inscription du coureur, en événement iCalendar (.ics).
 *
 * Servi en pièce jointe : le navigateur le remet au calendrier du système, qui
 * propose de l'ajouter. Aucune intégration Google ou Apple à maintenir, et ça
 * marche sur tous les appareils, y compris ceux qu'on n'a pas prévus.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * ⚠️ PROTÉGÉ COMME N'IMPORTE QUELLE PAGE DE L'ESPACE, ET POUR LA MÊME RAISON.
 *
 * Le fichier porte un nom, un numéro d'inscription et une adresse. Servi sans
 * session, il laisserait lire l'inscription de n'importe qui en devinant une
 * année et un numéro. `pauth_owns()` vérifie donc que l'inscription appartient
 * bien au compte connecté — c'est le même contrôle que la fiche de détail.
 *
 * ⚠️ SANS HEURE DE DÉPART PUBLIÉE, ON POSE UNE JOURNÉE ENTIÈRE (`VALUE=DATE`)
 * plutôt qu'un horaire inventé. Un rendez-vous à minuit dans l'agenda de
 * quelqu'un serait pire que pas de rendez-vous du tout.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
require_once '../../src/auth/participant_auth.php';
require_once '../../src/core/registrations_resolver.php';
require_once '../../src/content/course.php';

pauth_require($pdo, 'index.php');

$annee = (int) ($_GET['annee'] ?? 0);
$no    = trim((string) ($_GET['no'] ?? ''));

if ($annee <= 0 || $no === '' || !pauth_owns($pdo, pauth_id(), $annee, $no)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Inscription introuvable.\n");
}

$r = regres_find($pdo, $annee, $no);
if ($r === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Inscription introuvable.\n");
}

$course  = course_lire($pdo, $annee);
$libelle = trim((string) ($course['libelle'] ?? '')) ?: "Forbach en Rose $annee";
$lieu    = trim((string) ($course['lieu_rdv'] ?? $course['lieu_adresse'] ?? ''));

/* Heure de départ publiée ? Elle est stockée en UTC. */
$heure = $course['heure_depart'] ?? null;
$jour  = $course['date_course'] ?? null;

$d2 = fn(int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT);

if ($heure) {
    $debut = new DateTimeImmutable((string) $heure, new DateTimeZone('UTC'));
    // Deux heures : la durée d'une marche, pas celle de la course d'un seul.
    $fin   = $debut->add(new DateInterval('PT2H'));
    $champDebut = 'DTSTART:' . $debut->format('Ymd\THis\Z');
    $champFin   = 'DTEND:'   . $fin->format('Ymd\THis\Z');
} elseif ($jour) {
    $d   = new DateTimeImmutable((string) $jour);
    $fin = $d->add(new DateInterval('P1D'));
    $champDebut = 'DTSTART;VALUE=DATE:' . $d->format('Ymd');
    $champFin   = 'DTEND;VALUE=DATE:'   . $fin->format('Ymd');
} else {
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    exit("La date de la course n'est pas encore publiée.\n");
}

/** Échappement iCalendar : virgules, points-virgules et sauts de ligne. */
$esc = fn(string $s): string => str_replace(
    [chr(92), ';', ',', "\r\n", "\n"],
    [chr(92) . chr(92), '\;', '\,', '\n', '\n'],
    $s
);

$nomCoureur = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''));

$lignes = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Forbach en Rose//Espace coureur//FR',
    'CALSCALE:GREGORIAN',
    'BEGIN:VEVENT',
    'UID:fer-' . $annee . '-' . rawurlencode($no) . '@forbachenrose.fr',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    $champDebut,
    $champFin,
    'SUMMARY:' . $esc($libelle . ' — inscription n° ' . $no),
];
if ($lieu !== '')       $lignes[] = 'LOCATION:' . $esc($lieu);
if ($nomCoureur !== '') $lignes[] = 'DESCRIPTION:' . $esc("Inscription n° $no au nom de $nomCoureur.");
$lignes[] = 'END:VEVENT';
$lignes[] = 'END:VCALENDAR';

// ⚠️ CRLF EXIGÉ par la norme iCalendar. Avec des sauts de ligne simples,
// certains agendas refusent le fichier sans le moindre message.
$ics = implode("\r\n", $lignes) . "\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="forbach-en-rose-' . $annee . '.ics"');
header('Content-Length: ' . strlen($ics));
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
echo $ics;
