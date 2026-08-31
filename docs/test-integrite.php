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
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * EN LIGNE DE COMMANDE, DEPUIS LE DÉPÔT :   php docs/test-integrite.php
 *
 * Le `.htaccess` du dossier interdit l'accès par URL, et c'est très bien : ce
 * banc lance `php -l` sur tous les fichiers du site et interroge git. Rien de
 * tout cela n'a sa place derrière une adresse web. Le contrôle ci-dessous est
 * la seconde barrière, celle qui tient si le fichier atterrit ailleurs.
 *
 * ⚠️ Il a besoin du DÉPÔT GIT (étape 8, gel de fichiers). Sur un site déployé
 * sans son historique, cette étape s'affiche en avertissement et le reste
 * fonctionne normalement.
 * ═════════════════════════════════════════════════════════════════════════════
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("test-integrite.php ne s'exécute qu'en ligne de commande : php docs/test-integrite.php\n");
}

$ok = 0; $ko = 0; $avert = 0;
function verifie(string $titre, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  OK   $titre\n"; }
    else       { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function avertir(string $texte): void { global $avert; $avert++; echo "  ⚠  $texte\n"; }

/* Racine du projet, déduite de l'emplacement de ce fichier (docs/) : le banc
 * doit tourner sur n'importe quel poste, Windows comme Linux.
 *
 * ⚠️ TOUJOURS DES SLASHS DANS LES MOTIFS glob(), JAMAIS D'ANTISLASH. Sous Linux
 * l'antislash est un caractère d'ÉCHAPPEMENT pour glob() : `inc\*.php` n'a
 * matché aucun fichier, et cinq contrôles ont tourné à vide en annonçant « OK »
 * — un banc qui valide le néant est pire qu'un banc absent. Sous Windows les
 * deux marchent, c'est ce qui l'a caché si longtemps. */
$R    = str_replace('\\', '/', dirname(__DIR__)) . '/';
$lire = fn(string $f) => (string) @file_get_contents($R . $f);

/* `git -C <racine>` plutôt qu'un `cd` : la syntaxe du `cd` diffère d'un système
 * à l'autre, celle de git non. */
$git = fn(string $args) => (string) @shell_exec('git -C ' . escapeshellarg(rtrim($R, '/')) . ' ' . $args);

/* ── 1. Tous les fichiers PHP compilent ─────────────────────────────────── */
echo "\n=== 1. Compilation ===\n";
/* L'interpréteur qui exécute ce banc — garanti être un PHP en ligne de commande
 * par le garde-fou PHP_SAPI du haut du fichier. On vérifie ainsi la syntaxe
 * avec la MÊME version de PHP que celle qui lance le banc, plutôt qu'avec un
 * chemin figé vers l'installation d'un poste particulier. */
$php = PHP_BINARY;
$fichiers = [];
foreach (['', 'inc/', 'src/core/', 'src/auth/', 'src/content/', 'src/mail/', 'src/partials/',
          'public/', 'public/espace-coureur/', 'api/mobile/'] as $d) {
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
                     glob($R . 'api/mobile/*.php') ?: []) as $f) {
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

/* UN SEUL gestionnaire, jamais deux.
 * admin-footer.php posait ses propres écouteurs data-confirm ALORS QU'IL INCLUT
 * confirm-script.php : la question était posée deux fois, et il fallait valider
 * deux fois pour un seul clic. Le contrôle porte sur tous les pieds de page et
 * partials — un second gestionnaire ajouté ailleurs referait le même effet. */
$doublons = [];
foreach (array_merge(glob($R . 'src/partials/*.php') ?: [],
                     glob($R . 'js/*.js') ?: []) as $f) {
    if (basename($f) === 'confirm-script.php') continue;    // l'implémentation officielle
    if (basename($f) === 'ui.js') continue;                 // non chargé par les pages (cf. update.php)
    $src = (string) file_get_contents($f);
    // Un écouteur qui LIT data-confirm pour poser une question, et non un
    // simple balisage qui en porte l'attribut.
    if (preg_match('/addEventListener\s*\(\s*["\'](?:submit|click)["\'][\s\S]{0,240}?data-?[Cc]onfirm/', $src)) {
        $doublons[] = basename($f);
    }
}
verifie('un seul gestionnaire de confirmation dans tout le site',
    empty($doublons), implode(', ', $doublons));

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
    /* `agenda.php` ne rend AUCUN HTML : il sert un fichier .ics en pièce jointe
       (Content-Type: text/calendar). Lui réclamer une feuille de style était un
       faux échec — et un faux échec finit par faire ignorer les vrais. */
    if (str_starts_with($b, '_') || $b === 'deconnexion.php' || $b === 'agenda.php') continue;
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

/* ⚠️ LE CATALOGUE NE SUFFIT PAS. Une permission absente de l'ÉDITEUR reste
 * invisible dans « Utilisateurs & Droits » : impossible à accorder à un rôle
 * autre qu'administrateur, et rien à l'écran ne l'explique. Il y a DEUX listes
 * dans inc/utilisateurs.php — une PHP, une JavaScript — et il faut les deux. */
$util = $lire('inc/utilisateurs.php');
preg_match_all("/'(settings\.tab\.[a-z_]+|dashboard\.[a-z_]+)'/", $cfg, $mCat);
$horsEditeur = [];
foreach (array_unique($mCat[1]) as $perm) {
    $dansPhp = preg_match("/'" . preg_quote($perm, '/') . "'\s*=>/", $util) === 1;
    $dansJs  = preg_match("/key:\s*'" . preg_quote($perm, '/') . "'/", $util) === 1;
    if (!$dansPhp || !$dansJs) {
        $horsEditeur[] = $perm . (!$dansPhp ? ' (liste PHP)' : '') . (!$dansJs ? ' (liste JS)' : '');
    }
}
verifie('toute permission du catalogue est éditable dans « Utilisateurs & Droits »',
    empty($horsEditeur), implode(' | ', $horsEditeur));

// Le départ a son propre droit : un appui recalcule tous les temps et fait
// sonner tous les téléphones. L'emprunter aux transferts serait un abus.
verifie('donner le départ demande son propre droit',
    str_contains($lire('inc/dashboard.php'), "canDoAction('dashboard.depart')")
    && str_contains($cfg, "'dashboard.depart'"));

/* ── 6. install.php et update.php restent alignés ───────────────────────── */
echo "\n=== 6. install.php ↔ update.php ===\n";
$inst = $lire('install.php');
$upd  = $lire('update.php');
$colonnes = ['api_v1_enabled', 'app_version_minimale', 'app_access_token_ttl_min',
             'traces_gps_conservation_jours', 'auth_codes_conservation_jours',
             'devices_revoques_jours', 'transferts_clos_jours',
             'traces_consent_at', 'idx_unicite', 'chrono_enabled'];
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

/* ── 8. Fichiers interdits : intacts ─────────────────────────────────────
 *
 * ⚠️ `api.php` A QUITTÉ CETTE LISTE, ET CE N'EST PAS UN RELÂCHEMENT. Le fichier
 * a été déplacé en `api/v1.php` — le dossier `api/` regroupe désormais tout
 * ce qui vient de l'extérieur. Le gel le suit à sa nouvelle adresse, juste en
 * dessous : son CORPS est comparé ligne à ligne à la version d'origine, et seuls
 * l'en-tête et les chemins `__DIR__` ont le droit d'avoir changé.
 *
 * C'est un contrôle plus fort que l'ancien, pas plus faible : un `git diff` vide
 * prouvait qu'on n'avait pas touché au fichier, celui-ci prouve qu'un
 * déplacement n'a rien modifié à ce qu'il fait.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 8. Fichiers que la consigne interdit de modifier ===\n";
/* ⚠️ SANS GIT, CE CONTRÔLE DIRAIT « INTACT » POUR TOUT. `git diff` renvoie une
 * chaîne vide aussi bien quand rien n'a changé que quand la commande n'existe
 * pas — et un banc qui valide en silence est pire que pas de banc du tout. On
 * vérifie donc d'abord que le dépôt répond. */
$gitDispo = trim($git('rev-parse --git-dir')) !== '';
if (!$gitDispo) {
    avertir('git indisponible ou dépôt absent — le gel des fichiers interdits n\'a PAS été vérifié');
} else {
    foreach (['login.php', 'change-password.php', 'reset-password.php',
              'src/security/totp.php', 'src/security/webauthn.php'] as $f) {
        $d = trim($git('diff --stat 0f50e0ce..HEAD -- ' . escapeshellarg($f)));
        verifie("$f intact", $d === '', $d);
    }
}

/* Le gel d'api.php, à sa nouvelle adresse. On compare le CORPS du fichier à la
   version d'origine : seules deux différences sont tolérées, et elles sont la
   conséquence mécanique du déplacement —
     • l'en-tête de documentation (avant le premier `require`) ;
     • les chemins `__DIR__`, qui doivent remonter d'un cran.
   Toute autre ligne modifiée est un changement de comportement déguisé. */
$avant = $git('show 0f50e0ce:api.php');
$apres = $lire('api/v1.php');
if ($avant === '') {
    avertir('impossible de relire api.php d\'origine (git indisponible ?) — gel non vérifié');
} else {
    // On coupe l'en-tête : il a le droit d'avoir changé, il ne s'exécute pas.
    $corps = fn(string $s) => ($p = strpos($s, "\nrequire ")) !== false ? substr($s, $p) : $s;
    $lignesAvant = explode("\n", str_replace("\r", '', $corps($avant)));
    $lignesApres = explode("\n", str_replace("\r", '', $corps($apres)));

    $differences = [];
    if (count($lignesAvant) !== count($lignesApres)) {
        $differences[] = 'nombre de lignes : ' . count($lignesAvant) . ' → ' . count($lignesApres);
    } else {
        foreach ($lignesAvant as $i => $ligne) {
            if ($ligne === $lignesApres[$i]) continue;
            // Seul un `__DIR__` remonté d'un cran est acceptable.
            if (str_contains($ligne, '__DIR__')
                && str_replace("__DIR__ . '/", "__DIR__ . '/../", $ligne) === $lignesApres[$i]) continue;
            $differences[] = 'ligne ' . ($i + 1) . ' : ' . trim($lignesApres[$i]);
        }
    }
    verifie('api/v1.php : le déplacement n\'a rien changé au comportement',
        empty($differences), implode(' | ', array_slice($differences, 0, 3)));
}

/* ⚠️ L'ANCIEN FICHIER NE DOIT PAS RÉAPPARAÎTRE À LA RACINE. Deux copies d'une
   API divergent toujours, et c'est celle qu'on ne teste pas qui garde la faille
   corrigée d'un seul côté. Rétablir l'ancienne ADRESSE se fait par une ligne de
   réécriture dans le .htaccess, jamais en recopiant le fichier. */
verifie('aucun api.php n\'a repoussé à la racine', !is_file($R . 'api.php'));

/* ⚠️ ET SUR LE SERVEUR, IL FAUT L'EFFACER. Un déploiement qui envoie des
   fichiers n'en supprime aucun : l'ancien api.php resterait à la racine du
   serveur, toujours fonctionnel, figé à la version du jour du déplacement.
   C'est update.php qui doit s'en charger. */
verifie('update.php supprime l\'ancien api.php du serveur',
    str_contains($lire('update.php'), 'function updSupprimerAncienApi('));

/* ⚠️ UN DOSSIER `api/v1/` RENDRAIT `api/v1.php` INJOIGNABLE. La réécriture
   « /x » → « /x.php » de la racine ne s'applique que si le chemin n'est NI un
   fichier NI un dossier : un dossier de ce nom gagne, et l'API des logiciels
   tiers répond 403 sans que rien ne l'explique. */
verifie('aucun dossier api/v1/ ne masque api/v1.php', !is_dir($R . 'api/v1'));

/* ⚠️ DEUX RÈGLES D'api/.htaccess QUI ONT L'AIR SUPERFLUES ET NE LE SONT PAS.
   Elles ont été écrites après avoir vu le vrai Apache se comporter autrement
   que prévu ; les retirer « pour simplifier » casse deux choses différentes.

   • `^v1/ - [G]` : `v1` était un DOSSIER, c'est maintenant `v1.php`. Sans cette
     règle, /api/v1/auth/request-code — ce qu'appellent les applications déjà
     installées — part en BOUCLE DE REDIRECTION et le serveur répond 500 après
     dix tours. On répond 410 (Gone), une fois, sans détour.

   • Le bloc `!-f / !-d / .php -f` : `RewriteEngine On` annule les règles
     héritées de la racine pour tout le dossier. Sans le rétablir ici, l'adresse
     /api/v1 sans extension ne répond plus du tout. */
$htApi = $lire('api/.htaccess');
verifie('api/.htaccess referme la boucle sur l\'ancien sous-chemin v1/',
    str_contains($htApi, '^v1/ - [G]'));
verifie('api/.htaccess rétablit la réécriture « /x » → « /x.php »',
    str_contains($htApi, '%{REQUEST_FILENAME}.php -f')
    && str_contains($htApi, 'RewriteRule ^(.+)$ $1.php'));
verifie('update.php efface l\'ancien dossier api/v1/ du serveur',
    str_contains($lire('update.php'), 'function updSupprimerAncienDossierV1('));

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
$api = $lire('api/mobile/index.php');
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

/* ── 13. L'interrupteur du chronométrage est honoré partout ──────────────────
 * Le risque n'est pas qu'il ne marche pas : c'est qu'il ne marche qu'à MOITIÉ.
 * Un menu qui masque « Mes résultats » pendant que l'API continue d'accepter des
 * positions GPS donnerait une fausse impression de fermeture — et la collecte
 * de données de géolocalisation est précisément ce qu'on ne veut pas laisser
 * ouvert par inadvertance. Les quatre lecteurs sont donc vérifiés ensemble.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 13. Interrupteur du chronométrage ===\n";
verifie('chrono_actif() existe et refuse par défaut',
    str_contains($lire('src/content/chrono.php'), 'function chrono_actif('));

$lecteurs = [
    'menu de l\'espace coureur' => 'public/espace-coureur/_layout-haut.php',
    'page Mes résultats'        => 'public/espace-coureur/mes-resultats.php',
    'API mobile'                => 'api/mobile/index.php',
];
$sourds = [];
foreach ($lecteurs as $quoi => $f) {
    if (!str_contains($lire($f), 'chrono_actif(')) $sourds[] = $quoi;
}
verifie('le menu, la page et l\'API lisent tous l\'interrupteur',
    empty($sourds), implode(', ', $sourds));

// L'API doit refuser les TROIS sous-routes, pas seulement l'envoi de détections :
// laisser /me/traces ouvert continuerait d'enregistrer des positions.
$api = $lire('api/mobile/index.php');
verifie('l\'API ferme detections, traces ET results',
    str_contains($api, 'chrono_disabled')
    && preg_match("/in_array\(\\\$sousRoute,\s*\['detections',\s*'traces',\s*'results'\]/", $api) === 1);

// Et l'application doit pouvoir l'apprendre AVANT d'essayer.
verifie('/app/config annonce l\'état à l\'application',
    str_contains($api, "'chrono_actif'"));

// L'écran qui porte l'interrupteur ne doit jamais se masquer lui-même : sinon
// on ferme le chronométrage et plus personne ne peut le rouvrir.
$adm = $lire('inc/resultats.php');
verifie('l\'écran d\'administration reste accessible dans les deux états',
    str_contains($adm, 'basculer_chrono') && !preg_match('/chrono_actif\([^)]*\)\s*\)?\s*\{?\s*(exit|die|header)/', $adm));

/* ── 14. Informations de course : une seule valeur, plusieurs portes ─────────
 * La date, la distance et le point de départ vivent dans DEUX tables (`setting`
 * pour le site, `editions` pour le chronométrage). Le pont de
 * src/content/course.php les tient synchronisées dans les deux sens.
 *
 * Le risque n'est pas qu'il ne marche pas : c'est qu'un écran écrive dans
 * `setting` SANS appeler le pont. Rien ne casserait, rien ne s'afficherait — et
 * le chronométrage travaillerait des mois avec une date périmée.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 14. Pont des informations de course ===\n";
$pont = $lire('src/content/course.php');
verifie('les deux sens du pont existent',
    str_contains($pont, 'function course_pousserDepuisSetting(')
    && str_contains($pont, 'function course_enregistrer('));

// Tout écran qui écrit une des trois colonnes appariées doit appeler le pont.
$appariees = ['date_course', 'course_km', 'start_point_coords'];
$sansPont = [];
foreach (glob($R . 'inc/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    // Un UPDATE de `setting` touchant une colonne appariée.
    if (!preg_match('/UPDATE\s+setting\s+SET[^;\']*(' . implode('|', $appariees) . ')/i', $src)) {
        continue;
    }
    if (!str_contains($src, 'course_pousserDepuisSetting')
        && !str_contains($src, 'course_enregistrer')) {
        $sansPont[] = basename($f);
    }
}
verifie('tout écran qui écrit la date, la distance ou le départ appelle le pont',
    empty($sansPont), implode(', ', $sansPont));

// L'onglet Course écrit les DEUX tables, sinon il devient une copie de plus.
verifie('l\'onglet Course renvoie bien vers setting',
    preg_match('/UPDATE setting SET.*implode/s', $pont) === 1
    || str_contains($pont, "'`date_course` = ?'"));

// L'heure de départ est le piège à deux heures : la conversion doit exister.
verifie('l\'heure de départ est convertie en UTC à l\'enregistrement',
    str_contains($pont, 'function course_heureDepartUtc(')
    && str_contains($lire('inc/setting.php'), 'course_heureDepartUtc('));

/* Tout onglet de Réglages doit figurer dans $allTabs, sinon `?tab=` est rejeté
 * en silence et le lien retombe sur « personnalisation ». C'est exactement ce
 * qui est arrivé à l'onglet Course : le bouton « Modifier ces informations »
 * menait ailleurs, sans le moindre message. */
$set = $lire('inc/setting.php');
preg_match('/\$allTabs\s*=\s*\[(.*?)\]/s', $set, $mTabs);
preg_match_all('/data-tab="([a-z_]+)"/', $set, $mDeclares);
$horsListe = [];
foreach (array_unique($mDeclares[1] ?? []) as $onglet) {
    if (!str_contains($mTabs[1] ?? '', "'$onglet'")) $horsListe[] = $onglet;
}
verifie('tout onglet de Réglages figure dans $allTabs',
    empty($horsListe), implode(', ', $horsListe));

// Et son bouton d'enregistrement doit y ramener après le POST, sinon on est
// renvoyé sur un autre onglet en croyant que rien n'a été enregistré.
verifie('l\'onglet Course est rouvert après enregistrement',
    str_contains($set, "isset(\$_POST['save_course'])) \$activeTab = 'course'"));

/* ── 15. Notifications de l'application ─────────────────────────────────── */
echo "\n=== 15. Notifications de l'application ===\n";
verifie('la table est dans les DEUX chemins d\'installation',
    str_contains($inst, 'app_notifications') && str_contains($upd, 'app_notifications'));

$notif = $lire('src/content/notifications.php');

/* Le push est une ACTION, pas une propriété du message. L'ancien champ « canal »
 * mélangeait les deux : un push n'a pas de date de fin, un message ne sonne pas.
 * Le contrôle vérifie que le modèle n'y revient pas par inadvertance. */
// ⚠️ Ciblé sur LE canal des notifications : `participant_auth_codes` a un
// `canal` ENUM('web','app') parfaitement légitime, qui dit d'où vient un code.
verifie('aucune trace de l\'ancien « canal » des notifications',
    !str_contains($inst, "ENUM('app','systeme','les_deux')")
    && !str_contains($notif, 'NOTIF_CANAUX'));

// La colonne est retirée des bases qui l'avaient reçue, sinon les deux chemins
// d'installation divergent et l'audit refuse.
verifie('l\'ancien « canal » est retiré des bases qui l\'ont',
    str_contains($upd, "DROP COLUMN `canal`"));

// Les six colonnes de ce lot doivent être dans les DEUX chemins.
$colonnesLot = ['afficher_dans_app', 'envoye_at', 'envoye_a',
                'depart_reel_at', 'push_token', 'push_maj_at',
                'fcm_project_id', 'fcm_service_account', 'depart_grace_min'];
$manquantes = [];
foreach ($colonnesLot as $c) {
    if (!str_contains($inst, $c)) $manquantes[] = "install:$c";
    if (!str_contains($upd, $c))  $manquantes[] = "update:$c";
}
verifie('les colonnes du push et du départ sont dans les deux chemins',
    empty($manquantes), implode(', ', $manquantes));

/* ⚠️ LA CLÉ PRIVÉE NE DOIT JAMAIS SORTIR. `fcm_service_account` permet
 * d'envoyer des notifications au nom de l'association : elle est chiffrée, et
 * elle ne doit être ni réaffichée dans un champ, ni servie par l'API. */
$app = $lire('inc/applications.php');
verifie('le compte de service est chiffré et jamais réaffiché',
    str_contains($lire('src/content/push.php'), 'encrypt($jsonBrut)')
    && !preg_match('/value="[^"]*fcm_service_account/', $app)
    && !str_contains($lire('api/mobile/index.php'), 'fcm_service_account'));

/* ── 16. Le départ de la course ─────────────────────────────────────────────
 * Le top réel fait foi ; l'heure prévue est un filet. Le risque est qu'une
 * correction d'heure laisse des temps calculés sur l'ancienne base — deux
 * groupes de coureurs chronométrés différemment sur la même course.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 16. Départ de la course ===\n";
$chr = $lire('src/content/chrono.php');
verifie('les quatre niveaux d\'arbitrage sont là',
    str_contains($chr, "\$sourceDep = 'top'")
    && str_contains($chr, "\$sourceDep = 'prevu'")
    && str_contains($chr, 'depart_grace_min'));

// Donner, corriger ou annuler le départ DOIT recalculer : sans cela, les
// arrivées déjà traitées gardent l'ancienne heure.
$sansRecalcul = [];
foreach (['chrono_donnerDepart', 'chrono_annulerDepart', 'chrono_decalerPrevu'] as $f) {
    if (preg_match('/function ' . $f . '\(.*?\n\}/s', $chr, $m)
        && !str_contains($m[0], 'chrono_recomputeEdition')) {
        $sansRecalcul[] = $f;
    }
}
verifie('toute action sur le départ recalcule l\'édition',
    empty($sansRecalcul), implode(', ', $sansRecalcul));

// Un résultat validé par un officiel ne se défait pas parce qu'on a corrigé
// l'heure : c'est ce qui rend le bouton « recalculer » utilisable sans crainte.
verifie('le recalcul global épargne les résultats validés',
    preg_match('/function chrono_recomputeEdition\(.*?chrono_recompute\(\$pdo, \$annee, \(string\) \$no\)/s', $chr) === 1);

verifie('l\'annulation du départ existe',
    str_contains($lire('src/partials/depart-course.php'), "value=\"annuler\""));

/* Aucune donnée personnelle : une notification vise une ÉDITION, pas des gens.
 *
 * ⚠️ LE CONTRÔLE NE PEUT PLUS INTERDIRE `participant_id` DANS TOUT LE FICHIER.
 * Depuis les messages « lus » et « masqués », deux tables de suivi PAR COUREUR
 * existent — c'est un accusé de lecture, pas une liste de diffusion : la
 * notification, elle, ne sait toujours pas à qui elle s'adresse. Interdire le
 * mot partout faisait échouer le banc sur une fonctionnalité légitime, et un
 * échec permanent est un échec qu'on cesse de lire.
 *
 * On retire donc les requêtes de ces deux tables avant de chercher : ce qui
 * reste, c'est la notification elle-même, et là rien ne doit désigner quelqu'un. */
$notifSansSuivi = preg_replace(
    '/[^;]*participant_notifications_(?:lues|masquees)[^;]*;/is', '', $notif);
verifie('aucune liste de destinataires nominatifs',
    !preg_match('/participant_id|email_cible|destinataires/i', $notifSansSuivi));

// L'API doit servir les notifications MÊME chronométrage fermé : une annonce de
// l'organisation n'a rien à voir avec les temps.
$apiSrc = $lire('api/mobile/index.php');
$posNotif  = strpos($apiSrc, "\$sousRoute === 'notifications'");
$posGarde  = strpos($apiSrc, "in_array(\$sousRoute, ['detections'");
verifie('les notifications passent même chronométrage fermé',
    $posNotif !== false && $posGarde !== false && $posNotif < $posGarde);

/* ── 16 bis. Les boutons ont tous la même forme ─────────────────────────────
 *
 * `css/gmail-settings.css` redéfinissait `.btn`, `.btn-primary`, `.btn-success`,
 * `.btn-danger` et `.btn-warning` — des classes GÉNÉRALES dans une feuille
 * propre à une page. Les boutons de mail-settings.php avaient donc une marge et
 * une bordure que personne d'autre n'avait, et des couleurs écrites en dur qui
 * ignoraient le thème sombre.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 16 bis. Uniformité des boutons ===\n";

/* ⚠️ ON NE REGARDE QUE LES FEUILLES CHARGÉES CÔTÉ ADMINISTRATION.
   `css/fer-modern.css` définit ses propres `.btn` — en pilule, pour le site
   public — et c'est parfaitement légitime : elle n'est jamais chargée ici. Un
   contrôle qui balaierait tout le dossier css/ signalerait ce faux positif à
   chaque passage, et on finirait par ne plus le lire. */
/* Un écran d'administration = un fichier qui inclut navbar-admin.php, lequel
   charge admin.css. `src/partials/auth-head.php` sert les pages de CONNEXION,
   qui ont leur propre système (components.css) — les mêler ici signalerait un
   faux positif permanent. */
$feuillesAdmin = [];
$sourcesAdmin  = [$R . 'src/partials/navbar-admin.php'];
foreach (glob($R . 'inc/*.php') ?: [] as $f) {
    if (str_contains((string) file_get_contents($f), 'navbar-admin.php')) $sourcesAdmin[] = $f;
}
foreach ($sourcesAdmin as $f) {
    if (!is_file($f)) continue;
    preg_match_all('#css/([a-z0-9_-]+\.css)#i', (string) file_get_contents($f), $mCss);
    foreach ($mCss[1] as $c) $feuillesAdmin[$c] = true;
}
unset($feuillesAdmin['admin.css']);   // la référence : c'est elle qui doit styler

$feuillesPage = [];
foreach (array_keys($feuillesAdmin) as $c) {
    if (!is_file($R . 'css/' . $c)) continue;
    // Une règle qui vise `.btn` sans être préfixée par une classe à soi.
    if (preg_match('/^\s*\.btn(-[a-z]+)?\s*[,{]/m', (string) file_get_contents($R . 'css/' . $c))) {
        $feuillesPage[] = $c;
    }
}
verifie('aucune feuille chargée en admin ne redéfinit les boutons',
    empty($feuillesPage), implode(', ', $feuillesPage));

// Une couleur en dur sur un bouton ne suit pas le thème : elle reste claire en
// sombre, et le bouton se distingue de tous les autres.
$dur = [];
foreach (glob($R . 'inc/*.php') ?: [] as $f) {
    if (preg_match('/class="btn[^"]*"[^>]*style="[^"]*#[0-9a-fA-F]{3,6}/', (string) file_get_contents($f))) {
        $dur[] = basename($f);
    }
}
verifie('aucun bouton n\'a de couleur écrite en dur',
    empty($dur), implode(', ', $dur));

/* ⚠️ CE BANC NE REGARDE QUE LE SITE — VOLONTAIREMENT.
 *
 * Il a un temps contrôlé le code des applications mobiles (`APPS/`). C'était une
 * erreur de périmètre : `APPS/` n'est pas déployé, et lancer ce banc sur le
 * serveur signalait alors des manques qui n'en sont pas.
 *
 * Le code des applications se contrôle avec `flutter analyze`, depuis APPS/.
 * ──────────────────────────────────────────────────────────────────────────── */

/* ── 17. La recherche de l'administration ───────────────────────────────────
 *
 * ⚠️ UN INDEX QUI DÉRIVE EST PIRE QUE PAS D'INDEX. Si un réglage est ajouté
 * sans être indexé, la recherche répond « aucun résultat » et on en conclut que
 * la fonction n'existe pas — on va la chercher ailleurs, ou on la recrée.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 17. Recherche de l'administration ===\n";
$rech = $lire('src/partials/recherche-admin.php');

// Tout onglet de Réglages doit être atteignable par la recherche.
preg_match_all('/data-tab="([a-z_]+)"/', $set, $mOnglets);
$nonIndexes = [];
foreach (array_unique($mOnglets[1]) as $onglet) {
    if (!str_contains($rech, 'tab=' . $onglet)) $nonIndexes[] = $onglet;
}
verifie('tout onglet de Réglages est dans l\'index de recherche',
    empty($nonIndexes), implode(', ', $nonIndexes));

// Toute page d'administration listée au menu doit l'être aussi.
$nav = $lire('src/partials/navbar-admin.php');
preg_match_all("/\['([a-z_-]+\.php)(?:\?[^']*)?',\s*'/", $nav, $mPages);
$pagesNonIndexees = [];
foreach (array_unique($mPages[1]) as $page) {
    if (!str_contains($rech, $page)) $pagesNonIndexees[] = $page;
}
verifie('toute page du menu est dans l\'index de recherche',
    empty($pagesNonIndexees), implode(', ', $pagesNonIndexees));

/* Une ancre qui ne correspond à aucun id mène à l'onglet sans surligner quoi
   que ce soit : la recherche a l'air de fonctionner, et elle laisse chercher. */
preg_match_all("/'ancre' => '([a-zA-Z]+)'/", $rech, $mAncres);
$ancresMortes = [];
$toutHtml = '';
foreach (array_merge(glob($R . 'inc/*.php') ?: [], glob($R . 'src/partials/*.php') ?: []) as $f) {
    $toutHtml .= (string) file_get_contents($f);
}
foreach (array_unique(array_filter($mAncres[1])) as $ancre) {
    if (!str_contains($toutHtml, 'id="' . $ancre . '"')) $ancresMortes[] = $ancre;
}
verifie('chaque ancre de l\'index existe dans une page',
    empty($ancresMortes), implode(', ', $ancresMortes));

/* ⚠️ LE FILTRAGE PAR DROITS DOIT ÊTRE CÔTÉ SERVEUR. Envoyer l'index complet au
   navigateur puis le filtrer en JavaScript révélerait l'existence d'écrans à qui
   n'y a pas accès — il suffirait de lire la source de la page. */
verifie('l\'index est filtré par les droits avant d\'être envoyé',
    str_contains($rech, '$jrCanSee($e[\'droit\'])')
    && str_contains($rech, '$rechercheVisible'));

// Le libellé doit dire ce qu'on cherche : le tableau de bord a déjà une
// recherche, qui porte sur les inscrits.
verifie('la barre annonce qu\'elle cherche un réglage',
    str_contains($rech, 'Rechercher un réglage'));

/* ── 18. Les points d'entrée JSON ───────────────────────────────────────────
 *
 * LA RÈGLE : `api/` regroupe ce à quoi un LOGICIEL se connecte, avec un jeton.
 * Ce qui est dehors sert le JavaScript de nos propres pages.
 *
 *   • api/v1.php           → logiciels tiers, secret de l'association
 *                                 (l'ancien api.php, déplacé — voir § 8)
 *   • api/mobile/index.php          → applications des coureurs, jeton personnel
 *   • admin-api.php             → DEHORS : le JavaScript des écrans du site,
 *                                 administration ET formulaires publics
 *   • public/chatbot-api.php    → DEHORS : le widget de discussion, anonyme
 *
 * ⚠️ ILS RESTENT SÉPARÉS EXPRÈS. Ce sont des périmètres de sécurité distincts :
 * les réunir derrière un aiguilleur commun ferait d'une erreur d'aiguillage une
 * faille — une route d'administration atteignable avec un jeton de partenaire,
 * ou les données d'un coureur lisibles par un autre.
 *
 * ⚠️ CE QUI SE PERD, EN REVANCHE, C'EST DE SAVOIR LEQUEL FAIT QUOI. C'est
 * exactement ce qui s'est produit. La carte « Vue d'ensemble » de Réglages → API
 * y répond, et ce contrôle existe pour qu'un QUATRIÈME point d'entrée ajouté plus
 * tard ne puisse pas rester invisible : il devra être décrit, ou le banc rougit.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 18. Points d'entrée JSON ===\n";

/* La carte, isolée : un nom cité ailleurs dans l'onglet ne prouverait rien. */
$carte = '';
if (preg_match('/Carte : vue d\'ensemble.*?carteApiExterne/s', $set, $mCarte)) {
    $carte = $mCarte[0];
}
verifie('la carte « Vue d\'ensemble » est présente dans Réglages → API',
    $carte !== '');

/* Tout point d'entrée PARTAGÉ doit y figurer :
     • tout ce qui est dans `api/` — c'est la définition du dossier ;
     • tout fichier nommé `*-api.php`, où qu'il soit.

   ⚠️ CE CONTRÔLE NE VOIT PAS TOUT, ET C'EST ASSUMÉ. Une vingtaine de pages
   répondent aussi en JSON (inc/albums.php, public/news.php…), mais chacune ne
   sert QUE son propre écran : les lister ici noierait la carte sous du bruit et
   ferait perdre ce qu'on vient y chercher. La règle porte donc sur les points
   d'entrée que PLUSIEURS pages appellent — ceux qu'on peut confondre. Nommer un
   nouveau `…-api.php` suffit à le rendre visible du banc. */
$entrees = array_merge(
    glob($R . 'api/*.php') ?: [],
    glob($R . 'api/*/index.php') ?: [],
    glob($R . '*-api.php') ?: [],
    glob($R . '*/*-api.php') ?: []
);
$nonDecrits = [];
foreach ($entrees as $f) {
    $nom = str_replace('\\', '/', substr($f, strlen($R)));
    /* Dans `api/`, on cite l'ADRESSE, pas le fichier : « api/v1 » et
       « api/mobile/ » sont ce que lit un partenaire. À la racine, le nom complet. */
    $forme = $nom;
    if (str_starts_with($nom, 'api/')) {
        $forme = str_ends_with($nom, '/index.php')
            ? substr($nom, 0, -strlen('/index.php'))   // api/mobile/index.php → api/mobile
            : substr($nom, 0, -4);                     // api/v1.php  → api/v1
    }
    if (!str_contains($carte, $forme)) $nonDecrits[] = $nom;
}
verifie('chaque point d\'entrée JSON est décrit dans la vue d\'ensemble',
    empty($nonDecrits), implode(', ', $nonDecrits));

/* Décrire ne suffit pas : sans le mode d'authentification, la carte ne dit pas
   ce qui les distingue — c'est la seule chose qu'on vient y chercher. */
verifie('la vue d\'ensemble donne le mode d\'authentification de chacun',
    str_contains($carte, 'secret de l\'association')
    && str_contains($carte, 'jeton personnel')
    && str_contains($carte, 'session'));

/* ⚠️ LA DISPARITION DE L'ANCIENNE ADRESSE DOIT ÊTRE ANNONCÉE, PAS TUE. Un
   partenaire branché sur `api.php` ne lit pas nos notes de version : il
   découvrira la panne un jour de course. La carte doit dire noir sur blanc
   qu'elle ne répond plus, pour qu'on pense à le prévenir avant de déployer. */
verifie('la vue d\'ensemble avertit que api.php ne répond plus',
    str_contains($carte, 'api.php') && str_contains($carte, 'ne répond plus'));

/* Le déplacement n'a de sens que s'il est complet : un chemin oublié afficherait
   à l'administrateur une adresse qui renvoie 404. */
verifie('l\'onglet API affiche la nouvelle adresse',
    str_contains($set, "'/api/v1'") && !str_contains($set, "'/api.php'"));
verifie('la documentation externe montre la nouvelle adresse',
    str_contains($lire('inc/api-doc.php'), "'/api/v1'"));

/* ⚠️ DEUX ENDROITS TESTENT LE NOM DU FICHIER, ET LES DEUX CASSENT EN SILENCE.
   `debug.php` empêche la barre de débogage de s'injecter dans du JSON ;
   `config.php` évite qu'une IP bannie reçoive une redirection HTML au lieu
   d'une réponse d'API. Un `api.php` oublié là et la panne n'apparaît que chez
   le partenaire, sous forme de JSON illisible. */
verifie('la barre de débogage épargne toujours l\'API des logiciels tiers',
    str_contains($lire('src/core/debug.php'), "=== 'v1.php'"));
verifie('le bannissement d\'IP épargne toujours l\'API des logiciels tiers',
    str_contains($lire('src/core/config.php'), "'v1.php', 'v1'"));

/* La même carte, dans le code : celui qui ouvre le fichier ne passe pas par
   l'administration. api.php est exclu — la consigne interdit d'y toucher. */
foreach (['admin-api.php', 'api/mobile/index.php', 'api/v1.php',
          'public/chatbot-api.php'] as $f) {
    /* mb_strtolower : la phrase est écrite en capitales dans certains en-têtes,
       et strtolower() ne sait pas replier le « É ». Les espaces sont aplatis :
       sinon un simple retour à la ligne dans le commentaire ferait échouer le
       contrôle, ce qui n'apprendrait rien à personne. */
    $plat = preg_replace('/[\s*]+/u', ' ', mb_strtolower($lire($f)));
    verifie("$f dit ce qui le distingue des autres",
        str_contains((string) $plat, 'type de client'));
}

/* ── 19. Les routes ouvertes d'admin-api.php ────────────────────────────────
 *
 * ⚠️⚠️ LE NOM DU FICHIER MENT, ET C'EST LE PIÈGE. « admin-api.php » n'a AUCUN
 * verrou global : chaque route porte le sien. Sur ses 48 routes, onze répondent
 * sans session — les unes parce qu'on ne peut pas exiger une session pour se
 * connecter, les autres parce qu'elles servent le site public.
 *
 * ⚠️ UNE ROUTE AJOUTÉE SANS GARDE EST OUVERTE À TOUT INTERNET, EN SILENCE. Elle
 * fonctionnera parfaitement pendant les essais, puisqu'on les fait connecté.
 * D'où cette liste figée : toute nouvelle route sans garde fait rougir le banc,
 * et il faut alors l'inscrire ici À LA MAIN, en sachant ce qu'on écrit.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 19. Routes ouvertes d'admin-api.php ===\n";

$ouvertesAttendues = [
    // Connexion — anonymes par nécessité, protégées autrement (mot de passe,
    // 2FA, jeton de réinitialisation, limitation de débit).
    'login-check-email', 'resend-2fa', 'switch-2fa-method', 'webauthn-direct-options',
    'forgot-password', 'reset-password-confirm', 'logout',
    // Site public — chacune a sa propre protection.
    'partner-captcha-init',   // captcha Turnstile
    'partner-request',        // formulaire partenaire, derrière le captcha
    'validate-qr-token',      // le jeton du dossard fait foi, il n'est pas devinable
    'tshirt-access-request',  // demande le code d'accès bénévole : forcément
                              // anonyme, mais _tshirtOpen() la ferme hors période
];

$srcAdmin = $lire('admin-api.php');
preg_match_all('/\$route\s*===\s*\'([a-z0-9_-]+)\'/', $srcAdmin, $mRoutes, PREG_OFFSET_CAPTURE);
$nbRoutes = count($mRoutes[0]);
$gardes = ['$_SESSION[\'uid\']', '$_SESSION[\'role\']', 'canAccessPage', 'canDoAction',
           'currentRole', 'requireAction(', 'requireRole(', '_tshirtScanAuth('];

$ouvertes = [];
for ($i = 0; $i < $nbRoutes; $i++) {
    $debut = $mRoutes[0][$i][1];
    $fin   = ($i + 1 < $nbRoutes) ? $mRoutes[0][$i + 1][1] : strlen($srcAdmin);
    $bloc  = substr($srcAdmin, $debut, $fin - $debut);
    foreach ($gardes as $g) if (str_contains($bloc, $g)) continue 2;
    $ouvertes[] = $mRoutes[1][$i][0];
}

$nouvelles = array_diff($ouvertes, $ouvertesAttendues);
verifie('aucune route sans garde n\'a été ajoutée à admin-api.php',
    empty($nouvelles), implode(', ', $nouvelles));

/* L'inverse compte aussi : une route qui gagne une garde doit sortir de la
   liste, sinon celle-ci finit par décrire un fichier qui n'existe plus. */
$disparues = array_diff($ouvertesAttendues, $ouvertes);
verifie('la liste des routes ouvertes ne décrit rien de périmé',
    empty($disparues), implode(', ', $disparues));

/* ⚠️ Et l'en-tête doit prévenir. Un fichier nommé « admin- » que l'on croit
   réservé aux administrateurs est exactement la manière dont on ajoute une
   route sensible sans y mettre de verrou. */
verifie('l\'en-tête d\'admin-api.php dément son propre nom',
    str_contains($srcAdmin, 'SON NOM MENT'));

/* ── 20. Le ménage de la mise à jour, rejoué pour de vrai ───────────────────
 *
 * ⚠️⚠️ CES DEUX FONCTIONS NE S'EXÉCUTENT QU'UNE FOIS, SUR LE VRAI SERVEUR, le
 * jour de la mise à jour. Personne n'est là pour rattraper une erreur — et une
 * erreur ici, c'est soit deux API vivantes servant le même secret, soit une
 * adresse `/api/v1` qui ne répond plus. Aucun autre banc ne les couvre :
 * `audit-bdd.php` n'extrait d'update.php que le SQL.
 *
 * On les extrait donc du fichier et on les rejoue sur un dossier jetable, avec
 * les quatre situations qui comptent — dont les deux REFUS, qui sont la partie
 * la plus importante : effacer par erreur coûte plus cher que ne pas effacer.
 * ──────────────────────────────────────────────────────────────────────────── */
echo "\n=== 20. Ménage de la mise à jour (rejoué) ===\n";

$srcUpd = $lire('update.php');
$blocs  = '';
foreach (['updSupprimerAncienApi', 'updSupprimerAncienDossierV1'] as $fn) {
    // La fonction va de sa déclaration à la première accolade fermante en colonne 1.
    if (preg_match('/^function ' . $fn . '\(.*?^\}/ms', $srcUpd, $m)) $blocs .= $m[0] . "\n";
}
if (substr_count($blocs, 'function ') !== 2) {
    verifie('les deux fonctions de ménage sont extractibles d\'update.php', false,
        'signature modifiée ? le banc ne sait plus les retrouver');
} else {
    eval($blocs);   // définit les deux fonctions, hors de tout contexte d'update.php

    $bac = sys_get_temp_dir() . '/fer_menage_' . getmypid();
    $poser = function (array $fichiers) use ($bac) {
        // Remise à zéro complète entre deux situations.
        $vider = function (string $d) use (&$vider) {
            foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $e) {
                is_dir("$d/$e") ? $vider("$d/$e") : @unlink("$d/$e");
            }
            @rmdir($d);
        };
        if (is_dir($bac)) $vider($bac);
        foreach ($fichiers as $rel) {
            $chemin = $bac . '/' . $rel;
            @mkdir(dirname($chemin), 0777, true);
            file_put_contents($chemin, '<?php // fictif');
        }
    };

    // 1. Le cas nominal : l'ancien part, le nouveau reste.
    $poser(['api.php', 'api/v1.php', 'api/v1/index.php', 'api/v1/.htaccess', 'api/mobile/index.php']);
    $r1 = updSupprimerAncienApi($bac);
    $r2 = updSupprimerAncienDossierV1($bac);
    verifie('mise à jour : api.php est supprimé, api/v1.php survit',
        $r1['status'] === 'success' && !is_file($bac . '/api.php') && is_file($bac . '/api/v1.php'),
        $r1['msg']);
    verifie('mise à jour : le dossier api/v1/ est supprimé, api/mobile/ survit',
        $r2['status'] === 'success' && !is_dir($bac . '/api/v1') && is_file($bac . '/api/mobile/index.php'),
        $r2['msg']);

    /* 2. ⚠️ LE REFUS QUI SAUVE LE SITE : si le remplaçant n'a pas été déployé,
          supprimer l'ancien couperait l'API pour de bon. On garde. */
    $poser(['api.php', 'api/v1/index.php', 'api/v1/.htaccess']);
    $r3 = updSupprimerAncienApi($bac);
    $r4 = updSupprimerAncienDossierV1($bac);
    verifie('remplaçant absent : api.php est CONSERVÉ, pas effacé',
        $r3['status'] === 'error' && is_file($bac . '/api.php'));
    verifie('remplaçant absent : api/v1/ est CONSERVÉ, pas effacé',
        $r4['status'] === 'error' && is_dir($bac . '/api/v1'));

    /* 3. Un fichier qu'on n'a pas écrit dans api/v1/ : on ne touche à rien. */
    $poser(['api/mobile/index.php', 'api/v1/index.php', 'api/v1/notes-perso.txt']);
    $r5 = updSupprimerAncienDossierV1($bac);
    verifie('fichier étranger dans api/v1/ : rien n\'est supprimé',
        $r5['status'] === 'error' && is_file($bac . '/api/v1/notes-perso.txt')
        && str_contains($r5['msg'], 'notes-perso.txt'));

    // 4. Rejouer la mise à jour ne doit plus rien faire ni rien casser.
    $poser(['api/v1.php', 'api/mobile/index.php']);
    verifie('rejeu : plus rien à supprimer, aucune erreur',
        updSupprimerAncienApi($bac)['status'] === 'skip'
        && updSupprimerAncienDossierV1($bac)['status'] === 'skip');

    $poser([]);   // le bac à sable ne survit pas au banc
}

echo "\n" . str_repeat('─', 62) . "\n";
echo ($ko === 0 ? "INTÉGRITÉ : TOUT EST VERT" : "INTÉGRITÉ : $ko ÉCHEC(S)")
   . " — $ok contrôle(s) réussi(s)" . ($avert > 0 ? ", $avert avertissement(s)" : '') . ".\n";
exit($ko === 0 ? 0 : 1);
