<?php
/**
 * Template email personnalisable
 * Variables disponibles : $type, $mailTitle, $description, $firstname, $lastname,
 * $date, $instagram, $facebook, $cancer, $mail_email, $mail_phone,
 * $qrcode, $inscription_no, $mtc (config template)
 */

// ── Config avec fallbacks ──
$c = ($mtc['colors'] ?? []) + [
    'bg'=>'#f1f5f9','card_bg'=>'#ffffff','header_bg1'=>'#F42182','header_bg2'=>'#db2777',
    'accent'=>'#F42182','title_bg'=>'#fdf2f8','tips_bg'=>'#0f172a',
    'banner_bg1'=>'#fdf2f8','banner_bg2'=>'#fce7f3','banner_border'=>'#fbcfe8','footer_bg'=>'#0f172a'
];
$t = ($mtc['texts'] ?? []) + [
    'header_title'=>'Forbach en Rose','header_subtitle'=>'Course caritative contre le cancer du sein',
    'banner_title'=>'Ensemble contre le cancer du sein',
    'banner_text'=>'Merci de votre participation et de votre engagement pour cette belle cause.',
    'footer_title'=>'Forbach en Rose','footer_subtitle'=>'Course caritative contre le cancer du sein',
    'label_participant'=>'Participant','label_date'=>"Date de l'événement",'label_lieu'=>'Lieu de départ',
    'value_lieu'=>'Piscine de Forbach, Moselle','qrcode_title'=>'Votre QR Code','qrcode_subtitle'=>'Présentez-le le jour J',
    'tips_title'=>'Conseils pour le jour J','tips_1'=>'Arrivez 30 minutes avant le départ',
    'tips_2'=>'Portez des vêtements roses pour soutenir la cause','tips_3'=>'Prenez des chaussures confortables',
    'contact_title'=>'Une question ?'
];
$font = $mtc['font'] ?? 'system';
$sectionOrder = $mtc['section_order'] ?? ['details','tips','description','qrcode','banner','contact'];
$headerImage = $mtc['header_image'] ?? '';
$r = ($mtc['radius'] ?? []) + ['card'=>16,'section'=>12,'badge'=>20];
$visibility = $mtc['visibility'] ?? [];
// La section "description" porte le corps de certains mails système
// (newsletter "nouvel article", lien de réinitialisation de mot de passe) :
// on garantit qu'elle reste toujours visible pour ces sous-types, quelle que
// soit la configuration de visibilité enregistrée (un mail vide n'a aucun sens).
if (isset($visibility['description']) && is_array($visibility['description'])) {
    foreach (['new_article', 'password_reset', 'bulk_recap'] as $_forcedSubtype) {
        if (!in_array($_forcedSubtype, $visibility['description'], true)) {
            $visibility['description'][] = $_forcedSubtype;
        }
    }
}
$mailSubtype = $mail_subtype ?? ($type === 'inscription' ? 'inscription' : 'info');

// Convert relative header image path to absolute URL for emails
if (!empty($headerImage) && !preg_match('#^https?://#', $headerImage)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    $headerImage = $protocol . '://' . $host . $base . '/' . ltrim(str_replace('../', '', $headerImage), '/');
}

$fontFamily = $font === 'system'
    ? "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif"
    : htmlspecialchars($font) . ",-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif";

// Helper: darken accent for banner text
if (!function_exists('hexDarken')) {
function hexDarken(string $hex, float $factor = 0.35): string {
    $hex = ltrim($hex, '#');
    $r = max(0, (int)(hexdec(substr($hex,0,2)) * (1-$factor)));
    $g = max(0, (int)(hexdec(substr($hex,2,2)) * (1-$factor)));
    $b = max(0, (int)(hexdec(substr($hex,4,2)) * (1-$factor)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}}
$accentDark = hexDarken($c['accent'], 0.25);
$accentDarker = hexDarken($c['accent'], 0.45);

// ── Section renderers ──
$sections = [];

// Details (inscription only)
$sections['details'] = function() use ($type, $lastname, $firstname, $date, $c, $r, $t) {
    if ($type !== 'inscription' || (empty($lastname) && empty($firstname))) return '';
    $accent = htmlspecialchars($c['accent']);
    $rs = (int)$r['section'];
    $name = htmlspecialchars(mb_strtoupper($lastname ?? '', 'UTF-8') . ' ' . mb_convert_case($firstname ?? '', MB_CASE_TITLE, 'UTF-8'));
    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="border-radius:'.$rs.'px;overflow:hidden;margin-bottom:28px;border:1px solid #f1f5f9;">';
    $html .= '<tr><td style="padding:18px 24px;background:#f8fafc;border-left:3px solid '.$accent.';border-bottom:1px solid #f1f5f9;">';
    $html .= '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:'.$accent.';">'.htmlspecialchars($t['label_participant']).'</span><br>';
    $html .= '<span style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px;display:inline-block;">'.$name.'</span>';
    $html .= '</td></tr>';
    if (!empty($date)) {
        $html .= '<tr><td style="padding:18px 24px;background:#f8fafc;border-left:3px solid '.$accent.';border-bottom:1px solid #f1f5f9;">';
        $html .= '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:'.$accent.';">'.htmlspecialchars($t['label_date']).'</span><br>';
        $html .= '<span style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px;display:inline-block;">'.htmlspecialchars($date).'</span>';
        $html .= '</td></tr>';
    }
    $html .= '<tr><td style="padding:18px 24px;background:#f8fafc;border-left:3px solid '.$accent.';">';
    $html .= '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:'.$accent.';">'.htmlspecialchars($t['label_lieu']).'</span><br>';
    $html .= '<span style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px;display:inline-block;">'.htmlspecialchars($t['value_lieu']).'</span>';
    $html .= '</td></tr></table>';
    return $html;
};

// Tips (inscription only)
$sections['tips'] = function() use ($type, $c, $r, $t) {
    if ($type !== 'inscription') return '';
    $accent = htmlspecialchars($c['accent']);
    $bg = htmlspecialchars($c['tips_bg']);
    $rs = (int)$r['section'];
    return '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
        <tr><td style="padding:24px;background:'.$bg.';border-radius:'.$rs.'px;">
            <p style="font-size:14px;font-weight:700;color:#ffffff;margin:0 0 14px;">'.htmlspecialchars($t['tips_title']).'</p>
            <table cellpadding="0" cellspacing="0"><tr>
                <td style="padding:0 0 8px;font-size:14px;color:rgba(255,255,255,.75);line-height:1.6;">
                    <span style="color:'.$accent.';font-weight:700;margin-right:8px;">&#9656;</span>'.htmlspecialchars($t['tips_1']).'
                </td>
            </tr><tr>
                <td style="padding:0 0 8px;font-size:14px;color:rgba(255,255,255,.75);line-height:1.6;">
                    <span style="color:'.$accent.';font-weight:700;margin-right:8px;">&#9656;</span>'.htmlspecialchars($t['tips_2']).'
                </td>
            </tr><tr>
                <td style="font-size:14px;color:rgba(255,255,255,.75);line-height:1.6;">
                    <span style="color:'.$accent.';font-weight:700;margin-right:8px;">&#9656;</span>'.htmlspecialchars($t['tips_3']).'
                </td>
            </tr></table>
        </td></tr></table>';
};

// Description (custom content)
$sections['description'] = function() use ($description, $c, $r) {
    if (empty($description)) return '';
    $accent = htmlspecialchars($c['accent']);
    $rs = (int)$r['section'];
    return '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
        <tr><td style="padding:24px;background:#f8fafc;border-radius:'.$rs.'px;border-left:3px solid '.$accent.';">
            <div style="font-size:15px;line-height:1.7;color:#334155;">'.$description.'</div>
        </td></tr></table>';
};

// QR Code (inscription only)
$sections['qrcode'] = function() use ($qrcode, $type, $inscription_no, $c, $r, $t) {
    if (empty($qrcode) || $type !== 'inscription') return '';
    $accent = htmlspecialchars($c['accent']);
    $rs = (int)$r['section'];
    return '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
        <tr><td style="padding:28px;background:#ffffff;border-radius:'.$rs.'px;border:2px dashed '.$accent.';text-align:center;">
            <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:'.$accent.';margin:0 0 6px;">'.htmlspecialchars($t['qrcode_title']).'</p>
            <p style="font-size:13px;color:#64748b;margin:0 0 4px;">Billet n&deg; '.htmlspecialchars((string)($inscription_no ?? '')).'</p>
            <p style="font-size:13px;color:#64748b;margin:0 0 16px;">'.htmlspecialchars($t['qrcode_subtitle']).'</p>
            <img src="'.$qrcode.'" alt="QR Code" width="200" height="200" style="display:inline-block;border-radius:16px;">
        </td></tr></table>';
};

// Banner
$sections['banner'] = function() use ($c, $t, $accentDark, $accentDarker, $r) {
    $rs = (int)$r['section'];
    return '<table width="100%" cellpadding="0" cellspacing="0" style="border-radius:'.$rs.'px;overflow:hidden;">
        <tr><td style="background:linear-gradient(135deg,'.htmlspecialchars($c['banner_bg1']).' 0%,'.htmlspecialchars($c['banner_bg2']).' 100%);padding:24px 28px;text-align:center;border:1px solid '.htmlspecialchars($c['banner_border']).';">
            <p style="font-size:15px;font-weight:700;color:'.$accentDarker.';margin:0 0 6px;">'.htmlspecialchars($t['banner_title']).'</p>
            <p style="font-size:13px;color:'.$accentDark.';margin:0;font-weight:400;">'.htmlspecialchars($t['banner_text']).'</p>
        </td></tr></table>';
};

// Contact
$sections['contact'] = function() use ($mail_email, $mail_phone, $c, $t) {
    if (empty($mail_email) && empty($mail_phone)) return '';
    $accent = htmlspecialchars($c['accent']);
    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:28px;">
        <tr><td style="padding-top:24px;border-top:1px solid #e2e8f0;text-align:center;">
            <p style="font-size:14px;font-weight:700;color:#0f172a;margin:0 0 8px;">'.htmlspecialchars($t['contact_title']).'</p>
            <p style="font-size:14px;color:#64748b;margin:0;line-height:1.8;">';
    if (!empty($mail_email)) {
        $html .= '<a href="mailto:'.htmlspecialchars($mail_email).'" style="color:'.$accent.';text-decoration:none;font-weight:500;">'.htmlspecialchars($mail_email).'</a>';
    }
    if (!empty($mail_email) && !empty($mail_phone)) $html .= '<br>';
    if (!empty($mail_phone)) {
        $html .= htmlspecialchars($mail_phone);
    }
    $html .= '</p></td></tr></table>';
    return $html;
};

// Custom sections — create renderer for each custom_* section
foreach ($sectionOrder as $_secKey) {
    if (strpos($_secKey, 'custom') === 0 && !isset($sections[$_secKey])) {
        $sections[$_secKey] = function() use ($_secKey, $t, $r) {
            $rs = (int)$r['section'];
            $texts = [];
            foreach ($t as $k => $v) {
                if (strpos($k, $_secKey.'_') === 0) $texts[] = $v;
            }
            if (empty($texts)) return '';
            $html = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">';
            $html .= '<tr><td style="padding:24px;background:#f8fafc;border-radius:'.$rs.'px;text-align:center;">';
            foreach ($texts as $txt) {
                $html .= '<div style="font-size:15px;line-height:1.7;color:#334155;margin-bottom:8px;">'.htmlspecialchars($txt).'</div>';
            }
            $html .= '</td></tr></table>';
            return $html;
        };
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($t['header_title']) ?></title>
</head>
<body style="margin:0;padding:0;background:<?= htmlspecialchars($c['bg']) ?>;font-family:<?= $fontFamily ?>;color:#0f172a;-webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:<?= htmlspecialchars($c['bg']) ?>;padding:32px 16px;">
        <tr><td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:<?= htmlspecialchars($c['card_bg']) ?>;border-radius:<?= (int)$r['card'] ?>px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.07);">

            <!-- Header -->
            <tr><td style="background:linear-gradient(135deg,<?= htmlspecialchars($c['header_bg1']) ?> 0%,<?= htmlspecialchars($c['header_bg2']) ?> 100%);padding:40px 40px 36px;text-align:center;">
                <?php if (!empty($headerImage)): ?>
                    <img src="<?= htmlspecialchars($headerImage) ?>" alt="<?= htmlspecialchars($t['header_title']) ?>" style="max-width:80%;max-height:80px;height:auto;">
                <?php else: ?>
                    <h1 style="color:#ffffff;font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-0.02em;"><?= htmlspecialchars($t['header_title']) ?></h1>
                <?php endif; ?>
                <p style="color:rgba(255,255,255,.75);font-size:14px;margin:0;font-weight:400;"><?= htmlspecialchars($t['header_subtitle']) ?></p>
            </td></tr>

            <!-- Body -->
            <tr><td style="padding:0;">

                <!-- Title section -->
                <table width="100%" cellpadding="0" cellspacing="0" style="background:<?= htmlspecialchars($c['title_bg']) ?>;">
                    <tr><td style="padding:32px 40px 28px;text-align:center;">
                        <?php if ($type === 'inscription'): ?>
                            <p style="display:inline-block;background:<?= htmlspecialchars($c['card_bg']) ?>;color:<?= htmlspecialchars($c['accent']) ?>;font-size:12px;font-weight:700;padding:5px 16px;border-radius:<?= (int)$r['badge'] ?>px;margin:0 0 16px;text-transform:uppercase;letter-spacing:0.08em;">Inscription confirm&eacute;e</p><br>
                            <h2 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">
                                Bienvenue <?= htmlspecialchars(mb_convert_case($firstname ?? '', MB_CASE_TITLE, 'UTF-8')) ?> !
                            </h2>
                            <p style="font-size:15px;color:#64748b;margin:0;line-height:1.6;">Votre inscription a bien &eacute;t&eacute; enregistr&eacute;e.<br>Merci de rejoindre cette belle cause.</p>
                        <?php elseif (!empty($mailTitle)): ?>
                            <h2 style="font-size:22px;font-weight:700;color:#0f172a;margin:0;"><?= htmlspecialchars($mailTitle) ?></h2>
                        <?php endif; ?>
                    </td></tr>
                </table>

                <!-- Content: sections in custom order -->
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr><td style="padding:32px 40px 36px;">
                        <?php foreach ($sectionOrder as $sec):
                            // Check visibility config
                            if (!empty($visibility)) {
                                $secVis = $visibility[$sec] ?? null;
                                if ($secVis !== null && !in_array($mailSubtype, $secVis)) continue;
                            }
                            if (isset($sections[$sec])) echo $sections[$sec]();
                        endforeach; ?>
                    </td></tr>
                </table>

            </td></tr>

            <!-- Footer -->
            <tr><td style="background:<?= htmlspecialchars($c['footer_bg']) ?>;padding:32px 40px;text-align:center;">
                <?php if (!empty($facebook) || !empty($instagram) || !empty($cancer)): ?>
                <p style="margin:0 0 16px;">
                    <?php if (!empty($facebook)): ?>
                        <a href="<?= htmlspecialchars($facebook) ?>" style="display:inline-block;margin:0 6px;padding:8px 16px;background:rgba(255,255,255,.08);border-radius:6px;color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;font-weight:500;">Facebook</a>
                    <?php endif; ?>
                    <?php if (!empty($instagram)): ?>
                        <a href="<?= htmlspecialchars($instagram) ?>" style="display:inline-block;margin:0 6px;padding:8px 16px;background:rgba(255,255,255,.08);border-radius:6px;color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;font-weight:500;">Instagram</a>
                    <?php endif; ?>
                    <?php if (!empty($cancer)): ?>
                        <a href="<?= htmlspecialchars($cancer) ?>" style="display:inline-block;margin:0 6px;padding:8px 16px;background:rgba(255,255,255,.08);border-radius:6px;color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;font-weight:500;">Ligue contre le cancer</a>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <p style="font-size:14px;color:rgba(255,255,255,.7);margin:0 0 4px;font-weight:600;"><?= htmlspecialchars($t['footer_title']) ?></p>
                <p style="font-size:12px;color:rgba(255,255,255,.4);margin:0;line-height:1.6;"><?= htmlspecialchars($t['footer_subtitle']) ?></p>
            </td></tr>

        </table>
        </td></tr>
    </table>
</body>
</html>
