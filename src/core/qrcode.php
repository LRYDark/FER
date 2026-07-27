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
 * Cette inscription a-t-elle DROIT à un QR code (donc à un t-shirt) ?
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * POURQUOI CETTE FONCTION VIT ICI, ET PAS DANS LE CODE DES MAILS
 *
 * Les t-shirts sont en nombre limité. La règle qui décide qui y a droit était
 * enfermée dans src/mail/googleMail.php, et n'était donc consultée qu'à l'envoi
 * du mail. L'espace coureur, lui, affichait un QR code à TOUT LE MONDE, sous un
 * texte « Présentez-le au retrait des t-shirts » — y compris à des personnes qui
 * n'en avaient jamais reçu et n'y avaient pas droit.
 *
 * Le jour J, ces personnes se présentent au comptoir avec un QR code à l'écran.
 * Il n'y a pas de bonne façon de leur expliquer là, dans la file.
 *
 * La règle est donc ici, dans le module qui centralise déjà tout ce qui touche
 * au QR — et googleMail.php s'y adresse comme tout le monde.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LA RÈGLE, telle qu'elle était déjà appliquée aux mails :
 *   • mode « none »    → personne n'a de QR code ;
 *   • mode « all »     → tout le monde en a un ;
 *   • mode « first_x » → les X premières inscriptions PAYANTES, classées sur la
 *     date d'inscription réelle (et non la date de saisie dans le logiciel, pour
 *     qu'un inscrit antidaté garde son rang). Les inscriptions gratuites
 *     (enfants) ne comptent pas et n'y ont pas droit.
 *
 * @param  array $settings la ligne `setting` (qrcode_mail_mode, qrcode_mail_limit)
 * @return array{ok: bool, raison: string, limite: int}
 *         raison ∈ mode_all | mode_none | hors_limite | non_payant | introuvable | erreur
 */
function fer_qrEligibilite(PDO $pdo, array $settings, string|int $inscriptionNo): array
{
    $mode   = (string) ($settings['qrcode_mail_mode'] ?? 'none');
    $limite = (int) ($settings['qrcode_mail_limit'] ?? 0);

    if ($mode === 'none') return ['ok' => false, 'raison' => 'mode_none',  'limite' => $limite];
    if ($mode === 'all')  return ['ok' => true,  'raison' => 'mode_all',   'limite' => $limite];
    if ($limite <= 0)     return ['ok' => false, 'raison' => 'mode_none',  'limite' => 0];

    try {
        // COALESCE : repli sur created_at pour toute ligne sans date_inscription.
        $st = $pdo->prepare('SELECT COALESCE(date_inscription, created_at) AS dref, montant_du
                               FROM registrations WHERE inscription_no = ? LIMIT 1');
        $st->execute([$inscriptionNo]);
        $self = $st->fetch(PDO::FETCH_ASSOC);

        // Absente de la table vivante : édition archivée, ou inscription retirée.
        if (!$self || empty($self['dref'])) {
            return ['ok' => false, 'raison' => 'introuvable', 'limite' => $limite];
        }
        if ((float) ($self['montant_du'] ?? 0) <= 0) {
            return ['ok' => false, 'raison' => 'non_payant', 'limite' => $limite];
        }

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM registrations
              WHERE montant_du > 0
                AND (COALESCE(date_inscription, created_at) < :dref
                     OR (COALESCE(date_inscription, created_at) = :dref2 AND inscription_no <= :no))'
        );
        $st->execute(['dref' => $self['dref'], 'dref2' => $self['dref'], 'no' => $inscriptionNo]);
        $rang = (int) $st->fetchColumn();

        return $rang <= $limite
            ? ['ok' => true,  'raison' => 'mode_all',    'limite' => $limite]
            : ['ok' => false, 'raison' => 'hors_limite', 'limite' => $limite];
    } catch (\Throwable $e) {
        error_log('[QR] éligibilité : ' . $e->getMessage());
        // En cas de doute, on n'affiche PAS de QR : mieux vaut ne rien promettre
        // que promettre à tort.
        return ['ok' => false, 'raison' => 'erreur', 'limite' => $limite];
    }
}

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
