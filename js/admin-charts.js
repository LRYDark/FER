/**
 * FER Admin v2 — habillage jr-theme des graphiques Chart.js.
 *
 * Lit les tokens CSS (accent, encres, bordures) au moment du rendu et fournit :
 *   jrChartTokens()  → couleurs du thème courant (clair/sombre, accent utilisateur)
 *   jrChartPalette(n)→ n déclinaisons de l'accent (du plus clair au plus soutenu)
 *   jrChartDefaults()→ applique les défauts globaux Chart.js (police, couleurs,
 *                      grilles, tooltips) — à appeler AVANT new Chart(...).
 */
function jrChartTokens() {
  var cs = getComputedStyle(document.documentElement);
  function v(name, fallback) {
    var val = cs.getPropertyValue(name).trim();
    return val || fallback;
  }
  return {
    accent:  v('--accent', '#db2777'),
    ink:     v('--ink', '#101828'),
    inkDim:  v('--ink-dim', '#5A6472'),
    inkFaint: v('--ink-faint', '#98A1B0'),
    border:  v('--border', '#E7EAF1'),
    surface: v('--surface', '#ffffff'),
    ok:      v('--ok', '#16A34A'),
    warn:    v('--warn', '#D97706'),
    danger:  v('--danger', '#DC2626'),
    info:    v('--info', '#2563EB'),
    font:    v('--font-sans', 'Inter, sans-serif')
  };
}

/** Mélange hex→hex (t entre 0 et 1). */
function jrMixHex(hex, t, to) {
  var m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return hex;
  var h = m[1];
  var r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
  r = Math.round(r + (to[0] - r) * t); g = Math.round(g + (to[1] - g) * t); b = Math.round(b + (to[2] - b) * t);
  return '#' + [r, g, b].map(function (x) { return x.toString(16).padStart(2, '0'); }).join('');
}

/** Alpha sur une couleur hex. */
function jrAlpha(hex, a) {
  var m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return hex;
  var h = m[1];
  return 'rgba(' + parseInt(h.substr(0, 2), 16) + ',' + parseInt(h.substr(2, 2), 16) + ',' + parseInt(h.substr(4, 2), 16) + ',' + a + ')';
}

/** n nuances de l'accent, de la plus claire à la plus soutenue. */
function jrChartPalette(n) {
  var accent = jrChartTokens().accent;
  var out = [];
  for (var i = 0; i < n; i++) {
    // t décroît : premières valeurs très éclaircies, dernières renforcées
    var t = n === 1 ? 0 : (1 - i / (n - 1));
    out.push(t > 0 ? jrMixHex(accent, 0.72 * t, [255, 255, 255]) : jrMixHex(accent, 0.12, [0, 0, 0]));
  }
  return out;
}

/** Défauts globaux Chart.js alignés sur le thème (à appeler avant les new Chart). */
function jrChartDefaults() {
  if (typeof Chart === 'undefined') return;
  var t = jrChartTokens();
  Chart.defaults.font.family = t.font;
  Chart.defaults.font.size = 12;
  Chart.defaults.color = t.inkDim;
  Chart.defaults.borderColor = t.border;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.boxHeight = 8;
  Chart.defaults.plugins.tooltip.backgroundColor = t.ink;
  Chart.defaults.plugins.tooltip.titleColor = t.surface;
  Chart.defaults.plugins.tooltip.bodyColor = t.surface;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.elements.bar.borderRadius = 5;
  Chart.defaults.elements.bar.borderSkipped = 'bottom';
  Chart.defaults.elements.line.borderWidth = 2.5;
  Chart.defaults.elements.point.radius = 3;
  Chart.defaults.elements.point.hoverRadius = 5;
}
