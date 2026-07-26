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

/* ── Apparence : thème et couleur d'accent ───────────────────────────────────
 * Les deux sont attachés au COMPTE, pas au navigateur : ils suivent le coureur
 * d'un appareil à l'autre. Rechargement après enregistrement, car thème et
 * accent sont posés dans le <head>, avant tout rendu. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    } else {
        $t = (string) $_POST['theme'];
        if (!in_array($t, PAUTH_THEMES, true)) $t = 'light';
        $pdo->prepare('UPDATE participants SET theme = ? WHERE id = ?')->execute([$t, $moiId]);
        $_SESSION[PAUTH_SESSION_KEY]['theme'] = $t;
        header('Location: compte.php?apparence_ok=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accent'])) {
    if (!csrf_verify()) {
        $erreur = "Session expirée. Rechargez la page et réessayez.";
    } else {
        $a = (string) $_POST['accent'];
        if (!in_array($a, PAUTH_ACCENTS, true)) $a = 'rose';

        // La couleur libre n'est retenue que si elle est bien un code
        // hexadécimal : elle finit dans une variable CSS, pas question d'y
        // laisser passer une valeur arbitraire.
        $custom = trim((string) ($_POST['accent_custom'] ?? ''));
        if ($a === 'custom' && !preg_match('/^#[0-9a-fA-F]{6}$/', $custom)) {
            $erreur = "Couleur invalide. Choisissez-la avec le sélecteur.";
        } else {
            $custom = $a === 'custom' ? strtolower($custom) : null;
            $pdo->prepare('UPDATE participants SET accent = ?, accent_custom = ? WHERE id = ?')
                ->execute([$a, $custom, $moiId]);
            $_SESSION[PAUTH_SESSION_KEY]['accent']        = $a;
            $_SESSION[PAUTH_SESSION_KEY]['accent_custom'] = $custom;
            header('Location: compte.php?apparence_ok=1');
            exit;
        }
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

$ecTitre    = 'Mon compte';
$ecSurtitre = 'Vos informations et vos données';

$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Espace coureur — Mon compte</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php include __DIR__ . '/_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_layout-haut.php'; ?>

  <?php if ($erreur !== ''): ?>
    <div class="alert is-danger"><i class="bi bi-exclamation-triangle"></i> <?= $h($erreur) ?></div>
  <?php endif; ?>

  <section class="card">
    <header>
      <div class="iconwell"><i class="bi bi-person"></i></div>
      <h2>Mes informations</h2>
    </header>
    <dl class="ec-dl">
      <dt>Adresse email</dt><dd><?= $h($moi['email'] ?? '') ?></dd>
      <dt>Nom</dt><dd><?= $h($moi['nom'] ?? '') ?: '—' ?></dd>
      <dt>Prénom</dt><dd><?= $h($moi['prenom'] ?? '') ?: '—' ?></dd>
      <dt>Inscriptions</dt><dd><?= (int) $nb ?></dd>
    </dl>
    <p style="font-size:var(--fs-micro);color:var(--ink-faint);margin:0">
      <i class="bi bi-info-circle"></i>
      Nom et prénom proviennent de votre inscription. Pour les corriger, contactez
      l'organisation&nbsp;: ce sont les données officielles de la course.
    </p>
  </section>

  <section class="card">
    <header>
      <div class="iconwell"><i class="bi bi-palette"></i></div>
      <h2>Apparence</h2>
    </header>

    <?php if (isset($_GET['apparence_ok'])): ?>
      <div class="alert is-ok"><i class="bi bi-check-circle"></i> Apparence enregistrée.</div>
    <?php endif; ?>

    <p style="font-size:var(--fs-small);color:var(--ink-dim);margin:0">
      Thème et couleur sont enregistrés <strong>avec votre compte</strong>&nbsp;: ils vous
      suivent d'un appareil à l'autre, pas seulement dans ce navigateur.
    </p>

    <?php
      $themeActuel  = $_SESSION[PAUTH_SESSION_KEY]['theme'] ?? 'light';
      $accentActuel = $_SESSION[PAUTH_SESSION_KEY]['accent'] ?? 'rose';
      $accentCustom = $_SESSION[PAUTH_SESSION_KEY]['accent_custom'] ?? '#db2777';
    ?>

    <div class="field">
      <label>Thème</label>
      <form method="post" class="seg" style="align-self:flex-start">
        <?= csrf_field() ?>
        <?php foreach ([
              'light'  => ['Clair',   'bi-sun'],
              'dark'   => ['Sombre',  'bi-moon-stars'],
              'system' => ['Système', 'bi-circle-half'],
            ] as $val => [$lib, $ico]): ?>
          <button type="submit" name="theme" value="<?= $val ?>"
                  class="<?= $themeActuel === $val ? 'is-active' : '' ?>">
            <i class="bi <?= $ico ?>"></i> <?= $lib ?>
          </button>
        <?php endforeach; ?>
      </form>
      <span style="font-size:var(--fs-micro);color:var(--ink-faint)">
        « Système » suit le réglage clair/sombre de votre téléphone ou de votre ordinateur.
      </span>
    </div>

    <?php /* Mêmes palettes que le profil d'administration : elles viennent de
             css/tokens.css, il n'y a pas de seconde définition à tenir à jour.
             « Rose » suit la couleur primaire du site — c'est le défaut. */ ?>
    <div class="field">
      <label>Couleur d'accent</label>
      <form method="post" class="ec-accents">
        <?= csrf_field() ?>
        <?php foreach ([
              'rose'    => ['Rose (site)', '#db2777'],
              'blue'    => ['Bleu',        '#3D63F0'],
              'teal'    => ['Teal',        '#0FA894'],
              'violet'  => ['Violet',      '#7C5CF6'],
              'emerald' => ['Émeraude',    '#10B981'],
            ] as $val => [$lib, $apercu]): ?>
          <button type="submit" name="accent" value="<?= $val ?>"
                  class="ec-accent <?= $accentActuel === $val ? 'is-active' : '' ?>">
            <span class="dot" style="background:<?= $apercu ?>"></span><?= $lib ?>
          </button>
        <?php endforeach; ?>

        <label class="ec-accent <?= $accentActuel === 'custom' ? 'is-active' : '' ?>">
          <span class="dot" style="background:<?= htmlspecialchars($accentCustom) ?>"></span>
          Personnalisée
          <?php /* La soumission part au choix de la couleur : pas de bouton
                   « valider » supplémentaire pour un réglage d'affichage. */ ?>
          <input type="color" name="accent_custom" value="<?= htmlspecialchars($accentCustom) ?>"
                 onchange="this.form.querySelector('[name=accent][type=hidden]').value='custom';this.form.submit();">
          <input type="hidden" name="accent" value="custom">
        </label>
      </form>
    </div>
  </section>

  <section class="card">
    <header>
      <div class="iconwell"><i class="bi bi-download"></i></div>
      <h2>Exporter mes données</h2>
    </header>
    <p style="font-size:var(--fs-small);color:var(--ink-dim);margin:0">
      Un fichier JSON contenant votre compte, vos inscriptions et vos appareils de
      confiance — tout ce que le site détient vous concernant.
    </p>
    <div class="row-actions">
      <a class="btn" href="?export=1"><i class="bi bi-filetype-json"></i> Télécharger</a>
    </div>
  </section>

  <section class="card">
    <header>
      <div class="iconwell" style="background:var(--danger-soft);color:var(--danger)">
        <i class="bi bi-trash3"></i>
      </div>
      <h2>Supprimer mon compte</h2>
    </header>

    <div class="alert is-warn">
      <strong>Votre inscription à la course reste valable.</strong>
      Seul votre accès en ligne disparaît. L'association conserve les inscriptions
      pour sa comptabilité&nbsp;: vous serez toujours attendu au départ, et votre
      t-shirt vous sera remis normalement.
    </div>

    <p style="font-size:var(--fs-small);color:var(--ink-dim);margin:0">
      Concrètement&nbsp;: votre adresse est effacée de votre compte, vos
      <?= (int) $nb ?> rattachement(s) sont retirés et tous vos appareils sont révoqués.
      Si vous vous reconnectez un jour avec la même adresse, un <strong>nouveau</strong>
      compte sera créé et retrouvera vos inscriptions.
    </p>

    <form method="post" onsubmit="return confirm('Supprimer définitivement votre compte en ligne ?');">
      <?= csrf_field() ?>
      <div class="field" style="max-width:260px">
        <label for="ecConf">Saisissez <strong>SUPPRIMER</strong> pour confirmer</label>
        <input class="input" id="ecConf" name="confirmation" type="text" required
               autocomplete="off" placeholder="SUPPRIMER">
      </div>
      <?php /* Le bouton respire : collé au champ, il invitait à cliquer avant
               d'avoir fini de saisir la confirmation. */ ?>
      <div class="row-actions" style="margin-top:var(--sp-4)">
        <button class="btn btn-danger" type="submit" name="supprimer" value="1">
          <i class="bi bi-trash3"></i> Supprimer mon compte
        </button>
      </div>
    </form>
  </section>

<?php include __DIR__ . '/_layout-bas.php'; ?>
</body>
</html>
