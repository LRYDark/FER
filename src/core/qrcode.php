<?php
/**
 * qrcode.php — Génération des QR codes d'inscription.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POURQUOI CE FICHIER EXISTE : UNE SEULE SOURCE
 * Le QR code apparaît à deux endroits — dans le mail de confirmation et dans
 * l'espace coureur. S'ils étaient produits par deux bouts de code différents,
 * rien ne garantirait qu'ils encodent la même chose. Le jour du retrait des
 * t-shirts, un bénévole scannerait un QR non reconnu, sans que personne ne
 * comprenne pourquoi.
 *
 * Les paramètres (données encodées, taille, marge) sont donc définis ICI, et
 * ici seulement. src/mail/googleMail.php délègue à ce fichier.
 *
 * ⚠️ Ne pas confondre avec inc/qr_code.php, qui est la page d'administration
 * de la table `qrcodes` (jetons par organisation) — sans rapport.
 *
 * Bibliothèque : endroid/qr-code v6, déjà installée.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Taille et marge — communes au mail et au web, à ne pas diverger. */
const FER_QR_SIZE   = 200;
const FER_QR_MARGIN = 8;

/**
 * Octets PNG bruts du QR code, ou null en cas d'échec.
 * Destinés au mail (pièce jointe inline référencée par cid:) comme à l'écran.
 *
 * @param string|int $donnees Ce qui est encodé : un numéro d'inscription, ou
 *                            « G:<group_id> » pour le QR d'un lot groupé.
 */
function fer_qrCodePngBytes(string|int $donnees): ?string
{
    try {
        $qr = new \Endroid\QrCode\QrCode(
            data:   (string) $donnees,
            size:   FER_QR_SIZE,
            margin: FER_QR_MARGIN
        );
        return (new \Endroid\QrCode\Writer\PngWriter())->write($qr)->getString();
    } catch (\Throwable $e) {
        error_log('[QR] génération : ' . $e->getMessage());
        return null;
    }
}

/**
 * Même QR, en data URI base64 — pour un affichage direct dans une page HTML.
 *
 * ⚠️ Réservé au web : Gmail et Outlook bloquent les data: URI dans les <img>,
 * d'où la version en octets bruts pour les mails.
 */
function fer_qrCodeDataUri(string|int $donnees): string
{
    $png = fer_qrCodePngBytes($donnees);
    return $png === null ? '' : 'data:image/png;base64,' . base64_encode($png);
}
