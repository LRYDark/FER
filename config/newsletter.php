<?php
/**
 * Newsletter — helpers d'abonnement, de désabonnement et d'envoi.
 *
 * Table : `newsletter_subscribers` (email UNIQUE, status subscribed|unsubscribed).
 * L'abonnement se fait depuis la section "Rester informé" de la page d'accueil.
 * À la publication d'un article (case "Prévenir les abonnés" cochée), un mail
 * groupé est envoyé à tous les abonnés via sendMail() (BCC).
 */

if (!function_exists('newsletterIsValidEmail')) {

/** Validation simple d'une adresse email. */
function newsletterIsValidEmail(string $email): bool
{
    $email = trim($email);
    return $email !== '' && mb_strlen($email) <= 255
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Abonne une adresse (ou la ré-abonne si elle s'était désabonnée).
 * Retourne ['ok' => bool, 'msg' => string].
 */
function newsletterSubscribe(PDO $pdo, string $email): array
{
    $email = mb_strtolower(trim($email));
    if (!newsletterIsValidEmail($email)) {
        return ['ok' => false, 'msg' => "Adresse email invalide."];
    }
    try {
        // INSERT ... ON DUPLICATE : crée l'abonné, ou le réactive s'il existait déjà
        // (cas d'un ré-abonnement après désabonnement).
        $stmt = $pdo->prepare(
            "INSERT INTO newsletter_subscribers (email, status, created_at)
             VALUES (:e, 'subscribed', NOW())
             ON DUPLICATE KEY UPDATE status = 'subscribed', unsubscribed_at = NULL"
        );
        $stmt->execute(['e' => $email]);
        return ['ok' => true, 'msg' => "Merci ! Votre abonnement à la newsletter est confirmé."];
    } catch (\Throwable $e) {
        error_log('[NEWSLETTER] subscribe: ' . $e->getMessage());
        return ['ok' => false, 'msg' => "Une erreur est survenue. Réessayez plus tard."];
    }
}

/**
 * Désabonne une adresse. Retourne true si une ligne abonnée a bien été désactivée.
 */
function newsletterUnsubscribe(PDO $pdo, string $email): bool
{
    $email = mb_strtolower(trim($email));
    if (!newsletterIsValidEmail($email)) return false;
    try {
        $stmt = $pdo->prepare(
            "UPDATE newsletter_subscribers
                SET status = 'unsubscribed', unsubscribed_at = NOW()
              WHERE email = :e AND status = 'subscribed'"
        );
        $stmt->execute(['e' => $email]);
        return $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        error_log('[NEWSLETTER] unsubscribe: ' . $e->getMessage());
        return false;
    }
}

/** Liste des emails actuellement abonnés. */
function newsletterSubscribedEmails(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT email FROM newsletter_subscribers
              WHERE status = 'subscribed' ORDER BY created_at ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Construit l'URL publique absolue d'une page du dossier /public.
 * Les appelants (inc/news.php, public/newsletter.php) sont à 2 niveaux de
 * profondeur → dirname(dirname(SCRIPT_NAME)) donne la racine applicative.
 */
function newsletterPublicUrl(string $relative): string
{
    $base = function_exists('getAppBaseUrl') ? getAppBaseUrl() : '';
    $root = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($root === '/' || $root === '.' || $root === '\\') $root = '';
    return $base . $root . '/' . ltrim($relative, '/');
}

/**
 * Construit le corps HTML du mail "nouvel article" (injecté dans la section
 * "description" du gabarit mail). Réutilisé pour l'envoi réel ET pour l'aperçu
 * de mail-settings.php → ce qui est prévisualisé = ce qui est envoyé.
 *
 * @param array $article ['id', 'title_article', 'desc_article', 'img_article']
 */
function newsletterArticleMailBody(array $article): string
{
    $id      = (int)($article['id'] ?? 0);
    $title   = trim((string)($article['title_article'] ?? ''));
    $descRaw = (string)($article['desc_article'] ?? '');
    $img     = trim((string)($article['img_article'] ?? ''));

    // Extrait : texte brut tronqué à ~280 caractères
    $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($descRaw)));
    if (mb_strlen($excerpt) > 280) $excerpt = mb_substr($excerpt, 0, 280) . '…';

    $articleUrl     = newsletterPublicUrl('public/news.php?id=' . $id);
    $unsubscribeUrl = newsletterPublicUrl('public/newsletter.php?unsubscribe=1');

    // Image — même logique de repli que la page "actualités" :
    //   1) image dédiée de l'article (img_article)
    //   2) sinon, 1re image trouvée dans le contenu (desc_article)
    //   3) sinon, aucune image
    // L'URL est rendue ABSOLUE (obligatoire dans un e-mail).
    $imgUrl = '';
    if ($img !== '') {
        $imgUrl = newsletterPublicUrl('files/_news/' . rawurlencode($img));
    } elseif ($descRaw !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $descRaw, $m)) {
        $src = trim($m[1]);
        if ($src === '') {
            $imgUrl = '';
        } elseif (preg_match('#^(https?:)?//#i', $src) || stripos($src, 'data:') === 0) {
            $imgUrl = $src;                                   // URL absolue / protocole-relatif / data URI
        } elseif ($src[0] === '/') {
            $imgUrl = (function_exists('getAppBaseUrl') ? getAppBaseUrl() : '') . $src;
        } else {
            // Chemin relatif (ex: ../files/_news/xxx.jpg) → absolu
            $imgUrl = newsletterPublicUrl(preg_replace('#^(\.\./)+#', '', $src));
        }
    }

    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $body  = '<div style="font-family:inherit;">';
    if ($imgUrl !== '') {
        $body .= '<img src="' . $h($imgUrl) . '" alt="' . $h($title) . '" '
              .  'style="width:100%;max-width:520px;height:auto;border-radius:10px;margin-bottom:18px;display:block;">';
    }
    $body .= '<h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 12px;">' . $h($title) . '</h2>';
    if ($excerpt !== '') {
        $body .= '<p style="font-size:15px;line-height:1.7;color:#334155;margin:0 0 22px;">' . $h($excerpt) . '</p>';
    }
    $body .= '<p style="margin:0 0 8px;text-align:center;">'
          .  '<a href="' . $h($articleUrl) . '" '
          .  'style="display:inline-block;background:#F42182;color:#ffffff;text-decoration:none;'
          .  'font-weight:700;font-size:15px;padding:13px 28px;border-radius:10px;">Lire l\'article &rarr;</a>'
          .  '</p></div>';
    // Pied : mention + lien de désabonnement (générique)
    $body .= '<p style="font-size:12px;color:#94a3b8;line-height:1.6;margin:22px 0 0;text-align:center;">'
          .  'Vous recevez cet email car vous êtes abonné à la newsletter de Forbach en Rose.<br>'
          .  '<a href="' . $h($unsubscribeUrl) . '" style="color:#94a3b8;text-decoration:underline;">'
          .  'Se désabonner</a></p>';
    return $body;
}

/**
 * Envoie le mail "nouvel article" à tous les abonnés (envoi groupé en BCC).
 * Retourne le nombre d'abonnés ciblés (0 si aucun envoi).
 */
function newsletterSendNewArticle(PDO $pdo, array $article): int
{
    $emails = newsletterSubscribedEmails($pdo);
    if (empty($emails)) return 0;

    // sendMail() vit dans googleMail.php — chargé à la demande.
    if (!function_exists('sendMail')) {
        $gm = __DIR__ . '/googleMail.php';
        if (is_file($gm)) { try { require_once $gm; } catch (\Throwable $e) {} }
    }
    if (!function_exists('sendMail')) {
        error_log('[NEWSLETTER] sendMail indisponible — envoi annulé.');
        return 0;
    }

    $title = trim((string)($article['title_article'] ?? ''));
    $body  = newsletterArticleMailBody($article);

    try {
        // Sous-type 'new_article' : le gabarit mail applique la visibilité dédiée
        // à ce type (cf. mail_template.php — la section "description" = corps de
        // l'article reste toujours visible). mailTitle vide → pas de doublon de
        // titre (le corps de l'article porte déjà son propre <h2>).
        sendMail($emails, 'Nouvel article — ' . $title, '', $body, null, null, 'bulk', null, 'new_article');
    } catch (\Throwable $e) {
        error_log('[NEWSLETTER] envoi article: ' . $e->getMessage());
        return 0;
    }
    return count($emails);
}

} // function_exists guard
