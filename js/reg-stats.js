/*
 * reg-stats.js — Statistiques d'inscriptions PARTAGÉES entre le dashboard (saison
 * en cours) et la page Statistiques (archive d'une année). Objectif : une seule
 * source de vérité pour le calcul ET le rendu des cartes, afin que la page stats
 * affiche exactement les mêmes indicateurs que le dashboard, sans duplication.
 *
 * Dépend de window.FERInscription (js/inscription-form.js) pour le calcul de l'âge
 * — qui gère aussi bien l'âge déjà stocké (nouveau modèle) que les données héritées
 * (année / date) via une année de référence (l'année de l'archive côté page stats).
 *
 * API : window.FERRegStats
 *   - compute(rows, {refYear})          → objet de métriques
 *   - renderMainCards(el, stats)        → 3 cartes principales + bouton « Autres stats »
 *   - renderModalBody(el, stats, {childAge}) → toutes les stats (contenu du modal)
 */
(function () {
  'use strict';

  function ageOf(r, refYear) {
    return (window.FERInscription) ? window.FERInscription.ageFromBirth(r.naissance, refYear) : null;
  }

  /**
   * Agrège une liste d'inscriptions. Chaque ligne : {naissance, sexe, nom, prenom,
   * entreprise, tshirt_size, prestation, paiement_mode, montant_du}.
   * @param {Array}  rows
   * @param {Object} [opts] { refYear:Number } — année de référence pour les âges
   *        au format année/date (archives). Défaut : année courante.
   */
  function compute(rows, opts) {
    opts = opts || {};
    rows = rows || [];
    var refYear = opts.refYear || null;
    var s = {
      total: rows.length,
      revenue: 0,
      tshirtCount: 0,
      enfantTshirtCount: 0,
      oldest: { H: null, F: null },
      youngest: { H: null, F: null },
      topEnt: []
    };
    var byEnt = {};
    rows.forEach(function (r) {
      // Recettes estimées : somme des montants dus.
      var m = parseFloat(r.montant_du);
      if (isFinite(m)) s.revenue += m;

      // Âge (le plus vieux / le plus jeune, par sexe).
      var a = ageOf(r, refYear);
      if (a != null && (r.sexe === 'H' || r.sexe === 'F')) {
        var name = ((r.prenom || '') + ' ' + (r.nom || '')).trim();
        if (!s.oldest[r.sexe] || a > s.oldest[r.sexe].age) s.oldest[r.sexe] = { nom: name, age: a };
        if (!s.youngest[r.sexe] || a < s.youngest[r.sexe].age) s.youngest[r.sexe] = { nom: name, age: a };
      }

      // Entreprise / groupe (peut être une famille, une association…).
      var ent = (r.entreprise || '').toString().trim();
      if (ent) byEnt[ent] = (byEnt[ent] || 0) + 1;

      // T-shirt récupéré = taille renseignée (≠ vide et ≠ « - »).
      var sz = (r.tshirt_size || '').toString().trim();
      if (sz && sz !== '-') s.tshirtCount++;

      // Enfant sous le seuil AVEC t-shirt (catégorie payante distincte).
      var presta = (r.prestation || '').toString().toLowerCase();
      var pm = (r.paiement_mode || '').toString().toLowerCase();
      if (presta === 'enfant_tshirt' || pm === 'enfant_tshirt') s.enfantTshirtCount++;
    });

    // 3 entreprises / groupes les plus représentés.
    s.topEnt = Object.keys(byEnt)
      .map(function (k) { return [k, byEnt[k]]; })
      .sort(function (a, b) { return b[1] - a[1]; })
      .slice(0, 3);

    return s;
  }

  function euro(n) {
    return (n || 0).toLocaleString('fr-FR', { maximumFractionDigits: 0 }) + ' €';
  }
  // Jamais « 0 ans » : on omet l'âge quand il vaut 0 (ou absent).
  function person(p) { return p ? (p.nom + (p.age ? ' (' + p.age + ' ans)' : '')) : '–'; }
  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function card(label, value, small) {
    return '<div class="card statCard flex-fill text-center"><div class="card-body">'
      + '<div class="stat-label">' + label + '</div>'
      + '<p class="stat-value' + (small ? ' stat-value--sm' : '') + '">' + value + '</p></div></div>';
  }
  // Ligne « libellé → valeur » d'une section du modal.
  function row(label, value) {
    var v = (value == null || value === '') ? '' : value;
    return '<div class="reg-stats-row"><span class="reg-stats-row-label">' + label + '</span>'
      + '<span class="reg-stats-row-value">' + v + '</span></div>';
  }
  // Section titrée regroupant plusieurs lignes.
  function section(title, rows) {
    return '<div class="reg-stats-section">'
      + '<div class="reg-stats-section-title">' + title + '</div>'
      + '<div class="reg-stats-list">' + rows.join('') + '</div></div>';
  }

  /** 3 cartes principales + bouton discret « Autres stats » (ouvre le modal). */
  function renderMainCards(el, s) {
    if (!el) return;
    el.innerHTML =
        card('Inscriptions', s.total)
      + card('Montant estimé des recettes', euro(s.revenue))
      + card('T-shirts récupérés', s.tshirtCount)
      + '<button type="button" class="btn btn-sm btn-outline-secondary align-self-center reg-stats-more" '
      + 'data-reg-stats-more title="Voir toutes les statistiques">'
      + '<i class="bi bi-three-dots"></i> Autres stats</button>';
  }

  /**
   * Contenu du modal : les 3 cartes principales en grand, puis les indicateurs
   * secondaires regroupés en sections titrées (lignes « libellé → valeur »).
   */
  function renderModalBody(el, s, opts) {
    if (!el) return;
    opts = opts || {};
    var childAge = (opts.childAge != null) ? opts.childAge : 12;

    // 3 cartes principales (même style que le dashboard).
    var main = '<div class="d-flex flex-wrap gap-3 mb-4 align-items-stretch">'
      + card('Inscriptions', s.total)
      + card('Montant estimé des recettes', euro(s.revenue))
      + card('T-shirts récupérés', s.tshirtCount)
      + '</div>';

    // Section « Âges extrêmes » : le plus vieux / le plus jeune, par sexe.
    var ages = section('Âges extrêmes', [
      row('+ Vieux H',   esc(person(s.oldest.H))),
      row('+ Vieille F', esc(person(s.oldest.F))),
      row('+ Jeune H',   esc(person(s.youngest.H))),
      row('+ Jeune F',   esc(person(s.youngest.F)))
    ]);

    // Section « Enfants ».
    var enfants = section('Enfants', [
      row('Enfants -' + childAge + ' +T-shirt', s.enfantTshirtCount)
    ]);

    // Section « Entreprises / Groupes » : podium des 3 plus représentés.
    var entRows = [];
    for (var i = 0; i < 3; i++) {
      var e = s.topEnt[i];
      entRows.push(row(
        '<span class="reg-stats-rank">' + (i + 1) + '.</span> ' + (e ? esc(e[0]) : '–'),
        e ? (e[1] + (e[1] > 1 ? ' inscrits' : ' inscrit')) : ''
      ));
    }
    var ents = section('Entreprises / Groupes', entRows);

    el.innerHTML = main + ages + enfants + ents;
  }

  window.FERRegStats = {
    compute: compute,
    renderMainCards: renderMainCards,
    renderModalBody: renderModalBody
  };
})();
