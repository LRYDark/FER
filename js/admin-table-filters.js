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
    var active = {}; // index logique de colonne -> valeur filtrée active

    function isFilterable(title, dataKey) {
      return titles.indexOf(title) !== -1 || (dataKey && datas.indexOf(dataKey) !== -1);
    }

    function applyFilter(colIdx, val, btn) {
      if (val) {
        var esc = String(val).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        api.column(colIdx).search('^' + esc + '$', true, false).draw();
        active[colIdx] = val;
        if (btn) btn.classList.add('active');
      } else {
        api.column(colIdx).search('', true, false).draw();
        delete active[colIdx];
        if (btn) btn.classList.remove('active');
      }
    }

    function openPop(colIdx, title, dataKey, btn) {
      var p = getPop();
      p._forCol = colIdx; p._forApi = api;
      p.innerHTML = '';
      var head = document.createElement('div');
      head.className = 'cfp-head';
      head.textContent = 'Filtrer : ' + title;
      p.appendChild(head);

      var current = active[colIdx] != null ? String(active[colIdx]) : '';
      function addOpt(val, lbl) {
        var o = document.createElement('div');
        o.className = 'cfp-opt' + (String(val) === current ? ' active' : '');
        var ic = document.createElement('i'); ic.className = 'bi bi-check2';
        var sp = document.createElement('span'); sp.textContent = lbl;
        o.appendChild(ic); o.appendChild(sp);
        o.addEventListener('click', function (e) {
          e.stopPropagation();
          applyFilter(colIdx, val, btn);
          p.classList.remove('show');
        });
        p.appendChild(o);
      }
      addOpt('', 'Tous');
      var seen = {};
      api.column(colIdx).data().unique().sort().each(function (v) {
        if (v === null || v === undefined || v === '') return;
        if (seen[v]) return; seen[v] = 1;
        addOpt(v, makeLabel(title, dataKey, v, childAge));
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
      var ao = api.settings()[0].aoColumns;
      api.columns().every(function () {
        var colIdx = this.index();
        var th = this.header();
        if (!th) return;
        var title = (th.textContent || '').trim(); // l'icône (pseudo-élément) n'est pas dans textContent
        var dataKey = ao[colIdx] ? ao[colIdx].data : null;
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
            if (p.classList.contains('show') && p._forCol === colIdx && p._forApi === api) {
              p.classList.remove('show'); return;
            }
            openPop(colIdx, title, dataKey, btn);
          });
          th.appendChild(btn);
        }
        if (active[colIdx] != null) btn.classList.add('active');
        else btn.classList.remove('active');
      });
    }

    // Réordonnancement (ColReorder) : ferme un popover ouvert et rafraîchit l'état.
    api.on('column-reorder', function () { if (pop) pop.classList.remove('show'); build(); });
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
