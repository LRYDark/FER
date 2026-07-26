<?php
/**
 * compte.php — Mon compte : export RGPD et suppression (lot 3).
 *
 * ⚠️ LA SUPPRESSION N'EFFACE PAS L'INSCRIPTION À LA COURSE. L'association doit
 * conserver ses inscriptions pour sa comptabilité. On anonymise le COMPTE EN
 * LIGNE : c'est l'accès qui disparaît, pas la participation.
 *
 * `email_chiffre` et `email_hmac` sont NOT NULL, et l'empreinte est UNIQUE :
 * on ne peut donc pas les vider. On les remplace par une valeur déterministe et
 * non réutilisable, bâtie sur le TLD .invalid, réservé par la RFC 2606 — cette
 * adresse ne pourra jamais recevoir de mail, ni être revendiquée.
 *
 * Conséquence assumée, et annoncée à l'écran : se reconnecter plus tard avec la
 * même adresse crée un NOUVEAU compte, qui retrouvera les inscriptions par
 * correspondance d'email.
 */
define('FER_SESSION_COUREUR', true);
require '../../src/core/config.php';
checkMaintenance();
require_once '../../src/security/csrf.php';
require_once '../../src/auth/participant_auth.php';

pauth_require($pdo, 'compte.php');

$moiId  = pauth_id();
$erreur = '';

/* ── Export RGPD : tout ce que le site détient sur ce compte ─────────────── */
if (isset($_GET['export'])) {
    $p = $pdo->prepare('SELECT * FROM participants WHERE id = ?');
    $p->execute([$moiId]);
    $compte = $p->fetch(PDO::FETCH_ASSOC) ?: [];

    $d = $pdo->prepare('SELECT id, type, libelle, plateforme, modele, ip_creation, user_agent,
                               derniere_utilisation, expires_at, revoque_at, created_at
                          FROM participant_devices WHERE participant_id = ? ORDER BY id');
    $d->execute([$moiId]);

    $export = [
        'genere_le' => date('c'),
        'compte' => [
            'email'                => decrypt($compte['email_chiffre'] ?? null),
            'nom'                  => $compte['nom'] ?? null,
            'prenom'               => $compte['prenom'] ?? null,
            'actif'                => (bool) ($compte['is_active'] ?? false),
            'consentement_le'      => $compte['rgpd_consent_at'] ?? null,
            'consentement_version' => $compte['rgpd_consent_version'] ?? null,
            'derniere_connexion'   => $compte['derniere_connexion'] ?? null,
            'cree_le'              => $compte['created_at'] ?? null,
        ],
        'inscriptions' => array_map(fn($r) => [
            'edition'        => (int) $r['annee'],
            'inscription_no' => $r['inscription_no'],
            'nom'            => $r['nom'],
            'prenom'         => $r['prenom'],
            'ville'          => $r['ville'],
            'sexe'           => $r['sexe'],
            'naissance'      => $r['naissance'],
            'tshirt'         => $r['tshirt_size'],
            'equipe'         => $r['entreprise'],
            'montant_du'     => $r['montant_du'],
            'rattachee_le'   => $r['_revendique_at'],
            'rattachement'   => $r['_origine'],
        ], pauth_registrations($pdo, $moiId)),
        'appareils' => $d->fetchAll(PDO::FETCH_ASSOC),
        'note' => "Les inscriptions listées appartiennent à l'association (obligation "
                . "comptable) ; elles sont reproduites ici parce qu'elles vous concernent.",
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mes-donnees-forbach-en-rose-' . date('Y-m-d') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── Thème d'affichage ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    } else {
        $t = (string) $_POST['theme'];
        if (!in_array($t, PAUTH_THEMES, true)) $t = 'light';
        $pdo->prepare('UPDATE participants SET theme = ? WHERE id = ?')->execute([$t, $moiId]);
        $_SESSION[PAUTH_SESSION_KEY]['theme'] = $t;
        // Rechargement : le thème est posé dans le <head>, avant tout rendu.
        header('Location: compte.php?theme_ok=1');
        exit;
    }
}

/* ── Suppression du compte ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supprimer'])) {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    } elseif (trim((string) ($_POST['confirmation'] ?? '')) !== 'SUPPRIMER') {
        $erreur = "Saisissez SUPPRIMER en majuscules pour confirmer.";
    } else {
        try {
            $pdo->beginTransaction();

            // Adresse de remplacement : déterministe, unique par id, et sur un TLD
            // qui ne peut pas exister. Aucune collision possible, aucun mail possible.
            $bidon = 'supprime-' . $moiId . '@invalid.local';
            $pdo->prepare(
                'UPDATE participants
                    SET email_chiffre = ?, email_hmac = ?, nom = NULL, prenom = NULL, is_active = 0
                  WHERE id = ?'
            )->execute([encrypt($bidon), fer_emailHmac($bidon), $moiId]);

            // Tous les appareils tombent : plus aucun accès, ni web ni application.
            $pdo->prepare('UPDATE participant_devices SET revoque_at = NOW()
                            WHERE participant_id = ? AND revoque_at IS NULL')->execute([$moiId]);

            // Les rattachements disparaissent — équivalent du participant_id remis à
            // NULL. Les INSCRIPTIONS elles-mêmes ne sont pas touchées : elles gardent
            // leur email et restent valables pour la course.
            $pdo->prepare('DELETE FROM participant_registrations WHERE participant_id = ?')
                ->execute([$moiId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[PAUTH] suppression de compte : ' . $e->getMessage());
            $erreur = "La suppression a échoué. Réessayez, ou contactez l'organisation.";
        }

        if ($erreur === '') {
            pauth_logout($pdo);
            header('Location: login.php?supprime=1');
            exit;
        }
    }
}

$moi = $_SESSION[PAUTH_SESSION_KEY];
$nb  = count(pauth_registrations($pdo, $moiId));
$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mon compte</title>
<link rel="stylesheet" href="../../css/tokens.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

<div class="ec-page">
  <h1 class="ec-h1">Mon compte</h1>
  <p class="ec-sub">Vos informations, vos données, et la sortie si vous le souhaitez.</p>

  <?php if ($erreur !== ''): ?>
    <div class="ec-alert ec-err"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($erreur) ?></div>
  <?php endif; ?>

  <div class="ec-card">
    <dl class="ec-dl">
      <dt>Adresse email</dt><dd><?= $h($moi['email'] ?? '') ?></dd>
      <dt>Nom</dt><dd><?= $h($moi['nom'] ?? '') ?: '—' ?></dd>
      <dt>Prénom</dt><dd><?= $h($moi['prenom'] ?? '') ?: '—' ?></dd>
      <dt>Inscriptions</dt><dd><?= (int) $nb ?></dd>
    </dl>
    <div class="ec-meta" style="margin-top:12px">
      <i class="bi bi-info-circle me-1"></i>
      Nom et prénom proviennent de votre inscription. Pour les corriger, contactez
      l'organisation&nbsp;: ce sont les données officielles de la course.
    </div>
  </div>

  <h2 class="ec-h2"><i class="bi bi-palette"></i>Apparence</h2>
  <div class="ec-card">
    <?php if (isset($_GET['theme_ok'])): ?>
      <div class="ec-alert ec-ok" style="margin-bottom:12px">
        <i class="bi bi-check-circle me-1"></i>Thème enregistré.
      </div>
    <?php endif; ?>
    <p class="ec-meta" style="margin:0 0 12px">
      Le thème de votre espace coureur. Il vous suit d'un appareil à l'autre&nbsp;:
      il est enregistré avec votre compte, pas seulement dans ce navigateur.
    </p>
    <?php $themeActuel = $_SESSION[PAUTH_SESSION_KEY]['theme'] ?? 'light'; ?>
    <form method="post" class="ec-theme-seg" id="ecThemeSeg">
      <?= csrf_field() ?>
      <?php foreach ([
            'light'  => ['Clair',   'bi-sun'],
            'dark'   => ['Sombre',  'bi-moon-stars'],
            'system' => ['Système', 'bi-circle-half'],
          ] as $val => [$lib, $ico]): ?>
        <button type="submit" name="theme" value="<?= $val ?>"
                class="<?= $themeActuel === $val ? 'is-active' : '' ?>">
          <i class="bi <?= $ico ?>"></i><?= $lib ?>
        </button>
      <?php endforeach; ?>
    </form>
    <p class="ec-meta" style="margin-top:10px">
      « Système » suit le réglage clair/sombre de votre téléphone ou de votre ordinateur.
    </p>
  </div>

  <h2 class="ec-h2"><i class="bi bi-download"></i>Exporter mes données</h2>
  <div class="ec-card">
    <p class="ec-meta" style="margin:0 0 12px">
      Un fichier JSON contenant votre compte, vos inscriptions et vos appareils de
      confiance — tout ce que le site détient vous concernant.
    </p>
    <a class="ec-btn ec-btn-sec" href="?export=1"><i class="bi bi-filetype-json"></i>Télécharger</a>
  </div>

  <h2 class="ec-h2"><i class="bi bi-trash3"></i>Supprimer mon compte</h2>
  <div class="ec-card" style="border-left:4px solid #dc3545">
    <div class="ec-alert ec-warn" style="margin-bottom:14px">
      <strong>Votre inscription à la course reste valable.</strong><br>
      Seul votre accès en ligne disparaît. L'association conserve les inscriptions
      pour sa comptabilité&nbsp;: vous serez toujours attendu au départ, et votre
      t-shirt vous sera remis normalement.
    </div>
    <p class="ec-meta" style="margin:0 0 12px">
      Concrètement&nbsp;: votre adresse est effacée de votre compte, vos
      <?= (int) $nb ?> rattachement(s) sont retirés et tous vos appareils sont révoqués.<br>
      Si vous vous reconnectez un jour avec la même adresse, un <strong>nouveau</strong>
      compte sera créé et retrouvera vos inscriptions.
    </p>
    <form method="post" onsubmit="return confirm('Supprimer définitivement votre compte en ligne ?');">
      <?= csrf_field() ?>
      <label class="ec-meta" for="ecConf" style="display:block;margin-bottom:6px">
        Saisissez <strong>SUPPRIMER</strong> pour confirmer&nbsp;:
      </label>
      <input class="ec-input" id="ecConf" name="confirmation" type="text" required
             autocomplete="off" placeholder="SUPPRIMER"
             style="padding:.6rem .8rem;border:1px solid #cbd5e1;border-radius:.55rem;max-width:220px">
      <div class="ec-actions">
        <button class="ec-btn ec-btn-danger" type="submit" name="supprimer" value="1">
          <i class="bi bi-trash3"></i>Supprimer mon compte
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
