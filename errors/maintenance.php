<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maintenance en cours</title>
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    :root { --pink: #F42182; --dark: #0f172a; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #fff;
      color: var(--dark);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }
    .error-container {
      text-align: center;
      padding: 40px 24px;
      max-width: 560px;
      position: relative;
    }
    .error-code {
      font-size: clamp(120px, 20vw, 200px);
      font-weight: 900;
      letter-spacing: -6px;
      line-height: 1;
      color: rgba(15,23,42,0.06);
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -60%);
      pointer-events: none;
      user-select: none;
      white-space: nowrap;
    }
    .error-icon {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: #fce7f3;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 28px;
      position: relative;
      z-index: 1;
    }
    .error-icon svg {
      width: 36px;
      height: 36px;
      color: var(--pink);
    }
    .error-title {
      font-size: clamp(24px, 4vw, 36px);
      font-weight: 800;
      letter-spacing: -0.03em;
      margin-bottom: 12px;
      position: relative;
      z-index: 1;
    }
    .error-desc {
      font-size: 17px;
      line-height: 1.6;
      color: rgba(15,23,42,0.6);
      margin-bottom: 32px;
      position: relative;
      z-index: 1;
    }
    .error-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 14px 28px;
      background: var(--dark);
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 15px;
      font-weight: 600;
      text-decoration: none;
      transition: all .25s ease;
      position: relative;
      z-index: 1;
    }
    .error-btn:hover {
      background: var(--pink);
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(244,33,130,0.25);
    }
    .error-btn svg {
      width: 18px;
      height: 18px;
    }
    .pulse-dot {
      width: 10px; height: 10px;
      background: var(--pink);
      border-radius: 50%;
      display: inline-block;
      margin-right: 8px;
      animation: pulse 1.5s ease-in-out infinite;
      vertical-align: middle;
    }
    @keyframes pulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: .4; transform: scale(.8); }
    }
  </style>
</head>
<body>
  <div class="error-container">
    <span class="error-code">503</span>
    <div class="error-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
      </svg>
    </div>
    <h1 class="error-title"><span class="pulse-dot"></span>Maintenance en cours</h1>
    <p class="error-desc">
      <?php if (!empty($maintenance_message)): ?>
        <?= htmlspecialchars($maintenance_message, ENT_QUOTES, 'UTF-8') ?>
      <?php else: ?>
        Notre site est temporairement indisponible pour maintenance. Nous travaillons pour revenir au plus vite. Merci de votre patience !
      <?php endif; ?>
    </p>
    <a href="javascript:location.reload()" class="error-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      Rafraichir
    </a>
  </div>
</body>
</html>
