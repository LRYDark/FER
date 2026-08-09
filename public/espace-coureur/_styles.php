<?php
/**
 * _styles.php — Feuilles de styles et thème des pages de l'espace coureur.
 * Fragment à inclure dans le <head>.
 *
 * ⚠️ AUCUN style inventé ici. On charge le MÊME système de design que
 * l'administration (css/tokens.css, base.css, components.css, app.css) et on
 * n'utilise que ses composants : .card, .rows/.row, .pill, .btn, .field,
 * .input, .seg, .iconwell. Les quelques règles ci-dessous ne font que poser
 * l'ossature de page (en-tête et largeur), toujours à partir des mêmes jetons —
 * couleurs, espacements et rayons viennent de tokens.css, jamais de valeurs
 * écrites à la main. Le thème sombre suit donc automatiquement.
 *
 * Le thème (clair / sombre / système) est propre au coureur : il vient de
 * `participants.theme` une fois connecté, du localStorage avant. Il est posé
 * AVANT tout rendu, sinon la page clignoterait en blanc avant de basculer.
 */
$ecTheme = $_SESSION[PAUTH_SESSION_KEY]['theme'] ?? null;
if (!in_array($ecTheme, PAUTH_THEMES, true)) $ecTheme = null;

/* Accent : résolu par pauth_accentVars(), la même fonction que celle utilisée
   par les pages d'authentification — une seule définition pour tout l'espace. */
$ecAccentRes = pauth_accentVars($pdo);
$ecVars      = $ecAccentRes['vars'];
$ecDataAcc   = $ecAccentRes['data'];

$ecV = function (string $rel): string {
    $p = dirname(__DIR__, 2) . '/' . $rel;
    return '../../' . $rel . '?v=' . (@filemtime($p) ?: '1');
};
?>
<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
(function () {
  var t = <?= $ecTheme !== null ? json_encode($ecTheme) : 'null' ?>;
  if (t === null) { try { t = localStorage.getItem('<?= PAUTH_THEME_KEY ?>'); } catch (e) {} }
  if (t !== 'dark' && t !== 'system') t = 'light';
  document.documentElement.setAttribute('data-theme', t);
  var sombre = t === 'dark' || (t === 'system' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.style.backgroundColor = sombre ? '#05070D' : '#E9EDF4';
  /* Mémorisé côté navigateur : la page de connexion, qui n'a pas encore de
     session, s'appuie dessus pour éviter le clignotement. */
  try { localStorage.setItem('<?= PAUTH_THEME_KEY ?>', t); } catch (e) {}
})();
</script>
<link rel="stylesheet" href="<?= $ecV('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= $ecV('css/base.css') ?>">
<link rel="stylesheet" href="<?= $ecV('css/components.css') ?>">
<link rel="stylesheet" href="<?= $ecV('css/app.css') ?>">
<?php /* admin.css porte la coquille .jr-shell / .jr-nav / .jr-main : c'est elle
         qui donne à l'espace coureur la barre latérale de l'administration,
         y compris son repli sous 991px. */ ?>
<link rel="stylesheet" href="<?= $ecV('css/admin.css') ?>">
<?php if ($ecDataAcc !== null): ?>
<script<?= isset($GLOBALS['csp_nonce']) ? ' nonce="' . htmlspecialchars($GLOBALS['csp_nonce']) . '"' : '' ?>>
document.documentElement.setAttribute('data-accent', <?= json_encode($ecDataAcc) ?>);
</script>
<?php endif; ?>
<style>
<?php if ($ecVars !== null): ?>
  /* Accent dérivé côté serveur : rendu juste dès le premier octet, sans le
     clignotement d'une couleur appliquée après coup en JavaScript. */
  :root{
    --accent-l:<?= $ecVars[0] ?>; --accent-l-strong:<?= $ecVars[1] ?>; --accent-l-ink:<?= $ecVars[2] ?>;
    --accent-d:<?= $ecVars[3] ?>; --accent-d-strong:<?= $ecVars[4] ?>; --accent-d-ink:<?= $ecVars[5] ?>;
  }
<?php endif; ?>
  /* Rien que l'ossature de contenu — la coquille vient d'admin.css, les
     composants de components.css. */
  .ec-stack { display: flex; flex-direction: column; gap: var(--sp-4); }

  /* ── La carte de l'inscription ───────────────────────────────────────────
     Même objet que dans l'application : numéro en grand, nom, puis la date
     qui ouvre l'ajout à l'agenda. Quelqu'un qui passe du téléphone au
     navigateur doit reconnaître la même chose.

     ⚠️ Les classes gardent le préfixe `ec-dossard-` : les renommer toucherait
     le HTML de plusieurs pages sans rien changer à l'écran. Le mot ne paraît
     nulle part dans l'interface.

     Les couleurs viennent des jetons d'accent (tokens.css) : le dossard suit
     donc l'accent choisi par le coureur, comme le reste de son espace. */
  .ec-dossard {
    background: color-mix(in srgb, var(--accent) 14%, var(--canvas));
    border-radius: var(--radius-xl);
    padding: var(--sp-4) var(--sp-5) var(--sp-2);
    margin-bottom: var(--sp-4);
  }
  /* Libellé à gauche, action à droite. `wrap` : sur un écran étroit l'action
     passe dessous plutôt que d'écraser le libellé. */
  .ec-dossard-tete {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sp-3);
    flex-wrap: wrap;
  }
  .ec-dossard-voir {
    display: inline-flex;
    align-items: center;
    gap: var(--sp-2);
    padding: 6px 12px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--accent) 22%, var(--canvas));
    color: color-mix(in srgb, var(--accent) 80%, var(--ink));
    font-size: var(--fs-small);
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    transition: opacity var(--dur-fast, .15s) ease;
  }
  .ec-dossard-voir:hover { opacity: .8; }

  .ec-dossard-edition {
    font-size: var(--fs-micro);
    font-weight: 800;
    letter-spacing: 0.1em;
    color: color-mix(in srgb, var(--accent) 70%, var(--ink));
  }
  .ec-dossard-no {
    display: flex;
    align-items: baseline;
    gap: var(--sp-2);
    margin-top: var(--sp-3);
    font-size: clamp(2.2rem, 7vw, 3rem);
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.02em;
    color: color-mix(in srgb, var(--accent) 80%, var(--ink));
    /* Chiffres à chasse fixe : un numéro qui change de largeur selon ses
       chiffres se lit mal en grande taille. */
    font-variant-numeric: tabular-nums;
  }
  .ec-dossard-no .prefixe {
    font-size: var(--fs-small);
    font-weight: 600;
    opacity: 0.6;
    letter-spacing: 0;
  }
  .ec-dossard-nom {
    margin-top: var(--sp-1);
    font-size: var(--fs-h3, 1.1rem);
    font-weight: 600;
    color: color-mix(in srgb, var(--accent) 70%, var(--ink));
  }

  /* La date est un lien : il doit se comporter comme tel au survol, sans
     ressembler à un bouton — c'est une action secondaire. */
  .ec-dossard-date {
    display: flex;
    align-items: center;
    gap: var(--sp-3);
    margin-top: var(--sp-3);
    padding: var(--sp-3) 0;
    border-top: 1px solid color-mix(in srgb, var(--accent) 25%, transparent);
    color: color-mix(in srgb, var(--accent) 75%, var(--ink));
    text-decoration: none;
    transition: opacity var(--dur-fast, .15s) ease;
  }
  .ec-dossard-date:hover { opacity: 0.75; }
  .ec-dossard-date .txt { display: flex; flex-direction: column; flex: 1; min-width: 0; }
  .ec-dossard-date small { font-size: var(--fs-micro); opacity: 0.7; }
  .ec-dossard-date .jrs {
    padding: 2px 10px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--accent) 75%, var(--ink));
    color: var(--canvas);
    font-size: var(--fs-micro);
    font-weight: 800;
    white-space: nowrap;
  }
  .ec-dossard-date .chev { opacity: 0.5; }

  /* ⚠️ PAS DE CARTE AUTOUR DES INFOS PRATIQUES. Elles suivent immédiatement le
     dossard et s'y rattachent : deux blocs encadrés l'un sous l'autre les
     séparaient visuellement alors qu'ils ne font qu'un. Le dossard porte déjà
     le fond coloré ; ce qui suit n'a besoin que d'espace. */
  .ec-pratique { padding: 0 var(--sp-2); margin-bottom: var(--sp-5); }
  .ec-pratique .rows .row { padding-left: 0; padding-right: 0; }
  .ec-pratique .rows .row .sub { font-size: var(--fs-micro); color: var(--ink-faint); }
  .ec-pratique .rows .row .title { font-weight: 600; }

  /* ── Bloc sans cadre ─────────────────────────────────────────────────────
     `.card` apporte le fond, la bordure, l'ombre ET l'espacement interne. On ne
     retire donc pas la classe — on la neutralise visuellement, en gardant tout
     ce qu'elle règle pour l'en-tête et les rangées.

     ⚠️ POURQUOI DÉCADRER. Le panneau principal est DÉJÀ une carte : chaque
     section encadrée à l'intérieur faisait une boîte dans une boîte, et l'œil
     comptait les cadres au lieu de lire. Le titre et l'espace suffisent à
     séparer — c'est le même parti que l'application. */
  .ec-nu {
    background: transparent;
    border: 0;
    box-shadow: none;
    padding-left: 0;
    padding-right: 0;
  }
  .ec-nu > .rows { border: 0; }

  /* Le reçu de l'inscription, sur une ligne sous le nom. `wrap` : sur un
     écran étroit les valeurs passent dessous au lieu d'être tronquées. */
  .ec-recu {
    display: flex;
    flex-wrap: wrap;
    gap: var(--sp-2) var(--sp-4);
    margin-top: var(--sp-2);
    font-size: var(--fs-small);
    color: var(--ink-soft, var(--ink));
  }
  .ec-recu b { font-weight: 600; color: var(--ink-faint); margin-right: 4px; }

  /* Les trois chiffres qui suivent le chrono. En petit sous le temps, ils
     passaient pour une note de bas de page — ici ils se lisent. */
  /* ── Carte simple, à filet ───────────────────────────────────────────────
     `.card` de components.css pose un fond, une ombre et une bordure : à
     l'intérieur du panneau principal — qui est DÉJÀ une carte — cela fait une
     boîte dans une boîte, avec une ombre qui n'a rien à porter.

     `.ec-bloc` garde l'espacement de `.card` et ne conserve qu'un filet. C'est
     le même parti que les éditions de « Mes résultats » et que l'application :
     un trait pour délimiter, jamais un aplat. */
  .ec-bloc {
    background: transparent;
    box-shadow: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
  }

  /* ── Une carte par édition ───────────────────────────────────────────────
     Même parti que l'application : un FILET, pas un aplat.

     ⚠️ POURQUOI ENCADRER ICI ALORS QU'ON A TOUT DÉCADRÉ AILLEURS. Le principe
     du projet est de séparer par le vide ; il tient tant qu'un écran présente
     UNE chose. Cette page en empile trois ou quatre — une par édition, chacune
     avec son chrono, ses chiffres et son reçu. Sans limite visible, on ne sait
     plus où finit 2025 et où commence 2024, et le regard rattache les chiffres
     à la mauvaise année.

     ⚠️ LA RANGÉE DOIT POUVOIR PASSER À LA LIGNE. `.row` de components.css est
     une rangée flex sans `wrap` : sur un écran étroit, les deux colonnes se
     comprimaient jusqu'à couper les mots caractère par caractère. */
  .ec-resultat {
    flex-wrap: wrap;
    align-items: flex-start;
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: var(--sp-4);
    margin-bottom: var(--sp-4);
  }
  .ec-resultat:last-child { margin-bottom: 0; }
  /* `.rows > .row` pose un trait de séparation : deux traits pour une seule
     limite feraient un double filet entre les cartes. */
  .ec-nu .rows > .ec-resultat { border-bottom: 1px solid var(--border); }
  .ec-resultat > .grow { flex: 1 1 260px; min-width: 0; }

  /* Les trois chiffres sont DANS la colonne du chrono, sous lui — donc en face
     du reçu, à droite. Ils ne prennent plus toute la largeur : le regard suit
     une seule colonne au lieu de redescendre. */
  .ec-chiffres {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--sp-2) var(--sp-4);
    margin-top: var(--sp-3);
    padding-top: var(--sp-3);
    border-top: 1px solid var(--border);
  }
  .ec-chiffres > div { align-items: flex-end; }
  .ec-chiffres > div { display: flex; flex-direction: column; align-items: flex-end; }
  .ec-chiffres .v {
    font-size: var(--fs-h4, 1.05rem);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }
  .ec-chiffres .l { font-size: var(--fs-micro); color: var(--ink-faint); }

  /* ── Boîte de réception ──────────────────────────────────────────────────
     Une LISTE, pas une pile de cartes : c'est une boîte de réception, et son
     seul intérêt est de voir d'un coup d'œil ce qu'on a reçu. */
  .ec-messages { list-style: none; margin: 0; padding: 0; }
  .ec-msg {
    display: flex;
    align-items: flex-start;
    gap: var(--sp-3);
    /* Plus de retrait latéral : sans cadre, le texte s'aligne sur le titre de
       la page comme le reste du contenu. */
    padding: var(--sp-3) 0;
    border-bottom: 1px solid var(--border);
  }
  .ec-msg:last-child { border-bottom: 0; }
  .ec-msg[hidden] { display: none; }
  .ec-msg-ico { margin-top: 2px; color: var(--accent); font-size: 1.05rem; }
  .ec-msg.is-danger .ec-msg-ico { color: var(--danger, #dc2626); }
  .ec-msg.is-warn   .ec-msg-ico { color: var(--warn, #d97706); }
  .ec-msg.is-ok     .ec-msg-ico { color: var(--ok, #16a34a); }

  .ec-msg-corps { flex: 1; min-width: 0; }
  .ec-msg-tete {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: var(--sp-2);
  }
  .ec-msg-tete strong { font-weight: 650; }
  .ec-msg-date { font-size: var(--fs-micro); color: var(--ink-faint); }
  .ec-msg-corps p { margin: 2px 0 0; white-space: pre-line; }
  .ec-msg-exp { font-size: var(--fs-micro); color: var(--ink-faint); }

  /* La croix reste discrète : on ne retire pas un message par mégarde, mais on
     ne doit pas non plus avoir à la chercher. */
  .ec-msg-x {
    flex: none;
    border: 0;
    background: transparent;
    color: var(--ink-faint);
    cursor: pointer;
    padding: 4px 6px;
    border-radius: var(--radius-sm, 8px);
    line-height: 1;
  }
  .ec-msg-x:hover { background: var(--surface-2, rgba(0,0,0,.05)); color: var(--ink); }

  /* ── Le QR en grand ──────────────────────────────────────────────────────
     ⚠️ FOND BLANC ET NOIR PUR, quel que soit le thème. Un lecteur lit un
     CONTRASTE, pas une couleur : un QR sombre sur fond sombre ne se décode
     plus, et on ne s'en aperçoit qu'au stand, devant la file. */
  .ec-qr-zoom {
    border: 0; background: transparent; padding: 0; cursor: zoom-in;
    border-radius: var(--radius-md, 12px); line-height: 0;
  }
  .ec-qr-plein {
    position: fixed; inset: 0; z-index: 9999;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: var(--sp-4);
    background: #fff; cursor: zoom-out;
  }
  .ec-qr-plein[hidden] { display: none; }
  .ec-qr-plein img {
    /* Le plus grand carré possible, jamais déformé : un module rectangulaire
       ne se décode pas. */
    width: min(80vw, 80vh); height: auto; aspect-ratio: 1;
    image-rendering: pixelated;
  }
  .ec-qr-plein span { color: #666; font-size: var(--fs-small); }

  /* ── Le pied de page reste EN BAS, même quand la page est courte ──────────
     « Mes inscriptions » avec une seule ligne tient dans un tiers d'écran : le
     pied se retrouvait plaqué sous la carte, au milieu d'une grande zone vide,
     et se lisait comme un bloc de contenu abandonné là.

     `.jr-shell` fait déjà `min-height: 100vh` et `.jr-main` s'étire sur toute
     la hauteur : il suffit d'en faire une colonne et de laisser le contenu
     prendre la place restante. Le pied descend alors tout seul.

     ⚠️ CE N'EST PAS UN PIED FIXE (`position: fixed`). Il reste à la fin du
     document : sur une page longue il défile normalement avec le contenu, au
     lieu de manger en permanence une bande de l'écran — ce qui coûte cher sur
     un téléphone. Il est en bas de la PAGE, pas en bas de la FENÊTRE. */
  .ec-shell .jr-main { display: flex; flex-direction: column; }
  .ec-shell .ec-stack { flex: 1 0 auto; }
  .ec-shell .jr-main > footer.auth-links {
    margin-top: var(--sp-6);
    padding-top: var(--sp-4);
    /* Un filet, pas une bordure : il sépare sans encadrer. Sans lui, les liens
       flottent sans qu'on voie qu'ils appartiennent au pied. */
    border-top: 1px solid var(--border);
  }

  /* ⚠️ SANS CETTE LIGNE, LA FENÊTRE MODALE SERAIT VISIBLE EN PERMANENCE.
     .modal-backdrop (components.css) est en `display: grid` ; une règle de
     classe l'emporte sur le style par défaut de l'attribut [hidden], qui ne
     masquerait donc plus rien. */
  .modal-backdrop[hidden] { display: none; }
  /* La fenêtre porte À LA FOIS .modal et .card : .modal donne la largeur,
     l'ombre et l'animation, .card donne l'espacement vertical et l'en-tête
     (.card > header) — exactement le même rythme que les cartes de la page.
     Rien de nouveau n'est dessiné. */

  /* Ancrée EN HAUT, pas au centre. Centrée, elle se déplaçait verticalement
     selon son contenu — un message de confirmation en plus, un bouton de moins,
     et elle n'était jamais au même endroit d'une ouverture à l'autre. Le
     défilement passe sur le FOND : la fenêtre peut ainsi dépasser la vue sans
     être rognée en haut, là où se trouvent son titre et sa croix de fermeture. */
  .ec-modal-haut { align-items: start; padding-top: var(--sp-6); overflow-y: auto; }
  /* Un peu plus large que les 540 px du composant : la fenêtre porte deux
     paragraphes et deux boutons côte à côte, qui passaient à la ligne pour rien. */
  .ec-modal-haut > .modal.card { width: min(720px, 100%); }

  /* Choix de la couleur d'accent — calqué sur .accent-option du profil admin. */
  .ec-accents { display: flex; flex-wrap: wrap; gap: var(--sp-2); }
  .ec-accent {
    display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    padding: 0.45rem 0.8rem; border-radius: var(--radius-m);
    border: 1px solid var(--border-strong); background: var(--surface);
    color: var(--ink-dim); font: inherit; font-size: var(--fs-small); font-weight: 550;
    transition: border-color var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out);
  }
  .ec-accent:hover { border-color: var(--accent); color: var(--ink); }
  .ec-accent.is-active { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); }
  .ec-accent .dot { width: 14px; height: 14px; border-radius: 50%; flex: none;
                    box-shadow: inset 0 0 0 1px rgba(0,0,0,.12); }
  /* Le sélecteur natif est masqué : c'est la pastille entière qui l'ouvre. */
  .ec-accent input[type="color"] { position: absolute; opacity: 0; width: 0; height: 0; }
  .ec-mono  { font-family: var(--font-mono); font-weight: 600; color: var(--accent); }
  .ec-dl   { display: grid; grid-template-columns: auto 1fr; gap: var(--sp-2) var(--sp-5);
             font-size: var(--fs-small); margin: 0; }
  .ec-dl dt { color: var(--ink-faint); }
  .ec-dl dd { margin: 0; color: var(--ink); font-weight: 550; }

  /* Fond blanc imposé sous le QR : en thème sombre, un QR noir sur fond sombre
     n'est plus lisible par un lecteur. */
  .ec-qr { display: grid; place-items: center; gap: var(--sp-3); }
  .ec-qr img { width: 200px; height: 200px; image-rendering: pixelated;
               background: #fff; padding: 10px; border-radius: var(--radius-m); }

  /* Détail de l'inscription et QR code côte à côte sur ordinateur. La colonne
     du QR est fixe (320 px) : le QR fait 200 px et ne gagne rien à s'élargir,
     alors que la liste des champs, elle, profite de toute la place restante.
     align-items:start empêche la carte du QR de s'étirer inutilement à la
     hauteur de sa voisine. */
  /* Les deux cartes montent à la MÊME hauteur : `align-items: start` les
     laissait chacune à sa taille propre, et la carte du QR s'arrêtait bien
     au-dessus de sa voisine — deux blocs de hauteurs différentes côte à côte,
     ça se voit tout de suite. Le défaut de la grille (stretch) suffit ; il
     restait à faire descendre la carte jusqu'en bas et à recentrer le QR dans
     l'espace ainsi gagné. */
  .ec-duo { display: grid; gap: var(--sp-4); }
  @media (min-width: 900px) {
    .ec-duo { grid-template-columns: minmax(0, 1fr) 320px; }
    .ec-duo > .card { height: 100%; }
    /* Le QR se place au centre du vide plutôt que collé en haut. */
    .ec-duo .ec-qr { flex: 1; align-content: center; }
  }

  /* Deux champs par ligne dès qu'il y a la place (prénom/nom, sexe/âge). */
  .ec-grid2 { display: grid; gap: var(--sp-3); }
  @media (min-width: 560px) { .ec-grid2 { grid-template-columns: 1fr 1fr; } }

  /* Formulaire de correction, replié par défaut. */
  .ec-edit { border-top: 1px solid var(--border); padding-top: var(--sp-4); margin-top: var(--sp-2); }
  .ec-edit > summary {
    cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 8px;
    font-size: var(--fs-small); font-weight: 550; color: var(--accent);
  }
  .ec-edit > summary::-webkit-details-marker { display: none; }
  .ec-edit > summary:hover { text-decoration: underline; }
  .ec-edit[open] > summary { margin-bottom: var(--sp-4); }

  @media (max-width: 640px) {
    .ec-dl { grid-template-columns: 1fr; gap: 0; }
    .ec-dl dd { margin-bottom: var(--sp-2); }
  }
</style>
