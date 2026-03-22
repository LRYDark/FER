<?php
require '../config/config.php';
require '../config/googleMail.php';
requireRole(['admin','user']);

    $mailData = json_decode($_POST['mail_data'], true);
    $recipients = $mailData['recipients'] ?? [];
    $subject = $mailData['subject'] ?? '';
    $mailTitle = $mailData['mail_title'] ?? '';
    $description = $mailData['description'] ?? '';

        // Collect unique emails (BCC mode — recipients won't see each other)
        $adresses = [];
        foreach ($recipients as $r) {
            $mail = strtolower(trim($r['email']));
            if ($mail === '') continue;
            $adresses[$mail] = true;
        }
        $emailList = array_keys($adresses);

    // Send as array → sendMail uses BCC automatically
    $mailSent = sendMail($emailList, $subject, $mailTitle, $description, null, null, 'info');

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

    header('Location: dashboard.php');
    exit;
?>
