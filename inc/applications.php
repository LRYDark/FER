<?php
/**
 * applications.php — Applications mobiles : notifications et réveil.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * CETTE PAGE NE CONTIENT PAS LES INFORMATIONS DE COURSE, ET C'EST DÉLIBÉRÉ.
 *
 * La date, le lieu et l'heure de départ se règlent dans Réglages → Course, qui
 * en est la source unique. Les remettre ici en ferait un second endroit où les
 * modifier — c'est-à-dire exactement le problème qu'on vient de corriger, à
 * l'envers. On les AFFICHE (elles conditionnent le réveil), on ne les édite pas.
 *
 * Répartition : Course = les faits — quand, où, combien.
 *               Applications = ce qu'on en fait — notifier, réveiller.
 */

require '../src/core/config.php';
require_once __DIR__ . '/../src/security/csrf.php';
require __DIR__ . '/../src/partials/navbar-data.php';
require_once __DIR__ . '/../src/content/content-log.php';   // logContentAction()
require_once __DIR__ . '/../src/content/course.php';
require_once __DIR__ . '/../src/content/notifications.php';
require_once __DIR__ . '/../src/content/chrono.php';        // chrono_actif()
require_once __DIR__ . '/../src/content/push.php';          // push_envoyer()

requirePage('setting');
$role     = currentRole();
$canWrite = canDoAction('settings.write');

$erreur = '';
$succes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $erreur = 'Session expirée. Rechargez la page et réessayez.';
    } elseif (!$canWrite) {
        $erreur = "Vous n'avez pas le droit de modifier ces réglages.";
    } else {
        /* ── Réglages généraux de l'application ─────────────────────────── */
        if (isset($_POST['save_app'])) {
            $actives = !empty($_POST['app_notifications_actives']) ? 1 : 0;
            // Borné à 24 h : au-delà, le rappel arriverait la veille au soir
            // pour une course du surlendemain, et personne ne comprendrait.
            $reveil  = max(0, min(1440, (int) ($_POST['app_reveil_avant_min'] ?? 120)));
            try {
                $pdo->prepare('UPDATE setting SET app_notifications_actives = ?,
                                                  app_reveil_avant_min = ?
                                WHERE id = 1')->execute([$actives, $reveil]);
                $succes = 'Réglages de l\'application enregistrés.';
                logContentAction($pdo, 'applications', 'update', null,
                    "Notifications " . ($actives ? 'activées' : 'désactivées')
                    . ", réveil $reveil min", 'app');
            } catch (\Throwable $e) {
                $erreur = 'Colonnes absentes : lancez update.php.';
            }
        }

        /* ── Informations de course, modifiées DEPUIS CET ÉCRAN ──────────
         *
         * ⚠️ MÊME FONCTION QUE L'ONGLET COURSE, ET C'EST TOUT L'INTÉRÊT.
         * course_enregistrer() écrit `editions` ET `setting` dans une seule
         * transaction : changer l'heure ici la change dans Réglages → Course,
         * sur l'accueil, à l'inscription et dans l'application. Une requête
         * écrite à la main dans ce fichier serait une quatrième copie.
         *
         * On ne propose ici que les trois champs qui commandent le réveil et
         * les notifications. Le reste — coordonnées des lignes, temps minimum,
         * textes du village — reste dans l'onglet Course, qui a la place de les
         * présenter correctement. */
        elseif (isset($_POST['save_course_app'])) {
            $vide = fn(string $c): ?string =>
                trim((string) ($_POST[$c] ?? '')) === '' ? null : trim((string) $_POST[$c]);

            $r = course_enregistrer($pdo, [
                'date_course'  => $vide('course_date'),
                'heure_depart' => course_heureDepartUtc($vide('course_heure')),
                'lieu_adresse' => $vide('course_adresse'),
            ]);
            if ($r['ok']) {
                $succes = 'Informations de course enregistrées — '
                        . "l'accueil, l'inscription et l'application suivent.";
                logContentAction($pdo, 'applications', 'update', null,
                    'Infos de course modifiées depuis Applications', 'course');
            } else {
                $erreur = $r['erreur'] ?? "L'enregistrement a échoué.";
            }
        }

        /* ── Notification : création ou modification ────────────────────── */
        elseif (isset($_POST['save_notif'])) {
            $id = ($_POST['notif_id'] ?? '') === '' ? null : (int) $_POST['notif_id'];
            $r = notif_enregistrer($pdo, [
                'annee'             => $_POST['notif_annee']   ?? '',
                'type'              => $_POST['notif_type']    ?? 'info',
                'titre'             => $_POST['notif_titre']   ?? '',
                'message'           => $_POST['notif_message'] ?? '',
                'publie_at'         => $_POST['notif_publie']  ?? '',
                'expire_at'         => $_POST['notif_expire']  ?? '',
                'epingle'           => $_POST['notif_epingle'] ?? '',
                'afficher_dans_app' => $_POST['notif_dans_app'] ?? '',
            ], $id, currentUserId());

            if ($r['ok']) {
                $succes = $id === null ? 'Notification créée.' : 'Notification modifiée.';
                logContentAction($pdo, 'applications', $id === null ? 'create' : 'update',
                    $r['id'] ?? null, (string) ($_POST['notif_titre'] ?? ''), 'app');
            } else {
                $erreur = $r['erreur'] ?? "L'enregistrement a échoué.";
            }
        }

        /* ── L'envoi : une ACTION, séparée de l'enregistrement ───────────── */
        elseif (isset($_POST['envoyer_notif'])) {
            $id = (int) $_POST['envoyer_notif'];
            $r  = notif_envoyerPush($pdo, $id);
            if ($r['ok']) {
                $succes = 'Envoyée à ' . $r['envoyes'] . ' appareil(s)'
                        . ($r['echecs'] > 0 ? ' — ' . $r['echecs'] . ' injoignable(s)' : '') . '.';
                logContentAction($pdo, 'applications', 'update', $id,
                    'Notification envoyée à ' . $r['envoyes'] . ' appareil(s)', 'push');
            } else {
                $erreur = $r['erreur'] ?? "L'envoi a échoué.";
            }
        }

        /* ── Le compte de service Firebase ───────────────────────────────── */
        elseif (isset($_POST['save_fcm'])) {
            $r = push_enregistrerCompte($pdo, (string) ($_POST['fcm_json'] ?? ''));
            if ($r['ok']) {
                $succes = isset($r['projet'])
                    ? 'Firebase configuré : projet « ' . $r['projet'] . ' ».'
                    : 'Configuration Firebase retirée.';
                // ⚠️ On journalise le PROJET, jamais la clé.
                logContentAction($pdo, 'applications', 'update', null,
                    'Firebase : ' . ($r['projet'] ?? 'retiré'), 'push');
            } else {
                $erreur = $r['erreur'] ?? 'Configuration refusée.';
            }
        }

        elseif (isset($_POST['supprimer_notif'])) {
            $id = (int) $_POST['supprimer_notif'];
            if (notif_supprimer($pdo, $id)) {
                $succes = 'Notification supprimée.';
                logContentAction($pdo, 'applications', 'delete', $id, 'Notification supprimée', 'app');
            } else {
                $erreur = 'Suppression impossible.';
            }
        }

        elseif (isset($_POST['basculer_notif'])) {
            $id = (int) $_POST['basculer_notif'];
            $succes = notif_basculer($pdo, $id)
                ? 'Notification mise à jour.'
                : '';
            if ($succes === '') $erreur = 'Modification impossible.';
        }
    }
}

$reglages = notif_reglages($pdo);
$course   = course_lire($pdo);
$liste    = notif_toutes($pdo);
$fcm      = push_config($pdo);
// Combien de téléphones sonneront réellement. Affiché AVANT d'envoyer : « 0
// appareil » explique tout de suite qu'il ne se passera rien, alors qu'un envoi
// silencieusement vide laisserait croire que c'est parti.
$nbJoignables = $fcm['pret'] ? push_nbDestinataires($pdo, null) : 0;
$editions = [];
try {
    $editions = $pdo->query('SELECT annee FROM editions ORDER BY annee DESC')
                    ->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) { /* table absente */ }

/* Notification en cours d'édition, ouverte depuis la liste. */
$edite = isset($_GET['modifier']) ? notif_une($pdo, (int) $_GET['modifier']) : null;

/* L'heure à laquelle l'application se réveillera. Calculée et AFFICHÉE : un
   réglage « 120 minutes » ne dit rien tant qu'on ne voit pas qu'il tombe à
   8 h 00 le 4 octobre. C'est là qu'on repère une heure de départ erronée. */
$heureReveil = null;
$heureDepart = course_heureDepartLocale($course['heure_depart']);
if ($heureDepart !== null && $reglages['reveil_avant_min'] > 0) {
    $heureReveil = $heureDepart->modify('-' . $reglages['reveil_avant_min'] . ' minutes');
}

$pageTitle    = 'Applications';
$pageSubtitle = 'Applications';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Applications</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

  <div class="page-header">
    <h1 class="mb-2 fw-bold"><i class="bi bi-phone me-2"></i>Applications</h1>
    <p class="text-muted mb-0">Ce que les applications annoncent aux coureurs, et quand elles se réveillent.</p>
  </div>

<?php
  if ($erreur !== '') addToast('danger', $erreur);
  if ($succes !== '') addToast('success', $succes);
?>

<div class="row g-4">

  <!-- ═══════════════ Réglages de l'application ═══════════════════════════ -->
  <div class="col-lg-5">
    <div class="card-dashboard h-100" id="carteReglagesApp">
      <h2 class="h5 fw-bold mb-3"><i class="bi bi-toggles me-2"></i>Réglages</h2>

      <form method="post">
        <?= csrf_field() ?>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="app_notifications_actives"
                 name="app_notifications_actives"
                 <?= $reglages['notifications_actives'] ? 'checked' : '' ?>
                 <?= $canWrite ? '' : 'disabled' ?>>
          <label class="form-check-label" for="app_notifications_actives">
            Diffusion des messages
          </label>
        </div>
        <?php /* Le même robinet que l'API mobile et le chronométrage : un
                 interrupteur qu'on ferme en cas de problème, pas un réglage du
                 quotidien. Le dire évite de le chercher pour autre chose. */ ?>
        <p class="text-muted small">
          Coupe tout d'un coup, sans rien supprimer. À n'utiliser qu'en cas de problème.
        </p>

        <div class="mb-2">
          <label class="form-label" for="app_reveil_avant_min">
            Réveil de l'application avant le départ
          </label>
          <div class="input-group">
            <input type="number" class="form-control" id="app_reveil_avant_min"
                   name="app_reveil_avant_min" min="0" max="1440"
                   value="<?= (int) $reglages['reveil_avant_min'] ?>"
                   <?= $canWrite ? '' : 'disabled' ?>>
            <span class="input-group-text">minutes</span>
          </div>
        </div>

        <?php /* Conservé, en une phrase : sans elle, on compte le jour J sur un
                 démarrage automatique qui n'existe sur aucune plateforme. */ ?>
        <p class="text-muted small">
          L'application ne se lance pas seule : le rappel s'affiche, et le coureur l'ouvre.
        </p>

        <?php if ($heureReveil !== null): ?>
          <div class="alert alert-success small mb-3">
            <i class="bi bi-alarm me-1"></i>
            Réveil le <strong><?= $h($heureReveil->format('d/m/Y à H:i')) ?></strong>
            pour un départ à <strong><?= $h($heureDepart->format('H:i')) ?></strong>.
          </div>
        <?php elseif ($reglages['reveil_avant_min'] > 0): ?>
          <div class="alert alert-warning small mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Aucun réveil possible</strong> : l'heure de départ n'est pas
            renseignée. À saisir dans <a href="setting.php?tab=course">Réglages → Course</a>.
          </div>
        <?php endif; ?>

        <?php if ($canWrite): ?>
          <button type="submit" name="save_app" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i>Enregistrer
          </button>
        <?php endif; ?>
      </form>

      <hr class="my-4">

      <?php /* ⚠️ MODIFIABLE ICI, PAS SEULEMENT CONSULTABLE. Ces trois champs
               commandent le réveil : renvoyer vers un autre écran pour les
               corriger obligerait à faire l'aller-retour au moment précis où
               l'on constate l'erreur. La valeur est la même des deux côtés. */ ?>
      <h3 class="h6 fw-bold mb-1">Édition <?= (int) $course['annee'] ?></h3>
      <p class="text-muted small">
        Modifiable ici ou dans <a href="setting.php?tab=course">Réglages → Course</a> :
        c'est la même valeur.
      </p>

      <form method="post" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-7">
          <label class="form-label small mb-1" for="course_date">Date</label>
          <input type="date" class="form-control form-control-sm" id="course_date"
                 name="course_date" <?= $canWrite ? '' : 'disabled' ?>
                 value="<?= $h($course['date_course'] ?? '') ?>">
        </div>
        <div class="col-5">
          <label class="form-label small mb-1" for="course_heure">Départ</label>
          <input type="time" class="form-control form-control-sm" id="course_heure_h"
                 name="course_heure_h" <?= $canWrite ? '' : 'disabled' ?>
                 value="<?= $heureDepart !== null ? $h($heureDepart->format('H:i')) : '' ?>">
        </div>
        <div class="col-12">
          <label class="form-label small mb-1" for="course_adresse">Rendez-vous</label>
          <input type="text" class="form-control form-control-sm" id="course_adresse"
                 name="course_adresse" maxlength="255" <?= $canWrite ? '' : 'disabled' ?>
                 value="<?= $h($course['lieu_adresse'] ?? '') ?>">
        </div>
        <?php /* L'heure est saisie seule, mais stockée avec sa date : on les
                 recompose ici, sinon un champ `time` isolé ne dirait pas de
                 quel jour il parle. */ ?>
        <input type="hidden" name="course_heure" id="course_heure">
        <div class="col-12 d-flex align-items-center gap-2">
          <?php if ($canWrite): ?>
            <button type="submit" name="save_course_app" class="btn btn-sm btn-primary">
              <i class="bi bi-check2 me-1"></i>Enregistrer
            </button>
          <?php endif; ?>
          <span class="text-muted small ms-auto">
            Chronométrage :
            <?= chrono_actif($pdo)
                  ? '<span class="badge bg-success">activé</span>'
                  : '<span class="badge bg-secondary">désactivé</span>' ?>
          </span>
        </div>
      </form>

      <hr class="my-4">

      <!-- ══════════════ Firebase ══════════════════════════════════════════ -->
      <h3 class="h6 fw-bold mb-1">
        <i class="bi bi-bell me-1"></i>Notifications sur les téléphones
        <?= $fcm['pret']
              ? '<span class="badge bg-success">configuré</span>'
              : '<span class="badge bg-secondary">non configuré</span>' ?>
      </h3>
      <?php /* ⚠️ NE PAS ÉCRIRE « le seul moyen » : c'est faux pour l'iPhone,
               où le service obligatoire est APNs, celui d'Apple, que Firebase
               ne fait que relayer. La phrase précédente le prétendait. */ ?>
      <p class="text-muted small">
        Obligatoire pour Android. Pour l'iPhone, Firebase relaie vers le service
        d'Apple — c'est un raccourci, pas une obligation.
      </p>

      <?php if ($fcm['pret']): ?>
        <p class="small mb-2">
          Projet : <code><?= $h($fcm['projet']) ?></code><br>
          Compte : <code><?= $h($fcm['compte']['client_email'] ?? '?') ?></code>
        </p>
      <?php endif; ?>

      <?php /* La clé se colle UNE FOIS, à la configuration. Un pavé JSON de
               trois lignes en permanence sous les yeux, sur un écran qu'on
               ouvre pour écrire une notification, n'aide personne. */ ?>
      <?php if ($canWrite): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="collapse" data-bs-target="#fcmConfig">
          <i class="bi bi-key me-1"></i>
          <?= $fcm['pret'] ? 'Remplacer la clé' : 'Configurer Firebase' ?>
        </button>

        <form method="post" class="collapse<?= $fcm['pret'] ? '' : ' show' ?> mt-2" id="fcmConfig">
          <?= csrf_field() ?>
          <label class="form-label small" for="fcm_json">
            Compte de service <em>(JSON)</em>
          </label>
          <?php /* ⚠️ LE CHAMP EST TOUJOURS VIDE À L'AFFICHAGE, et c'est
                   volontaire : c'est une clé privée. La réafficher, même en
                   lecture seule, la rendrait copiable depuis n'importe quel
                   écran d'administration ouvert. */ ?>
          <textarea class="form-control form-control-sm font-monospace" id="fcm_json"
                    name="fcm_json" rows="3"
                    placeholder='<?= $fcm['pret']
                        ? "Configuré. Collez un nouveau fichier pour le remplacer, ou laissez vide."
                        : '{"type":"service_account", …}' ?>'></textarea>
          <small class="text-muted d-block mb-2">
            Console Firebase → Paramètres du projet → Comptes de service →
            <em>Générer une nouvelle clé privée</em>. Le projet est lu dans le fichier.
          </small>
          <button type="submit" name="save_fcm" class="btn btn-sm btn-primary">
            <i class="bi bi-key me-1"></i>Enregistrer la clé
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════ Notifications ═══════════════════════════════════════ -->
  <div class="col-lg-7">
    <div class="card-dashboard" id="carteMessages">
      <h2 class="h5 fw-bold mb-3">
        <?php /* « Messages aux coureurs » et non « Notifications » : l'écran
                 Emails a ses « Alertes par email », qui vont aux
                 administrateurs. Deux noms proches pour deux publics opposés,
                 c'était l'erreur à corriger. */ ?>
        <i class="bi bi-megaphone me-2"></i>
        <?= $edite !== null ? 'Modifier le message' : 'Nouveau message aux coureurs' ?>
      </h2>

      <?php /* Ce qui remplace l'ancien avertissement « pas instantanément » :
               désormais l'envoi part vraiment, et ce qui compte est de savoir
               combien de téléphones sont joignables. */ ?>
      <?php if (!$fcm['pret']): ?>
        <div class="alert alert-warning small">
          <i class="bi bi-bell-slash me-1"></i>
          <strong>Firebase n'est pas configuré</strong> : les messages restent
          consultables dans l'application, mais aucun téléphone ne sonnera.
          Réglage ci-contre.
        </div>
      <?php else: ?>
        <p class="text-muted small">
          <i class="bi bi-bell me-1"></i>
          <strong><?= (int) $nbJoignables ?> appareil(s)</strong> recevront les notifications.
        </p>
      <?php endif; ?>

      <?php if ($canWrite): ?>
      <form method="post" class="row g-3 mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="notif_id" value="<?= $edite !== null ? (int) $edite['id'] : '' ?>">

        <div class="col-md-8">
          <label class="form-label" for="notif_titre">Titre</label>
          <input type="text" class="form-control" id="notif_titre" name="notif_titre"
                 maxlength="120" required
                 value="<?= $h($edite['titre'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="notif_type">Type</label>
          <select class="form-select" id="notif_type" name="notif_type">
            <?php foreach (['info' => 'Information', 'course' => 'Course', 'urgent' => 'Urgent'] as $k => $lib): ?>
              <option value="<?= $k ?>" <?= ($edite['type'] ?? 'info') === $k ? 'selected' : '' ?>><?= $lib ?></option>
            <?php endforeach; ?>
          </select>
        </div>


        <div class="col-12">
          <label class="form-label" for="notif_message">Message</label>
          <textarea class="form-control" id="notif_message" name="notif_message" rows="3" required><?= $h($edite['message'] ?? '') ?></textarea>
        </div>

        <div class="col-md-4">
          <label class="form-label" for="notif_annee">Édition concernée</label>
          <select class="form-select" id="notif_annee" name="notif_annee">
            <option value="">Toutes les éditions</option>
            <?php foreach ($editions as $a): ?>
              <option value="<?= (int) $a ?>"
                <?= (string) ($edite['annee'] ?? '') === (string) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="notif_publie">Publier à partir de</label>
          <input type="datetime-local" class="form-control" id="notif_publie" name="notif_publie"
                 value="<?= !empty($edite['publie_at'])
                       ? $h(date('Y-m-d\TH:i', strtotime((string) $edite['publie_at']))) : '' ?>">
          <small class="text-muted">Vide = tout de suite.</small>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="notif_expire">Ne plus afficher après</label>
          <input type="datetime-local" class="form-control" id="notif_expire" name="notif_expire"
                 value="<?= !empty($edite['expire_at'])
                       ? $h(date('Y-m-d\TH:i', strtotime((string) $edite['expire_at']))) : '' ?>">
          <small class="text-muted">Vide = sans fin.</small>
        </div>

        <div class="col-md-6">
          <?php /* « Active » a disparu d'ici : créer un message, c'est
                   l'activer. La case n'offrait qu'une façon de créer quelque
                   chose d'invisible sans le vouloir. Le bouton pause de la
                   liste sert à le retirer ensuite — ça, c'est un vrai geste. */ ?>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="notif_dans_app" name="notif_dans_app"
                   <?= ($edite === null || !empty($edite['afficher_dans_app'])) ? 'checked' : '' ?>>
            <label class="form-check-label" for="notif_dans_app">
              Garder dans les messages de l'application
            </label>
          </div>
          <small class="text-muted">
            Décoché, le message ne servira qu'à un envoi ponctuel sur les téléphones.
          </small>
        </div>
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="notif_epingle" name="notif_epingle"
                   <?= !empty($edite['epingle']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="notif_epingle">
              Épingler sur l'écran d'accueil
            </label>
          </div>
          <small class="text-muted">Pour ce qu'on relit la veille : rendez-vous, parking, dossards.</small>
        </div>

        <div class="col-12">
          <button type="submit" name="save_notif" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i>
            <?= $edite !== null ? 'Enregistrer' : 'Créer le message' ?>
          </button>
          <?php if ($edite !== null): ?>
            <a href="applications.php" class="btn btn-outline-secondary">Annuler</a>
          <?php endif; ?>
          <?php /* L'envoi est SÉPARÉ de l'enregistrement, et c'est tout
                   l'intérêt : on écrit, on relit, puis on envoie. En un seul
                   bouton, corriger une faute de frappe referait sonner mille
                   téléphones. */ ?>
          <span class="text-muted small ms-2">
            L'envoi sur les téléphones se fait depuis la liste, une fois le message relu.
          </span>
        </div>
      </form>
      <?php endif; ?>

      <h3 class="h6 fw-bold mb-2">Notifications existantes</h3>
      <?php if (!$liste): ?>
        <p class="text-muted small mb-0">
          Aucune notification. Celles que vous créerez apparaîtront ici, avec leur état réel.
        </p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table fer-table table-sm align-middle">
            <thead>
              <tr><th>Titre</th><th>Édition</th><th>Fenêtre</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($liste as $n): $etat = notif_etat($n); ?>
              <tr>
                <td>
                  <strong><?= $h($n['titre']) ?></strong>
                  <?php if ((int) $n['epingle'] === 1): ?>
                    <i class="bi bi-pin-angle-fill text-primary" title="Épinglé"></i>
                  <?php endif; ?>
                  <div class="text-muted small"><?= $h(mb_substr((string) $n['message'], 0, 90)) ?></div>
                  <?php /* La TRACE d'envoi, sous le titre. Sans elle, on ne sait
                           pas si l'envoi a eu lieu — et on renvoie « au cas où »,
                           ce qui fait sonner deux fois ceux qui l'ont déjà reçu. */ ?>
                  <?php if (!empty($n['envoye_at'])): ?>
                    <div class="small text-success">
                      <i class="bi bi-bell-fill me-1"></i>
                      Envoyée le <?= $h(date('d/m à H:i', strtotime((string) $n['envoye_at']))) ?>
                      à <?= (int) $n['envoye_a'] ?> appareil(s)
                    </div>
                  <?php endif; ?>
                </td>
                <td class="small"><?= $n['annee'] === null ? 'Toutes' : (int) $n['annee'] ?></td>
                <td class="small text-muted">
                  <?= !empty($n['publie_at']) ? $h(date('d/m H:i', strtotime((string) $n['publie_at']))) : 'immédiat' ?>
                  →
                  <?= !empty($n['expire_at']) ? $h(date('d/m H:i', strtotime((string) $n['expire_at']))) : 'sans fin' ?>
                </td>
                <?php /* L'état RÉEL, pas la simple colonne `active` : une
                         notification active mais programmée pour demain n'est
                         visible chez personne. */ ?>
                <td><span class="badge bg-<?= $etat['couleur'] ?>"><?= $h($etat['libelle']) ?></span></td>
                <td class="text-end text-nowrap">
                  <?php if ($canWrite): ?>
                    <?php /* Le bouton qui fait sonner. Désactivé tant que
                             Firebase n'est pas configuré : proposer un envoi qui
                             ne partira pas est pire que ne rien proposer. */ ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <button type="submit" name="envoyer_notif" value="<?= (int) $n['id'] ?>"
                              class="btn btn-sm <?= empty($n['envoye_at']) ? 'btn-primary' : 'btn-outline-primary' ?>"
                              <?= $fcm['pret'] ? '' : 'disabled title="Firebase n\'est pas configuré"' ?>
                              data-confirm="<?= empty($n['envoye_at'])
                                  ? 'Envoyer sur les téléphones ? ' . (int) $nbJoignables . ' appareil(s) recevront une notification.'
                                  : 'Cette notification a DÉJÀ été envoyée. La renvoyer fera sonner une seconde fois ceux qui l\'ont reçue. Continuer ?' ?>">
                        <i class="bi bi-send"></i>
                      </button>
                    </form>
                    <a href="?modifier=<?= (int) $n['id'] ?>" class="btn btn-sm btn-outline-secondary">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <button type="submit" name="basculer_notif" value="<?= (int) $n['id'] ?>"
                              class="btn btn-sm btn-outline-secondary"
                              title="<?= (int) $n['active'] === 1 ? 'Désactiver' : 'Activer' ?>">
                        <i class="bi bi-<?= (int) $n['active'] === 1 ? 'pause' : 'play' ?>"></i>
                      </button>
                    </form>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <button type="submit" name="supprimer_notif" value="<?= (int) $n['id'] ?>"
                              class="btn btn-sm btn-outline-danger"
                              data-confirm="Supprimer définitivement cette notification ?">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function () {
  /* L'heure de départ se saisit en deux champs (date + heure) mais se stocke en
     une seule valeur. On les recompose à l'envoi : un champ `time` isolé ne
     dirait pas de quel jour il parle, et le serveur enregistrerait 1970. */
  var date = document.getElementById('course_date');
  var heure = document.getElementById('course_heure_h');
  var cache = document.getElementById('course_heure');
  if (!date || !heure || !cache) return;

  cache.form.addEventListener('submit', function () {
    cache.value = (date.value && heure.value) ? date.value + ' ' + heure.value : '';
  });
})();
</script>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>
</body>
</html>
