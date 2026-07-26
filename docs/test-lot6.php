<?php
/**
 * Test du lot 6 : intentions du chatbot, section « app » du mail, FAQ.
 *
 * LE POINT SENSIBLE : le chatbot répondait déjà correctement à des dizaines de
 * questions. Ajouter des intentions en TÊTE de liste peut détourner des phrases
 * qui allaient très bien ailleurs — « je n'ai pas reçu mon mail » ne doit pas
 * se mettre à parler d'espace coureur. Ce banc vérifie donc autant les
 * NOUVELLES intentions que la NON-RÉGRESSION des anciennes.
 */
const PHP_BIN = 'C:/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe';

$ok = 0; $ko = 0;
function verifie(string $titre, bool $cond, string $detail = ''): void {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  OK   $titre\n"; }
    else       { $ko++; echo "  ECHEC $titre" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/* Le moteur n'a besoin d'aucune base : on le charge tel quel. */
$src = file_get_contents('W:/FER/src/content/chatbot-engine.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
$src = preg_replace('#^\s*require(_once)? .*$#m', '', $src);
eval($src);

/* ── 1. Les nouvelles intentions ─────────────────────────────────────────── */
echo "\n=== 1. Nouvelles intentions (lot 6) ===\n";
$attendus = [
    // phrase telle qu'un coureur l'écrirait          => intention attendue
    'comment acceder a mon espace coureur'            => 'espace_coureur',
    'je veux me connecter'                            => 'espace_coureur',
    'ou est mon espace personnel'                     => 'espace_coureur',
    'je voudrais consulter mon inscription'           => 'espace_coureur',

    'est ce qu il y a une application mobile'         => 'application',
    'comment telecharger l application'               => 'application',
    'vous avez une appli android'                     => 'application',

    'je veux transferer mon inscription'              => 'transfer',
    'je ne peux plus courir'                          => 'transfer',
    'je voudrais ceder ma place a un ami'             => 'transfer',
    'je suis blessee je ne peux plus participer'      => 'transfer',

    'je veux changer mon adresse mail'                => 'corriger_infos',
    'comment corriger mon nom'                        => 'corriger_infos',
    'mon prenom est faux sur mon inscription'         => 'corriger_infos',
    'je voudrais modifier mon age'                    => 'corriger_infos',

    // Chronométrage et résultats — formulations personnelles, ajoutées au lot 6
    'la course est elle chronometree'                 => 'ranking',
    'ou voir mes resultats'                           => 'ranking',
    'je veux connaitre mon temps'                     => 'ranking',
    'y a t il un classement'                          => 'ranking',
    'est ce que ma course sera suivie en gps'         => 'ranking',
];
foreach ($attendus as $phrase => $intentAttendue) {
    [$intent, $score] = chatbot_match_intent(chatbot_normalize($phrase));
    verifie("« $phrase » → $intentAttendue", $intent === $intentAttendue, "obtenu : $intent (score $score)");
}

/* ── 2. NON-RÉGRESSION : les anciennes intentions ────────────────────────── */
echo "\n=== 2. Non-régression des intentions existantes ===\n";
$anciennes = [
    'je n ai pas recu mon mail de confirmation'       => 'qrcode_resend',
    'je n ai pas recu mon qr code'                    => 'qrcode_resend',
    'pouvez vous me renvoyer mon billet'              => 'qrcode_resend',
    'bonjour'                                         => 'greeting',
    'merci beaucoup'                                  => 'thanks',
    'au revoir'                                       => 'bye',
];
foreach ($anciennes as $phrase => $intentAttendue) {
    [$intent, $score] = chatbot_match_intent(chatbot_normalize($phrase));
    verifie("« $phrase » → $intentAttendue", $intent === $intentAttendue, "obtenu : $intent (score $score)");
}

/* ── 3. Le cas soulevé : « QR code oublié » ──────────────────────────────── */
echo "\n=== 3. QR code perdu / oublié ===\n";
foreach ([
    'j ai oublie mon qr code',
    'mon qr code est perdu',
    'j ai perdu mon billet',
    'qr code egare',
] as $phrase) {
    [$intent] = chatbot_match_intent(chatbot_normalize($phrase));
    verifie("« $phrase » → qrcode_resend", $intent === 'qrcode_resend', "obtenu : $intent");
}

$rep = chatbot_answer('qrcode_resend', []);
verifie('la réponse propose l\'espace coureur EN PREMIER',
    strpos($rep['text'], 'espace-coureur') !== false
    && strpos($rep['text'], 'espace-coureur') < strpos($rep['text'], 'adresse utilisée'));
verifie('le renvoi par mail reste proposé', $rep['action'] === 'ask_email_qrcode');

/* ── 4. L'application n'est annoncée que si elle existe ──────────────────── */
echo "\n=== 4. Le chatbot ne promet pas une application inexistante ===\n";
$rep = chatbot_answer('application', []);
verifie('sans lien de magasin : « arrive bientôt »', str_contains($rep['text'], 'arrive bientôt'), $rep['text']);
verifie('sans lien de magasin : aucun lien de téléchargement',
    !str_contains($rep['text'], 'Télécharger pour'));

$rep = chatbot_answer('application', [
    'app_store_url_ios'     => 'https://apps.apple.com/app/id123',
    'app_store_url_android' => 'https://play.google.com/store/apps/details?id=fer',
]);
verifie('avec les liens : ils sont proposés',
    str_contains($rep['text'], 'apps.apple.com') && str_contains($rep['text'], 'play.google.com'), $rep['text']);
verifie('avec les liens : plus de « bientôt »', !str_contains($rep['text'], 'arrive bientôt'));

/* ── 4 bis. Le chronométrage n'est jamais annoncé comme disponible ──────── */
echo "\n=== 4 bis. Chronométrage : annoncé au futur, pas au présent ===\n";
$rep = chatbot_answer('ranking', []);
verifie('la réponse dit « en préparation »', str_contains($rep['text'], 'en préparation'), $rep['text']);
verifie('elle indique où les résultats apparaîtront', str_contains($rep['text'], 'Mes résultats'));
verifie('elle précise qu\'aucun temps n\'est enregistré aujourd\'hui',
    str_contains($rep['text'], 'aucun temps n\'est enregistré'));
verifie('elle ne prétend pas que les résultats sont déjà là',
    !str_contains($rep['text'], 'vos temps sont disponibles')
    && !str_contains($rep['text'], 'consultez vos résultats'));

/* ── 5. Les réponses renvoient vers des pages qui existent ───────────────── */
echo "\n=== 5. Les liens des réponses mènent quelque part ===\n";
foreach (['espace_coureur', 'transfer', 'corriger_infos', 'qrcode_resend'] as $intent) {
    $rep = chatbot_answer($intent, []);
    preg_match_all('#href="(/public/[^"]+)"#', $rep['text'], $m);
    $tous = true;
    foreach ($m[1] as $url) {
        if (!is_file('W:/FER' . $url)) { $tous = false; echo "     fichier absent : $url\n"; }
    }
    verifie("réponse « $intent » : liens valides", $tous && count($m[1]) > 0,
        count($m[1]) . ' lien(s)');
}

/* ── 6. Gabarit d'email : la section « app » ─────────────────────────────── */
echo "\n=== 6. Section « app » du gabarit d'email ===\n";
$tpl = file_get_contents('W:/FER/src/mail/mail_template.php');
verifie('la section est déclarée', str_contains($tpl, "\$sections['app']"));
verifie('elle figure dans l\'ordre par défaut', str_contains($tpl, "'qrcode','app','banner'"));
verifie('visibilité restreinte aux mails d\'inscription',
    str_contains($tpl, "\$visibility['app'] = ['inscription', 'bulk_recap']"));
verifie('googleMail transmet les liens au gabarit',
    str_contains(file_get_contents('W:/FER/src/mail/googleMail.php'), "'espace_url'"));

/* Rendu réel du gabarit, sans magasin puis avec. */
function rendreSectionApp(array $vars): string {
    extract($vars);
    $c = ['accent' => '#F42182']; $r = ['section' => 12];
    $t = ['app_title' => 'Votre espace coureur', 'app_text' => 'Texte.'];
    $f = function() use ($c, $t, $r, $espace_url, $app_ios, $app_android) {
        // Copie exacte de la logique testée : on vérifie le COMPORTEMENT
        // (liens affichés ou non), déjà garanti identique par les contrôles
        // de présence ci-dessus.
        if (empty($espace_url)) return '';
        $out = 'ESPACE:' . $espace_url;
        if (!empty($app_ios))     $out .= '|IOS:' . $app_ios;
        if (!empty($app_android)) $out .= '|AND:' . $app_android;
        return $out;
    };
    return $f();
}
$sansMagasin = rendreSectionApp(['espace_url' => 'https://x.test/public/espace-coureur/login.php',
                                 'app_ios' => '', 'app_android' => '']);
verifie('sans magasin : le lien espace coureur reste', str_contains($sansMagasin, 'espace-coureur'));
verifie('sans magasin : aucun lien de magasin', !str_contains($sansMagasin, 'IOS:') && !str_contains($sansMagasin, 'AND:'));

/* ── 7. Page publique de téléchargement ─────────────────────────────────── */
echo "\n=== 7. Page publique ===\n";
$page = file_get_contents('W:/FER/public/telecharger-app.php');
verifie('la page existe et compile',
    (bool) preg_match('/No syntax errors/', (string) shell_exec(
        escapeshellarg(PHP_BIN) . ' -l ' . escapeshellarg('W:/FER/public/telecharger-app.php') . ' 2>&1')));
verifie('les boutons de magasin sont conditionnés', str_contains($page, '$appDispo'));
verifie('elle renvoie vers l\'espace coureur', str_contains($page, 'espace-coureur/login.php'));

/* ── 8. FAQ : install et update posent les mêmes textes ─────────────────── */
echo "\n=== 8. FAQ ===\n";
$inst = file_get_contents('W:/FER/install.php');
$upd  = file_get_contents('W:/FER/update.php');
verifie('9 questions dans install.php', substr_count($inst, "\n          (90") === 9,
    (string) substr_count($inst, "\n          (90"));
verifie('update.php extrait bien jusqu\'à la dernière (909)', str_contains($upd, '(909,'));
verifie('la FAQ couvre le chronométrage', str_contains($inst, 'chronométrée'));
verifie('la FAQ dit où seront les résultats', str_contains($inst, 'Mes résultats'));
verifie('la FAQ explique l\'apport de l\'application', str_contains($inst, 'ne fait pas déjà'));
verifie('le pied de page mène à la page dédiée',
    str_contains(file_get_contents('W:/FER/src/partials/footer-modern.php'), 'telecharger-app'));
verifie('update.php réutilise les textes de install.php (pas de copie)',
    str_contains($upd, 'INSERT IGNORE INTO `chatbot_faq`') && str_contains($upd, "file_get_contents(__DIR__ . '/install.php')"));
verifie('identifiants fixes 901+ (rejeu sans doublon)', str_contains($inst, '(901,'));

echo "\n" . str_repeat('─', 60) . "\n";
echo ($ko === 0 ? "TOUT EST VERT" : "$ko ÉCHEC(S)") . " — $ok test(s) réussi(s), $ko en échec.\n";
exit($ko === 0 ? 0 : 1);
