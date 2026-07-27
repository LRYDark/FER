<?php
/**
 * Assistant virtuel / FAQ — administration
 *
 * Regroupe tout ce qui concerne le chatbot du site public :
 *   - activation + infos pratiques servies au bot (horaires, RDV, t-shirts)
 *   - FAQ : questions/réponses gérées ici, servies par le chatbot (mots-clés)
 *     et affichées sur la page publique /faq
 *   - journal des questions incomprises (pour enrichir la FAQ)
 *
 * Permissions : page 'assistant' (lecture) / action 'assistant.write' (écriture).
 */
require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
requirePage('assistant');
$canWrite = canDoAction('assistant.write');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canWrite) {
    http_response_code(403);
    addToast('error', 'Action non autorisée (lecture seule).');
    header('Location: assistant.php');
    exit;
}

require __DIR__ . '/../src/partials/navbar-data.php';

/* ── Réglages de l'assistant (mêmes champs qu'avant dans Réglages → Accueil) ── */
if (isset($_POST['save_chatbot_infos'])) {
    $chatbot_enabled          = !empty($_POST['chatbot_enabled']) ? 1 : 0;
    $course_horaires          = trim($_POST['course_horaires'] ?? '');
    $course_rdv               = trim($_POST['course_rdv'] ?? '');
    $tshirt_retrait_info      = trim($_POST['tshirt_retrait_info'] ?? '');
    $registration_onsite_info = trim($_POST['registration_onsite_info'] ?? '');

    $pdo->prepare(
        'UPDATE setting SET chatbot_enabled = :en, course_horaires = :hor,
         course_rdv = :rdv, tshirt_retrait_info = :ret,
         registration_onsite_info = :onsite WHERE id = 1'
    )->execute([
        'en'     => $chatbot_enabled,
        'hor'    => $course_horaires !== '' ? $course_horaires : null,
        'rdv'    => $course_rdv !== '' ? $course_rdv : null,
        'ret'    => $tshirt_retrait_info !== '' ? $tshirt_retrait_info : null,
        'onsite' => $registration_onsite_info !== '' ? $registration_onsite_info : null,
    ]);
    addToast('success', 'Réglages de l\'assistant enregistrés !');
    header('Location: assistant.php');
    exit;
}

/* ── Journal des questions incomprises ── */
if (isset($_POST['clear_chatbot_unmatched'])) {
    try { $pdo->exec('DELETE FROM chatbot_unmatched'); } catch (\Throwable $e) {}
    addToast('success', 'Journal des questions vidé.');
    header('Location: assistant.php');
    exit;
}

/* ── FAQ : ajout / modification / suppression ── */
$faqValidate = function (): ?array {
    $question = trim($_POST['question'] ?? '');
    $answer   = trim($_POST['answer'] ?? '');
    $keywords = trim($_POST['keywords'] ?? '');
    $position = (int)($_POST['position'] ?? 0);
    $active   = !empty($_POST['active']) ? 1 : 0;
    if ($question === '' || mb_strlen($question) > 255 || $answer === '' || mb_strlen($keywords) > 500) {
        addToast('error', 'Question (255 caractères max), réponse obligatoires — mots-clés 500 caractères max.');
        return null;
    }
    return [$question, $answer, $keywords !== '' ? $keywords : null, $position, $active];
};

if (isset($_POST['add_faq'])) {
    if ($v = $faqValidate()) {
        $pdo->prepare('INSERT INTO chatbot_faq (question, answer, keywords, position, active) VALUES (?,?,?,?,?)')
            ->execute($v);
        addToast('success', 'Question ajoutée à la FAQ !');
    }
    header('Location: assistant.php');
    exit;
}
if (isset($_POST['update_faq'])) {
    $id = (int)($_POST['faq_id'] ?? 0);
    if ($id > 0 && ($v = $faqValidate())) {
        $v[] = $id;
        $pdo->prepare('UPDATE chatbot_faq SET question = ?, answer = ?, keywords = ?, position = ?, active = ? WHERE id = ?')
            ->execute($v);
        addToast('success', 'Question mise à jour.');
    }
    header('Location: assistant.php');
    exit;
}
if (isset($_POST['delete_faq'])) {
    $id = (int)($_POST['faq_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM chatbot_faq WHERE id = ?')->execute([$id]);
        addToast('success', 'Question supprimée.');
    }
    header('Location: assistant.php');
    exit;
}

/* ── Données d'affichage ── */
$faqs = [];
$faqTableMissing = false;
try {
    $faqs = $pdo->query('SELECT * FROM chatbot_faq ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $faqTableMissing = true; // update.php pas encore exécuté
}
$chatbotUnmatched = [];
try {
    $chatbotUnmatched = $pdo->query('SELECT question, created_at FROM chatbot_unmatched ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Assistant virtuel / FAQ</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<style>
  .setting-card{background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:1rem;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 1px 8px rgba(0,0,0,.04)}
  .setting-card h2{font-size:1.15rem;font-weight:700;margin-bottom:1rem}
  .btn-rose{background:var(--primary,#f42182);color:#fff;font-weight:600}
  .btn-rose:hover{background:var(--primary,#f42182);filter:brightness(.92);color:#fff}
  .faq-q{font-weight:600}
  .faq-item{border:1px solid var(--border,#e5e7eb);border-radius:.75rem;margin-bottom:.6rem;overflow:hidden;background:var(--card,#fff)}
  .faq-item summary{padding:.7rem 1rem;cursor:pointer;display:flex;align-items:center;gap:.6rem;list-style:none}
  .faq-item summary::-webkit-details-marker{display:none}
  .faq-item .faq-body{padding:0 1rem 1rem;border-top:1px dashed var(--border,#e5e7eb)}
  .faq-badge-off{font-size:11px;background:#fee2e2;color:#b91c1c;border-radius:20px;padding:2px 9px;font-weight:700}
  .faq-badge-pos{font-size:11px;background:var(--surface-2,#f1f5f9);border-radius:20px;padding:2px 9px;font-weight:600;color:var(--ink-dim,#64748b)}
  .unmatched-list{font-size:13px;max-height:260px;overflow-y:auto}
</style>
</head>

<body>

<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<div class="py-3">

  <?php if ($faqTableMissing): ?>
  <div class="alert alert-warning">
    La table de la FAQ n'existe pas encore : lancez <strong>update.php</strong> pour finaliser la migration.
  </div>
  <?php endif; ?>

  <!-- ═══ Réglages de l'assistant ═══ -->
  <div class="setting-card">
    <h2><i class="bi bi-chat-heart me-2"></i>Assistant virtuel (chatbot)</h2>
    <p class="text-muted" style="font-size:13px;margin-top:-6px">
      Bulle de chat sur le site public : répond aux questions des visiteurs (inscription,
      t-shirt, tarifs, lieu, horaires, renvoi du mail de confirmation…) à partir des
      informations ci-dessous et de la FAQ, et remplace la page Contact (le formulaire
      s'ouvre dans le chat). Si l'assistant est désactivé, la page Contact classique est
      de nouveau servie.
    </p>
    <form action="" method="post" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="save_chatbot_infos" value="1">
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="chatbotEnabled"
                 name="chatbot_enabled" value="1" <?= !empty($data['chatbot_enabled']) ? 'checked' : '' ?> <?= $canWrite ? '' : 'disabled' ?>>
          <label class="form-check-label" for="chatbotEnabled">Activer l'assistant sur le site public</label>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="courseHoraires">Horaires de la course</label>
        <textarea class="form-control" id="courseHoraires" name="course_horaires" rows="3" <?= $canWrite ? '' : 'disabled' ?>
                  placeholder="Ex. : Ouverture du village à 8h — Échauffement à 9h15 — Départ à 9h30"><?= htmlspecialchars($data['course_horaires'] ?? '') ?></textarea>
        <small class="text-muted">Répond aux questions « quand ? », « à quelle heure ? »…</small>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="courseRdv">Point de rendez-vous</label>
        <textarea class="form-control" id="courseRdv" name="course_rdv" rows="3" <?= $canWrite ? '' : 'disabled' ?>
                  placeholder="Ex. : Rendez-vous devant la piscine de Forbach — parking gratuit rue X"><?= htmlspecialchars($data['course_rdv'] ?? '') ?></textarea>
        <small class="text-muted">Complète le lieu de départ (réglé dans Contenu → Page d'accueil, section « Retrouver le départ »).</small>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="tshirtRetrait">Retrait des t-shirts</label>
        <textarea class="form-control" id="tshirtRetrait" name="tshirt_retrait_info" rows="3" <?= $canWrite ? '' : 'disabled' ?>
                  placeholder="Ex. : Les t-shirts sont à retirer le jour J au stand Accueil, de 8h à 9h15, sur présentation du QR code reçu par mail"><?= htmlspecialchars($data['tshirt_retrait_info'] ?? '') ?></textarea>
        <small class="text-muted">Répond à « comment récupérer mon t-shirt ? » et complète la vérification d'éligibilité par e-mail.</small>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="registrationOnsite">Inscription sur place (si souci en ligne)</label>
        <textarea class="form-control" id="registrationOnsite" name="registration_onsite_info" rows="3" <?= $canWrite ? '' : 'disabled' ?>
                  placeholder="Ex. : Inscription possible à la mairie de Forbach, du lundi au vendredi de 9h à 12h et de 14h à 17h"><?= htmlspecialchars($data['registration_onsite_info'] ?? '') ?></textarea>
        <small class="text-muted">Proposée par l'assistant quand un visiteur signale un problème d'inscription en ligne (« je n'arrive pas à m'inscrire »…). Laisser vide pour ne pas la proposer.</small>
      </div>
      <?php if ($canWrite): ?>
      <div class="col-12 d-flex justify-content-end">
        <button type="submit" class="btn btn-rose">Enregistrer</button>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ═══ FAQ ═══ -->
  <div class="setting-card">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h2 class="mb-0"><i class="bi bi-question-circle me-2"></i>FAQ (<?= count($faqs) ?>)</h2>
      <a href="../public/faq.php" target="_blank" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-box-arrow-up-right me-1"></i>Voir la page publique
      </a>
    </div>
    <p class="text-muted mt-2" style="font-size:13px">
      Ces questions/réponses sont affichées sur la page publique <strong>/faq</strong> et servies par
      le chatbot : quand un visiteur pose une question que le bot ne connaît pas, il cherche ici
      via les <strong>mots-clés</strong> (séparés par des virgules) et les mots de la question.
      S'il ne trouve rien, il propose la FAQ ou le formulaire de contact.
    </p>

    <?php if ($canWrite && !$faqTableMissing): ?>
    <details class="faq-item" <?= empty($faqs) ? 'open' : '' ?>>
      <summary><i class="bi bi-plus-circle"></i><span class="faq-q">Ajouter une question</span></summary>
      <div class="faq-body pt-3">
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="add_faq" value="1">
          <div class="col-md-8">
            <label class="form-label">Question</label>
            <input type="text" class="form-control" name="question" maxlength="255" required
                   placeholder="Ex. : Les chiens sont-ils acceptés ?">
          </div>
          <div class="col-md-2">
            <label class="form-label">Ordre</label>
            <input type="number" class="form-control" name="position" value="0">
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" name="active" value="1" checked id="faqAddActive">
              <label class="form-check-label" for="faqAddActive">Active</label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Réponse</label>
            <textarea class="form-control" name="answer" rows="3" required
                      placeholder="Texte libre — les sauts de ligne sont conservés."></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Mots-clés (pour le chatbot)</label>
            <input type="text" class="form-control" name="keywords" maxlength="500"
                   placeholder="Ex. : chien, animaux, poussette, laisse">
            <small class="text-muted">Séparés par des virgules. Un mot-clé peut être une expression (« certificat médical »).</small>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button type="submit" class="btn btn-rose btn-sm">Ajouter</button>
          </div>
        </form>
      </div>
    </details>
    <hr>
    <?php endif; ?>

    <?php if (empty($faqs) && !$faqTableMissing): ?>
      <p class="text-muted mb-0">Aucune question pour l'instant — ajoutez la première ci-dessus !</p>
    <?php endif; ?>

    <?php foreach ($faqs as $f): ?>
    <details class="faq-item">
      <summary>
        <span class="faq-badge-pos">#<?= (int)$f['position'] ?></span>
        <span class="faq-q"><?= htmlspecialchars($f['question']) ?></span>
        <?php if (!(int)$f['active']): ?><span class="faq-badge-off">désactivée</span><?php endif; ?>
      </summary>
      <div class="faq-body pt-3">
        <?php if ($canWrite): ?>
        <form action="" method="post" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="faq_id" value="<?= (int)$f['id'] ?>">
          <div class="col-md-8">
            <label class="form-label">Question</label>
            <input type="text" class="form-control" name="question" maxlength="255" required
                   value="<?= htmlspecialchars($f['question']) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Ordre</label>
            <input type="number" class="form-control" name="position" value="<?= (int)$f['position'] ?>">
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" name="active" value="1" <?= (int)$f['active'] ? 'checked' : '' ?> id="faqActive<?= (int)$f['id'] ?>">
              <label class="form-check-label" for="faqActive<?= (int)$f['id'] ?>">Active</label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Réponse</label>
            <textarea class="form-control" name="answer" rows="3" required><?= htmlspecialchars($f['answer']) ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Mots-clés (pour le chatbot)</label>
            <input type="text" class="form-control" name="keywords" maxlength="500"
                   value="<?= htmlspecialchars($f['keywords'] ?? '') ?>">
          </div>
          <div class="col-12 d-flex justify-content-between">
            <button type="submit" name="delete_faq" value="1" class="btn btn-outline-danger btn-sm"
                    data-confirm="Supprimer définitivement cette question ?">Supprimer</button>
            <button type="submit" name="update_faq" value="1" class="btn btn-rose btn-sm">Enregistrer</button>
          </div>
        </form>
        <?php else: ?>
        <p class="mb-1" style="white-space:pre-line"><?= htmlspecialchars($f['answer']) ?></p>
        <?php if (!empty($f['keywords'])): ?><small class="text-muted">Mots-clés : <?= htmlspecialchars($f['keywords']) ?></small><?php endif; ?>
        <?php endif; ?>
      </div>
    </details>
    <?php endforeach; ?>
  </div>

  <!-- ═══ Questions incomprises ═══ -->
  <div class="setting-card">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h2 class="mb-0"><i class="bi bi-patch-question me-2"></i>Questions incomprises par l'assistant</h2>
      <?php if ($canWrite && $chatbotUnmatched): ?>
      <form method="post" class="mb-0">
        <?= csrf_field() ?>
        <input type="hidden" name="clear_chatbot_unmatched" value="1">
        <button type="submit" class="btn btn-sm btn-outline-secondary">Vider le journal</button>
      </form>
      <?php endif; ?>
    </div>
    <p class="text-muted mt-2" style="font-size:13px">
      Les questions des visiteurs auxquelles ni le bot ni la FAQ n'ont su répondre (journal anonyme,
      30 dernières). La mine d'or pour enrichir la FAQ ci-dessus !
    </p>
    <?php if ($chatbotUnmatched): ?>
    <ul class="list-unstyled mb-0 unmatched-list">
      <?php foreach ($chatbotUnmatched as $q): ?>
      <li class="py-1 border-bottom d-flex justify-content-between gap-3">
        <span>« <?= htmlspecialchars($q['question']) ?> »</span>
        <span class="text-muted flex-shrink-0"><?= htmlspecialchars(date('d/m H:i', strtotime($q['created_at']))) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="text-muted mb-0">Rien pour le moment — l'assistant a réponse à tout ! 🎉</p>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
