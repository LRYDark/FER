<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maintenance</title>
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    :root { --pink: #ec4899; --dark: #0f172a; }
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
      box-shadow: 0 8px 24px rgba(236,72,153,0.25);
    }
    .error-btn svg {
      width: 18px;
      height: 18px;
    }
  </style>
</head>
<body>
  <div class="error-container">
    <span class="error-code">503</span>
    <div class="error-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
      </svg>
    </div>
    <h1 class="error-title">Maintenance en cours</h1>
    <p class="error-desc">Le site est temporairement indisponible pour maintenance. Veuillez réessayer dans quelques instants.</p>
    <a href="/FER/public/accueil.php" class="error-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      Réessayer
    </a>
  </div>
</body>
</html>
