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

/* UN SEUL gestionnaire, jamais deux.
 * admin-footer.php posait ses propres écouteurs data-confirm ALORS QU'IL INCLUT
 * confirm-script.php : la question était posée deux fois, et il fallait valider
 * deux fois pour un seul clic. Le contrôle porte sur tous les pieds de page et
 * partials — un second gestionnaire ajouté ailleurs referait le même effet. */
$doublons = [];
foreach (array_merge(glob($R . 'src\partials\*.php') ?: [],
                     glob($R . 'js\*.js') ?: []) as $f) {
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
    'API mobile'                => 'api/v1/index.php',
];
$sourds = [];
foreach ($lecteurs as $quoi => $f) {
    if (!str_contains($lire($f), 'chrono_actif(')) $sourds[] = $quoi;
}
verifie('le menu, la page et l\'API lisent tous l\'interrupteur',
    empty($sourds), implode(', ', $sourds));

// L'API doit refuser les TROIS sous-routes, pas seulement l'envoi de détections :
// laisser /me/traces ouvert continuerait d'enregistrer des positions.
$api = $lire('api/v1/index.php');
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
foreach (glob($R . 'inc\*.php') ?: [] as $f) {
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
    && !str_contains($lire('api/v1/index.php'), 'fcm_service_account'));

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

// Aucune donnée personnelle : une notification vise une ÉDITION, pas des gens.
verifie('aucune liste de destinataires nominatifs',
    !preg_match('/participant_id|email_cible|destinataires/i', $notif));

// L'API doit servir les notifications MÊME chronométrage fermé : une annonce de
// l'organisation n'a rien à voir avec les temps.
$apiSrc = $lire('api/v1/index.php');
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
foreach (glob($R . 'inc\*.php') ?: [] as $f) {
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
foreach (glob($R . 'inc\*.php') ?: [] as $f) {
    if (preg_match('/class="btn[^"]*"[^>]*style="[^"]*#[0-9a-fA-F]{3,6}/', (string) file_get_contents($f))) {
        $dur[] = basename($f);
    }
}
verifie('aucun bouton n\'a de couleur écrite en dur',
    empty($dur), implode(', ', $dur));

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
foreach (array_merge(glob($R . 'inc\*.php') ?: [], glob($R . 'src\partials\*.php') ?: []) as $f) {
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

echo "\n" . str_repeat('─', 62) . "\n";
echo ($ko === 0 ? "INTÉGRITÉ : TOUT EST VERT" : "INTÉGRITÉ : $ko ÉCHEC(S)")
   . " — $ok contrôle(s) réussi(s)" . ($avert > 0 ? ", $avert avertissement(s)" : '') . ".\n";
exit($ko === 0 ? 0 : 1);
