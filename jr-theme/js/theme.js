/* JR Theme — à charger dans <head> SANS defer (évite le flash de thème).
   Applique le thème (clair/sombre) et la couleur d'accent mémorisés.
   Expose jrApplyAccent(hex) : dérive les variantes claire/sombre de
   n'importe quelle couleur et les applique en variables CSS. */
(function () {
  "use strict";

  function mix(rgb, target, t) {
    return rgb.map((c, i) => Math.round(c + (target[i] - c) * t));
  }
  function toHex(rgb) {
    return "#" + rgb.map((c) => c.toString(16).padStart(2, "0")).join("");
  }
  function luminance(rgb) {
    return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
  }

  window.jrApplyAccent = function (hex) {
    if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return;
    var rgb = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    var BLACK = [10, 14, 26], WHITE = [255, 255, 255];

    var dark = mix(rgb, WHITE, 0.32);
    var root = document.documentElement;
    root.style.setProperty("--accent-l", hex);
    root.style.setProperty("--accent-l-strong", toHex(mix(rgb, BLACK, 0.18)));
    root.style.setProperty("--accent-l-ink", luminance(rgb) > 175 ? "#101828" : "#FFFFFF");
    root.style.setProperty("--accent-d", toHex(dark));
    root.style.setProperty("--accent-d-strong", toHex(mix(rgb, WHITE, 0.52)));
    root.style.setProperty("--accent-d-ink", luminance(dark) > 140 ? "#101422" : "#FFFFFF");
    root.setAttribute("data-accent", "custom");
  };

  window.jrClearAccent = function () {
    var root = document.documentElement;
    ["--accent-l", "--accent-l-strong", "--accent-l-ink",
     "--accent-d", "--accent-d-strong", "--accent-d-ink"].forEach((p) => root.style.removeProperty(p));
  };

  try {
    // Défaut : thème CLAIR. "dark" et "system" uniquement sur choix explicite.
    var t = localStorage.getItem("jr-theme");
    if (t === "dark" || t === "system") {
      document.documentElement.setAttribute("data-theme", t);
    }
    var a = localStorage.getItem("jr-accent");
    if (a === "custom") {
      var hex = localStorage.getItem("jr-accent-custom");
      if (hex) window.jrApplyAccent(hex);
    } else if (a && /^[a-z]+$/.test(a) && a !== "blue") {
      document.documentElement.setAttribute("data-accent", a);
    }
  } catch (e) { /* stockage indisponible : défauts (clair selon OS, bleu) */ }
})();
