<?php
$mailData = json_decode($_POST['mail_data'], true);

$recipients = $mailData['recipients'] ?? [];
$subject = $mailData['subject'] ?? '';

$mailTitle = $mailData['mail_title'] ?? '';
$description = $mailData['description'] ?? '';

echo "<h2>Objet : " . htmlspecialchars($subject) . "</h2>";
echo "<h3>Titre : " . htmlspecialchars($mailTitle) . "</h3>";
echo "<div>" . $description . "</div>";
echo "<ul>";
foreach ($recipients as $r) {
    echo "<li>" . htmlspecialchars($r['name']) . " (" . htmlspecialchars($r['email']) . ")</li>";
}
echo "</ul>";

include_once 'mail2.php';



?>
