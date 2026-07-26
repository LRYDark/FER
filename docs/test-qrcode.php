<?php
/** Le QR de l'espace coureur est-il rigoureusement celui du mail ? */
require_once 'W:/FER/src/core/qrcode.php';

$ko = 0;
function t(string $titre, bool $c) { global $ko; echo ($c ? "OK   " : "KO   ") . $titre . "\n"; if (!$c) $ko++; }

$png = fer_qrCodePngBytes('S142');
t('PNG généré', $png !== null && strlen($png) > 100);
t('signature PNG valide', $png !== null && str_starts_with($png, "\x89PNG\r\n\x1a\n"));

// Déterminisme : deux appels doivent donner exactement les mêmes octets, sinon
// le QR du mail et celui de l'écran pourraient différer.
t('génération déterministe', fer_qrCodePngBytes('S142') === $png);

// Le data URI n'est que le même PNG encodé.
t('data URI = mêmes octets', fer_qrCodeDataUri('S142') === 'data:image/png;base64,' . base64_encode($png));

// Deux numéros différents → deux images différentes (sinon tout le monde
// aurait le même QR, ce qui passerait inaperçu à l'œil nu).
t('numéros différents → QR différents', fer_qrCodePngBytes('S143') !== $png);

// QR groupé : préfixe « G: » comme dans le mail récapitulatif.
t('QR de groupe distinct', fer_qrCodePngBytes('G:abc') !== fer_qrCodePngBytes('abc'));

// googleMail.php délègue-t-il bien, plutôt que de regénérer de son côté ?
$gm = file_get_contents('W:/FER/src/mail/googleMail.php');
t('generateQrCodePngBytes délègue à fer_qrCodePngBytes',
    str_contains($gm, 'return fer_qrCodePngBytes($inscriptionNo);'));
t('generateQrCodeDataUri délègue à fer_qrCodeDataUri',
    str_contains($gm, 'return fer_qrCodeDataUri($inscriptionNo);'));
t('plus aucun new QrCode() dans googleMail.php', !str_contains($gm, 'new \Endroid\QrCode\QrCode'));

// Les paramètres visuels sont des CONSTANTES, définies une seule fois, et aucun
// littéral ne subsiste ailleurs : impossible de faire diverger mail et web.
$core = file_get_contents('W:/FER/src/core/qrcode.php');
t('constantes de taille et de marge définies une seule fois',
    preg_match_all('/const FER_QR_(SIZE|MARGIN)/', $core) === 2);
t('aucun paramètre de QR codé en dur dans googleMail.php',
    !preg_match('/size:\s*\d+|margin:\s*\d+/', $gm));

printf("\n%s\n", $ko === 0 ? 'AUCUNE ANOMALIE' : "$ko ANOMALIE(S)");
exit($ko > 0 ? 1 : 0);
