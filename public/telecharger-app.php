<?php
/**
 * telecharger-app.php — Application mobile & espace coureur (lot 6).
 *
 * ⚠️ CETTE PAGE NE PROMET RIEN QUI N'EXISTE PAS.
 * Tant que les liens des magasins ne sont pas renseignés dans les réglages, elle
 * n'affiche AUCUN bouton de téléchargement : elle présente l'espace coureur —
 * qui, lui, fonctionne aujourd'hui, dans n'importe quel navigateur — et annonce
 * l'application comme « à venir ».
 *
 * Une page qui propose de télécharger une application inexistante fait perdre
 * son temps au coureur et passer l'association pour négligente. Le jour où les
 * liens sont saisis, les boutons apparaissent d'eux-mêmes : rien à modifier ici.
 */
require '../src/core/config.php';
require_once '../src/content/tracker.php';
trackPageVisit();
checkMaintenance();

/* Espace coureur fermé (Réglages → Maintenance) : cette page part avec lui.
 * Elle n'existe que pour y mener — ses deux boutons pointent vers la connexion
 * coureur, et l'application mobile se connecte aux mêmes comptes. La laisser
 * ouverte afficherait une page entière de promesses qui ne mènent nulle part.
 * Le lien du pied de page disparaît en même temps ; cette redirection couvre
 * ceux qui arrivent par un favori ou par le moteur de recherche. */
if (!espace_coureur_actif()) { header('Location: accueil.php'); exit; }

require __DIR__ . '/../src/partials/navbar-data.php';

$storeIos     = trim((string) ($data['app_store_url_ios'] ?? ''));
$storeAndroid = trim((string) ($data['app_store_url_android'] ?? ''));
$appDispo     = $storeIos !== '' || $storeAndroid !== '';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Espace coureur & application — Forbach en Rose</title>
  <meta name="description" content="Retrouvez votre inscription, votre QR code et vos informations
        de course dans votre espace coureur Forbach en Rose.">
  <link rel="stylesheet" href="../css/fer-modern.css">
<?php include __DIR__ . '/../src/content/theme.php'; ?>
<style>
  .app-hero { text-align: center; padding: clamp(2.5rem, 8vw, 5rem) 1.25rem 2rem; }
  .app-hero h1 { font-size: clamp(1.75rem, 5vw, 2.6rem); margin: 0 0 .75rem; }
  .app-hero p  { max-width: 46ch; margin: 0 auto; opacity: .8; line-height: 1.7; }

  .app-wrap { max-width: 980px; margin: 0 auto; padding: 0 1.25rem 4rem; }

  /* Grille : une colonne sur téléphone, deux dès qu'il y a la place. */
  .app-grid { display: grid; gap: 1.25rem; margin-top: 2.5rem; }
  @media (min-width: 780px) { .app-grid { grid-template-columns: 1fr 1fr; } }

  .app-card {
    border: 1px solid var(--border, #e2e8f0); border-radius: 16px;
    padding: 1.75rem; background: var(--surface, #fff);
  }
  .app-card h2 { font-size: 1.15rem; margin: 0 0 .5rem; display: flex; align-items: center; gap: .6rem; }
  .app-card p  { margin: 0 0 1rem; line-height: 1.7; opacity: .82; font-size: .95rem; }
  .app-card ul { margin: 0 0 1.25rem; padding-left: 1.15rem; line-height: 1.9; font-size: .95rem; opacity: .82; }

  .app-btn {
    display: inline-block; padding: .8rem 1.5rem; border-radius: 10px;
    text-decoration: none; font-weight: 600; font-size: .95rem;
  }
  .app-btn-primary { background: var(--fer-pink, #F42182); color: #fff; }
  .app-btn-ghost   { border: 1px solid var(--border, #e2e8f0); color: inherit; }
  .app-stores { display: flex; flex-wrap: wrap; gap: .75rem; }

  .app-soon {
    display: inline-block; font-size: .78rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; padding: .25rem .7rem; border-radius: 999px;
    background: rgba(148,163,184,.18); color: #64748b;
  }
  .app-note { margin-top: 2.5rem; font-size: .88rem; opacity: .7; line-height: 1.8; text-align: center; }
  .app-steps { counter-reset: etape; list-style: none; padding: 0; margin: 0; }
  .app-steps li {
    counter-increment: etape; position: relative; padding-left: 2.4rem;
    margin-bottom: 1rem; line-height: 1.7; font-size: .95rem; opacity: .85;
  }
  .app-steps li::before {
    content: counter(etape); position: absolute; left: 0; top: -.1rem;
    width: 1.7rem; height: 1.7rem; border-radius: 50%;
    background: var(--fer-pink, #F42182); color: #fff;
    display: grid; place-items: center; font-size: .8rem; font-weight: 700;
  }
</style>
</head>
<body>
  <?php include __DIR__ . '/../src/partials/preloader.php'; ?>
  <?php include __DIR__ . '/../src/partials/navbar-modern.php'; ?>

  <main>
    <section class="app-hero">
      <h1>Votre espace coureur</h1>
      <p>
        Votre inscription, votre QR code et vos informations de course,
        accessibles à tout moment — sans mot de passe à retenir.
      </p>
    </section>

    <div class="app-wrap">
      <div class="app-grid">

        <!-- Ce qui existe aujourd'hui -->
        <section class="app-card">
          <h2>🌐 Depuis votre navigateur</h2>
          <p>
            Rien à installer. Ça fonctionne sur téléphone, tablette et ordinateur,
            <strong>dès maintenant</strong>.
          </p>
          <ul>
            <li>Votre QR code, à présenter au retrait des t-shirts</li>
            <li>Le détail de votre inscription</li>
            <li>Corriger votre nom, votre âge ou votre adresse email</li>
            <li>Transférer votre inscription à quelqu'un d'autre</li>
            <li>Vos éditions précédentes</li>
          </ul>
          <a class="app-btn app-btn-primary" href="espace-coureur/login.php">
            Accéder à mon espace
          </a>
        </section>

        <!-- L'application -->
        <section class="app-card">
          <h2>
            📱 L'application mobile
            <?php if (!$appDispo): ?><span class="app-soon">à venir</span><?php endif; ?>
          </h2>

          <?php if ($appDispo): ?>
            <p>
              Tout ce que fait votre espace en ligne, plus le suivi de votre course
              le jour J.
            </p>
            <div class="app-stores">
              <?php if ($storeIos !== ''): ?>
                <a class="app-btn app-btn-ghost" href="<?= $h($storeIos) ?>"
                   rel="noopener" target="_blank">Télécharger pour iPhone</a>
              <?php endif; ?>
              <?php if ($storeAndroid !== ''): ?>
                <a class="app-btn app-btn-ghost" href="<?= $h($storeAndroid) ?>"
                   rel="noopener" target="_blank">Télécharger pour Android</a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <?php /* Aucun lien de magasin renseigné : on n'invente pas de bouton.
                     Le jour où les liens sont saisis dans les réglages, cette
                     branche disparaît d'elle-même. */ ?>
            <p>
              Elle arrive. Elle apportera le suivi de votre course le jour J —
              quelque chose qu'une page web ne peut pas faire, car elle s'arrête
              dès que l'écran s'éteint.
            </p>
            <p>
              En attendant, <strong>votre espace en ligne fait déjà tout le reste</strong>,
              et il ne prend aucune place sur votre téléphone.
            </p>
            <a class="app-btn app-btn-ghost" href="espace-coureur/login.php">
              Utiliser l'espace en ligne
            </a>
          <?php endif; ?>
        </section>
      </div>

      <!-- Comment se connecter -->
      <section class="app-card" style="margin-top:1.25rem">
        <h2>🔑 Comment se connecter</h2>
        <p>Pas de mot de passe : un code à six chiffres, valable quelques minutes.</p>
        <ol class="app-steps">
          <li>Saisissez l'adresse email utilisée lors de votre inscription à la course.</li>
          <li>Un code à six chiffres vous est envoyé par email.</li>
          <li>Vous le recopiez, et vous voilà connecté.</li>
        </ol>
        <p style="margin-bottom:0">
          Vous pouvez demander à rester connecté sur votre appareil, et le déconnecter
          à distance depuis « Mes appareils » si vous le perdez.
        </p>
      </section>

      <p class="app-note">
        Une question&nbsp;? Consultez la <a href="faq.php">foire aux questions</a>
        ou <a href="contact.php">contactez-nous</a>.
      </p>
    </div>
  </main>

  <?php include __DIR__ . '/../src/partials/footer-modern.php'; ?>
</body>
</html>
