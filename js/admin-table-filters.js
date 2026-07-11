/* ════════════════════════════════════════════════════════════════════════
 * Filtres d'en-tête par colonne (icône entonnoir + popover) — MUTUALISÉ.
 * Utilisé par les tableaux admin « .fer-table » (saisie, stats, …).
 * Dépendances : jQuery + DataTables (déjà chargés par les pages).
 *
 * Usage :
 *   FERTableFilters.attach(dataTableApi, {
 *     filterableTitles: ['Sexe','Ville', …],   // titres d'en-tête filtrables
 *     filterableData:   ['sexe','ville', …],    // OU clés de données (bdd_column)
 *     childAge: 12                              // pour les libellés « -N ans »
 *   });
 *   → renvoie { rebuild(), setVisible(bool) }
 *
 * Les entonnoirs vivent dans le <th> : ils suivent automatiquement le
 * réordonnancement (ColReorder) et le masquage de colonnes.
 * Le style (.col-filter-btn / .col-filter-pop) est fourni globalement par
 * navbar-admin.php.
 * ════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var pop = null; // popover unique partagé par toutes les tables de la page

  function getPop() {
    if (pop) return pop;
    pop = document.createElement('div');
    pop.className = 'col-filter-pop';
    document.body.appendChild(pop);
    document.addEventListener('click', function (e) {
      if (pop.classList.contains('show')
        && !pop.contains(e.target)
        && !(e.target.closest && e.target.closest('.col-filter-btn'))) {
        pop.classList.remove('show');
      }
    });
    window.addEventListener('resize', function () { pop.classList.remove('show'); });
    return pop;
  }

  // Libellé convivial pour les catégories (valeur brute conservée pour le filtre).
  function makeLabel(title, dataKey, v, childAge) {
    var s = String(v);
    if (dataKey === 'paiement_mode' || title === 'Paiement') {
      var lc = s.toLowerCase();
      if (lc === 'gratuit') return 'Gratuit/-' + childAge + 'ans';
      if (lc === 'enfant_tshirt') return 'en ligne (CB)';
    } else if (dataKey === 'prestation' || title === 'Prestation') {
      var lp = s.toLowerCase();
      if (lp === 'tarif_unique') return 'Tarif unique';
      if (lp === 'enfant_gratuit') return 'Enfant -' + childAge + ' (gratuit sans t-shirt)';
      if (lp === 'enfant_tshirt') return 'Enfant -' + childAge + ' +T-shirt';
    } else if (dataKey === 'montant_du' || title === 'Montant') {
      var n = parseFloat(s); if (!isFinite(n)) n = 0;
      return n.toFixed(2).replace(/\.00$/, '') + ' €';
    }
    return s;
  }

  function attach(api, opts) {
    opts = opts || {};
    var titles = opts.filterableTitles || [];
    var datas = opts.filterableData || [];
    var childAge = opts.childAge || 0;
    var active = {}; // clé de données (bdd_column) -> valeur filtrée active

    function isFilterable(title, dataKey) {
      return titles.indexOf(title) !== -1 || (dataKey && datas.indexOf(dataKey) !== -1);
    }

    // Exécute cb(colApi) sur la colonne dont la source de données vaut dataKey.
    // Robuste au réordonnancement (ColReorder) : aucun index numérique n'est mémorisé.
    function withColByData(dataKey, cb) {
      var done = false;
      api.columns().every(function () {
        if (!done && this.dataSrc() === dataKey) { cb(this); done = true; }
      });
      return done;
    }

    function applyFilter(dataKey, val, btn) {
      withColByData(dataKey, function (col) {
        if (val) {
          var esc = String(val).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          col.search('^' + esc + '$', true, false).draw();
        } else {
          col.search('', true, false).draw();
        }
      });
      if (val) { active[dataKey] = val; if (btn) btn.classList.add('active'); }
      else { delete active[dataKey]; if (btn) btn.classList.remove('active'); }
    }

    function openPop(dataKey, title, btn) {
      var p = getPop();
      p._forCol = dataKey; p._forApi = api;
      p.innerHTML = '';
      var head = document.createElement('div');
      head.className = 'cfp-head';
      head.textContent = 'Filtrer : ' + title;
      p.appendChild(head);

      var current = active[dataKey] != null ? String(active[dataKey]) : '';
      function addOpt(val, lbl) {
        var o = document.createElement('div');
        o.className = 'cfp-opt' + (String(val) === current ? ' active' : '');
        var ic = document.createElement('i'); ic.className = 'bi bi-check2';
        var sp = document.createElement('span'); sp.textContent = lbl;
        o.appendChild(ic); o.appendChild(sp);
        o.addEventListener('click', function (e) {
          e.stopPropagation();
          applyFilter(dataKey, val, btn);
          p.classList.remove('show');
        });
        p.appendChild(o);
      }
      addOpt('', 'Tous');
      var seen = {};
      withColByData(dataKey, function (col) {
        col.data().unique().sort().each(function (v) {
          if (v === null || v === undefined || v === '') return;
          if (seen[v]) return; seen[v] = 1;
          addOpt(v, makeLabel(title, dataKey, v, childAge));
        });
      });

      var r = btn.getBoundingClientRect();
      p.style.visibility = 'hidden';
      p.classList.add('show');
      var pw = p.offsetWidth, ph = p.offsetHeight;
      var left = Math.min(r.left, window.innerWidth - pw - 12);
      var top = r.bottom + 6;
      if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
      p.style.left = Math.max(8, left) + 'px';
      p.style.top = top + 'px';
      p.style.visibility = '';
    }

    // Pose/maj des entonnoirs. Idempotent : sûr à rappeler (reorder, rechargement…).
    function build() {
      api.columns().every(function () {
        var th = this.header();
        if (!th) return;
        var title = (th.textContent || '').trim(); // l'icône (pseudo-élément) n'est pas dans textContent
        var dataKey = this.dataSrc();               // clé de données immuable (bdd_column)
        var existing = th.querySelector('.col-filter-btn');
        if (!isFilterable(title, dataKey)) {
          if (existing) existing.remove();
          return;
        }
        var btn = existing;
        if (!btn) {
          btn = document.createElement('span');
          btn.className = 'col-filter-btn';
          btn.title = 'Filtrer';
          btn.innerHTML = '<i class="bi bi-funnel"></i>';
          btn.addEventListener('mousedown', function (e) { e.stopPropagation(); }); // n'amorce pas tri/déplacement
          btn.addEventListener('click', function (e) {
            e.stopPropagation(); e.preventDefault();                                // ne déclenche pas le tri
            var p = getPop();
            if (p.classList.contains('show') && p._forCol === dataKey && p._forApi === api) {
              p.classList.remove('show'); return;
            }
            openPop(dataKey, title, btn);
          });
          th.appendChild(btn);
        }
        if (active[dataKey] != null) btn.classList.add('active');
        else btn.classList.remove('active');
      });
    }

    // Réordonnancement (ColReorder) : ferme un popover ouvert et rafraîchit l'état.
    api.on('column-reorder', function () { if (pop) pop.classList.remove('show'); build(); });
    // Après masquage/affichage d'une colonne, réinstalle les entonnoirs (un <th>
    // redevenu visible n'a plus son bouton) et ferme un popover éventuellement ouvert.
    api.on('column-visibility.dt', function () { if (pop) pop.classList.remove('show'); build(); });
    build();

    return {
      rebuild: build,
      setVisible: function (show) {
        var thead = api.table().header();
        if (thead && thead.querySelectorAll) {
          thead.querySelectorAll('.col-filter-btn').forEach(function (b) {
            b.style.display = show ? '' : 'none';
          });
        }
        if (!show && pop) pop.classList.remove('show');
      }
    };
  }

  window.FERTableFilters = { attach: attach };
})();
