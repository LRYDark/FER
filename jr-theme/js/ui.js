/* JR Theme — comportements d'interface (à charger avec defer).
   Réglages d'apparence, confirmations, prompts, modales, œil mot de passe,
   checklist mot de passe, horloge, barres de progression.
   Les choix de thème/couleur sont mémorisés (clés jr-*) et partagés
   entre tous les sites du domaine. */
"use strict";

/* ---- Apparence : thème + couleur d'accent ---- */
(function appearance() {
  const root = document.documentElement;
  const store = (k, v) => {
    try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { /* ignore */ }
  };

  /* Thème : clair (défaut) / sombre / système */
  const themeButtons = document.querySelectorAll("[data-theme-choice]");
  const markTheme = () => {
    const current = root.getAttribute("data-theme") || "light";
    themeButtons.forEach((b) => b.classList.toggle("is-active", b.dataset.themeChoice === current));
  };
  themeButtons.forEach((b) => b.addEventListener("click", () => {
    const choice = b.dataset.themeChoice;
    if (choice === "light") { root.removeAttribute("data-theme"); store("jr-theme", null); }
    else { root.setAttribute("data-theme", choice); store("jr-theme", choice); }
    markTheme();
  }));
  markTheme();

  /* Accent : préréglages + palette libre */
  const swatches = document.querySelectorAll("[data-accent-set]");
  const customInput = document.querySelector("[data-accent-custom]");
  const customWrap = customInput ? customInput.closest(".accent-option") : null;
  const markAccent = () => {
    const cur = root.getAttribute("data-accent") || "blue";
    swatches.forEach((s) => s.classList.toggle("is-active", s.dataset.accentSet === cur));
    if (customWrap) customWrap.classList.toggle("is-active", cur === "custom");
  };
  swatches.forEach((s) => s.addEventListener("click", () => {
    const name = s.dataset.accentSet;
    if (window.jrClearAccent) window.jrClearAccent();
    if (name === "blue") root.removeAttribute("data-accent");
    else root.setAttribute("data-accent", name);
    store("jr-accent", name);
    store("jr-accent-custom", null);
    markAccent();
  }));
  if (customInput) {
    try {
      const saved = localStorage.getItem("jr-accent-custom");
      if (saved) customInput.value = saved;
    } catch (e) { /* ignore */ }
    customInput.addEventListener("input", () => {
      if (window.jrApplyAccent) window.jrApplyAccent(customInput.value);
      store("jr-accent", "custom");
      store("jr-accent-custom", customInput.value);
      markAccent();
    });
  }
  markAccent();
})();

/* ---- Envoi automatique du formulaire au changement (CSP-safe) ---- */
(function autosubmit() {
  document.querySelectorAll("[data-autosubmit]").forEach((el) => {
    el.addEventListener("change", () => { if (el.form) el.form.submit(); });
  });
})();

/* ---- Confirmation avant envoi : <form data-confirm="message"> ---- */
(function confirms() {
  document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });
})();

/* ---- Prompt avant envoi : <form data-prompt="..." data-prompt-default="...">
   remplit le champ name="name" vide avec la valeur saisie ---- */
(function prompts() {
  document.querySelectorAll("form[data-prompt]").forEach((form) => {
    form.addEventListener("submit", (e) => {
      const value = window.prompt(form.dataset.prompt, form.dataset.promptDefault || "");
      if (value === null || value.trim() === "") { e.preventDefault(); return; }
      const field = form.querySelector('input[name="name"]');
      if (field) field.value = value.trim();
    });
  });
})();

/* ---- Modales : [data-open-modal="id"] ouvre [data-modal="id"] ---- */
(function modals() {
  const open = (id) => {
    const m = document.querySelector(`[data-modal="${id}"]`);
    if (m) m.hidden = false;
  };
  const closeAll = () => document.querySelectorAll("[data-modal]").forEach((m) => (m.hidden = true));
  document.querySelectorAll("[data-open-modal]").forEach((btn) =>
    btn.addEventListener("click", () => open(btn.dataset.openModal)));
  document.querySelectorAll("[data-close-modal]").forEach((btn) =>
    btn.addEventListener("click", closeAll));
  document.querySelectorAll("[data-modal]").forEach((backdrop) =>
    backdrop.addEventListener("click", (e) => { if (e.target === backdrop) closeAll(); }));
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeAll(); });
})();

/* ---- Afficher/masquer un mot de passe (.passfield + [data-password-toggle]) ---- */
(function passwordToggle() {
  document.querySelectorAll("[data-password-toggle]").forEach((btn) => {
    const input = document.getElementById(btn.dataset.passwordToggle);
    if (!input) return;
    btn.addEventListener("click", () => {
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      btn.closest(".passfield").classList.toggle("is-visible", show);
      input.focus();
    });
  });
})();

/* ---- Checklist de mot de passe en direct ----
   <input id="x"> + <ul class="checks" data-password-checks="x">
   avec des <li data-check="len|upper|lower|digit|special">. */
(function passwordChecks() {
  const RULES = {
    len: (v) => v.length >= 12,
    upper: (v) => /[A-Z]/.test(v),
    lower: (v) => /[a-z]/.test(v),
    digit: (v) => /[0-9]/.test(v),
    special: (v) => /[^A-Za-z0-9]/.test(v),
  };
  document.querySelectorAll("[data-password-checks]").forEach((list) => {
    const input = document.getElementById(list.dataset.passwordChecks);
    if (!input) return;
    const update = () => {
      list.querySelectorAll("[data-check]").forEach((li) => {
        const rule = RULES[li.dataset.check];
        li.classList.toggle("is-ok", !!rule && rule(input.value));
      });
    };
    input.addEventListener("input", update);
    update();
  });
})();

/* ---- Horloge ---- */
(function clock() {
  const nodes = document.querySelectorAll("[data-clock]");
  if (!nodes.length) return;
  const fmt = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit" });
  const tick = () => nodes.forEach((n) => { n.textContent = fmt.format(new Date()); });
  tick();
  setInterval(tick, 10000);
})();

/* ---- Barres de progression (largeur via data-width, compatible CSP) ---- */
(function bars() {
  document.querySelectorAll(".bar > span[data-width]").forEach((el) => {
    requestAnimationFrame(() => { el.style.width = el.dataset.width + "%"; });
  });
})();
