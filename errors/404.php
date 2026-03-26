<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Page introuvable</title>
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
    <span class="error-code">404</span>
    <div class="error-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/>
      </svg>
    </div>
    <h1 class="error-title">Page introuvable</h1>
    <p class="error-desc">La page que vous recherchez n'existe pas ou a été déplacée.</p>
    <a href="/FER/public/accueil.php" class="error-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/></svg>
      Retour à l'accueil
    </a>
  </div>
</body>
</html>
