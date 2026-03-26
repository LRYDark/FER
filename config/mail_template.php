<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forbach en Rose</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0f172a;-webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
        <tr><td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.07);">

            <!-- Header: gradient rose → rose foncé -->
            <tr><td style="background:linear-gradient(135deg,#ec4899 0%,#db2777 100%);padding:40px 40px 36px;text-align:center;">
                <h1 style="color:#ffffff;font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-0.02em;">Forbach en Rose</h1>
                <p style="color:rgba(255,255,255,.75);font-size:14px;margin:0;font-weight:400;">Course caritative contre le cancer du sein</p>
            </td></tr>

            <!-- Body -->
            <tr><td style="padding:0;">

                <!-- Title section with colored background -->
                <table width="100%" cellpadding="0" cellspacing="0" style="background:#fdf2f8;">
                    <tr><td style="padding:32px 40px 28px;text-align:center;">
                        <?php if ($type === 'inscription'): ?>
                            <p style="display:inline-block;background:#ffffff;color:#db2777;font-size:12px;font-weight:700;padding:5px 16px;border-radius:20px;margin:0 0 16px;text-transform:uppercase;letter-spacing:0.08em;">Inscription confirmée</p><br>
                            <h2 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 8px;">
                                Bienvenue <?= htmlspecialchars(mb_convert_case($firstname ?? '', MB_CASE_TITLE, 'UTF-8')) ?> !
                            </h2>
                            <p style="font-size:15px;color:#64748b;margin:0;line-height:1.6;">Votre inscription a bien été enregistrée.<br>Merci de rejoindre cette belle cause.</p>
                        <?php elseif (!empty($mailTitle)): ?>
                            <h2 style="font-size:22px;font-weight:700;color:#0f172a;margin:0;"><?= htmlspecialchars($mailTitle) ?></h2>
                        <?php endif; ?>
                    </td></tr>
                </table>

                <!-- Content area -->
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr><td style="padding:32px 40px 36px;">

                        <!-- Inscription: participant details -->
                        <?php if ($type === 'inscription' && (!empty($lastname) || !empty($firstname))): ?>
                        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:12px;overflow:hidden;margin-bottom:28px;border:1px solid #f1f5f9;">
                            <tr>
                                <td style="padding:18px 24px;background:#f8fafc;border-left:3px solid #ec4899;border-bottom:1px solid #f1f5f9;">
                                    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#ec4899;">Participant</span><br>
                                    <span style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px;display:inline-block;"><?= htmlspecialchars(mb_strtoupper($lastname ?? '', 'UTF-8') . ' ' . mb_convert_case($firstname ?? '', MB_CASE_TITLE, 'UTF-8')) ?></span>
                                </td>
                            </tr>
                            <?php if (!empty($date)): ?>
                            <tr>
                                <td style="padding:18px 24px;background:#f8fafc;border-left:3px solid #ec4899;border-bottom:1px solid #f1f5f9;">
                                    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#ec4899;">Date de l'événement</span><br>
                                    <span style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px;display:inline-block;"><?= htmlspecialchars($date) ?></span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="padding:18px 24px;background:#f8fafc;border-left:3px solid #ec4899;">
                                    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#ec4899;">Lieu de départ</span><br>
                                    <span style="font-size:16px;color:#0f172a;font-weight:600;margin-top:4px;display:inline-block;">Piscine de Forbach, Moselle</span>
                                </td>
                            </tr>
                        </table>

                        <!-- Tips -->
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                            <tr><td style="padding:24px;background:#0f172a;border-radius:12px;">
                                <p style="font-size:14px;font-weight:700;color:#ffffff;margin:0 0 14px;">Conseils pour le jour J</p>
                                <table cellpadding="0" cellspacing="0"><tr>
                                    <td style="padding:0 0 8px;font-size:14px;color:rgba(255,255,255,.75);line-height:1.6;">
                                        <span style="color:#ec4899;font-weight:700;margin-right:8px;">&#9656;</span>Arrivez 30 minutes avant le départ
                                    </td>
                                </tr><tr>
                                    <td style="padding:0 0 8px;font-size:14px;color:rgba(255,255,255,.75);line-height:1.6;">
                                        <span style="color:#ec4899;font-weight:700;margin-right:8px;">&#9656;</span>Portez des vêtements roses pour soutenir la cause
                                    </td>
                                </tr><tr>
                                    <td style="font-size:14px;color:rgba(255,255,255,.75);line-height:1.6;">
                                        <span style="color:#ec4899;font-weight:700;margin-right:8px;">&#9656;</span>Prenez des chaussures confortables
                                    </td>
                                </tr></table>
                            </td></tr>
                        </table>
                        <?php endif; ?>

                        <!-- Description (info mails: HTML content) -->
                        <?php if (!empty($description)): ?>
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                            <tr><td style="padding:24px;background:#f8fafc;border-radius:12px;border-left:3px solid #ec4899;">
                                <div style="font-size:15px;line-height:1.7;color:#334155;">
                                    <?= $description ?>
                                </div>
                            </td></tr>
                        </table>
                        <?php endif; ?>

                        <!-- Motivation banner -->
                        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:12px;overflow:hidden;">
                            <tr><td style="background:linear-gradient(135deg,#fdf2f8 0%,#fce7f3 100%);padding:24px 28px;text-align:center;border:1px solid #fbcfe8;">
                                <p style="font-size:15px;font-weight:700;color:#9d174d;margin:0 0 6px;">Ensemble contre le cancer du sein</p>
                                <p style="font-size:13px;color:#be185d;margin:0;font-weight:400;">Merci de votre participation et de votre engagement pour cette belle cause.</p>
                            </td></tr>
                        </table>

                        <!-- Contact -->
                        <?php if (!empty($mail_email) || !empty($mail_phone)): ?>
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:28px;">
                            <tr><td style="padding-top:24px;border-top:1px solid #e2e8f0;text-align:center;">
                                <p style="font-size:14px;font-weight:700;color:#0f172a;margin:0 0 8px;">Une question ?</p>
                                <p style="font-size:14px;color:#64748b;margin:0;line-height:1.8;">
                                    <?php if (!empty($mail_email)): ?>
                                        <a href="mailto:<?= htmlspecialchars($mail_email) ?>" style="color:#ec4899;text-decoration:none;font-weight:500;"><?= htmlspecialchars($mail_email) ?></a>
                                    <?php endif; ?>
                                    <?php if (!empty($mail_email) && !empty($mail_phone)): ?><br><?php endif; ?>
                                    <?php if (!empty($mail_phone)): ?>
                                        <?= htmlspecialchars($mail_phone) ?>
                                    <?php endif; ?>
                                </p>
                            </td></tr>
                        </table>
                        <?php endif; ?>

                    </td></tr>
                </table>

            </td></tr>

            <!-- Footer -->
            <tr><td style="background:#0f172a;padding:32px 40px;text-align:center;">
                <?php if (!empty($facebook) || !empty($instagram)): ?>
                <p style="margin:0 0 16px;">
                    <?php if (!empty($facebook)): ?>
                        <a href="<?= htmlspecialchars($facebook) ?>" style="display:inline-block;margin:0 6px;padding:8px 16px;background:rgba(255,255,255,.08);border-radius:6px;color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;font-weight:500;">Facebook</a>
                    <?php endif; ?>
                    <?php if (!empty($instagram)): ?>
                        <a href="<?= htmlspecialchars($instagram) ?>" style="display:inline-block;margin:0 6px;padding:8px 16px;background:rgba(255,255,255,.08);border-radius:6px;color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;font-weight:500;">Instagram</a>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <p style="font-size:14px;color:rgba(255,255,255,.7);margin:0 0 4px;font-weight:600;">Forbach en Rose</p>
                <p style="font-size:12px;color:rgba(255,255,255,.4);margin:0;line-height:1.6;">Course caritative contre le cancer du sein</p>
            </td></tr>

        </table>
        </td></tr>
    </table>
</body>
</html>
