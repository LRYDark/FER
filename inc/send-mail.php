<?php
require '../config/config.php';
require '../config/googleMail.php';
requireRole(['admin','user']);

    $mailData = json_decode($_POST['mail_data'], true);
    $recipients = $mailData['recipients'] ?? [];
    $subject = $mailData['subject'] ?? '';
    $mailTitle = $mailData['mail_title'] ?? '';
    $description = $mailData['description'] ?? '';

        // 0) $recipients provient de ta sélection (JSON du formulaire, requête SQL…)
        $adresses = [];
        foreach ($recipients as $r) {
            $mail = strtolower(trim($r['email']));
            if ($mail === '') continue;
            $adresses[$mail] = $r['email'] ?: $mail;   // supprime les doublons
        }

        // 2) Envoi
        $toHeader = implode(', ', array_map(
                    fn ($m,$n) => sprintf('"%s" <%s>', addslashes($n), $m),
                    array_keys($adresses), $adresses));

    // Appel de la fonction sendMail et gestion du retour
    $mailSent = sendMail($toHeader, $subject, $mailTitle, $description, 'null', 'null', 'info');

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
