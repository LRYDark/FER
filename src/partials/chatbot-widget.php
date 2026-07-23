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
      <div class="fcb-avatar" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 11.22C11 9.997 10 9 10 8a2 2 0 0 1 4 0c0 1-.998 2.002-2.01 3.22"/><path d="m12 18 2.57-3.5"/><path d="M6.243 9.016a7 7 0 0 1 11.507-.009"/><path d="M9.35 14.53 12 11.22"/><path d="M9.35 14.53C7.728 12.246 6 10.221 6 7a6 5 0 0 1 12 0c-.005 3.22-1.778 5.235-3.43 7.5l3.557 4.527a1 1 0 0 1-.203 1.43l-1.894 1.36a1 1 0 0 1-1.384-.215L12 18l-2.679 3.593a1 1 0 0 1-1.39.213l-1.865-1.353a1 1 0 0 1-.203-1.422z"/></svg>
      </div>
      <div class="fcb-head-txt">
        <strong>Assistant Forbach en Rose</strong>
        <small><span class="fcb-dot"></span> En ligne — réponse immédiate</small>
      </div>
      <button type="button" class="fcb-home" aria-label="Revenir au menu principal" title="Menu principal">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      </button>
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
  <div class="fcb-teaser" hidden>
    <button type="button" class="fcb-teaser-close" aria-label="Masquer le message">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
    </button>
    <span class="fcb-teaser-full">Besoin d'aide, une question&nbsp;?<br><strong>Je vous réponds ici&nbsp;!</strong> 👋</span>
    <span class="fcb-teaser-short"><strong>Besoin d'aide&nbsp;?</strong> 👋</span>
  </div>
  <button type="button" class="fcb-bubble" aria-label="Ouvrir l'assistant" aria-expanded="false">
    <span class="fcb-status-dot" aria-hidden="true"></span>
    <!-- Robot assistant (tête carrée, antenne, sourire — les yeux clignent) -->
    <svg class="fcb-ico-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="4.5" y="6.5" width="15" height="11.5" rx="4"/>
      <path d="M12 6.5V4"/><circle cx="12" cy="2.8" r="1" fill="currentColor" stroke="none"/>
      <path d="M4.5 11H3M21 11h-1.5"/>
      <circle class="fcb-eye" cx="9" cy="11.5" r="1" fill="currentColor" stroke="none"/>
      <circle class="fcb-eye" cx="15" cy="11.5" r="1" fill="currentColor" stroke="none"/>
      <path d="M9.5 14.7c.7.7 1.6 1 2.5 1s1.8-.3 2.5-1"/>
    </svg>
    <svg class="fcb-ico-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
</div>
<script src="../<?= $chatbotV('js/chatbot.js') ?>"></script>
