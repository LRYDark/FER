<?php
/**
 * Widget chatbot — inclus par footer-modern.php sur toutes les pages publiques.
 *
 * S'appuie sur $data (navbar-data.php, SELECT * FROM setting) si disponible,
 * sinon interroge la base. Désactivable via Réglages → chatbot_enabled.
 * Le token CSRF de session est transmis au widget pour les appels API.
 */
if (!isset($data) || !is_array($data)) {
    try {
        $data = isset($pdo) ? ($pdo->query('SELECT * FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (\Throwable $e) { $data = []; }
}
// Activé par défaut (colonne absente avant migration → on n'affiche pas pour éviter les erreurs API)
$chatbotEnabled = array_key_exists('chatbot_enabled', $data) ? (int)$data['chatbot_enabled'] === 1 : false;
if (!$chatbotEnabled) return;

require_once __DIR__ . '/../security/csrf.php';
$chatbotV = function (string $rel) { $p = dirname(__DIR__, 2) . '/' . $rel; return $rel . '?v=' . (@filemtime($p) ?: '1'); };
?>
<link rel="stylesheet" href="../<?= $chatbotV('css/chatbot.css') ?>">
<div id="ferChatbot"
     data-api="chatbot-api.php"
     data-admin-api="../admin-api.php"
     data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
  <div class="fcb-window" role="dialog" aria-label="Assistant Forbach en Rose">
    <div class="fcb-header">
      <div class="fcb-avatar">🎀</div>
      <div class="fcb-head-txt">
        <strong>Assistant Forbach en Rose</strong>
        <small><span class="fcb-dot"></span> En ligne — réponse immédiate</small>
      </div>
      <button type="button" class="fcb-close" aria-label="Fermer le chat">✕</button>
    </div>
    <div class="fcb-messages" aria-live="polite"></div>
    <div class="fcb-inputbar">
      <input type="text" class="fcb-input" placeholder="Écrivez votre question…" maxlength="500" aria-label="Votre question">
      <button type="button" class="fcb-send" aria-label="Envoyer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </button>
    </div>
  </div>
  <button type="button" class="fcb-bubble" aria-label="Ouvrir l'assistant" aria-expanded="false">
    <svg class="fcb-ico-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <svg class="fcb-ico-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
</div>
<script src="../<?= $chatbotV('js/chatbot.js') ?>"></script>
