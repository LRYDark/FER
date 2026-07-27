<?php
/**
 * CONTRÔLE D'INTÉGRITÉ — rien d'oublié, rien de cassé.
 *
 * Ce banc ne teste pas une fonctionnalité : il vérifie que l'ENSEMBLE se tient.
 * Il attrape la classe de bug que les tests unitaires laissent passer — un
 * écran ajouté au menu mais dont le fichier n'existe pas, une page qui emploie
 * des classes CSS non servies, une permission référencée mais absente du
 * catalogue, un lien mort dans une réponse du chatbot.
 *
 * Il ne demande aucune base de données.
 */
$ok = 0; $ko = 0; $avert = 0;
function verifie(string $titre, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  OK   $titre\n"; }
    else       { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function avertir(string $texte): void { global $avert; $avert++; echo "  ⚠  $texte\n"; }

$R    = 'W:/FER/';
$lire = fn(string $f) => (string) @file_get_contents($R . $f);

/* ── 1. Tous les fichiers PHP compilent ─────────────────────────────────── */
echo "\n=== 1. Compilation ===\n";
$php = 'C:/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe';
$fichiers = [];
foreach (['', 'inc/', 'src/core/', 'src/auth/', 'src/content/', 'src/mail/', 'src/partials/',
          'public/', 'public/espace-coureur/', 'api/v1/'] as $d) {
    foreach (glob($R . $d . '*.php') ?: [] as $f) $fichiers[] = $f;
}
$casses = [];
foreach ($fichiers as $f) {
    $out = (string) shell_exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f) . ' 2>&1');
    if (!str_contains($out, 'No syntax errors')) $casses[] = basename($f) . ' : ' . trim($out);
}
verifie(count($fichiers) . ' fichiers PHP compilent', empty($casses), implode(' | ', $casses));

/* ── 2. Le menu d'administration ne pointe que vers des pages existantes ── */
echo "\n=== 2. Menu d'administration ===\n";
$nav = $lire('src/partials/navbar-admin.php');
preg_match_all("/\['([a-z0-9\-_]+\.php)(?:\?[^']*)?',\s*'/i", $nav, $m);
$manquants = [];
foreach (array_unique($m[1]) as $page) {
    if (!is_file($R . 'inc/' . $page) && !is_file($R . $page)) $manquants[] = $page;
}
verifie('toutes les entrées de menu mènent à un fichier', empty($manquants), implode(', ', $manquants));

/* Chaque page du menu doit avoir un titre : sinon elle s'affiche « Administration ». */
preg_match_all("/'([a-z0-9\-_]+\.php)'\s*=>\s*['\"]/i", $nav, $mt);
$titres = $mt[1];
$sansTitre = array_values(array_diff(array_unique($m[1]), $titres, ['dashboard.php']));
if ($sansTitre) avertir('pages sans titre déclaré (afficheront « Administration ») : ' . implode(', ', $sansTitre));
else            verifie('chaque page du menu a un titre déclaré', true);

/* ── 3. Les écrans admin n'emploient que des classes servies côté admin ─── */
echo "\n=== 3. Cohérence CSS des écrans d'administration ===\n";
/* navbar-admin.php ne sert que tokens.css, base.css et admin.css. Les classes
   maison de l'espace coureur (components.css) n'y existent pas — et `.row` est
   même la grille flex de Bootstrap, ce qui CASSE la mise en page au lieu de
   simplement ne rien faire. */
/* Chaînes EXACTES, attribut compris : chercher « row-actions » tout court
   attrapait `.ife-row-actions`, une classe locale de setting.php qui n'a rien
   à voir. Un contrôle qui crie au loup finit par être ignoré. */
$interdites = ['class="iconwell"', 'class="pill', 'class="rows"', 'class="seg"',
               'class="empty"', 'class="stat"', 'class="row-actions"'];
$fautives = [];
foreach (glob($R . 'inc/*.php') ?: [] as $f) {
    $s = (string) file_get_contents($f);
    if (!str_contains($s, 'navbar-admin.php')) continue;
    foreach ($interdites as $cls) {
        if (str_contains($s, $cls)) $fautives[] = basename($f) . ' → ' . $cls;
    }
}
verifie('aucun écran admin n\'emploie les classes de l\'espace coureur',
    empty($fautives), implode(' | ', $fautives));

$adminCss = $lire('css/admin.css');
verifie('.page-header est défini dans admin.css (et non par page)',
    str_contains($adminCss, '.page-header {'));

/* ── 3 bis. Fonction appelée ⇒ fichier chargé ───────────────────────────────
 * LE CONTRÔLE QUI MANQUAIT. Trois écrans appelaient logContentAction() sans
 * charger src/content/content-log.php : « Call to undefined function », fatale
 * au premier clic. Rien ne le signalait — ni la compilation, ni les tests
 * fonctionnels, qui ne passaient pas par ce bouton.
 *
 * On résout les `require` de proche en proche : une page qui charge
 * participant_auth.php obtient regres_* sans avoir à le demander, puisque
 * participant_auth.php charge lui-même le résolveur.
 * ──────────────────────────────────────────────────────────────────────── */
echo "\n=== 3 bis. Fonctions appelées vs fichiers chargés ===\n";

/** Fichiers requis par un fichier donné, de façon transitive. */
function inclusionsDe(string $chemin, array $vus = []): array {
    $chemin = str_replace('\\', '/', (string) realpath($chemin));
    if ($chemin === '' || isset($vus[$chemin])) return $vus;
    $vus[$chemin] = true;
    $src = (string) @file_get_contents($chemin);
    // require ET include : les pieds de page sont insérés avec `include`, et ne
    // pas les suivre faisait croire que confirm-script.php n'était chargé nulle
    // part. Les deux formes __DIR__ . '/x.php' et '../x.php' sont acceptées.
    if (preg_match_all('#(?:require|include)(?:_once)?\s+(?:__DIR__\s*\.\s*)?[\'"]([^\'"]+)[\'"]#', $src, $m)) {
        foreach ($m[1] as $rel) {
            $cible = realpath(dirname($chemin) . '/' . ltrim($rel, '/'));
            if ($cible) $vus = inclusionsDe($cible, $vus);
        }
    }
    return $vus;
}

/* Préfixe de fonction → fichier qui la définit. config.php ne charge aucun
   de ces fichiers : chaque page doit le demander explicitement. */
$fournisseurs = [
    'logContentAction' => 'src/content/content-log.php',
    'fetchContentLogs' => 'src/content/content-log.php',
    'pauth_'           => 'src/auth/participant_auth.php',
    'pprofile_'        => 'src/auth/participant_profile.php',
    'xfer_'            => 'src/content/transfers.php',
    'regres_'          => 'src/core/registrations_resolver.php',
    'chrono_'          => 'src/content/chrono.php',
    'purge_'           => 'src/content/purges.php',
    'fer_qrCode'       => 'src/core/qrcode.php',
    'csrf_verify'      => 'src/security/csrf.php',
    'csrf_field'       => 'src/security/csrf.php',
];

$oublis = [];
foreach (array_merge(glob($R . 'inc/*.php') ?: [],
                     glob($R . 'public/*.php') ?: [],
                     glob($R . 'public/espace-coureur/*.php') ?: [],
                     glob($R . 'api/v1/*.php') ?: []) as $f) {
    // Les fragments (préfixe « _ ») ne sont jamais appelés directement : ils
    // héritent des inclusions de la page qui les insère. Les compter ici
    // produirait des alertes systématiques et sans objet — et un contrôle qui
    // crie au loup finit par être ignoré.
    if (str_starts_with(basename($f), '_')) continue;
    $src = (string) file_get_contents($f);
    $inc = null;
    foreach ($fournisseurs as $prefixe => $definit) {
        // Appel effectif (et non simple mention dans un commentaire)
        if (!preg_match('/\b' . preg_quote($prefixe, '/') . '[A-Za-z_0-9]*\s*\(/', $src)) continue;
        // La fonction peut être définie dans le fichier lui-même
        if (preg_match('/function\s+' . preg_quote($prefixe, '/') . '/', $src)) continue;
        $inc ??= inclusionsDe($f);
        $attendu = str_replace('\\', '/', (string) realpath($R . $definit));
        if ($attendu !== '' && !isset($inc[$attendu])) {
            $oublis[] = basename(dirname($f)) . '/' . basename($f) . ' → ' . $prefixe . '*';
        }
    }
}
verifie('toute fonction appelée a son fichier chargé', empty($oublis), implode(' | ', $oublis));

/* ── 3 ter. Aucun gestionnaire d'événement en ligne ─────────────────────────
 * LA CSP DU SITE LES BLOQUE TOUS. src/core/config.php envoie
 *   script-src 'self' 'nonce-…'   — sans 'unsafe-inline'
 * donc onsubmit=, onclick= et onchange= écrits dans le HTML ne s'exécutent
 * JAMAIS. Et ils échouent en silence : aucune erreur à l'écran, juste une ligne
 * dans la console. Un « êtes-vous sûr ? » disparaît sans que personne le voie.
 *
 * C'est ce qui s'est produit : l'envoi d'un code de connexion partait sans rien
 * demander, tout comme la suppression d'une colonne de la base.
 * ──────────────────────────────────────────────────────────────────────── */
echo "\n=== 3 ter. Gestionnaires en ligne (bloqués par la CSP) ===\n";
verifie('la CSP interdit bien le script en ligne (sinon ce contrôle est inutile)',
    str_contains($cfgSrc = $lire('src/core/config.php'), "script-src 'self' 'nonce-")
    && !preg_match("/script-src[^;]*'unsafe-inline'/", $cfgSrc));

$enLigne = [];
foreach (array_merge(glob($R . 'inc/*.php') ?: [], glob($R . 'public/*.php') ?: [],
                     glob($R . 'public/espace-coureur/*.php') ?: [],
                     glob($R . 'src/partials/*.php') ?: []) as $f) {
    if (basename($f) === 'confirm-script.php') continue;          // il en parle, il n'en pose pas
    foreach (explode("\n", (string) file_get_contents($f)) as $n => $ligne) {
        // Attribut HTML uniquement : « el.onclick = … » en JavaScript est valide
        // et fonctionne parfaitement — c'est l'attribut dans le balisage qui est
        // bloqué. D'où l'exigence d'un espace ou d'un retour à la ligne devant.
        if (preg_match('/(?:^|\s)on(?:submit|click|change|input|load)\s*=\s*["\']/', $ligne)) {
            $enLigne[] = basename($f) . ':' . ($n + 1);
        }
    }
}
verifie('aucun gestionnaire d\'événement en ligne', empty($enLigne), implode(' | ', $enLigne));

/* Le remplaçant doit être chargé partout où on s'en sert. */
$sansScript = [];
foreach (array_merge(glob($R . 'inc/*.php') ?: [],
                     glob($R . 'public/espace-coureur/*.php') ?: []) as $f) {
    $src = (string) file_get_contents($f);
    if (!str_contains($src, 'data-confirm') && !str_contains($src, 'data-autosubmit')) continue;
    $inc = inclusionsDe($f);
    $attendu = str_replace('\\', '/', (string) realpath($R . 'src/partials/confirm-script.php'));
    // Le script est inclus par les pieds de page, eux-mêmes inclus en fin de
    // fichier : inclusionsDe() les suit.
    if (!isset($inc[$attendu])) $sansScript[] = basename($f);
}
verifie('data-confirm n\'est employé que là où le script est chargé',
    empty($sansScript), implode(', ', $sansScript));

/* ── 3 quater. Les retours passent par les toasts, pas par des alertes ───────
 * Le site annonce ses succès et ses erreurs par addToast(), rendu en bas de
 * page. Quatre écrans affichaient à la place un bloc .alert en tête de page :
 * deux façons différentes de dire « c'est enregistré » dans la même
 * application, c'est une de trop, et ça se remarque tout de suite.
 * ──────────────────────────────────────────────────────────────────────── */
echo "\n=== 3 quater. Retours utilisateur : toasts ===\n";
$alertes = [];
foreach (glob($R . 'inc/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    if (!str_contains($src, 'navbar-admin.php')) continue;
    // Alerte pilotée par les variables de retour = ancien mécanisme.
    if (preg_match('/<\?php if \(\$(erreur|succes) !== \x27\x27\): \?>\s*<div class="alert/', $src)) {
        $alertes[] = basename($f);
    }
}
verifie('aucun écran admin n\'affiche ses retours en bloc .alert',
    empty($alertes), implode(', ', $alertes));

/* addToast n'a d'effet que si toast.php est rendu — il l'est par admin-footer. */
$sansToast = [];
foreach (glob($R . 'inc/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    if (!str_contains($src, 'addToast(')) continue;
    if (!isset(inclusionsDe($f)[str_replace('\\', '/', (string) realpath($R . 'src/partials/toast.php'))])) {
        $sansToast[] = basename($f);
    }
}
verifie('addToast n\'est employé que là où toast.php est rendu',
    empty($sansToast), implode(', ', $sansToast));

/* ── 4. Les pages coureur, elles, chargent bien leur feuille ────────────── */
echo "\n=== 4. Cohérence de l'espace coureur ===\n";
$sansStyles = [];
foreach (glob($R . 'public/espace-coureur/*.php') ?: [] as $f) {
    $b = basename($f);
    if (str_starts_with($b, '_') || $b === 'deconnexion.php') continue;
    $s = (string) file_get_contents($f);
    if (!str_contains($s, '_styles.php') && !str_contains($s, 'auth-head.php')) $sansStyles[] = $b;
}
verifie('toutes les pages coureur chargent leur feuille de style',
    empty($sansStyles), implode(', ', $sansStyles));

/* ── 5. Permissions : référencées ⇒ présentes au catalogue ──────────────── */
echo "\n=== 5. Permissions ===\n";
$cfg = $lire('src/core/config.php');
preg_match_all("/canDoAction\('([a-z0-9._]+)'\)/i", implode("\n",
    array_map(fn($f) => (string) file_get_contents($f), glob($R . 'inc/*.php') ?: [])), $mp);
$absentes = [];
foreach (array_unique($mp[1]) as $perm) {
    if (!str_contains($cfg, "'" . $perm . "'")) $absentes[] = $perm;
}
verifie('toute permission utilisée figure au catalogue', empty($absentes), implode(', ', $absentes));

/* ── 6. install.php et update.php restent alignés ───────────────────────── */
echo "\n=== 6. install.php ↔ update.php ===\n";
$inst = $lire('install.php');
$upd  = $lire('update.php');
$colonnes = ['api_v1_enabled', 'app_version_minimale', 'app_access_token_ttl_min',
             'traces_gps_conservation_jours', 'auth_codes_conservation_jours',
             'devices_revoques_jours', 'transferts_clos_jours',
             'traces_consent_at', 'idx_unicite'];
$oubliees = [];
foreach ($colonnes as $c) {
    if (!str_contains($inst, $c)) $oubliees[] = "install:$c";
    if (!str_contains($upd, $c))  $oubliees[] = "update:$c";
}
verifie('les colonnes des lots 5 à 7 sont dans les DEUX chemins',
    empty($oubliees), implode(', ', $oubliees));

/* ── 7. Les liens des réponses du chatbot mènent quelque part ───────────── */
echo "\n=== 7. Liens du chatbot et des mails ===\n";
$bot = $lire('src/content/chatbot-engine.php');
preg_match_all('#href="(/public/[^"]+)"#', $bot, $ml);
$morts = [];
foreach (array_unique($ml[1]) as $u) if (!is_file($R . ltrim($u, '/'))) $morts[] = $u;
verifie('les liens du chatbot mènent à des fichiers existants', empty($morts), implode(', ', $morts));

$tpl = $lire('src/mail/mail_template.php');
verifie('le gabarit d\'email reçoit bien l\'URL de l\'espace coureur',
    str_contains($tpl, '$espace_url') && str_contains($lire('src/mail/googleMail.php'), "'espace_url'"));

/* ── 8. Fichiers interdits : intacts ────────────────────────────────────── */
echo "\n=== 8. Fichiers que la consigne interdit de modifier ===\n";
foreach (['api.php', 'login.php', 'change-password.php', 'reset-password.php',
          'src/security/totp.php', 'src/security/webauthn.php'] as $f) {
    $d = trim((string) shell_exec('cd /d W:\FER && git diff --stat 0f50e0ce..HEAD -- '
                                  . escapeshellarg($f) . ' 2>nul'));
    verifie("$f intact", $d === '', $d);
}

/* ── 9. Le compte de démonstration a bien été retiré partout ────────────── */
echo "\n=== 9. Compte de démonstration (retiré) ===\n";
$traces = [];
foreach (glob($R . 'inc/*.php') ?: [] as $f) {
    $s = (string) file_get_contents($f);
    if (str_contains($s, 'creer_demo') || str_contains($s, 'DÉMONSTRATION')) $traces[] = basename($f);
}
verifie('plus aucune trace du compte de démonstration', empty($traces), implode(', ', $traces));

/* ── 10. Aucune donnée personnelle en clair dans une URL ────────────────── */
echo "\n=== 10. Fuites d'URL ===\n";
$api = $lire('api/v1/index.php');
verifie('l\'API mobile ne lit aucune adresse depuis $_GET',
    !preg_match('/\$_GET\[[\'"]email/i', $api));

/* ── 11. Toutes les pages publiques nouvelles sont atteignables ─────────── */
echo "\n=== 11. Accessibilité des pages publiques ===\n";
$footer = $lire('src/partials/footer-modern.php');
verifie('telecharger-app.php est liée depuis le pied de page',
    str_contains($footer, 'telecharger-app'));
verifie('la page existe', is_file($R . 'public/telecharger-app.php'));

/* ── 12. Les bancs de test sont tous documentés ─────────────────────────── */
echo "\n=== 12. Documentation des bancs ===\n";
$readme = $lire('docs/README.md');
$nonDoc = [];
foreach (glob($R . 'docs/test-*.php') ?: [] as $f) {
    $b = basename($f);
    if (str_contains($b, '-appel.')) continue;     // pilotes, documentés en bloc
    if ($b === 'test-integrite.php') continue;     // celui-ci
    if (!str_contains($readme, $b)) $nonDoc[] = $b;
}
verifie('chaque banc figure dans docs/README.md', empty($nonDoc), implode(', ', $nonDoc));

echo "\n" . str_repeat('─', 62) . "\n";
echo ($ko === 0 ? "INTÉGRITÉ : TOUT EST VERT" : "INTÉGRITÉ : $ko ÉCHEC(S)")
   . " — $ok contrôle(s) réussi(s)" . ($avert > 0 ? ", $avert avertissement(s)" : '') . ".\n";
exit($ko === 0 ? 0 : 1);
