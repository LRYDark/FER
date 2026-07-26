<?php /* Panneau droit des pages d'authentification (jr-theme) : halos d'accent
         + ruban Forbach en Rose qui se redessine en boucle (remplace le globe
         générique du kit — styles autonomes, jr-theme non modifié).

         $authArtKicker : surtitre du panneau. Par défaut « Administration »,
         puisque ce panneau ne servait qu'aux pages d'administration. L'espace
         coureur pose sa propre valeur AVANT l'include — afficher
         « Administration » à un coureur laisserait croire qu'il s'est trompé
         de porte. */ ?>
<aside class="auth-art" aria-hidden="true">
  <div class="art-inner">
    <div>
      <span class="kicker"><?= htmlspecialchars($authArtKicker ?? 'Forbach en Rose · Administration', ENT_QUOTES, 'UTF-8') ?></span>
      <h2><?= $authArtTitre ?? 'Luttons ensemble.<br>Contre le cancer.' ?></h2>
    </div>
  </div>
  <svg class="fer-ribbon" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
    <g class="fer-ribbon-cycle">
      <!-- Grand contour : geste continu, départ en bas à gauche -->
      <path class="fer-r-main" pathLength="1" d="M 5.863 19.031 L 9.35 14.53
        C 7.728 12.246 6 10.221 6 7
        a 6 5 0 0 1 12 0
        c -.005 3.22 -1.778 5.235 -3.43 7.5
        l 3.557 4.527 a 1 1 0 0 1 -.203 1.43 l -1.894 1.36 a 1 1 0 0 1 -1.384 -.215
        L 12 18 l -2.679 3.593 a 1 1 0 0 1 -1.39 .213 l -1.865 -1.353 a 1 1 0 0 1 -.203 -1.422 z"/>
      <!-- Détails intérieurs, posés en cascade (sans l'arc supérieur) -->
      <path class="fer-r-d2" pathLength="1" d="M12 11.22C11 9.997 10 9 10 8a2 2 0 0 1 4 0c0 1-.998 2.002-2.01 3.22"/>
      <path class="fer-r-d3" pathLength="1" d="M9.35 14.53 12 11.22"/>
      <path class="fer-r-d4" pathLength="1" d="m12 18 2.57-3.5"/>
    </g>
  </svg>
  <style nonce="<?= htmlspecialchars($GLOBALS['csp_nonce'] ?? '') ?>">
    .auth-art .fer-ribbon {
      position: absolute;
      right: -3%;
      bottom: -5%;
      width: 56%;
      pointer-events: none;
      overflow: visible;
    }
    .auth-art .fer-ribbon path {
      stroke: color-mix(in srgb, var(--accent, #f42182) 72%, #FFFFFF);
      stroke-width: .7;
      opacity: .75;
      stroke-dasharray: 1;
      stroke-dashoffset: 1;
      animation-duration: 9s;
      animation-timing-function: ease-in-out;
      animation-iteration-count: infinite;
    }
    /* Cycle de 9 s : le contour se dessine, les détails se posent, court
       maintien, puis tout S'EFFACE EN SE RETRAÇANT (la gomme suit le même
       chemin que le crayon : offset 0 → -1), et ça recommence sans fin. */
    .auth-art .fer-r-main { animation-name: ferRibbonMain; }
    .auth-art .fer-r-d2   { animation-name: ferRibbonD2; }
    .auth-art .fer-r-d3   { animation-name: ferRibbonD3; }
    .auth-art .fer-r-d4   { animation-name: ferRibbonD4; }
    @keyframes ferRibbonMain {
      0% { stroke-dashoffset: 1; } 40% { stroke-dashoffset: 0; }
      74% { stroke-dashoffset: 0; } 98%, 100% { stroke-dashoffset: -1; }
    }
    @keyframes ferRibbonD2 {
      0%, 42% { stroke-dashoffset: 1; } 52% { stroke-dashoffset: 0; }
      66% { stroke-dashoffset: 0; } 74%, 100% { stroke-dashoffset: -1; }
    }
    @keyframes ferRibbonD3 {
      0%, 54% { stroke-dashoffset: 1; } 60% { stroke-dashoffset: 0; }
      66% { stroke-dashoffset: 0; } 72%, 100% { stroke-dashoffset: -1; }
    }
    @keyframes ferRibbonD4 {
      0%, 60% { stroke-dashoffset: 1; } 65% { stroke-dashoffset: 0; }
      66% { stroke-dashoffset: 0; } 70%, 100% { stroke-dashoffset: -1; }
    }
    @media (prefers-reduced-motion: reduce) {
      .auth-art .fer-ribbon path { animation: none; stroke-dashoffset: 0; }
    }
  </style>
</aside>
