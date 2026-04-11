<?php
require '../config/config.php';
require_once '../config/csrf.php';
require '../config/googleMail.php';
requirePage('mail-settings');
// L'envoi de mails est protégé par mail.send (séparé de mail.write qui couvre la conf)
if (!canDoAction('mail.send')) {
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Action non autorisée (droit « Envoyer des mails » requis).']);
        exit;
    }
    http_response_code(403);
    die('Action non autorisée');
}

$isAjax = isAjaxRequest();

// 🔒 [SEC-02] Vérification CSRF avant envoi de mails en masse (CWE-352)
if (!csrf_verify()) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Session expirée. Veuillez réessayer.']);
        exit;
    }
    $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Session expirée. Veuillez réessayer.'];
    header('Location: ../public/accueil');
    exit;
}

    $mailData = json_decode($_POST['mail_data'], true);
    $recipients = $mailData['recipients'] ?? [];
    $subject = $mailData['subject'] ?? '';
    $mailTitle = $mailData['mail_title'] ?? '';

    // Décodage Base64 de la description (encodée côté JS pour contourner le WAF)
    $description = decodeHtmlField($mailData['description'] ?? '');

        // Collect unique emails (BCC mode — recipients won't see each other)
        $adresses = [];
        foreach ($recipients as $r) {
            $mail = strtolower(trim($r['email']));
            if ($mail === '') continue;
            $adresses[$mail] = true;
        }
        $emailList = array_keys($adresses);

    // Send as array → sendMail uses BCC automatically
    $mailSent = sendMail($emailList, $subject, $mailTitle, $description, null, null, 'info', null, 'bulk');

    // Définir le message flash selon le résultat
    if ($mailSent) {
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'message' => '✅ Mail envoyé avec succès à ' . count($adresses) . ' destinataire(s) !'
        ];
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'message' => '❌ Erreur lors de l\'envoi du mail. Veuillez réessayer.'
        ];
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $mailSent]);
        exit;
    }

    header('Location: mail-settings.php?tab=envoi');
    exit;
?>
