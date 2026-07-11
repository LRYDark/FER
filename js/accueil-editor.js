/**
 * Éditeur visuel de la mise en page d'accueil (iframe-based WYSIWYG).
 *
 * Dépend de window.AccueilEditor (bootstrap injecté par setting.php) :
 *   - AccueilEditor.layoutData : structure du layout chargée depuis la BDD
 *   - AccueilEditor.predefinedSections : meta des sections pré-définies
 *   - AccueilEditor.allowedWidths : largeurs autorisées (3, 4, 6, 8, 9, 12)
 *   - AccueilEditor.initTinyMce(selector, content) : helper pour init TinyMCE
 *
 * Dépend des éléments DOM côté setting.php :
 *   - #ifePreview : l'iframe qui charge accueil.php?editor=1
 *   - #ifeOverlay : le calque parent où sont positionnés les overlays de section
 *   - .ife-sb-* : sidebar gauche (props + add)
 *   - #customBlockModal, #fieldEditModal : modaux d'édition
 */
(function() {
  var iframe = document.getElementById('ifePreview');
  var overlay = document.getElementById('ifeOverlay');
  if (!iframe || !overlay || !window.AccueilEditor) return;

  // Init Bootstrap tooltips ([data-bs-toggle="tooltip"]) une fois pour toute la page
  // — utilisé par les icônes ⓘ qui accompagnent les labels de section dans la sidebar.
  if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
      try { new bootstrap.Tooltip(el); } catch (e) {}
    });
  }

  var layoutData = window.AccueilEditor.layoutData || [];
  var editablesByRowId = {};  // {rowId: [{field, kind, preview}, ...]}
  var sortable = null;
  var selectedRowId = null;
  var pendingInsertPos = -1;

  function getCsrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  // ── Réception messages de l'iframe ──
  window.addEventListener('message', function(e) {
    if (!iframe.contentWindow || e.source !== iframe.contentWindow) return;
    if (!e.data || typeof e.data !== 'object') return;
    if (e.data.type === 'editor-layout') {
      // Cache la liste des éléments éditables par rowId (utilisée par selectRow pour
      // afficher les boutons "Modifier ce texte/code" dans la sidebar)
      editablesByRowId = {};
      (e.data.rows || []).forEach(function(r) {
        editablesByRowId[r.rowId] = r.editables || [];
      });
      if (e.data.docHeight && e.data.docHeight > 100) {
        // Buffer minimal (2px) : juste de quoi éviter une scrollbar parasite, mais
        // assez petit pour ne pas amplifier les boucles si l'iframe se redimensionne.
        var newH = Math.min(e.data.docHeight + 2, 8000);
        var currentH = parseInt(iframe.style.height, 10) || 0;
        // Seuil de 5px pour update : évite les micro-oscillations à chaque message.
        if (Math.abs(newH - currentH) > 5) {
          iframe.style.height = newH + 'px';
          setTimeout(function() { buildOverlays(e.data.rows); }, 50);
        } else {
          buildOverlays(e.data.rows);
        }
      } else {
        buildOverlays(e.data.rows);
      }
    } else if (e.data.type === 'editor-click-section') {
      selectRow(e.data.rowId);
    } else if (e.data.type === 'editor-edit-custom-col') {
      openCustomBlockEditorForSection(e.data.sectionId);
    } else if (e.data.type === 'editor-contextmenu') {
      showEditorContextMenu(e.data);
    } else if (e.data.type === 'editor-select-edit') {
      selectEditableElement(e.data);
    } else if (e.data.type === 'editor-dblclick-edit') {
      openEditFromIframe(e.data);
    } else if (e.data.type === 'editor-save-inline') {
      saveInlineField(e.data.field, e.data.value);
    } else if (e.data.type === 'editor-save-geometry') {
      saveGeometry(e.data.field, e.data.geometry, e.data.device);
    }
  });

  // Reset AJAX : supprime la géométrie d'un champ + ordonne à l'iframe de retirer
  // ses styles inline pour revenir à l'affichage CSS par défaut.
  // DEVICE-AWARE : en mode mobile, on ne supprime QUE la variante `{field}_mobile`
  // (la position desktop reste). En mode desktop, on ne touche QU'à `{field}`.
  function getCurrentEditorDevice(){
    try { return localStorage.getItem('ife_editor_device') === 'mobile' ? 'mobile' : 'desktop'; }
    catch(e) { return 'desktop'; }
  }
  function resetGeometry(field) {
    if (typeof setDraftState === 'function') setDraftState(true);
    var device = getCurrentEditorDevice();
    var csrf = getCsrf();
    var fd = new FormData();
    fd.append('reset_accueil_geometry', '1');
    fd.append('field', field);
    fd.append('device', device);
    fd.append('csrf_token', csrf);
    fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j && j.ok) {
          // Demande à l'iframe de retirer les styles inline pour cet élément
          if (iframe.contentWindow) {
            iframe.contentWindow.postMessage({ type: 'parent-reset-geometry', field: field, device: device }, '*');
          }
          showSaveIndicator();
        } else {
          alert('Erreur reset : ' + ((j && j.err) || 'inconnue'));
        }
      });
  }

  // Save AJAX d'une géométrie (x/y/w/h) pour drag/resize libre.
  // `device` (optionnel) : 'desktop' | 'mobile' — l'iframe l'envoie selon le toggle
  // courant ; le PHP préfixe alors `_mobile` au nom du champ.
  // Erreur de sauvegarde (réseau, ou réponse non-JSON = session expirée) : on prévient
  // l'admin au lieu de perdre sa modification silencieusement.
  function accueilSaveError(e) {
    console.error('Sauvegarde accueil échouée :', e);
    alert('Sauvegarde échouée (réseau ou session expirée). Reconnectez-vous puis réessayez.');
  }

  function saveGeometry(field, geom, device) {
    if (typeof setDraftState === 'function') setDraftState(true);
    var csrf = getCsrf();
    var fd = new FormData();
    fd.append('save_accueil_geometry', '1');
    fd.append('field', field);
    fd.append('geometry', JSON.stringify(geom));
    if (device === 'mobile' || device === 'desktop') fd.append('device', device);
    fd.append('csrf_token', csrf);
    fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        // Confirme visuellement à l'admin la CLÉ effective sauvegardée (suffixée
        // `_mobile` en mode mobile) pour qu'il VOIE que mobile/desktop sont isolés.
        if (j && j.ok && j.savedKey) {
          showGeometrySavedToast(j.savedKey, j.device || device);
        }
      })
      .catch(accueilSaveError);
  }
  // Toast léger en bas/droite de l'éditeur, masqué après 1.8s. Réutilise un seul nœud DOM.
  var __geomToast = null;
  function showGeometrySavedToast(key, device) {
    if (!__geomToast) {
      __geomToast = document.createElement('div');
      __geomToast.style.cssText = ''
        + 'position:fixed;bottom:20px;right:20px;z-index:99999;'
        + 'background:#0f172a;color:#fff;padding:10px 14px;border-radius:8px;'
        + 'font-size:12px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.25);'
        + 'transition:opacity .25s, transform .25s;opacity:0;transform:translateY(8px);'
        + 'pointer-events:none;';
      document.body.appendChild(__geomToast);
    }
    var color = device === 'mobile' ? '#ec4899' : '#22d3ee';
    __geomToast.innerHTML = ''
      + '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + color + ';margin-right:8px;vertical-align:middle;"></span>'
      + 'Sauvegardé : <code style="background:rgba(255,255,255,.1);padding:1px 5px;border-radius:3px;">' + key + '</code>';
    __geomToast.style.opacity = '1';
    __geomToast.style.transform = 'translateY(0)';
    clearTimeout(__geomToast.__hide);
    __geomToast.__hide = setTimeout(function(){
      if (__geomToast) {
        __geomToast.style.opacity = '0';
        __geomToast.style.transform = 'translateY(8px)';
      }
    }, 1800);
  }

  // Save AJAX d'un champ texte édité inline (PAS de reload iframe — on update juste le DOM)
  function saveInlineField(field, value) {
    if (typeof setDraftState === 'function') setDraftState(true);
    var csrf = getCsrf();
    var fd = new FormData();
    var isText = field.indexOf('.') !== -1;
    if (isText) {
      fd.append('save_accueil_text', '1');
      fd.append('textKey', field);
      fd.append('textValue', value);
    } else {
      fd.append('save_accueil_field', '1');
      fd.append('field', field);
      fd.append('value', value);
    }
    fd.append('csrf_token', csrf);
    fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j && j.ok) {
          // Pas de reload iframe : on update directement le DOM via postMessage
          updateIframeText(field, value);
          showSaveIndicator();
        } else {
          alert('Erreur sauvegarde inline : ' + ((j && j.err) || 'inconnue'));
        }
      })
      .catch(accueilSaveError);
  }

  // Demande à l'iframe de mettre à jour son DOM sans reload
  function updateIframeText(field, value) {
    if (!iframe.contentWindow) return;
    iframe.contentWindow.postMessage({
      type: 'parent-update-text',
      field: field,
      value: value
    }, '*');
  }

  // Indicateur visuel "✓ enregistré" en haut de l'éditeur
  var saveIndicatorTimer = null;
  function showSaveIndicator() {
    var el = document.getElementById('layoutSaveStatus');
    if (!el) return;
    el.style.display = 'block';
    el.style.color = '#16a34a';
    el.textContent = '✓ Modification enregistrée';
    clearTimeout(saveIndicatorTimer);
    saveIndicatorTimer = setTimeout(function() { el.style.display = 'none'; }, 1500);
  }

  // Helper : retire tous les widgets dynamiques injectés dans la sidebar par
  // selectEditableElement / selectRow / etc. Indispensable pour éviter qu'un bouton
  // "Revenir à la position" laissé par une précédente sélection persiste sur une
  // sélection actuelle qui n'en a pas besoin.
  function clearSidebarDynamicWidgets() {
    ['ifeSbFilePickerRow', 'ifeSbTextEditRow', 'ifeSbAlignRow', 'ifeSbResetRow',
     'ifeSbEditableList', 'ifeSbSectionOptions', 'ifeSbBadgeInfoRow',
     'ifeSbBadgeTooltipRow', 'ifeSbHeroVideoRow']
      .forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.remove();
      });
  }

  // Bascule sur un onglet de réglages (Personnalisation / Accueil / Inscription / etc.)
  // et défile vers un élément précis (input ou card) pour le mettre en évidence.
  function goToSettingTab(tabName, targetSelector) {
    var tab = document.querySelector('#settingsTabs .nav-link[data-tab="' + tabName + '"]');
    if (tab) tab.click();
    if (!targetSelector) return;
    setTimeout(function() {
      var target = document.querySelector(targetSelector);
      if (!target) return;
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      // Surbrillance temporaire pour l'attirer le regard
      var prevOutline = target.style.outline;
      var prevTransition = target.style.transition;
      target.style.transition = 'outline 0.3s ease';
      target.style.outline = '3px solid #F42182';
      setTimeout(function() {
        target.style.outline = prevOutline;
        target.style.transition = prevTransition;
      }, 1800);
      if (typeof target.focus === 'function') {
        try { target.focus({ preventScroll: true }); } catch (_) { target.focus(); }
      }
    }, 80);
  }

  // Sélection d'un élément éditable du Hero (single click)
  function selectEditableElement(data) {
    switchSidebarTab('props');
    document.getElementById('ifeSbEmpty').style.display = 'none';
    document.getElementById('ifeSbProps').style.display = '';
    document.getElementById('ifeSbTitle').textContent = data.field;
    document.getElementById('ifeSbWidthRow').style.display = 'none';
    // La grille n'a pas de sens pour un texte/élément individuel : on la cache
    var gridRowEl = document.getElementById('ifeSbGridRow');
    if (gridRowEl) gridRowEl.style.display = 'none';
    document.getElementById('ifeSbVisRow').style.display = 'none';
    document.getElementById('ifeSbBtnDelete').style.display = 'none';
    // L'espacement de ligne ne concerne que les LIGNES du layout (margin haut/bas entre rows),
    // pas les éléments individuels du Hero. On le cache ici (et selectRow le ré-affiche).
    var spacingRowEl = document.getElementById('ifeSbSpacingRow');
    if (spacingRowEl) spacingRowEl.style.display = 'none';

    var sizeRow = document.getElementById('ifeSbSizeRow');
    if (data.sizeKey) {
      sizeRow.style.display = '';
      document.getElementById('ifeSbSize').value = data.sizeCurrent;
      document.getElementById('ifeSbSizeVal').textContent = data.sizeCurrent + '%';
      sizeRow.dataset.sizeKey = data.sizeKey;
    } else {
      sizeRow.style.display = 'none';
    }

    // Cleanup anciens widgets dynamiques (partagé avec selectRow pour éviter résidus)
    clearSidebarDynamicWidgets();

    // Widgets spécifiques à la VIDÉO Hero : hauteur + toggles pleine largeur,
    // device-aware (mobile/desktop). Lit/écrit dans accueilStyles via save_accueil_style.
    if (data.field === 'video_accueil' || data.field === 'video_accueil_mobile') {
      var heroVidRow = document.createElement('div');
      heroVidRow.id = 'ifeSbHeroVideoRow';
      heroVidRow.className = 'ife-sb-row';
      heroVidRow.style.flexDirection = 'column';
      heroVidRow.style.alignItems = 'stretch';
      heroVidRow.style.gap = '8px';
      var deviceNow = getCurrentEditorDevice();
      var hSizeKey  = (deviceNow === 'mobile') ? 'hero_card_height_mobile'    : 'hero_card_height';
      var hWidthKey = (deviceNow === 'mobile') ? 'hero_card_width_mobile'     : 'hero_card_width';
      var hFullKey  = (deviceNow === 'mobile') ? 'hero_card_fullwidth_mobile' : 'hero_card_fullwidth';
      var stylesCache = (window.AccueilEditor && window.AccueilEditor.accueilStyles) || {};
      // Lecture de la valeur sauvée OU mesure de la dimension réelle de la card.
      var curHeight = parseInt(stylesCache[hSizeKey], 10);
      if (isNaN(curHeight) || curHeight < 300 || curHeight > 1500) curHeight = 700;
      var curWidth = parseInt(stylesCache[hWidthKey], 10);
      if (isNaN(curWidth) || curWidth < 50 || curWidth > 100) {
        // Pas de valeur sauvée → on MESURE la largeur réelle de la card en % de la
        // viewport de l'iframe (cohérent avec l'unité `vw` appliquée). Ainsi le slider
        // démarre sur la vraie valeur visuelle (ex : 86 %) au lieu d'un 100 % erroné.
        try {
          var ifrW = iframe.contentWindow;
          var cardM = iframe.contentDocument && iframe.contentDocument.querySelector('.demo-card');
          if (cardM && ifrW && ifrW.innerWidth > 0) {
            curWidth = Math.round(cardM.getBoundingClientRect().width / ifrW.innerWidth * 100);
          }
        } catch (_) {}
        if (isNaN(curWidth) || curWidth < 50 || curWidth > 100) curWidth = 100;
      }
      var curFull   = String(stylesCache[hFullKey] || '0') === '1';
      heroVidRow.innerHTML = ''
        + '<label class="mb-0">Hauteur de la vidéo <span id="ifeSbHeroVideoHeightVal" style="color:#64748b">' + curHeight + ' px</span></label>'
        + '<input type="range" min="300" max="1500" step="10" id="ifeSbHeroVideoHeight" value="' + curHeight + '" class="form-range">'
        + '<label class="mb-0">Largeur de la vidéo <span id="ifeSbHeroVideoWidthVal" style="color:#64748b">' + curWidth + ' %</span></label>'
        + '<input type="range" min="50" max="100" step="1" id="ifeSbHeroVideoWidth" value="' + curWidth + '" class="form-range">'
        + '<small class="text-muted" style="margin-top:-4px;display:block">100 % = pleine largeur de la fenêtre. Active le toggle ci-dessous pour étendre la vidéo hors du conteneur central du site.</small>'
        + '<div class="form-check form-switch mt-1">'
        + '  <input class="form-check-input" type="checkbox" id="ifeSbHeroVideoFull"' + (curFull ? ' checked' : '') + '>'
        + '  <label class="form-check-label" for="ifeSbHeroVideoFull">Vidéo pleine largeur</label>'
        + '</div>'
        + '<button type="button" class="btn btn-sm btn-outline-warning mt-1" id="ifeSbHeroVideoReset">'
        + '  <i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser dimensions vidéo'
        + '</button>'
        + '<small class="text-muted">Ces réglages s\'appliquent à la vue ' + (deviceNow === 'mobile' ? 'MOBILE' : 'DESKTOP') + ' seulement.</small>';
      // Insertion après sizeRow.
      sizeRow.parentNode.insertBefore(heroVidRow, sizeRow.nextSibling);
      var heightInput = document.getElementById('ifeSbHeroVideoHeight');
      var heightVal   = document.getElementById('ifeSbHeroVideoHeightVal');
      var widthInput  = document.getElementById('ifeSbHeroVideoWidth');
      var widthVal    = document.getElementById('ifeSbHeroVideoWidthVal');
      var fullInput   = document.getElementById('ifeSbHeroVideoFull');
      // Slider hauteur : save debounced + update live via CSS variable dans l'iframe.
      var heightTimer = null;
      heightInput.addEventListener('input', function() {
        var v = parseInt(this.value, 10);
        heightVal.textContent = v + ' px';
        // Update live : pose la CSS variable sur .demo-card dans l'iframe.
        try {
          var ifrDoc = iframe.contentDocument;
          var card = ifrDoc && ifrDoc.querySelector('.demo-card');
          if (card) {
            var varName = (deviceNow === 'mobile') ? '--hero-card-height-mobile' : '--hero-card-height';
            card.style.setProperty(varName, v + 'px');
          }
        } catch (_) {}
        clearTimeout(heightTimer);
        heightTimer = setTimeout(function() {
          saveStyleKey(hSizeKey, v);
          stylesCache[hSizeKey] = v;
        }, 400);
      });
      // Slider largeur : valeur en % de la VIEWPORT (vw). Cohérent avec la mesure
      // initiale et indépendant des contraintes du wrap parent.
      var widthTimer = null;
      widthInput.addEventListener('input', function() {
        var v = parseInt(this.value, 10);
        widthVal.textContent = v + ' %';
        try {
          var ifrDoc = iframe.contentDocument;
          var card = ifrDoc && ifrDoc.querySelector('.demo-card');
          if (card) {
            var varName = (deviceNow === 'mobile') ? '--hero-card-width-mobile' : '--hero-card-width';
            var varMx   = (deviceNow === 'mobile') ? '--hero-card-mx-mobile'    : '--hero-card-mx';
            // width en vw + marge calculée → centre la card pile sur la viewport.
            // C'est ce qui fait que 100 % = pleine fenêtre (et non 100 % du wrap).
            card.style.setProperty(varName, v + 'vw');
            card.style.setProperty(varMx, 'calc(50% - 50vw + ' + ((100 - v) / 2) + 'vw)');
          }
        } catch (_) {}
        clearTimeout(widthTimer);
        widthTimer = setTimeout(function() {
          saveStyleKey(hWidthKey, v);
          stylesCache[hWidthKey] = v;
        }, 400);
      });
      // Toggle pleine largeur : save + update class dans l'iframe.
      fullInput.addEventListener('change', function() {
        var val = this.checked ? '1' : '0';
        saveStyleKey(hFullKey, val);
        stylesCache[hFullKey] = val;
        // Update live : ajoute/retire la classe sur .demo-wrap dans l'iframe.
        try {
          var ifrDoc = iframe.contentDocument;
          var wrap = ifrDoc && ifrDoc.querySelector('.demo-wrap');
          if (wrap) {
            var cls = (deviceNow === 'mobile') ? 'fullwidth-mobile' : 'fullwidth-desktop';
            wrap.classList.toggle(cls, val === '1');
          }
        } catch (_) {}
      });
      // Bouton "Réinitialiser dimensions vidéo" : supprime hauteur + largeur + pleine
      // largeur pour le DEVICE COURANT. Visuel revient au CSS d'origine SANS reload :
      //  - retire les CSS variables --hero-card-height/-width sur .demo-card
      //  - retire la classe fullwidth-desktop/-mobile sur .demo-wrap
      //  - mesure la NOUVELLE dimension réelle et met à jour les sliders en direct
      var resetBtn = document.getElementById('ifeSbHeroVideoReset');
      if (resetBtn) {
        resetBtn.addEventListener('click', function() {
          if (!confirm('Réinitialiser la hauteur, largeur et pleine largeur de la vidéo pour la version ' + (deviceNow === 'mobile' ? 'MOBILE' : 'DESKTOP') + ' ?')) return;
          if (typeof setDraftState === 'function') setDraftState(true);
          var csrf = getCsrf();
          var fd = new FormData();
          fd.append('reset_hero_card_dimensions', '1');
          fd.append('scope', deviceNow);
          fd.append('csrf_token', csrf);
          fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(function(r) { return r.json(); })
            .then(function(j) {
              if (j && j.ok) {
                // Purge le cache de styles local pour les 3 clés concernées.
                delete stylesCache[hSizeKey];
                delete stylesCache[hWidthKey];
                delete stylesCache[hFullKey];
                // Retire les CSS variables + classe pleine largeur dans l'iframe →
                // le CSS d'origine reprend la main. Puis on RE-MESURE les dimensions
                // résultantes et on remet les CURSEURS sur ces valeurs par défaut.
                try {
                  var ifrDoc2 = iframe.contentDocument;
                  var card2 = ifrDoc2 && ifrDoc2.querySelector('.demo-card');
                  var wrap2 = ifrDoc2 && ifrDoc2.querySelector('.demo-wrap');
                  var vH  = (deviceNow === 'mobile') ? '--hero-card-height-mobile' : '--hero-card-height';
                  var vW  = (deviceNow === 'mobile') ? '--hero-card-width-mobile'  : '--hero-card-width';
                  var vMx = (deviceNow === 'mobile') ? '--hero-card-mx-mobile'     : '--hero-card-mx';
                  if (card2) {
                    card2.style.removeProperty(vH);
                    card2.style.removeProperty(vW);
                    card2.style.removeProperty(vMx);
                  }
                  if (wrap2) {
                    wrap2.classList.remove(deviceNow === 'mobile' ? 'fullwidth-mobile' : 'fullwidth-desktop');
                  }
                  // Re-mesure les valeurs CSS d'origine et remet les curseurs dessus.
                  setTimeout(function() {
                    if (card2) {
                      var nH = Math.round(card2.getBoundingClientRect().height);
                      if (heightInput && nH) {
                        heightInput.value = Math.min(1500, Math.max(300, nH));
                        heightVal.textContent = heightInput.value + ' px';
                      }
                      var ifrWin2 = iframe.contentWindow;
                      if (widthInput && ifrWin2 && ifrWin2.innerWidth > 0) {
                        var nW = Math.round(card2.getBoundingClientRect().width / ifrWin2.innerWidth * 100);
                        widthInput.value = Math.min(100, Math.max(50, nW));
                        widthVal.textContent = widthInput.value + ' %';
                      }
                      if (fullInput) fullInput.checked = false;
                    }
                  }, 60);
                } catch (_) {}
              } else {
                alert('Erreur réinitialisation : ' + ((j && j.err) || 'inconnue'));
              }
            })
            .catch(function() { alert('Erreur réseau lors de la réinitialisation.'); });
        });
      }
    }

    // Info contextuelle pour les badges : la valeur (km / montant) n'est pas modifiable
    // depuis l'éditeur visuel. Renvoie l'utilisateur vers l'onglet Inscription → carte
    // "Paramètres d'inscription" pour modifier la donnée elle-même.
    var badgeInfo = null;
    if (data.field === 'badge_km')  badgeInfo = { msg: 'Pour changer la distance (km),', target: '#course_km' };
    if (data.field === 'badge_fee') badgeInfo = { msg: "Pour changer le montant d'inscription,", target: '#registration_fee' };
    if (badgeInfo) {
      var infoRow = document.createElement('div');
      infoRow.id = 'ifeSbBadgeInfoRow';
      infoRow.className = 'ife-sb-row';
      infoRow.style.flexDirection = 'column';
      infoRow.style.alignItems = 'stretch';
      infoRow.style.background = '#fff8e1';
      infoRow.style.border = '1px solid #ffe082';
      infoRow.style.borderRadius = '6px';
      infoRow.style.padding = '8px 10px';
      infoRow.style.fontSize = '12px';
      infoRow.innerHTML = ''
        + '<div><i class="bi bi-info-circle me-1 text-warning"></i>'
        + badgeInfo.msg + ' <a href="#" id="ifeSbBadgeInfoLink" style="font-weight:600;text-decoration:underline;">cliquez ici</a>.'
        + '</div>';
      sizeRow.parentNode.insertBefore(infoRow, sizeRow);
      document.getElementById('ifeSbBadgeInfoLink').addEventListener('click', function(e) {
        e.preventDefault();
        goToSettingTab('inscription', badgeInfo.target);
      });
    }

    // Champ texte pour la TOOLTIP du badge prix (visible au hover/clic sur le badge €).
    // Disponible quand l'admin sélectionne badge_fee ou badge_fee_mobile : il voit ainsi
    // immédiatement ce qui est écrit et peut le modifier sans passer par un autre menu.
    if (data.field === 'badge_fee' || data.field === 'badge_fee_mobile') {
      var tipKey = 'badge_fee.tooltip';
      var tipRow = document.createElement('div');
      tipRow.id = 'ifeSbBadgeTooltipRow';
      tipRow.className = 'ife-sb-row';
      tipRow.style.flexDirection = 'column';
      tipRow.style.alignItems = 'stretch';
      tipRow.innerHTML = ''
        + '<label class="mb-1">Texte au survol du badge prix</label>'
        + '<textarea class="form-control form-control-sm" id="ifeSbBadgeTooltipInput" rows="2" style="resize:vertical;"></textarea>'
        + '<small class="text-muted mt-1">S\'affiche quand le visiteur passe la souris sur le badge €.</small>';
      sizeRow.parentNode.insertBefore(tipRow, sizeRow.nextSibling);
      var tipInput = document.getElementById('ifeSbBadgeTooltipInput');
      // Lit la valeur courante depuis le DOM de l'iframe (textContent du tooltip rendu).
      var iframeDoc = null;
      try { iframeDoc = iframe.contentDocument; } catch (_) {}
      var liveTip = iframeDoc && iframeDoc.getElementById('badgeTooltip');
      tipInput.value = liveTip ? (liveTip.textContent || '').trim() : '';
      // Sauvegarde debounced à la saisie + au blur.
      var tipTimer = null;
      function saveTip() {
        var v = tipInput.value.trim();
        saveInlineField(tipKey, v);
        // Met à jour le DOM iframe sans reload (effet immédiat dans l'aperçu).
        if (liveTip) liveTip.textContent = v;
      }
      tipInput.addEventListener('blur', saveTip);
      tipInput.addEventListener('input', function() {
        clearTimeout(tipTimer);
        tipTimer = setTimeout(saveTip, 700);
      });
      // Ajoute l'id au clear-up pour éviter qu'il reste affiché lors d'une autre sélection.
      // (clearSidebarDynamicWidgets liste les ids à nettoyer — on l'étend dynamiquement.)
    }

    // Bouton "Revenir à la position par défaut" pour les éléments hero/partners draggables
    var resettableFields = [
      'titleAccueil', 'titleAccueil_mobile',
      'subtitle_accueil', 'subtitle_accueil_mobile',
      'hero_timer', 'hero_timer_mobile', 'picture_partner',
      'hero.cta_register', 'hero.cta_register_mobile',
      'badge_fee', 'badge_km',
      'video_toggle', 'video_social_card'
    ];
    if (resettableFields.indexOf(data.field) !== -1) {
      var resetRow = document.createElement('div');
      resetRow.id = 'ifeSbResetRow';
      resetRow.className = 'ife-sb-row';
      resetRow.style.flexDirection = 'column';
      resetRow.style.alignItems = 'stretch';
      resetRow.innerHTML = ''
        + '<button type="button" class="btn btn-sm btn-outline-warning" id="ifeSbResetBtn">'
        + '<i class="bi bi-arrow-counterclockwise me-1"></i>Revenir à la position par défaut'
        + '</button>'
        + '<small class="text-muted mt-1">Réinitialise position, taille et déplacement de cet élément.</small>';
      sizeRow.parentNode.insertBefore(resetRow, sizeRow.nextSibling);
      document.getElementById('ifeSbResetBtn').addEventListener('click', function() {
        if (!confirm('Remettre cet élément à sa position et taille par défaut ?')) return;
        resetGeometry(data.field);
        // Remet aussi la taille à 100% (sinon le slider reste sur l'ancienne valeur
        // et la taille sauvée dans accueil_styles_draft n'est pas réinitialisée → la
        // publication conservait l'ancienne taille).
        if (data.sizeKey) {
          var sizeInput = document.getElementById('ifeSbSize');
          var sizeValLbl = document.getElementById('ifeSbSizeVal');
          if (sizeInput) sizeInput.value = 100;
          if (sizeValLbl) sizeValLbl.textContent = '100%';
          data.sizeCurrent = 100;
          saveStyleKey(data.sizeKey, 100);
        }
      });
    }

    var btnEdit = document.getElementById('ifeSbBtnEdit');

    // Pour les textes simples : on AFFICHE un textarea dans la sidebar pré-rempli
    if (data.kind === 'text') {
      btnEdit.style.display = 'none';
      var textRow = document.createElement('div');
      textRow.id = 'ifeSbTextEditRow';
      textRow.className = 'ife-sb-row';
      textRow.style.flexDirection = 'column';
      textRow.style.alignItems = 'stretch';
      textRow.innerHTML = ''
        + '<label class="mb-1">Contenu du texte</label>'
        + '<textarea class="form-control form-control-sm" id="ifeSbTextInput" rows="3" style="resize:vertical;"></textarea>'
        + '<small class="text-muted mt-1">Modification automatique en quittant le champ.</small>';
      sizeRow.parentNode.insertBefore(textRow, sizeRow.nextSibling);
      var textInput = document.getElementById('ifeSbTextInput');
      textInput.value = data.currentValue || '';

      // Save sur blur (changement) — debounced
      var textTimer = null;
      function saveText() {
        var newVal = textInput.value.trim();
        if (newVal !== (data.currentValue || '').trim()) {
          saveInlineField(data.field, newVal);
        }
      }
      textInput.addEventListener('blur', saveText);
      textInput.addEventListener('input', function() {
        clearTimeout(textTimer);
        textTimer = setTimeout(saveText, 800);
      });

      // Boutons d'alignement (gauche / centre / droite)
      var alignRow = document.createElement('div');
      alignRow.id = 'ifeSbAlignRow';
      alignRow.className = 'ife-sb-row';
      alignRow.innerHTML = ''
        + '<label>Alignement</label>'
        + '<div class="btn-group btn-group-sm">'
        + '  <button type="button" class="btn btn-outline-secondary" data-align="left"   title="Aligner à gauche"><i class="bi bi-text-left"></i></button>'
        + '  <button type="button" class="btn btn-outline-secondary" data-align="center" title="Centrer"><i class="bi bi-text-center"></i></button>'
        + '  <button type="button" class="btn btn-outline-secondary" data-align="right"  title="Aligner à droite"><i class="bi bi-text-right"></i></button>'
        + '</div>';
      textRow.parentNode.insertBefore(alignRow, textRow.nextSibling);

      alignRow.querySelectorAll('button[data-align]').forEach(function(b) {
        b.addEventListener('click', function() {
          saveStyleKey('text_align__' + data.field, b.dataset.align);
        });
      });
    } else if (data.kind === 'image' || data.kind === 'video') {
      btnEdit.style.display = 'none';
      var pickerRow = document.createElement('div');
      pickerRow.id = 'ifeSbFilePickerRow';
      pickerRow.className = 'ife-sb-row';
      pickerRow.style.flexDirection = 'column';
      pickerRow.style.alignItems = 'stretch';
      pickerRow.innerHTML = ''
        + '<label class="mb-2">Remplacer le ' + (data.kind === 'image' ? 'image' : 'vidéo') + '</label>'
        + '<input type="file" class="form-control form-control-sm" id="ifeSbFileInput" accept="' + (data.kind === 'image' ? 'image/jpeg,image/png,image/gif,image/webp' : 'video/mp4,video/webm,video/ogg') + '">'
        + '<small class="text-muted mt-1">' + (data.kind === 'image' ? 'JPG, PNG, GIF, WEBP — max 5 Mo.' : 'MP4, WebM, OGG — max 50 Mo.') + '</small>'
        + (data.currentValue ? '<small class="text-muted">Fichier actuel : <code>' + data.currentValue + '</code></small>' : '')
        + '<div id="ifeSbFileStatus" class="mt-2 small" style="display:none;"></div>';
      sizeRow.parentNode.insertBefore(pickerRow, sizeRow.nextSibling);

      document.getElementById('ifeSbFileInput').addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;
        if (typeof setDraftState === 'function') setDraftState(true);
        var status = document.getElementById('ifeSbFileStatus');
        status.style.display = 'block';
        status.style.color = '#64748b';
        status.textContent = 'Upload en cours…';
        var csrf = getCsrf();
        var fd = new FormData();
        fd.append('save_accueil_field', '1');
        // La vidéo est COMMUNE desktop/mobile (même fichier) : même si le champ
        // affiché est `video_accueil_mobile` en mode mobile, l'upload cible toujours
        // la colonne unique `video_accueil`. Seules les DIMENSIONS sont device-aware.
        var uploadField = (data.field === 'video_accueil_mobile') ? 'video_accueil' : data.field;
        fd.append('field', uploadField);
        fd.append('file', file);
        fd.append('csrf_token', csrf);
        fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
          .then(function(r) { return r.json(); })
          .then(function(j) {
            if (j && j.ok) {
              status.style.color = '#16a34a';
              status.textContent = '✓ Uploadé. Rechargement…';
              setTimeout(function() { reloadIframe(); }, 500);
            } else {
              status.style.color = '#dc2626';
              status.textContent = '✗ ' + ((j && j.err) || 'Erreur upload.');
            }
          })
          .catch(function() {
            status.style.color = '#dc2626';
            status.textContent = '✗ Erreur réseau.';
          });
      });
    } else if (data.kind !== 'size-only') {
      btnEdit.style.display = '';
      btnEdit.onclick = function() { openEditFromIframe(data); };
    } else {
      btnEdit.style.display = 'none';
    }
  }

  // Helper générique : sauve une clé/valeur dans accueil_styles (utilisé pour text_align__field, etc.)
  // Met à jour l'iframe via postMessage sans reload.
  // DEVICE-AWARE pour les tailles (`*_size`) : en mode mobile, on cible la variante
  // `*_size_mobile`. Alignements / options enum restent communs.
  function saveStyleKey(key, value) {
    if (typeof setDraftState === 'function') setDraftState(true);
    var device = getCurrentEditorDevice();
    var csrf = getCsrf();
    var fd = new FormData();
    fd.append('save_accueil_style', '1');
    fd.append('sizeKey', key);
    fd.append('sizeValue', value);
    fd.append('device', device);
    fd.append('csrf_token', csrf);
    fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j && j.ok) {
          // L'iframe reçoit la clé EFFECTIVE (suffixée _mobile si applicable) pour
          // appliquer la taille au bon viewport visuellement, sans attendre reload.
          var effectiveKey = (device === 'mobile' && /_size$/.test(key)) ? (key + '_mobile') : key;
          updateIframeStyle(effectiveKey, value);
          showSaveIndicator();
        }
      })
      .catch(accueilSaveError);
  }

  // Push un changement de style à l'iframe sans reload
  function updateIframeStyle(key, value) {
    if (!iframe.contentWindow) return;
    iframe.contentWindow.postMessage({
      type: 'parent-update-style',
      key: key,
      value: value
    }, '*');
  }

  // Slider de taille → save AJAX + update iframe sans reload.
  // DEVICE-AWARE : en mode mobile, on sauve dans `<sizeKey>_mobile`.
  var sizeSaveTimer = null;
  document.getElementById('ifeSbSize').addEventListener('input', function() {
    var val = parseInt(this.value, 10);
    document.getElementById('ifeSbSizeVal').textContent = val + '%';
    var sizeKey = document.getElementById('ifeSbSizeRow').dataset.sizeKey;
    if (!sizeKey) return;
    var device = getCurrentEditorDevice();
    var effectiveKey = (device === 'mobile' && /_size$/.test(sizeKey)) ? (sizeKey + '_mobile') : sizeKey;
    // Badge "modifications non publiées" instantané (avant même le debounce)
    if (typeof setDraftState === 'function') setDraftState(true);
    // Update visual immédiat dans l'iframe (l'iframe applique la taille selon la clé reçue)
    updateIframeStyle(effectiveKey, val);
    // Save BDD (debounced)
    clearTimeout(sizeSaveTimer);
    sizeSaveTimer = setTimeout(function() {
      var csrf = getCsrf();
      var fd = new FormData();
      fd.append('save_accueil_style', '1');
      fd.append('sizeKey', sizeKey);
      fd.append('sizeValue', val);
      fd.append('device', device);
      fd.append('csrf_token', csrf);
      fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) { if (j && j.ok) showSaveIndicator(); })
        .catch(accueilSaveError);
    }, 350);
  });

  function reloadIframe() {
    var scrollY = 0;
    try { scrollY = iframe.contentWindow ? iframe.contentWindow.scrollY : 0; } catch(err) {}
    iframe.src = '../public/accueil.php?editor=1&_t=' + Date.now();
    iframe.onload = function() {
      try { iframe.contentWindow.scrollTo(0, scrollY); } catch(err) {}
    };
  }

  // ── Construit les overlays à partir des rects reçus ──
  // Optimisation : pendant les premières secondes de chargement de l'iframe (vidéo,
  // images), le layout reflow plusieurs fois → editor-layout message tire en boucle.
  // Si on rebuild tout à chaque fois, le drag-rail (qu'on est éventuellement en train
  // de hover) clignotte. Stratégie : si la structure (nb + IDs des rows) est inchangée,
  // on met juste à jour les positions (top/height) des overlays existants. Rebuild
  // complet uniquement si une row a été ajoutée/supprimée/réordonnée.
  function buildOverlays(rows) {
    var existing = Array.prototype.slice.call(overlay.querySelectorAll('.ife-row-overlay'));
    var existingIds = existing.map(function(el) { return el.dataset.rowId; });
    var newIds = rows.map(function(r) { return r.rowId; });
    var sameStructure = existingIds.length === newIds.length
                     && existingIds.every(function(id, i) { return id === newIds[i]; });

    if (sameStructure) {
      // Mise à jour incrémentale : juste les positions, pas de destruction DOM
      rows.forEach(function(row) {
        var el = overlay.querySelector('.ife-row-overlay[data-row-id="' + row.rowId + '"]');
        if (!el) return;
        var top = row.rect.top;
        var height = Math.max(40, row.rect.height);
        if (parseFloat(el.style.top) !== top) el.style.top = top + 'px';
        if (parseFloat(el.style.height) !== height) el.style.height = height + 'px';
      });
      // Met aussi à jour les positions des plus-markers existants
      var plusMarkers = overlay.querySelectorAll('.ife-add-marker');
      rows.forEach(function(row, idx) {
        var marker = plusMarkers[idx + 1]; // markers[0] est le marker avant la 1re row
        if (marker) {
          var pos = row.rect.top + Math.max(40, row.rect.height);
          marker.style.top = (pos - 14) + 'px';
        }
      });
      return;
    }

    // Rebuild complet (structure changée)
    overlay.innerHTML = '';
    addPlusMarker(0, 0);

    rows.forEach(function(row, idx) {
      var top = row.rect.top;
      var height = Math.max(40, row.rect.height);

      var rowOv = document.createElement('div');
      rowOv.className = 'ife-row-overlay';
      rowOv.dataset.rowId = row.rowId;
      rowOv.dataset.rowIdx = idx;
      rowOv.style.top = top + 'px';
      rowOv.style.left = '0';
      rowOv.style.right = '0';
      rowOv.style.height = height + 'px';
      if (row.rowId === selectedRowId) rowOv.classList.add('is-selected');

      // Rail de drag toujours visible à gauche (poignée Sortable)
      var dragRail = document.createElement('div');
      dragRail.className = 'ife-row-drag-rail';
      dragRail.title = 'Glisser pour réorganiser cette section';
      dragRail.innerHTML = '<i class="bi bi-grip-vertical"></i>';
      rowOv.appendChild(dragRail);

      // Détecte si la row contient un bloc custom (= supprimable) ou un pré-défini (= juste masquable)
      var isCustomRow = false;
      var rowData = layoutData.find(function(r) { return r.id === row.rowId; });
      if (rowData && rowData.columns) {
        isCustomRow = rowData.columns.every(function(c) { return c.section && c.section.type === 'custom'; });
      }

      // Combien de colonnes a la row → conditionne les boutons disponibles
      var colCount = rowData ? rowData.columns.length : 1;
      var canAddCol = colCount < 3;

      var actions = document.createElement('div');
      actions.className = 'ife-row-actions';
      var deleteBtn = isCustomRow
        ? '<button data-act="delete" class="danger" title="Supprimer cette ligne"><i class="bi bi-trash3"></i></button>'
        : '<button data-act="hide" title="Masquer cette section"><i class="bi bi-eye-slash"></i></button>';
      // 2 boutons "Diviser" : texte et HTML/CSS/JS séparés
      var addColTextBtn = canAddCol
        ? '<button data-act="add-col-text" title="Ajouter une colonne TEXTE (WYSIWYG)"><i class="bi bi-text-paragraph"></i></button>'
        : '';
      var addColHtmlBtn = canAddCol
        ? '<button data-act="add-col-html" title="Ajouter une colonne HTML/CSS/JS (code brut)"><i class="bi bi-code-slash"></i></button>'
        : '';
      // "Revenir à 1 colonne" : déconstruit une ligne multi-col en lignes individuelles
      var unmergeBtn = (colCount > 1)
        ? '<button data-act="unmerge" title="Revenir à 1 section par ligne"><i class="bi bi-distribute-vertical"></i></button>'
        : '';
      actions.innerHTML = ''
        + '<button class="ife-handle" title="Glisser pour réorganiser"><i class="bi bi-arrows-move"></i></button>'
        + '<button data-act="up" title="Monter cette ligne" ' + (idx === 0 ? 'disabled' : '') + '><i class="bi bi-arrow-up"></i></button>'
        + '<button data-act="down" title="Descendre cette ligne" ' + (idx === rows.length - 1 ? 'disabled' : '') + '><i class="bi bi-arrow-down"></i></button>'
        + addColTextBtn
        + addColHtmlBtn
        + unmergeBtn
        + '<button data-act="select" title="Sélectionner"><i class="bi bi-cursor"></i></button>'
        + deleteBtn;
      rowOv.appendChild(actions);

      rowOv.addEventListener('click', function(e) {
        if (e.target.closest('.ife-row-actions')) return;
        selectRow(row.rowId);
      });

      actions.addEventListener('click', function(e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn || btn.disabled) return;
        e.stopPropagation();
        var act = btn.dataset.act;
        if (act === 'select') selectRow(row.rowId);
        if (act === 'delete') deleteRow(row.rowId);
        if (act === 'hide')   toggleHideRow(row.rowId);
        if (act === 'up')   moveRow(row.rowId, -1);
        if (act === 'down') moveRow(row.rowId,  1);
        if (act === 'add-col-text') addColumnToRow(row.rowId, 'text');
        if (act === 'add-col-html') addColumnToRow(row.rowId, 'html');
        if (act === 'unmerge')      unmergeRow(row.rowId);
      });

      overlay.appendChild(rowOv);
      addPlusMarker(idx + 1, top + height);
    });

    // Détruit l'ancien Sortable proprement (try/catch car peut être null/stale)
    try { if (sortable) sortable.destroy(); } catch (err) {}
    sortable = null;

    // État du drag : drop-zones calculées dynamiquement à partir du rect de chaque row,
    // pour rester robuste aux animations de Sortable qui pourraient déplacer les rows.
    var dropZones = [];      // [{rowId, insertAt, rect}]
    var dropHit = null;      // {rowId, insertAt}
    var draggedRowId = null;
    var isDragging = false;

    function rebuildDropZones() {
      dropZones = [];
      if (!draggedRowId) return;
      var draggedRow = layoutData.find(function(r) { return r.id === draggedRowId; });
      if (!draggedRow) return;

      // Récupère TOUTES les rows (sauf dragguée) avec leur rect courant
      var rowsInfo = [];
      overlay.querySelectorAll('.ife-row-overlay').forEach(function(rowEl) {
        var rid = rowEl.dataset.rowId;
        if (!rid || rid === draggedRowId) return;
        var row = layoutData.find(function(r) { return r.id === rid; });
        if (!row) return;
        rowsInfo.push({ rowId: rid, row: row, rect: rowEl.getBoundingClientRect() });
      });
      rowsInfo.sort(function(a, b) { return a.rect.top - b.rect.top; });

      // Pour chaque row, on coupe verticalement en 3 bandes SANS GAP entre lignes :
      //   - Bande HAUT : "Nouvelle ligne au-dessus" (insertAt = idx)
      //                  S'étend du MILIEU du gap avec la ligne précédente jusqu'à row.top + bandH
      //   - Bande MILIEU : "Insérer comme colonne" (zones par position 0..N+1)
      //   - Bande BAS (dernière row uniquement) : "Nouvelle ligne en-dessous" (insertAt = idx+1)
      // Chaque zone a une "hit area" (large pour cibler facilement) ET une "visual area"
      // (étroite, ancrée AU BORD de la row pour qu'elle soit toujours à la bonne place
      // même si les rows ont des hauteurs irrégulières).
      rowsInfo.forEach(function(info, idx) {
        var row = info.row;
        var rect = info.rect;
        var prevRect = idx > 0 ? rowsInfo[idx - 1].rect : null;
        var nextRect = idx < rowsInfo.length - 1 ? rowsInfo[idx + 1].rect : null;

        // HIT areas — zones cliquables tolérantes (clampées pour ne PAS dépasser dans la
        // row précédente / suivante en cas de chevauchement de rects)
        var hitBand = Math.max(14, Math.min(24, rect.height * 0.18));
        var safeAboveLimit = prevRect ? Math.max(prevRect.bottom, rect.top - 60) : rect.top - 60;
        var hitAboveTop    = Math.max(safeAboveLimit, rect.top - 60);
        // Si chevauchement avec la row précédente, on commence à rect.top (pas avant)
        if (prevRect && prevRect.bottom > rect.top - 4) hitAboveTop = rect.top - 4;
        var hitAboveBottom = rect.top + hitBand;
        var hitColTop      = hitAboveBottom;
        var hitColBottom   = rect.bottom - hitBand;
        var safeBelowLimit = nextRect ? Math.min(nextRect.top, rect.bottom + 60) : rect.bottom + 60;
        var hitBelowTop    = rect.bottom - hitBand;
        var hitBelowBottom = Math.min(safeBelowLimit, rect.bottom + 60);
        if (nextRect && nextRect.top < rect.bottom + 4) hitBelowBottom = rect.bottom + 4;

        // VISUAL areas — ancré PILE au bord de la row (pas au milieu du gap).
        // → bande au-dessus = 10px centrée sur rect.top
        // → bande en-dessous = 10px centrée sur rect.bottom
        // → slots as-col = quasi toute la hauteur (5px padding aux bords)
        var visH = 10;
        var visAboveTop    = rect.top - visH / 2;
        var visAboveBottom = rect.top + visH / 2;
        var visColTop      = rect.top + 6;
        var visColBottom   = rect.bottom - 6;
        var visBelowTop    = rect.bottom - visH / 2;
        var visBelowBottom = rect.bottom + visH / 2;

        // Zone haut → insérer AU-DESSUS de la cible
        dropZones.push({
          kind: 'new-row', position: 'above',
          insertAt: idx, targetRowId: info.rowId,
          left:  rect.left,  right: rect.right,
          top:   hitAboveTop, bottom: hitAboveBottom,
          visTop: visAboveTop, visBottom: visAboveBottom
        });

        // Zones milieu (as-col) — seulement si fusion possible
        if ((row.columns.length + draggedRow.columns.length) <= 3) {
          var n = row.columns.length;
          var slots = n + 1;
          for (var i = 0; i < slots; i++) {
            dropZones.push({
              kind: 'as-col',
              rowId: info.rowId, insertAt: i,
              left:   rect.left + (i / slots) * rect.width,
              right:  rect.left + ((i + 1) / slots) * rect.width,
              top:    hitColTop, bottom: hitColBottom,
              visTop: visColTop, visBottom: visColBottom
            });
          }
        } else {
          // Fusion impossible → étend la zone haut hit sur toute la row pour ne pas avoir de trou
          dropZones[dropZones.length - 1].bottom = rect.bottom;
        }

        // Zone bas (dernière row) → insérer EN-DESSOUS de la cible
        if (!nextRect) {
          dropZones.push({
            kind: 'new-row', position: 'below',
            insertAt: idx + 1, targetRowId: info.rowId,
            left:  rect.left, right: rect.right,
            top:   hitBelowTop, bottom: hitBelowBottom,
            visTop: visBelowTop, visBottom: visBelowBottom
          });
        }
      });
    }

    function hitTest(px, py) {
      // Le curseur DOIT être à l'intérieur de l'iframe visible (sinon on est sur le
      // header, un menu, etc. — un drop n'a aucun sens).
      var ifr = iframe.getBoundingClientRect();
      if (px < ifr.left || px > ifr.right || py < ifr.top || py > ifr.bottom) return null;
      for (var i = 0; i < dropZones.length; i++) {
        var z = dropZones[i];
        if (px >= z.left && px <= z.right && py >= z.top && py <= z.bottom) {
          return {
            kind: z.kind,
            position: z.position || null,
            rowId: z.rowId || null,
            targetRowId: z.targetRowId || null,
            insertAt: z.insertAt,
            zoneIdx: i
          };
        }
      }
      return null;
    }

    // Position du curseur mise à jour par tous les listeners + onMove de Sortable
    // + le capture-layer au-dessus de l'iframe.
    var lastPx = null, lastPy = null;
    var lastIframeTop = 0;
    function pollHitTest() {
      if (!isDragging) return;
      // Détecte tout déplacement de l'iframe (scroll page OU scroll d'un container parent).
      // On ne se fie pas à window.scrollY car ce n'est pas toujours le bon scroll.
      // → rebuild des HIT-zones (en coords viewport) pour rester alignées avec le curseur.
      // Les visuels sont en position:absolute dans le wrap, donc ils suivent
      // automatiquement le scroll (pas de redraw nécessaire).
      var currentIframeTop = iframe.getBoundingClientRect().top;
      if (Math.abs(currentIframeTop - lastIframeTop) > 0.5) {
        lastIframeTop = currentIframeTop;
        rebuildDropZones();
      }
      if (lastPx != null && lastPy != null) {
        var hit = hitTest(lastPx, lastPy);
        dropHit = hit;
        highlightHit(hit);
      }
      requestAnimationFrame(pollHitTest);
    }

    function highlightHit(hit) {
      // Le drop-overlay vit dans document.body (pas dans overlay) pour rester en coords
      // fixed → on cherche au niveau document.
      document.querySelectorAll('.ife-drop-overlay .ife-drop-slot, .ife-drop-overlay .ife-drop-band').forEach(function(s) {
        var match = hit && parseInt(s.dataset.zoneIdx, 10) === hit.zoneIdx;
        s.classList.toggle('is-hot', !!match);
      });
    }

    function trackPointer(e) {
      if (!isDragging) return;
      var px, py;
      if (e.touches && e.touches[0]) { px = e.touches[0].clientX; py = e.touches[0].clientY; }
      else if (e.changedTouches && e.changedTouches[0]) { px = e.changedTouches[0].clientX; py = e.changedTouches[0].clientY; }
      else { px = e.clientX; py = e.clientY; }
      if (px == null || py == null) return;
      // Met seulement à jour le cache : le polling rAF fait le hit-test au prochain frame.
      // Évite d'écraser l'animation/transitions de Sortable + garantit que le hit-test
      // tourne en continu même si certains events sont absorbés.
      lastPx = px; lastPy = py;
    }

    // On attache plusieurs types d'événements + capture:true pour battre Sortable qui
    // pourrait consommer certains pointer/mouse events. Au moins UN doit fonctionner.
    function attachTrackers() {
      document.addEventListener('pointermove', trackPointer, { capture: true, passive: true });
      document.addEventListener('mousemove',   trackPointer, { capture: true, passive: true });
      document.addEventListener('touchmove',   trackPointer, { capture: true, passive: true });
      document.addEventListener('dragover',    trackPointer, { capture: true, passive: true });
    }
    function detachTrackers() {
      document.removeEventListener('pointermove', trackPointer, { capture: true });
      document.removeEventListener('mousemove',   trackPointer, { capture: true });
      document.removeEventListener('touchmove',   trackPointer, { capture: true });
      document.removeEventListener('dragover',    trackPointer, { capture: true });
    }

    // Helper : extrait clientX/Y depuis un événement (mouse/pointer/touch)
    function getEventPos(e) {
      if (!e) return null;
      if (typeof e.clientX === 'number' && typeof e.clientY === 'number') {
        return { x: e.clientX, y: e.clientY };
      }
      var t = (e.changedTouches && e.changedTouches[0]) || (e.touches && e.touches[0]);
      if (t) return { x: t.clientX, y: t.clientY };
      return null;
    }

    // Capture-layer : div transparent au-dessus de l'iframe pour capter les mousemove
    // même quand le curseur passe au-dessus du <iframe> (sinon les events vont au document
    // de l'iframe, pas au parent). Sans ça, le hit-test reste "bloqué" sur la dernière
    // position connue quand on bouge vite au-dessus de l'iframe.
    var captureLayer = null;
    function attachCaptureLayer() {
      var wrap = iframe.parentNode;
      if (!wrap) return;
      captureLayer = document.createElement('div');
      captureLayer.id = 'ifeCaptureLayer';
      // CSS positionné par le styleset .ife-preview-wrap (qui est déjà position:relative)
      captureLayer.style.cssText = 'position:absolute;inset:0;z-index:5;background:transparent;cursor:grabbing;pointer-events:auto;';
      wrap.appendChild(captureLayer);
      captureLayer.addEventListener('pointermove', trackPointer, { passive: true });
      captureLayer.addEventListener('mousemove',   trackPointer, { passive: true });
      captureLayer.addEventListener('touchmove',   trackPointer, { passive: true });
    }
    function detachCaptureLayer() {
      if (captureLayer) { captureLayer.remove(); captureLayer = null; }
    }

    try {
      sortable = Sortable.create(overlay, {
        handle: '.ife-row-drag-rail',
        filter: '.ife-add-marker, .ife-drop-slot, .ife-drop-slots, .ife-drop-band, .ife-drop-overlay',
        draggable: '.ife-row-overlay',
        animation: 0,
        forceFallback: true,
        // Auto-scroll de la fenêtre quand le curseur s'approche des bords pendant le drag.
        // Permet de drag depuis le haut de la page vers une cible en bas (ou inverse).
        scroll: true,
        scrollSensitivity: 80,    // distance en px du bord avant déclenchement
        scrollSpeed: 18,          // vitesse en px par frame
        forceAutoScrollFallback: true, // force le scroll JS-based avec forceFallback
        onMove: function(evt) {
          var pos = getEventPos(evt.originalEvent);
          if (pos) { lastPx = pos.x; lastPy = pos.y; }
          return true;
        },
        onStart: function(evt) {
          draggedRowId = evt.item.dataset.rowId;
          var draggedRow = layoutData.find(function(r) { return r.id === draggedRowId; });
          if (!draggedRow) return;
          isDragging = true;
          lastIframeTop = iframe.getBoundingClientRect().top;
          rebuildDropZones();
          showDropOverlayFromZones(dropZones);
          var pos = getEventPos(evt.originalEvent);
          if (pos) { lastPx = pos.x; lastPy = pos.y; }
          attachTrackers();
          attachCaptureLayer();
          requestAnimationFrame(pollHitTest);
        },
        onEnd: function(evt) {
          detachTrackers();
          detachCaptureLayer();
          isDragging = false;
          var draggedId = evt.item.dataset.rowId || draggedRowId;

          // Dernière chance : hit-test au point de release exact
          if (!dropHit) {
            var pos = getEventPos(evt.originalEvent);
            if (pos) dropHit = hitTest(pos.x, pos.y);
          }

          var hit = dropHit;
          clearDropOverlay();
          dropHit = null;
          dropZones = [];
          draggedRowId = null;

          if (hit) {
            if (hit.kind === 'as-col') {
              mergeIntoRowAt(draggedId, hit.rowId, hit.insertAt);
              return;
            } else if (hit.kind === 'new-row') {
              moveRowToPosition(draggedId, hit.targetRowId, hit.position || 'above');
              return;
            }
          }

          // Reorder DOM-based (au cas où ni as-col ni new-row ne sont détectés)
          var newOrder = [];
          overlay.querySelectorAll('.ife-row-overlay').forEach(function(el) {
            if (!el.dataset || !el.dataset.rowId) return;
            var row = layoutData.find(function(r) { return r.id === el.dataset.rowId; });
            if (row) newOrder.push(row);
          });
          if (newOrder.length === layoutData.length) {
            layoutData = newOrder;
            saveLayoutAndReload();
          }
        }
      });
    } catch (err) {
      console.warn('Sortable init failed:', err);
    }
  }

  // Déplace la row dragguée juste au-dessus ou en-dessous de la row cible.
  function moveRowToPosition(draggedId, targetRowId, position) {
    if (draggedId === targetRowId) return;
    var draggedIdx = layoutData.findIndex(function(r) { return r.id === draggedId; });
    if (draggedIdx < 0) return;
    var dragged = layoutData.splice(draggedIdx, 1)[0];
    var targetIdx = layoutData.findIndex(function(r) { return r.id === targetRowId; });
    if (targetIdx < 0) {
      // Edge case : retour à la position d'origine
      layoutData.splice(draggedIdx, 0, dragged);
      return;
    }
    var insertIdx = position === 'below' ? targetIdx + 1 : targetIdx;
    layoutData.splice(insertIdx, 0, dragged);
    saveLayoutAndReload();
  }

  // Dessine TOUS les visuels (as-col + new-row) à partir du tableau zones.
  // IMPORTANT : l'overlay est attaché au PARENT de l'iframe (.ife-preview-wrap)
  // qui est dans le flux normal de la page. Ainsi, quand la page scrolle, le wrap
  // scrolle avec elle et les visuels (positionnés absolute par rapport au wrap)
  // suivent automatiquement, restant ancrés aux rows.
  function showDropOverlayFromZones(zones) {
    var wrap = iframe.parentNode;
    if (!wrap) return;
    // Le wrap doit être en position:relative pour servir d'ancrage absolute aux enfants.
    // Le CSS le fait déjà mais on s'en assure.
    if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';

    var dropContainer = document.createElement('div');
    dropContainer.className = 'ife-drop-overlay';
    dropContainer.style.cssText = 'position:absolute;inset:0;z-index:9000;pointer-events:none;';
    wrap.appendChild(dropContainer);
    if (!zones || !zones.length) return;

    var wrapRect = wrap.getBoundingClientRect();
    var iframeRect = iframe.getBoundingClientRect();
    // Bornes de l'iframe en coords LOCALES au wrap (pour le clamping)
    var ifrLocalTop    = iframeRect.top - wrapRect.top;
    var ifrLocalBottom = iframeRect.bottom - wrapRect.top;

    zones.forEach(function(z, idx) {
      var vt = (z.visTop    !== undefined) ? z.visTop    : z.top;
      var vb = (z.visBottom !== undefined) ? z.visBottom : z.bottom;
      // viewport → coords LOCALES au wrap
      var localTop    = vt - wrapRect.top;
      var localBottom = vb - wrapRect.top;
      var localLeft   = z.left - wrapRect.left;
      // Skip si entièrement hors de l'iframe
      if (localBottom < ifrLocalTop || localTop > ifrLocalBottom) return;
      localTop    = Math.max(localTop, ifrLocalTop);
      localBottom = Math.min(localBottom, ifrLocalBottom);
      if (localBottom <= localTop) return;
      var el = document.createElement('div');
      el.dataset.zoneIdx = idx;
      el.style.position = 'absolute';
      el.style.left   = localLeft + 'px';
      el.style.top    = localTop + 'px';
      el.style.width  = (z.right - z.left) + 'px';
      el.style.height = (localBottom - localTop) + 'px';
      if (z.kind === 'as-col') {
        el.className = 'ife-drop-slot';
        el.innerHTML = '<span>Insérer ici</span>';
      } else if (z.kind === 'new-row') {
        el.className = 'ife-drop-band';
        var txt = z.position === 'below' ? 'Nouvelle ligne en-dessous' : 'Nouvelle ligne au-dessus';
        el.innerHTML = '<span>' + txt + '</span>';
      }
      dropContainer.appendChild(el);
    });
  }

  function clearDropOverlay() {
    document.querySelectorAll('.ife-drop-overlay').forEach(function(el) { el.remove(); });
  }

  // Fusionne la row dragguée dans une row cible à la position insertAt (0..n)
  function mergeIntoRowAt(draggedRowId, targetRowId, insertAt) {
    var draggedIdx = layoutData.findIndex(function(r) { return r.id === draggedRowId; });
    var targetIdx  = layoutData.findIndex(function(r) { return r.id === targetRowId; });
    if (draggedIdx < 0 || targetIdx < 0) return;
    var dragged = layoutData[draggedIdx];
    var target  = layoutData[targetIdx];
    if ((target.columns.length + dragged.columns.length) > 3) {
      alert('Impossible : maximum 3 colonnes par ligne.');
      saveLayoutAndReload(); // re-render pour annuler le DOM Sortable
      return;
    }
    // Insère les colonnes de dragged dans target à la position insertAt
    target.columns.splice.apply(target.columns, [insertAt, 0].concat(dragged.columns));
    var nbCols = target.columns.length;
    var w = Math.floor(12 / nbCols);
    var rem = 12 - w * nbCols;
    target.columns.forEach(function(c, i) { c.width = w + (i < rem ? 1 : 0); });
    layoutData.splice(draggedIdx, 1);
    saveLayoutAndReload();
  }

  // ── Menu contextuel (clic-droit dans l'iframe) ──
  // Affiche un menu flottant au-dessus de l'iframe avec les actions disponibles selon
  // le contexte (col/row, custom/prédéfini). Coords iframe → parent via getBoundingClientRect.
  var ctxMenu = null;
  function closeContextMenu() {
    if (ctxMenu) { ctxMenu.remove(); ctxMenu = null; }
    document.removeEventListener('click',   ctxOutsideClick, true);
    document.removeEventListener('keydown', ctxKeyHandler,   true);
  }
  function ctxKeyHandler(e) { if (e.key === 'Escape') closeContextMenu(); }
  function ctxOutsideClick(e) {
    // Ne ferme PAS si le clic est dans le menu (sinon la closeContextMenu serait appelée
    // avant le handler du bouton et le click serait perdu).
    if (ctxMenu && ctxMenu.contains(e.target)) return;
    closeContextMenu();
  }

  function showEditorContextMenu(data) {
    closeContextMenu();
    var rect = iframe.getBoundingClientRect();
    var x = rect.left + (data.x || 0);
    var y = rect.top + (data.y || 0);

    // Items selon contexte
    var items = [];
    // ── Élément hero éditable : options de mode d'ancrage (axis) pour stabiliser
    // la position visuelle malgré les changements de taille de .demo-card. La coche
    // ✓ devant l'item indique le mode actif pour le device courant.
    if (data.heroField) {
      var cm = data.heroMode || 'free';
      var deviceTxt = data.heroDevice === 'mobile' ? ' (mobile)' : ' (desktop)';
      var modeItems = [
        { label: 'Libre (déplaçable)' + deviceTxt,         icon: 'bi-arrows-move',          mode: 'free' },
        { label: 'Centré horizontal' + deviceTxt,           icon: 'bi-align-center',         mode: 'centerX' },
        { label: 'Centré vertical' + deviceTxt,             icon: 'bi-align-middle',         mode: 'centerY' },
        { label: 'Centré (2D, immobile)' + deviceTxt,       icon: 'bi-bullseye',             mode: 'center' }
      ];
      modeItems.forEach(function(mi){
        items.push({
          label: (cm === mi.mode ? '✓ ' : '   ') + mi.label,
          icon: mi.icon,
          act: 'hero-mode',
          mode: mi.mode
        });
      });
      if (data.sectionType || data.rowId) items.push({ separator: true });
    }
    if (data.sectionType === 'custom' && data.sectionId) {
      items.push({ label: 'Modifier le texte',           icon: 'bi-pencil',        act: 'edit'      });
      items.push({ label: 'Extraire en ligne séparée',  icon: 'bi-box-arrow-up-right', act: 'extract' });
      items.push({ separator: true });
      items.push({ label: 'Supprimer ce bloc',          icon: 'bi-trash3',        act: 'delete', danger: true });
    } else if (data.sectionType) {
      items.push({ label: 'Masquer / Afficher',         icon: 'bi-eye',           act: 'toggle-vis' });
      items.push({ label: 'Extraire en ligne séparée',  icon: 'bi-box-arrow-up-right', act: 'extract' });
    }
    if (data.rowId) {
      if (items.length) items.push({ separator: true });
      items.push({ label: 'Ajouter une colonne texte',     icon: 'bi-text-paragraph', act: 'add-col-text' });
      items.push({ label: 'Ajouter une colonne HTML/CSS/JS', icon: 'bi-code-slash',    act: 'add-col-html' });
      items.push({ label: 'Sélectionner la ligne',         icon: 'bi-cursor',         act: 'select-row' });
    }
    if (!items.length) return;

    // Build menu DOM
    ctxMenu = document.createElement('div');
    ctxMenu.className = 'ife-ctx-menu';
    items.forEach(function(it) {
      if (it.separator) {
        var sep = document.createElement('div');
        sep.className = 'ife-ctx-sep';
        ctxMenu.appendChild(sep);
        return;
      }
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ife-ctx-item' + (it.danger ? ' is-danger' : '');
      btn.innerHTML = '<i class="bi ' + it.icon + '"></i><span>' + it.label + '</span>';
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        closeContextMenu();
        runCtxAction(it.act, data, it);
      });
      ctxMenu.appendChild(btn);
    });

    document.body.appendChild(ctxMenu);

    // Position en évitant les bords (menu de ~220px de large)
    var menuW = ctxMenu.offsetWidth || 220;
    var menuH = ctxMenu.offsetHeight || 200;
    if (x + menuW > window.innerWidth)  x = window.innerWidth  - menuW - 10;
    if (y + menuH > window.innerHeight) y = window.innerHeight - menuH - 10;
    ctxMenu.style.left = x + 'px';
    ctxMenu.style.top  = y + 'px';

    // Close on outside click / Escape (le listener vérifie que le clic est hors du menu)
    setTimeout(function() {
      document.addEventListener('click',   ctxOutsideClick, true);
      document.addEventListener('keydown', ctxKeyHandler,   true);
    }, 50);
  }

  function runCtxAction(act, data, item) {
    if (act === 'edit' && data.sectionId) {
      openCustomBlockEditorForSection(data.sectionId);
    } else if (act === 'extract' && data.rowId && data.colIdx !== null) {
      extractColumnAsRow(data.rowId, data.colIdx);
    } else if (act === 'delete' && data.rowId && data.colIdx !== null) {
      deleteColumn(data.rowId, data.colIdx);
    } else if (act === 'toggle-vis' && data.rowId && data.colIdx !== null) {
      toggleHideColumn(data.rowId, data.colIdx);
    } else if (act === 'add-col-text' && data.rowId) {
      addColumnToRow(data.rowId, 'text');
    } else if (act === 'add-col-html' && data.rowId) {
      addColumnToRow(data.rowId, 'html');
    } else if (act === 'select-row' && data.rowId) {
      selectRow(data.rowId);
    } else if (act === 'hero-mode' && data.heroField && item && item.mode) {
      // Change le mode d'ancrage de l'élément hero pour le device courant.
      // 1) Demande à l'iframe d'appliquer immédiatement (dataset + reflow visuel)
      // 2) L'iframe persistera via le flux save_accueil_geometry standard
      if (iframe.contentWindow) {
        iframe.contentWindow.postMessage({
          type: 'parent-set-axis-mode',
          field: data.heroField,
          device: data.heroDevice || 'desktop',
          mode: item.mode
        }, '*');
      }
    }
  }

  // Ouvre le modal TinyMCE pré-rempli avec le contenu de la colonne (custom) qui a
  // l'ID `sectionId`. Utilisé par le clic direct sur un bloc texte dans l'iframe ET
  // par les boutons "✎" des cellules de la grille sidebar.
  function openCustomBlockEditorForSection(sectionId) {
    var foundRow = null, foundCol = null;
    layoutData.forEach(function(row) {
      row.columns.forEach(function(c) {
        if (c.section.type === 'custom' && c.section.id === sectionId) {
          foundRow = row; foundCol = c;
        }
      });
    });
    if (!foundCol) return;
    selectedRowId = foundRow.id;
    pendingInsertPos = -1;
    // Route selon le kind du bloc : text → TinyMCE WYSIWYG, html → CodeMirror + preview
    var kind = foundCol.section.kind || 'text';
    if (kind === 'html') {
      openHtmlBlockEditor(
        foundCol.section.id,
        foundCol.section.title || '',
        foundCol.section.content || '',
        foundCol.section.align  || 'left',
        foundCol.section.valign || 'top'
      );
      return;
    }
    var modalEl = document.getElementById('customBlockModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl, { focus: false });
    document.getElementById('customBlockEditId').value = foundCol.section.id || '';
    document.getElementById('customBlockTitle').value = foundCol.section.title || '';
    bsModal.show();
    modalEl.addEventListener('shown.bs.modal', function once() {
      modalEl.removeEventListener('shown.bs.modal', once);
      if (window.AccueilEditor.initTinyMce) {
        window.AccueilEditor.initTinyMce('#customBlockEditor', foundCol.section.content || '');
      }
    });
  }

  // ── Éditeur de bloc HTML/CSS/JS (CodeMirror + preview live) ──
  var htmlBlockCM = null;          // instance CodeMirror (lazy init)
  var htmlBlockPreviewTimer = null;

  function openHtmlBlockEditor(sectionId, title, content, align, valign) {
    var modalEl = document.getElementById('htmlBlockModal');
    if (!modalEl) return;
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl, { focus: false });
    document.getElementById('htmlBlockEditId').value = sectionId || '';
    document.getElementById('htmlBlockTitle').value = title || '';
    document.getElementById('htmlBlockModalTitle').textContent = sectionId ? 'Modifier le bloc HTML / CSS / JS' : 'Nouveau bloc HTML / CSS / JS';
    // Sync boutons alignement
    var curAlign = align || 'left';
    var curValign = valign || 'top';
    document.querySelectorAll('#htmlBlockAlignGroup [data-align]').forEach(function(b) {
      b.classList.toggle('active', b.dataset.align === curAlign);
    });
    document.querySelectorAll('#htmlBlockValignGroup [data-valign]').forEach(function(b) {
      b.classList.toggle('active', b.dataset.valign === curValign);
    });
    modalEl.dataset.curAlign  = curAlign;
    modalEl.dataset.curValign = curValign;
    bsModal.show();
    modalEl.addEventListener('shown.bs.modal', function once() {
      modalEl.removeEventListener('shown.bs.modal', once);
      // Init CodeMirror une fois la modal visible (pour que le container ait sa taille)
      var codeContainer = document.getElementById('htmlBlockCode');
      if (htmlBlockCM) {
        // Réutilise l'instance, juste set la valeur
        htmlBlockCM.setValue(content || '');
        setTimeout(function() { htmlBlockCM.refresh(); updateHtmlBlockPreview(); }, 50);
      } else {
        if (typeof CodeMirror === 'undefined') {
          codeContainer.innerHTML = '<div class="p-3 text-danger">CodeMirror non chargé.</div>';
          return;
        }
        codeContainer.innerHTML = '';
        htmlBlockCM = CodeMirror(codeContainer, {
          value: content || '',
          mode: 'htmlmixed',
          theme: 'eclipse',
          lineNumbers: true,
          lineWrapping: true,
          indentUnit: 2,
          tabSize: 2,
          autoCloseTags: true,
          matchBrackets: true
        });
        htmlBlockCM.on('change', function() {
          clearTimeout(htmlBlockPreviewTimer);
          htmlBlockPreviewTimer = setTimeout(updateHtmlBlockPreview, 300);
        });
        setTimeout(function() { htmlBlockCM.refresh(); updateHtmlBlockPreview(); htmlBlockCM.focus(); }, 50);
      }
    });
  }

  function updateHtmlBlockPreview() {
    var iframeEl = document.getElementById('htmlBlockPreview');
    if (!iframeEl || !htmlBlockCM) return;
    var code = htmlBlockCM.getValue();
    var modalEl = document.getElementById('htmlBlockModal');
    var align  = (modalEl && modalEl.dataset.curAlign)  || 'left';
    var valign = (modalEl && modalEl.dataset.curValign) || 'top';
    var justMap = { left: 'flex-start', center: 'center', right: 'flex-end' };
    var itemMap = { top:  'flex-start', center: 'center', bottom: 'flex-end' };
    var wrapStart = '', wrapEnd = '';
    if (align !== 'left' || valign !== 'top') {
      wrapStart = '<div style="display:flex;justify-content:' + justMap[align] + ';align-items:' + itemMap[valign] + ';width:100%;height:100%;min-height:200px;">';
      wrapEnd = '</div>';
    }
    var doc = iframeEl.contentDocument || iframeEl.contentWindow.document;
    doc.open();
    doc.write('<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;padding:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;height:100%;}body{padding:16px;box-sizing:border-box;}</style></head><body>' + wrapStart + code + wrapEnd + '</body></html>');
    doc.close();
  }

  // Bouton "Rafraîchir la preview"
  document.addEventListener('click', function(e) {
    if (e.target.closest('#htmlBlockReloadPreview')) updateHtmlBlockPreview();
  });

  // Boutons d'alignement HORIZONTAL dans la modal HTML (live preview update)
  document.querySelectorAll('#htmlBlockAlignGroup [data-align]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var modalEl = document.getElementById('htmlBlockModal');
      modalEl.dataset.curAlign = btn.dataset.align;
      document.querySelectorAll('#htmlBlockAlignGroup [data-align]').forEach(function(b) {
        b.classList.toggle('active', b === btn);
      });
      updateHtmlBlockPreview();
    });
  });
  // Boutons d'alignement VERTICAL
  document.querySelectorAll('#htmlBlockValignGroup [data-valign]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var modalEl = document.getElementById('htmlBlockModal');
      modalEl.dataset.curValign = btn.dataset.valign;
      document.querySelectorAll('#htmlBlockValignGroup [data-valign]').forEach(function(b) {
        b.classList.toggle('active', b === btn);
      });
      updateHtmlBlockPreview();
    });
  });

  // Bouton "Enregistrer" du bloc HTML
  var btnSaveHtml = document.getElementById('btnSaveHtmlBlock');
  if (btnSaveHtml) {
    btnSaveHtml.addEventListener('click', function() {
      if (!htmlBlockCM) return;
      var id = document.getElementById('htmlBlockEditId').value.trim();
      var title = document.getElementById('htmlBlockTitle').value.trim();
      var content = htmlBlockCM.getValue();
      var modalEl = document.getElementById('htmlBlockModal');
      var align  = modalEl.dataset.curAlign  || 'left';
      var valign = modalEl.dataset.curValign || 'top';
      if (id) {
        // Update existing
        layoutData.forEach(function(row) {
          row.columns.forEach(function(col) {
            if (col.section.type === 'custom' && col.section.id === id) {
              col.section.title = title;
              col.section.content = content;
              col.section.kind = 'html';
              col.section.align  = align;
              col.section.valign = valign;
            }
          });
        });
      } else {
        // Create new — insert at pendingInsertPos ou à la fin
        var pos = pendingInsertPos >= 0 ? pendingInsertPos : layoutData.length;
        pendingInsertPos = -1;
        layoutData.splice(pos, 0, {
          id: 'row_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
          columns: [{ width: 12, section: {
            type: 'custom',
            kind: 'html',
            id: 'custom_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
            title: title, content: content, visible: true,
            align: align, valign: valign
          }}]
        });
      }
      var bsModal = bootstrap.Modal.getInstance(modalEl);
      if (bsModal) bsModal.hide();
      saveLayoutAndReload();
    });
  }

  // Bouton "Ajouter un bloc HTML" dans la sidebar
  var btnAddHtml = document.getElementById('btnAddHtmlBlock');
  if (btnAddHtml) {
    btnAddHtml.addEventListener('click', function() {
      if (pendingInsertPos < 0) pendingInsertPos = layoutData.length;
      openHtmlBlockEditor('', '', '', 'left', 'top');
    });
  }

  // Supprime UNE colonne d'une ligne. Si la colonne est prédéfinie, on refuse
  // (seulement masquable). Si c'est la dernière colonne, on supprime la ligne entière.
  function deleteColumn(rowId, colIdx) {
    var row = layoutData.find(function(r) { return r.id === rowId; });
    if (!row || !row.columns[colIdx]) return;
    var col = row.columns[colIdx];
    if (col.section.type !== 'custom') {
      alert('Cette section pré-définie ne peut pas être supprimée. Utilisez le bouton « masquer » (œil) à la place.');
      return;
    }
    if (!confirm('Supprimer ce bloc ?')) return;
    row.columns.splice(colIdx, 1);
    if (row.columns.length === 0) {
      // Plus aucune colonne : supprime la ligne entière
      layoutData = layoutData.filter(function(r) { return r.id !== rowId; });
      selectedRowId = null;
    } else {
      // Redistribue les largeurs équitablement
      var nb = row.columns.length;
      var w = Math.floor(12 / nb);
      var rem = 12 - w * nb;
      row.columns.forEach(function(c, i) { c.width = w + (i < rem ? 1 : 0); });
    }
    saveLayoutAndReload();
  }

  // Extrait UNE colonne et la place dans une nouvelle ligne juste en-dessous de la
  // ligne d'origine. Les colonnes restantes voient leurs largeurs redistribuées.
  // Si la ligne d'origine n'avait qu'une colonne, on ne fait rien (déjà en ligne seule).
  function extractColumnAsRow(rowId, colIdx) {
    var idx = layoutData.findIndex(function(r) { return r.id === rowId; });
    if (idx < 0) return;
    var row = layoutData[idx];
    if (!row || !row.columns[colIdx]) return;
    if (row.columns.length <= 1) {
      alert('Cette colonne est déjà seule dans sa ligne.');
      return;
    }
    var extracted = row.columns.splice(colIdx, 1)[0];
    extracted.width = 12;
    // Nouvelle ligne avec cette colonne, insérée juste après la ligne d'origine
    var newRow = {
      id: 'row_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
      align: row.align || 'left',
      columns: [extracted]
    };
    layoutData.splice(idx + 1, 0, newRow);
    // Redistribue les largeurs restantes pour que la ligne d'origine totalise 12
    var nb = row.columns.length;
    var w = Math.floor(12 / nb);
    var rem = 12 - w * nb;
    row.columns.forEach(function(c, i) { c.width = w + (i < rem ? 1 : 0); });
    saveLayoutAndReload();
  }

  // Bascule la visibilité d'UNE colonne (sans toucher aux autres dans la même ligne).
  function toggleHideColumn(rowId, colIdx) {
    var row = layoutData.find(function(r) { return r.id === rowId; });
    if (!row || !row.columns[colIdx]) return;
    var c = row.columns[colIdx];
    c.section.visible = !c.section.visible;
    saveLayoutAndReload();
  }

  // Ajoute une colonne VIDE (texte simple) à la ligne. L'utilisateur pourra ensuite
  // y glisser une autre section ou éditer son contenu texte.
  // kind par défaut = 'text' (rétrocompatible). 'html' = bloc code brut.
  function addColumnToRow(rowId, kind) {
    var row = layoutData.find(function(r) { return r.id === rowId; });
    if (!row) return;
    if (row.columns.length >= 3) {
      alert('Maximum 3 colonnes par ligne.');
      return;
    }
    kind = (kind === 'html') ? 'html' : 'text';
    var defaultContent = (kind === 'html')
      ? '<p style="text-align:center;color:#6366f1;font-family:monospace;padding:20px;margin:0;border:2px dashed #c7d2fe;border-radius:8px;">&lt;/&gt; Cliquer pour modifier ce code…</p>'
      : '<p>Cliquer pour modifier ce texte…</p>';
    var defaultTitle = (kind === 'html') ? 'Nouveau bloc HTML' : 'Nouveau bloc';
    var emptyCol = {
      width: 6,
      section: {
        type: 'custom',
        kind: kind,
        id: 'custom_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
        title: defaultTitle,
        content: defaultContent,
        visible: true
      }
    };
    row.columns.push(emptyCol);
    // Redistribue les largeurs équitablement
    var nb = row.columns.length;
    var w = Math.floor(12 / nb);
    var rem = 12 - w * nb;
    row.columns.forEach(function(c, i) { c.width = w + (i < rem ? 1 : 0); });
    saveLayoutAndReload();
  }

  // Sépare une row multi-colonnes en plusieurs lignes à 1 colonne
  function unmergeRow(rowId) {
    var idx = layoutData.findIndex(function(r) { return r.id === rowId; });
    if (idx < 0) return;
    var row = layoutData[idx];
    if (!row || row.columns.length <= 1) return;
    var newRows = row.columns.map(function(col, i) {
      col.width = 12;
      return { id: row.id + '-s' + i + '-' + Date.now(), columns: [col] };
    });
    // Le 1er garde l'id d'origine pour préserver la sélection
    newRows[0].id = row.id;
    layoutData.splice.apply(layoutData, [idx, 1].concat(newRows));
    saveLayoutAndReload();
  }

  function addPlusMarker(insertAt, top) {
    var marker = document.createElement('div');
    marker.className = 'ife-add-marker';
    marker.style.top = (top - 14) + 'px';
    marker.dataset.insertAt = insertAt;
    marker.innerHTML = '<button title="Ajouter une ligne ici"><i class="bi bi-plus"></i></button>';
    marker.querySelector('button').addEventListener('click', function() {
      switchSidebarTab('add');
      pendingInsertPos = insertAt;
      setSidebarHint('Cliquez sur un bloc à ajouter dans le menu de gauche.');
    });
    overlay.appendChild(marker);
  }

  function setSidebarHint(text) {
    var emptyEl = document.getElementById('ifeSbEmpty');
    if (emptyEl) emptyEl.innerHTML = '<i class="bi bi-cursor" style="font-size:2rem;color:#cbd5e1;display:block;margin-bottom:8px;"></i>' + text;
    document.getElementById('ifeSbProps').style.display = 'none';
    if (emptyEl) emptyEl.style.display = '';
  }

  function selectRow(rowId) {
    selectedRowId = rowId;
    overlay.querySelectorAll('.ife-row-overlay').forEach(function(el) {
      el.classList.toggle('is-selected', el.dataset.rowId === rowId);
    });
    var row = layoutData.find(function(r) { return r.id === rowId; });
    if (!row) return;
    var firstCol = row.columns[0];
    var sectionType = firstCol ? firstCol.section.type : 'unknown';
    var labels = {
      reg_bar: "Compteur d'inscrits",
      partners: 'Bandeau Partenaires',
      timeline: 'Historique (Timeline)',
      news: 'Dernières actualités',
      start_point: 'Retrouver le départ',
      newsletter: 'Rester informé (newsletter)',
      custom: 'Bloc personnalisé'
    };
    document.getElementById('ifeSbEmpty').style.display = 'none';
    document.getElementById('ifeSbProps').style.display = '';
    document.getElementById('ifeSbTitle').textContent = labels[sectionType] || sectionType;
    document.getElementById('ifeSbVisRow').style.display = '';
    document.getElementById('ifeSbBtnDelete').style.display = '';
    document.getElementById('ifeSbSizeRow').style.display = 'none';
    // Ré-affiche l'espacement de ligne (caché par selectEditableElement pour les éléments du Hero)
    var spacingRowEl = document.getElementById('ifeSbSpacingRow');
    if (spacingRowEl) spacingRowEl.style.display = '';
    // Nettoie tous les widgets dynamiques laissés par une précédente sélection
    // (reset, textarea inline, alignement, picker, liste éditables) → plus de résidu.
    clearSidebarDynamicWidgets();

    // Si row multi-col → affiche tableau, cache slider simple
    var isMulti = row.columns.length > 1;
    document.getElementById('ifeSbWidthRow').style.display = isMulti ? 'none' : '';
    document.getElementById('ifeSbGridRow').style.display = isMulti ? '' : 'none';
    if (isMulti) renderRowGrid(row);

    document.getElementById('ifeSbWidth').value = firstCol ? firstCol.width : 12;
    document.getElementById('ifeSbVis').checked = firstCol ? !!firstCol.section.visible : true;

    // Le bouton Supprimer n'est dispo que pour les blocs custom (pré-définis = juste masquables via la checkbox)
    var btnDel = document.getElementById('ifeSbBtnDelete');
    if (sectionType === 'custom') {
      btnDel.style.display = '';
      btnDel.disabled = false;
      btnDel.title = 'Supprimer ce bloc personnalisé';
    } else {
      btnDel.style.display = 'none';
    }

    document.getElementById('ifeSbBtnEdit').style.display = (sectionType === 'custom') ? '' : 'none';
    document.getElementById('ifeSbBtnEdit').onclick = function() {
      if (sectionType !== 'custom') return;
      pendingInsertPos = -1;
      var bsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('customBlockModal'), { focus: false });
      document.getElementById('customBlockEditId').value = firstCol.section.id || '';
      document.getElementById('customBlockTitle').value = firstCol.section.title || '';
      bsModal.show();
      var modalEl = document.getElementById('customBlockModal');
      modalEl.addEventListener('shown.bs.modal', function once() {
        modalEl.removeEventListener('shown.bs.modal', once);
        if (window.AccueilEditor.initTinyMce) {
          window.AccueilEditor.initTinyMce('#customBlockEditor', firstCol.section.content || '');
        }
      });
    };
    // Liste des éléments éditables dans cette ligne (textes, images, vidéo, timer…)
    // → boutons d'accès rapide depuis la sidebar en plus du double-clic dans l'iframe
    renderEditablesList(rowId);
    // Options spécifiques à la section (ex: news.card_style toggle)
    renderSectionOptions(row);
    // Sync inputs d'espacement — défauts : 5rem au-dessus (= la marge entre rows par
    // défaut), 0rem en-dessous. La somme A.bottom + B.top = écart visible entre A et B.
    var DEFAULT_TOP = 5;
    var DEFAULT_BOTTOM = 0;
    var spTop = document.getElementById('ifeSbSpaceTop');
    var spBot = document.getElementById('ifeSbSpaceBottom');
    if (spTop) spTop.value = (typeof row.spaceTop    === 'number') ? row.spaceTop    : DEFAULT_TOP;
    if (spBot) spBot.value = (typeof row.spaceBottom === 'number') ? row.spaceBottom : DEFAULT_BOTTOM;
    switchSidebarTab('props');
  }

  // Affiche les options spécifiques à un type de section (ex: pour news, toggle "Avec image")
  // Chaque type de section a son propre bloc → plusieurs peuvent coexister dans une
  // même ligne multi-colonnes (ex: compteur d'inscrits + actualités côte à côte).
  function renderSectionOptions(row) {
    var existing = document.getElementById('ifeSbSectionOptions');
    if (existing) existing.remove();
    // Detect si une colonne contient un type de section avec options
    var hasNews = row.columns.some(function(c) { return c.section.type === 'news'; });
    var hasStartPoint = row.columns.some(function(c) { return c.section.type === 'start_point'; });
    var hasRegBar = row.columns.some(function(c) { return c.section.type === 'reg_bar'; });
    if (!hasNews && !hasStartPoint && !hasRegBar) return;
    var styles = window.AccueilEditor.accueilStyles || {};
    var panel = document.createElement('div');
    panel.id = 'ifeSbSectionOptions';
    panel.className = 'ife-sb-section-options';

    if (hasRegBar) {
      var rbBlock = document.createElement('div');
      var currentRegBar = styles['reg_bar.display_style'] || 'counter';
      var currentRbSource = styles['reg_bar.counter_source'] || 'live';
      rbBlock.innerHTML = ''
        + '<div class="ife-sb-section-label">Options "Compteur d\'inscrits"</div>'
        + '<div class="ife-sb-toggle-row">'
        +   '<label>Version de la card</label>'
        +   '<div class="btn-group-vertical btn-group-sm w-100" id="ifeSbRegBarStyle">'
        +     '<button type="button" class="btn btn-outline-secondary text-start' + (currentRegBar === 'counter' ? ' active' : '') + '" data-regbar-style="counter" title="Affiche le nombre d\'inscrits (version historique)"><i class="bi bi-123"></i> Compteur d\'inscrits</button>'
        +     '<button type="button" class="btn btn-outline-secondary text-start' + (currentRegBar === 'urgency' ? ' active' : '') + '" data-regbar-style="urgency" title="Message d\'urgence : inscrivez-vous vite, les X premiers inscrits…"><i class="bi bi-lightning-charge"></i> Urgence (offre limitée)</button>'
        +     '<button type="button" class="btn btn-outline-secondary text-start' + (currentRegBar === 'motivation' ? ' active' : '') + '" data-regbar-style="motivation" title="Message solidaire, sans afficher le nombre d\'inscrits"><i class="bi bi-heart"></i> Solidaire (sans compteur)</button>'
        +   '</div>'
        + '</div>'
        + '<div class="ife-sb-toggle-row" id="ifeSbRegBarSourceRow"' + (currentRegBar === 'counter' ? '' : ' style="display:none"') + '>'
        +   '<label>Nombre affiché (version compteur)</label>'
        +   '<div class="btn-group btn-group-sm" id="ifeSbRegBarSource">'
        +     '<button type="button" class="btn btn-outline-secondary' + (currentRbSource === 'live' ? ' active' : '') + '" data-regbar-source="live" title="Inscriptions de cette année, en temps réel"><i class="bi bi-broadcast"></i> En direct</button>'
        +     '<button type="button" class="btn btn-outline-secondary' + (currentRbSource === 'archive' ? ' active' : '') + '" data-regbar-source="archive" title="Inscriptions de la dernière année archivée (bouton Archiver du dashboard)"><i class="bi bi-archive"></i> Dernière archive</button>'
        +   '</div>'
        +   '<small class="text-muted">"Dernière archive" = total de la dernière année archivée. Pensez à adapter le libellé (ex : "Inscrits en 2025"). S\'il n\'existe aucune archive, le compteur en direct est affiché.</small>'
        + '</div>'
        + '<small class="text-muted">Le titre et le texte des versions Urgence / Solidaire se modifient en double-cliquant dessus dans l\'aperçu. Astuces dans les textes : {count} = inscrits en direct, {count_last} = inscrits de la dernière archive, {count_2025} = inscrits d\'une année précise.</small>';
      rbBlock.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-regbar-style]');
        if (btn) {
          var newRegBar = btn.dataset.regbarStyle;
          if (newRegBar === (styles['reg_bar.display_style'] || 'counter')) return;
          styles['reg_bar.display_style'] = newRegBar;
          window.AccueilEditor.accueilStyles = styles;
          rbBlock.querySelectorAll('[data-regbar-style]').forEach(function(b) {
            b.classList.toggle('active', b === btn);
          });
          var srcRow = rbBlock.querySelector('#ifeSbRegBarSourceRow');
          if (srcRow) srcRow.style.display = (newRegBar === 'counter') ? '' : 'none';
          saveStyleKey('reg_bar.display_style', newRegBar);
          setTimeout(function() { reloadIframe(); }, 200);
          return;
        }
        var srcBtn = e.target.closest('[data-regbar-source]');
        if (srcBtn) {
          var newSource = srcBtn.dataset.regbarSource;
          if (newSource === (styles['reg_bar.counter_source'] || 'live')) return;
          styles['reg_bar.counter_source'] = newSource;
          window.AccueilEditor.accueilStyles = styles;
          rbBlock.querySelectorAll('[data-regbar-source]').forEach(function(b) {
            b.classList.toggle('active', b === srcBtn);
          });
          saveStyleKey('reg_bar.counter_source', newSource);
          setTimeout(function() { reloadIframe(); }, 200);
        }
      });
      panel.appendChild(rbBlock);
    }

    if (hasNews) {
      var newsBlock = document.createElement('div');
      var currentStyle = styles['news.card_style'] || 'simple';
      newsBlock.innerHTML = ''
        + '<div class="ife-sb-section-label">Options "Dernières actualités"</div>'
        + '<div class="ife-sb-toggle-row">'
        +   '<label>Affichage des cards</label>'
        +   '<div class="btn-group btn-group-sm" id="ifeSbNewsCardStyle">'
        +     '<button type="button" class="btn btn-outline-secondary' + (currentStyle === 'simple' ? ' active' : '') + '" data-card-style="simple" title="Cards minimalistes côte à côte, sans image"><i class="bi bi-list-task"></i> Sans image</button>'
        +     '<button type="button" class="btn btn-outline-secondary' + (currentStyle === 'with-image' ? ' active' : '') + '" data-card-style="with-image" title="Cards avec image en haut (style timeline)"><i class="bi bi-image"></i> Image en haut</button>'
        +     '<button type="button" class="btn btn-outline-secondary' + (currentStyle === 'with-image-side' ? ' active' : '') + '" data-card-style="with-image-side" title="Cards avec image à gauche (style page actualités)"><i class="bi bi-layout-text-window-reverse"></i> Image à gauche</button>'
        +   '</div>'
        + '</div>';
      newsBlock.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-card-style]');
        if (!btn) return;
        var newStyle = btn.dataset.cardStyle;
        if (newStyle === (styles['news.card_style'] || 'simple')) return;
        styles['news.card_style'] = newStyle;
        window.AccueilEditor.accueilStyles = styles;
        newsBlock.querySelectorAll('[data-card-style]').forEach(function(b) {
          b.classList.toggle('active', b === btn);
        });
        saveStyleKey('news.card_style', newStyle);
        setTimeout(function() { reloadIframe(); }, 200);
      });
      panel.appendChild(newsBlock);
    }

    if (hasStartPoint) {
      var spBlock = document.createElement('div');
      buildStartPointPanel(spBlock, styles);
      panel.appendChild(spBlock);
    }
    var propsEl = document.getElementById('ifeSbProps');
    if (propsEl) propsEl.appendChild(panel);
  }

  // Panneau "Retrouver le départ" : choix adresse OU coordonnées (jamais les deux)
  // + dimensions de la carte (hauteur/largeur), séparées desktop / mobile.
  function buildStartPointPanel(panel, styles) {
    var sp = window.AccueilEditor.startPoint || { address: '', coords: '' };
    // Mode courant : coordonnées si renseignées, sinon adresse (défaut).
    var mode = (sp.coords && sp.coords.trim() !== '') ? 'coords' : 'address';
    var device = getCurrentEditorDevice();
    var devLabel = (device === 'mobile') ? 'MOBILE' : 'DESKTOP';

    // Clés de dimensions device-aware
    var hKey = (device === 'mobile') ? 'start_point_map_height_mobile' : 'start_point_map_height';
    var wKey = (device === 'mobile') ? 'start_point_map_width_mobile'  : 'start_point_map_width';
    // Valeurs par défaut alignées sur le CSS (.start-point-map : 380px desktop /
    // 320px mobile, largeur 100%).
    var defH = (device === 'mobile') ? 320 : 380;
    var curH = parseInt(styles[hKey], 10);
    if (isNaN(curH) || curH < 180 || curH > 900) curH = defH;
    var curW = parseInt(styles[wKey], 10);
    if (isNaN(curW) || curW < 40 || curW > 100) curW = 100;

    panel.innerHTML = ''
      + '<div class="ife-sb-section-label">Point de départ</div>'
      + '<div class="ife-sb-toggle-row">'
      +   '<label>Type de point</label>'
      +   '<div class="btn-group btn-group-sm" id="ifeSpMode">'
      +     '<button type="button" class="btn btn-outline-secondary' + (mode === 'address' ? ' active' : '') + '" data-sp-mode="address"><i class="bi bi-pin-map"></i> Adresse</button>'
      +     '<button type="button" class="btn btn-outline-secondary' + (mode === 'coords' ? ' active' : '') + '" data-sp-mode="coords"><i class="bi bi-compass"></i> Coordonnées</button>'
      +   '</div>'
      + '</div>'
      + '<div style="margin-top:8px;position:relative">'
      +   '<input type="text" id="ifeSpInput" class="form-control form-control-sm" autocomplete="off">'
      +   '<div id="ifeSpSuggest" class="ife-sp-suggest" style="display:none"></div>'
      +   '<small id="ifeSpHint" class="text-muted" style="display:block;margin-top:3px"></small>'
      +   '<small id="ifeSpErr" class="text-danger" style="display:none;margin-top:3px"></small>'
      + '</div>'
      + '<hr style="margin:12px 0">'
      + '<div class="ife-sb-section-label">Dimensions de la carte — vue ' + devLabel + '</div>'
      + '<label class="mb-0">Hauteur <span id="ifeSpHVal" style="color:#64748b">' + curH + ' px</span></label>'
      + '<input type="range" min="180" max="900" step="10" id="ifeSpH" value="' + curH + '" class="form-range">'
      + '<label class="mb-0">Largeur <span id="ifeSpWVal" style="color:#64748b">' + curW + ' %</span></label>'
      + '<input type="range" min="40" max="100" step="1" id="ifeSpW" value="' + curW + '" class="form-range">'
      + '<button type="button" class="btn btn-sm btn-outline-warning mt-1" id="ifeSpReset">'
      +   '<i class="bi bi-arrow-counterclockwise me-1"></i>Restaurer les dimensions de la carte'
      + '</button>'
      + '<small class="text-muted">Ces dimensions s\'appliquent à la vue ' + devLabel + ' seulement.</small>';

    var input = panel.querySelector('#ifeSpInput');
    var hint  = panel.querySelector('#ifeSpHint');
    var errEl = panel.querySelector('#ifeSpErr');
    var suggestEl = panel.querySelector('#ifeSpSuggest');

    function hideSuggest() {
      if (suggestEl) { suggestEl.style.display = 'none'; suggestEl.innerHTML = ''; }
    }

    function applyMode(m) {
      mode = m;
      panel.querySelectorAll('[data-sp-mode]').forEach(function(b) {
        b.classList.toggle('active', b.dataset.spMode === m);
      });
      if (m === 'coords') {
        input.value = sp.coords || '';
        input.placeholder = 'ex : 49.1869,6.8983';
        hint.textContent = 'Latitude,longitude. Le point se place automatiquement sur la carte.';
      } else {
        input.value = sp.address || '';
        input.placeholder = 'ex : 12 rue des Mines, Forbach';
        hint.textContent = 'Tapez une adresse : des suggestions apparaissent automatiquement.';
      }
      errEl.style.display = 'none';
      hideSuggest();
    }
    applyMode(mode);

    panel.querySelector('#ifeSpMode').addEventListener('click', function(e) {
      var b = e.target.closest('[data-sp-mode]');
      if (b && b.dataset.spMode !== mode) applyMode(b.dataset.spMode);
    });

    // — Autocomplétion d'adresse (mode adresse uniquement) —
    // API Photon (OpenStreetMap, sans clé ni facturation, conçue pour le
    // type-ahead). Cohérent avec la carte qui utilise déjà Maps sans clé.
    var acTimer = null, acReqId = 0;
    function photonLabel(p) {
      var l1 = [p.housenumber, p.street || p.name].filter(Boolean).join(' ');
      if (!l1) l1 = p.name || '';
      var l2 = [p.postcode, p.city].filter(Boolean).join(' ');
      return [l1, l2, p.country].filter(Boolean).join(', ');
    }
    function runAutocomplete(q) {
      var reqId = ++acReqId;
      fetch('https://photon.komoot.io/api/?lang=fr&limit=5&q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (reqId !== acReqId || mode !== 'address') return; // réponse périmée
          var feats = (data && data.features) || [];
          suggestEl.innerHTML = '';
          feats.forEach(function(f) {
            var label = photonLabel(f.properties || {});
            if (!label) return;
            var item = document.createElement('div');
            item.className = 'ife-sp-suggest-item';
            item.textContent = label;
            // mousedown (et non click) : se déclenche avant le blur de l'input.
            item.addEventListener('mousedown', function(e) {
              e.preventDefault();
              input.value = label;
              hideSuggest();
              clearTimeout(spTimer);
              saveStartPoint('address', label, errEl);
            });
            suggestEl.appendChild(item);
          });
          suggestEl.style.display = suggestEl.children.length ? 'block' : 'none';
        })
        .catch(function() { hideSuggest(); });
    }

    // Sauvegarde du point (debounced) → handler save_start_point.
    var spTimer = null;
    input.addEventListener('input', function() {
      clearTimeout(spTimer);
      spTimer = setTimeout(function() { saveStartPoint(mode, input.value.trim(), errEl); }, 600);
      // Suggestions d'adresses (mode adresse, ≥ 3 caractères)
      clearTimeout(acTimer);
      if (mode !== 'address') { hideSuggest(); return; }
      var q = input.value.trim();
      if (q.length < 3) { hideSuggest(); return; }
      acTimer = setTimeout(function() { runAutocomplete(q); }, 300);
    });
    input.addEventListener('blur', function() { setTimeout(hideSuggest, 150); });

    // — Sliders dimensions de la carte —
    var hInput = panel.querySelector('#ifeSpH'), hVal = panel.querySelector('#ifeSpHVal');
    var wInput = panel.querySelector('#ifeSpW'), wVal = panel.querySelector('#ifeSpWVal');
    var hVar = (device === 'mobile') ? '--sp-map-h-mobile' : '--sp-map-h';
    var wVar = (device === 'mobile') ? '--sp-map-w-mobile' : '--sp-map-w';
    var hTimer = null, wTimer = null;
    hInput.addEventListener('input', function() {
      var v = parseInt(this.value, 10);
      hVal.textContent = v + ' px';
      setStartPointMapVar(hVar, v + 'px');
      // La hauteur de la section change → re-mesure immédiate des lignes pour que
      // les pointillés (overlays) de l'éditeur suivent la carte en direct.
      resyncOverlaysFromIframe();
      clearTimeout(hTimer);
      hTimer = setTimeout(function() { saveStyleKey(hKey, v); styles[hKey] = v; }, 400);
    });
    wInput.addEventListener('input', function() {
      var v = parseInt(this.value, 10);
      wVal.textContent = v + ' %';
      setStartPointMapVar(wVar, v + '%');
      clearTimeout(wTimer);
      wTimer = setTimeout(function() { saveStyleKey(wKey, v); styles[wKey] = v; }, 400);
    });

    // — Bouton "Restaurer les dimensions de la carte" —
    // Supprime les clés start_point_map_* du device courant + remet les sliders
    // sur les valeurs par défaut + retire les CSS variables dans l'iframe.
    panel.querySelector('#ifeSpReset').addEventListener('click', function() {
      if (!confirm('Restaurer la hauteur et la largeur de la carte pour la vue ' + devLabel + ' ?')) return;
      var csrf = getCsrf();
      var fd = new FormData();
      fd.append('reset_start_point_map_dimensions', '1');
      fd.append('scope', device);
      fd.append('csrf_token', csrf);
      fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r) { return r.text(); })
        .then(function(txt) {
          var j = null;
          try { j = JSON.parse(txt); } catch (_) {}
          if (j && j.ok) {
            delete styles[hKey]; delete styles[wKey];
            // Sliders ramenés au défaut
            hInput.value = defH; hVal.textContent = defH + ' px';
            wInput.value = 100;  wVal.textContent = '100 %';
            // Retire les CSS variables → la carte retombe sur le défaut CSS
            setStartPointMapVar(hVar, null);
            setStartPointMapVar(wVar, null);
            resyncOverlaysFromIframe();
            if (typeof setDraftState === 'function') setDraftState(true);
            showSaveIndicator();
          } else {
            alert('Erreur de réinitialisation : ' + ((j && j.err) || 'réponse inattendue du serveur'));
          }
        })
        .catch(function(e) { alert('Erreur réseau : ' + e.message); });
    });
  }

  // Demande à l'iframe de renvoyer la structure du layout (rects des lignes).
  function requestIframeLayout() {
    try {
      if (iframe.contentWindow) {
        iframe.contentWindow.postMessage({ type: 'editor-request-layout' }, '*');
      }
    } catch (_) {}
  }

  // Re-mesure DIRECTEMENT les lignes dans l'iframe (sans aller-retour postMessage)
  // et reconstruit les overlays + ajuste la hauteur de l'iframe. Utilisé quand une
  // dimension change en direct (slider hauteur de carte) : garantit que les
  // pointillés de l'éditeur suivent la nouvelle hauteur immédiatement.
  function resyncOverlaysFromIframe() {
    try {
      var doc = iframe.contentDocument;
      if (!doc) return;
      // Ajuste la hauteur de l'iframe pour ne pas tronquer le contenu agrandi
      var docH = Math.max(doc.body ? doc.body.scrollHeight : 0,
                          doc.documentElement ? doc.documentElement.scrollHeight : 0);
      if (docH > 100) iframe.style.height = Math.min(docH + 2, 8000) + 'px';
      // Re-mesure chaque ligne et rebâtit les overlays
      var scrollY = 0;
      try { scrollY = iframe.contentWindow.scrollY || 0; } catch (_) {}
      var rows = [];
      doc.querySelectorAll('[data-editor-row-id]').forEach(function(rowEl) {
        var r = rowEl.getBoundingClientRect();
        rows.push({
          rowId: rowEl.dataset.editorRowId,
          rect: { top: r.top + scrollY, left: r.left, width: r.width, height: r.height }
        });
      });
      if (rows.length) buildOverlays(rows);
    } catch (_) {}
  }

  // Pose (ou retire si value == null) une CSS variable sur la section "Retrouver
  // le départ" dans l'iframe (preview live).
  function setStartPointMapVar(varName, value) {
    try {
      var doc = iframe.contentDocument;
      var sec = doc && doc.querySelector('.start-point-section');
      if (!sec) return;
      if (value === null || value === undefined) sec.style.removeProperty(varName);
      else sec.style.setProperty(varName, value);
    } catch (_) {}
  }

  // Enregistre l'adresse OU les coordonnées du point de départ, puis rafraîchit la carte.
  // Écriture directe en colonne SQL (pas de brouillon) → on ne marque PAS l'état "draft".
  function saveStartPoint(mode, value, errEl) {
    var csrf = getCsrf();
    var fd = new FormData();
    fd.append('save_start_point', '1');
    fd.append('mode', mode);
    fd.append('value', value);
    fd.append('csrf_token', csrf);
    fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j && j.ok) {
          if (errEl) errEl.style.display = 'none';
          window.AccueilEditor.startPoint = { address: j.address || '', coords: j.coords || '' };
          // Rafraîchit la carte dans l'iframe (le point se replace tout seul).
          if (iframe.contentWindow) {
            iframe.contentWindow.postMessage({
              type: 'parent-start-point-update',
              address: j.address || '',
              coords: j.coords || ''
            }, '*');
          }
          showSaveIndicator();
        } else if (errEl) {
          errEl.textContent = (j && j.err) || 'Erreur de sauvegarde.';
          errEl.style.display = 'block';
        }
      })
      .catch(accueilSaveError);
  }

  // Re-render la sélection courante (appelé par setting.php au changement de device
  // pour que les widgets device-aware de la sidebar se rebasent sur le bon device).
  window.AccueilEditor.refreshSelection = function() {
    if (selectedRowId) selectRow(selectedRowId);
  };

  // Affiche la liste des champs éditables d'une row dans la sidebar.
  // Inclut : textes hardcodés, blocs custom (text), blocs custom (html avec boutons align)
  function renderEditablesList(rowId) {
    var existing = document.getElementById('ifeSbEditableList');
    if (existing) existing.remove();
    var editables = editablesByRowId[rowId] || [];
    if (!editables.length) return;
    var fieldLabels = {
      titleAccueil: 'Titre principal (PC)',
      titleAccueil_mobile: 'Titre principal (mobile)',
      subtitle_accueil: 'Sous-titre (PC)',
      subtitle_accueil_mobile: 'Sous-titre (mobile)',
      hero_timer: 'Compte à rebours',
      video_accueil: 'Vidéo Hero',
      picture_partner: 'Image partenaires',
      'reg_bar.kicker_open': 'Compteur — libellé',
      'reg_bar.value': 'Compteur — nombre (taille)',
      'reg_bar.title_search': 'Titre recherche inscription',
      'reg_bar.btn_check': 'Bouton vérifier',
      'reg_bar.hint': 'Texte d\'aide recherche',
      'reg_bar.urgency_title': 'Titre (version urgence)',
      'reg_bar.urgency_text': 'Texte (version urgence)',
      'reg_bar.moti_title': 'Titre (version solidaire)',
      'reg_bar.moti_text': 'Texte (version solidaire)',
      'start_point.title': 'Titre de la section',
      'newsletter.title': 'Newsletter — titre',
      'newsletter.subtitle': 'Newsletter — sous-titre',
      'newsletter.intro': 'Newsletter — texte',
      'newsletter.button': 'Newsletter — bouton',
      'newsletter.consent': 'Newsletter — consentement'
    };
    var iconByKind = {
      text: 'bi-fonts', tinymce: 'bi-text-paragraph',
      image: 'bi-image', video: 'bi-film', 'size-only': 'bi-aspect-ratio',
      'custom-text': 'bi-text-paragraph', 'custom-html': 'bi-code-slash'
    };
    var labelByKind = {
      text: 'Texte', tinymce: 'Texte riche', image: 'Image',
      video: 'Vidéo', 'size-only': 'Élément',
      'custom-text': 'Bloc texte', 'custom-html': 'Bloc HTML / CSS / JS'
    };
    var listEl = document.createElement('div');
    listEl.id = 'ifeSbEditableList';
    listEl.className = 'ife-sb-editable-list';
    var head = document.createElement('div');
    head.className = 'ife-sb-editable-head';
    head.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Éléments modifiables';
    listEl.appendChild(head);
    editables.forEach(function(ed) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ife-sb-editable-btn';
      var isCustom = (ed.kind === 'custom-text' || ed.kind === 'custom-html');
      var label = isCustom ? (labelByKind[ed.kind]) : (fieldLabels[ed.field] || ed.field);
      var icon = iconByKind[ed.kind] || 'bi-pencil';
      var preview = ed.preview ? ('<small class="ife-sb-editable-preview">' + escapeHtml(ed.preview) + (ed.preview.length >= 60 ? '…' : '') + '</small>') : '';
      btn.innerHTML = '<i class="bi ' + icon + '"></i>'
        + '<div class="ife-sb-editable-info">'
        +   '<strong>' + label + '</strong>'
        +   '<span class="ife-sb-editable-kind">' + (isCustom ? 'Cliquer pour modifier' : (labelByKind[ed.kind] || ed.kind)) + '</span>'
        +   preview
        + '</div>'
        + '<i class="bi bi-chevron-right ife-sb-editable-chev"></i>';
      btn.addEventListener('click', function() {
        if (isCustom) {
          openCustomBlockEditorForSection(ed.sectionId || ed.field);
        } else {
          triggerEditField(ed.field);
        }
      });
      listEl.appendChild(btn);
      // Pour les blocs HTML : 6 boutons d'alignement (3 horiz + 3 vert) en dessous
      if (ed.kind === 'custom-html' && ed.sectionId) {
        var alignBar = renderHtmlBlockAlignBar(ed.sectionId);
        if (alignBar) listEl.appendChild(alignBar);
      }
    });
    var propsEl = document.getElementById('ifeSbProps');
    if (propsEl) propsEl.appendChild(listEl);
  }

  // Barre d'alignement (6 boutons) pour un bloc HTML, intégrée sous son entry dans la liste
  function renderHtmlBlockAlignBar(sectionId) {
    var foundCol = null;
    layoutData.forEach(function(row) {
      row.columns.forEach(function(c) {
        if (c.section && c.section.type === 'custom' && c.section.id === sectionId) foundCol = c;
      });
    });
    if (!foundCol) return null;
    var curA = foundCol.section.align  || 'left';
    var curV = foundCol.section.valign || 'top';
    var bar = document.createElement('div');
    bar.className = 'ife-sb-html-align-bar';
    bar.innerHTML = ''
      + '<div class="ife-sb-html-align-row">'
      +   '<span class="ife-sb-html-align-label">Horizontal</span>'
      +   '<div class="btn-group btn-group-sm" data-group="align">'
      +     '<button type="button" class="btn btn-outline-secondary' + (curA==='left'?' active':'')   + '" data-align="left"   title="Gauche"><i class="bi bi-align-start"></i></button>'
      +     '<button type="button" class="btn btn-outline-secondary' + (curA==='center'?' active':'') + '" data-align="center" title="Centre"><i class="bi bi-align-center"></i></button>'
      +     '<button type="button" class="btn btn-outline-secondary' + (curA==='right'?' active':'')  + '" data-align="right"  title="Droite"><i class="bi bi-align-end"></i></button>'
      +   '</div>'
      + '</div>'
      + '<div class="ife-sb-html-align-row">'
      +   '<span class="ife-sb-html-align-label">Vertical</span>'
      +   '<div class="btn-group btn-group-sm" data-group="valign">'
      +     '<button type="button" class="btn btn-outline-secondary' + (curV==='top'?' active':'')    + '" data-valign="top"    title="Haut"><i class="bi bi-align-top"></i></button>'
      +     '<button type="button" class="btn btn-outline-secondary' + (curV==='center'?' active':'') + '" data-valign="center" title="Milieu"><i class="bi bi-align-middle"></i></button>'
      +     '<button type="button" class="btn btn-outline-secondary' + (curV==='bottom'?' active':'') + '" data-valign="bottom" title="Bas"><i class="bi bi-align-bottom"></i></button>'
      +   '</div>'
      + '</div>';
    bar.addEventListener('click', function(e) {
      e.stopPropagation();
      var b = e.target.closest('button[data-align],button[data-valign]');
      if (!b) return;
      var col = null;
      layoutData.forEach(function(row) {
        row.columns.forEach(function(c) {
          if (c.section && c.section.type === 'custom' && c.section.id === sectionId) col = c;
        });
      });
      if (!col) return;
      if (b.dataset.align)  { col.section.align  = b.dataset.align;  }
      if (b.dataset.valign) { col.section.valign = b.dataset.valign; }
      // Toggle active class dans le bon groupe
      var grp = b.closest('[data-group]');
      if (grp) grp.querySelectorAll('button').forEach(function(x) { x.classList.toggle('active', x === b); });
      saveLayoutAndReload();
    });
    return bar;
  }

  function escapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Demande à l'iframe de déclencher l'édition d'un champ (équivalent à un dblclick
  // dans l'iframe sur l'élément correspondant). Comme ça pas besoin de réimplémenter
  // toute la logique d'édition côté parent.
  function triggerEditField(field) {
    if (!iframe.contentWindow) return;
    iframe.contentWindow.postMessage({
      type: 'parent-trigger-edit',
      field: field
    }, '*');
  }

  // ── Sidebar : tabs + actions ──
  document.querySelectorAll('.ife-sb-tab').forEach(function(tab) {
    tab.addEventListener('click', function() { switchSidebarTab(tab.dataset.sbTab); });
  });
  function switchSidebarTab(target) {
    document.querySelectorAll('.ife-sb-tab').forEach(function(t) { t.classList.toggle('active', t.dataset.sbTab === target); });
    document.querySelectorAll('.ife-sb-pane').forEach(function(p) { p.classList.toggle('active', p.dataset.sbPane === target); });
  }

  document.getElementById('ifeSbWidth').addEventListener('change', function() {
    if (!selectedRowId) return;
    var row = layoutData.find(function(r) { return r.id === selectedRowId; });
    if (!row || !row.columns[0]) return;
    var newW = parseInt(this.value, 10);
    if (!newW || newW < 1) return;
    row.columns[0].width = newW;
    redistributeRowWidths(row, 0);
    saveLayoutAndReload();
  });

  // ── Grille de la ligne (sidebar) : 1 case par colonne, largeur éditable + drag ──
  var gridSortable = null;
  function renderRowGrid(row) {
    var grid = document.getElementById('ifeSbGrid');
    if (!grid) return;
    grid.innerHTML = '';
    var sectionLabels = {
      reg_bar: 'Compteur', partners: 'Partenaires', timeline: 'Timeline',
      news: 'Actu', start_point: 'Départ', newsletter: 'Newsletter', custom: 'Bloc'
    };
    row.columns.forEach(function(col, idx) {
      var cell = document.createElement('div');
      cell.className = 'ife-sb-grid-cell';
      cell.style.flex = col.width + ' 1 0%';
      cell.dataset.colIdx = idx;
      if (!col.section.visible) cell.classList.add('is-hidden');
      var label = sectionLabels[col.section.type] || col.section.type;
      var isCustom = col.section.type === 'custom';

      // Boutons d'action selon le type
      // - Custom = ✎ modifier, 🗑 supprimer
      // - Prédéfini = 👁 masquer/afficher
      // - Tous = 📤 extraire (sortir cette col en ligne séparée au-dessous)
      var actionsHtml = '<div class="cell-actions">';
      if (isCustom) {
        actionsHtml += '<button type="button" class="cell-btn" data-cell-act="edit" title="Modifier le texte"><i class="bi bi-pencil"></i></button>';
      } else {
        var visIcon = col.section.visible ? 'bi-eye-slash' : 'bi-eye';
        var visTitle = col.section.visible ? 'Masquer cette section' : 'Afficher cette section';
        actionsHtml += '<button type="button" class="cell-btn" data-cell-act="toggle-vis" title="' + visTitle + '"><i class="bi ' + visIcon + '"></i></button>';
      }
      // Extraire = retire de la ligne actuelle et crée une nouvelle ligne juste en-dessous
      actionsHtml += '<button type="button" class="cell-btn" data-cell-act="extract" title="Sortir en ligne séparée (en-dessous)"><i class="bi bi-box-arrow-up-right"></i></button>';
      if (isCustom) {
        actionsHtml += '<button type="button" class="cell-btn cell-btn-danger" data-cell-act="delete" title="Supprimer ce bloc"><i class="bi bi-trash3"></i></button>';
      }
      actionsHtml += '</div>';

      cell.innerHTML = ''
        + '<div class="drag-handle" title="Glisser pour réordonner"><i class="bi bi-grip-horizontal"></i></div>'
        + '<div class="label" title="' + label + '">' + label + '</div>'
        + '<input type="number" min="1" max="11" value="' + col.width + '" data-col-idx="' + idx + '">'
        + actionsHtml;

      var input = cell.querySelector('input');
      input.addEventListener('change', function() {
        var v = parseInt(this.value, 10);
        if (!v || v < 1) v = 1; if (v > 11) v = 11;
        var ci = parseInt(cell.dataset.colIdx, 10);
        row.columns[ci].width = v;
        redistributeRowWidths(row, ci);
        renderRowGrid(row);
        saveLayoutAndReload();
      });

      // Actions des boutons par cellule
      cell.querySelectorAll('[data-cell-act]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          var ci = parseInt(cell.dataset.colIdx, 10);
          var act = btn.dataset.cellAct;
          if (act === 'edit') {
            openCustomBlockEditorForSection(row.columns[ci].section.id);
          } else if (act === 'delete') {
            deleteColumn(row.id, ci);
          } else if (act === 'toggle-vis') {
            toggleHideColumn(row.id, ci);
          } else if (act === 'extract') {
            extractColumnAsRow(row.id, ci);
          }
        });
      });

      grid.appendChild(cell);
    });

    // Init Sortable horizontal pour réordonner les colonnes intra-row
    try { if (gridSortable) gridSortable.destroy(); } catch (err) {}
    gridSortable = null;
    if (row.columns.length > 1 && typeof Sortable !== 'undefined') {
      try {
        gridSortable = Sortable.create(grid, {
          handle: '.drag-handle',
          animation: 150,
          ghostClass: 'is-dragging',
          onEnd: function() {
            var newCols = [];
            grid.querySelectorAll('.ife-sb-grid-cell').forEach(function(c) {
              var i = parseInt(c.dataset.colIdx, 10);
              if (!isNaN(i) && row.columns[i]) newCols.push(row.columns[i]);
            });
            if (newCols.length === row.columns.length) {
              row.columns = newCols;
              renderRowGrid(row);
              saveLayoutAndReload();
            }
          }
        });
      } catch (err) { console.warn('grid sortable init failed:', err); }
    }

    // Total + état des boutons d'alignement
    var total = row.columns.reduce(function(s, c) { return s + (c.width || 0); }, 0);
    var label = grid.parentElement.querySelector('.ife-sb-grid-label');
    if (label) {
      label.textContent = 'Grille de la ligne (total = ' + total + '/12)';
      label.style.color = (total === 12) ? '#475569' : '#ef4444';
    }
    // Sync boutons alignement horizontal
    var currentAlign = row.align || 'left';
    document.querySelectorAll('#ifeSbAlignGroup [data-align]').forEach(function(b) {
      b.classList.toggle('active', b.dataset.align === currentAlign);
    });
    // Sync boutons alignement vertical
    var currentValign = row.valign || 'center';
    document.querySelectorAll('#ifeSbValignGroup [data-valign]').forEach(function(b) {
      b.classList.toggle('active', b.dataset.valign === currentValign);
    });
    // Sync inputs espacement haut/bas
    var spTop = document.getElementById('ifeSbSpaceTop');
    var spBot = document.getElementById('ifeSbSpaceBottom');
    if (spTop) spTop.value = (row.spaceTop    !== null && row.spaceTop    !== undefined) ? row.spaceTop    : '';
    if (spBot) spBot.value = (row.spaceBottom !== null && row.spaceBottom !== undefined) ? row.spaceBottom : '';
  }

  // Boutons d'alignement HORIZONTAL de la ligne (gauche/centre/droite)
  document.querySelectorAll('#ifeSbAlignGroup [data-align]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (!selectedRowId) return;
      var row = layoutData.find(function(r) { return r.id === selectedRowId; });
      if (!row) return;
      row.align = btn.dataset.align;
      document.querySelectorAll('#ifeSbAlignGroup [data-align]').forEach(function(b) {
        b.classList.toggle('active', b === btn);
      });
      saveLayoutAndReload();
    });
  });

  // Inputs d'espacement vertical (margin haut/bas indépendants par ligne, en rem).
  // 0 = pas d'espace. Défauts : 5 au-dessus, 0 en-dessous. Debounced 500ms.
  var spacingTimer = null;
  var DEFAULT_TOP_REM = 5;
  var DEFAULT_BOTTOM_REM = 0;
  function saveSpacing() {
    clearTimeout(spacingTimer);
    spacingTimer = setTimeout(function() {
      if (!selectedRowId) return;
      var row = layoutData.find(function(r) { return r.id === selectedRowId; });
      if (!row) return;
      var spTopVal = document.getElementById('ifeSbSpaceTop').value;
      var spBotVal = document.getElementById('ifeSbSpaceBottom').value;
      var topNum = parseFloat(spTopVal);
      var botNum = parseFloat(spBotVal);
      row.spaceTop    = (spTopVal === '' || isNaN(topNum)) ? null : topNum;
      row.spaceBottom = (spBotVal === '' || isNaN(botNum)) ? null : botNum;
      saveLayoutAndReload();
    }, 500);
  }
  ['ifeSbSpaceTop', 'ifeSbSpaceBottom'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', saveSpacing);
  });
  var btnSpaceReset = document.getElementById('ifeSbSpaceReset');
  if (btnSpaceReset) {
    btnSpaceReset.addEventListener('click', function() {
      var spTopEl = document.getElementById('ifeSbSpaceTop');
      var spBotEl = document.getElementById('ifeSbSpaceBottom');
      spTopEl.value = DEFAULT_TOP_REM;
      spBotEl.value = DEFAULT_BOTTOM_REM;
      if (!selectedRowId) return;
      var row = layoutData.find(function(r) { return r.id === selectedRowId; });
      if (!row) return;
      row.spaceTop    = DEFAULT_TOP_REM;
      row.spaceBottom = DEFAULT_BOTTOM_REM;
      saveLayoutAndReload();
    });
  }

  // Boutons d'alignement VERTICAL de la ligne (haut/centre/bas) — utile quand les
  // colonnes ont des hauteurs différentes (ex: un bouton HTML à côté d'une reg_bar)
  document.querySelectorAll('#ifeSbValignGroup [data-valign]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (!selectedRowId) return;
      var row = layoutData.find(function(r) { return r.id === selectedRowId; });
      if (!row) return;
      row.valign = btn.dataset.valign;
      document.querySelectorAll('#ifeSbValignGroup [data-valign]').forEach(function(b) {
        b.classList.toggle('active', b === btn);
      });
      saveLayoutAndReload();
    });
  });

  // Presets de la grille (ex: 6/6, 8/4, 10/2, etc.)
  // Comportement smart : si le preset a PLUS de colonnes que la ligne courante,
  // on ajoute automatiquement des colonnes vides (texte simple). S'il en a moins,
  // on demande confirmation avant de supprimer les dernières colonnes.
  document.querySelectorAll('#ifeSbGridRow .ife-sb-grid-presets [data-preset]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (!selectedRowId) return;
      var row = layoutData.find(function(r) { return r.id === selectedRowId; });
      if (!row) return;
      var widths = btn.dataset.preset.split(',').map(function(n) { return parseInt(n, 10); });
      var target = widths.length;
      var current = row.columns.length;

      // Plus de cols nécessaires → ajoute des blocs vides (texte simple)
      while (row.columns.length < target) {
        row.columns.push({
          width: 12,
          section: {
            type: 'custom',
            id: 'custom_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
            title: 'Nouveau bloc',
            content: '<p>Cliquer pour modifier ce texte…</p>',
            visible: true
          }
        });
      }

      // Moins de cols → demande confirmation, garde les premières
      if (current > target) {
        var lost = current - target;
        if (!confirm('Ce préréglage utilise ' + target + ' colonnes. ' + lost + ' colonne(s) seront supprimée(s). Continuer ?')) return;
        row.columns = row.columns.slice(0, target);
      }

      // Applique les largeurs du preset
      row.columns.forEach(function(c, i) { c.width = widths[i]; });
      renderRowGrid(row);
      saveLayoutAndReload();
    });
  });

  // Pour une row multi-colonnes, après modif de columns[fixedIdx], force total=12
  // en répartissant le reste sur les autres colonnes proportionnellement.
  function redistributeRowWidths(row, fixedIdx) {
    if (!row || !row.columns || row.columns.length < 2) return;
    var fixedW = row.columns[fixedIdx].width;
    var remaining = 12 - fixedW;
    if (remaining < 1) {
      // Cas extrême : col fixée prend tout sauf 1 par autre colonne
      row.columns[fixedIdx].width = 12 - (row.columns.length - 1);
      remaining = row.columns.length - 1;
    }
    var others = row.columns.length - 1;
    var perCol = Math.floor(remaining / others);
    var extra = remaining - perCol * others;
    var i = 0;
    row.columns.forEach(function(c, idx) {
      if (idx === fixedIdx) return;
      c.width = perCol + (i < extra ? 1 : 0);
      i++;
    });
  }

  document.getElementById('ifeSbVis').addEventListener('change', function() {
    if (!selectedRowId) return;
    var row = layoutData.find(function(r) { return r.id === selectedRowId; });
    if (!row || !row.columns[0]) return;
    row.columns[0].section.visible = this.checked;
    saveLayoutAndReload();
  });

  document.getElementById('ifeSbBtnDelete').addEventListener('click', function() {
    if (!selectedRowId) return;
    if (!confirm('Supprimer cette ligne ?')) return;
    deleteRow(selectedRowId);
  });

  function deleteRow(rowId) {
    var row = layoutData.find(function(r) { return r.id === rowId; });
    if (!row) return;
    // Sécurité : on n'autorise la suppression que pour les rows ne contenant QUE des blocs custom
    var hasPredefined = row.columns.some(function(c) { return c.section.type !== 'custom'; });
    if (hasPredefined) {
      alert('Cette section pré-définie ne peut pas être supprimée. Vous pouvez la masquer (œil) ou la déplacer.');
      return;
    }
    layoutData = layoutData.filter(function(r) { return r.id !== rowId; });
    selectedRowId = null;
    saveLayoutAndReload();
  }

  function toggleHideRow(rowId) {
    var row = layoutData.find(function(r) { return r.id === rowId; });
    if (!row || !row.columns[0]) return;
    var newVis = !row.columns[0].section.visible;
    row.columns.forEach(function(c) { c.section.visible = newVis; });
    saveLayoutAndReload();
  }

  function moveRow(rowId, delta) {
    var idx = layoutData.findIndex(function(r) { return r.id === rowId; });
    if (idx < 0) return;
    var newIdx = idx + delta;
    if (newIdx < 0 || newIdx >= layoutData.length) return;
    var item = layoutData.splice(idx, 1)[0];
    layoutData.splice(newIdx, 0, item);
    saveLayoutAndReload();
  }

  // ── Add Custom Block (sidebar bouton) ──
  document.getElementById('btnAddCustomBlock').addEventListener('click', function() {
    if (pendingInsertPos < 0) pendingInsertPos = layoutData.length;
    var bsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('customBlockModal'), { focus: false });
    document.getElementById('customBlockEditId').value = '';
    document.getElementById('customBlockTitle').value = '';
    bsModal.show();
    var modalEl = document.getElementById('customBlockModal');
    modalEl.addEventListener('shown.bs.modal', function once() {
      modalEl.removeEventListener('shown.bs.modal', once);
      if (window.AccueilEditor.initTinyMce) {
        window.AccueilEditor.initTinyMce('#customBlockEditor', '');
      }
    });
  });

  // ── Restore predefined section ──
  document.querySelectorAll('#restoreMenu button[data-restore-type]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var type = btn.dataset.restoreType;
      var pos = pendingInsertPos >= 0 ? pendingInsertPos : layoutData.length;
      pendingInsertPos = -1;
      var newRow = {
        id: 'row_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
        columns: [{ width: 12, section: { type: type, visible: true } }]
      };
      layoutData.splice(pos, 0, newRow);
      saveLayoutAndReload();
    });
  });

  // ── Override "Save custom block" pour insérer à la position désirée ──
  var btnSaveCustom = document.getElementById('btnSaveCustomBlock');
  if (btnSaveCustom) {
    btnSaveCustom.addEventListener('click', function() {
      var id = document.getElementById('customBlockEditId').value.trim();
      var title = document.getElementById('customBlockTitle').value.trim();
      var content = '';
      if (typeof tinymce !== 'undefined') {
        var ed = tinymce.get('customBlockEditor');
        content = ed ? ed.getContent() : '';
      }
      if (id) {
        layoutData.forEach(function(row) {
          row.columns.forEach(function(col) {
            if (col.section.type === 'custom' && col.section.id === id) {
              col.section.title = title;
              col.section.content = content;
              col.section.kind = 'text';
            }
          });
        });
      } else {
        var pos = pendingInsertPos >= 0 ? pendingInsertPos : layoutData.length;
        pendingInsertPos = -1;
        layoutData.splice(pos, 0, {
          id: 'row_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
          columns: [{ width: 12, section: {
            type: 'custom',
            kind: 'text',
            id: 'custom_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
            title: title, content: content, visible: true
          }}]
        });
      }
      var modalEl = document.getElementById('customBlockModal');
      var bsModal = bootstrap.Modal.getInstance(modalEl);
      if (bsModal) bsModal.hide();
      saveLayoutAndReload();
    }, true);
  }

  // ── Click on data-edit-field dans iframe → ouvre le modal universel ──
  function openEditFromIframe(data) {
    var modalEl = document.getElementById('fieldEditModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('fieldEditTitle').textContent = 'Modifier : ' + data.field;
    document.getElementById('fieldEditFieldName').value = data.field;
    document.getElementById('fieldEditKind').value = data.kind;
    document.getElementById('fieldEditStatus').style.display = 'none';
    document.getElementById('fieldEditTinymceWrap').style.display = 'none';
    document.getElementById('fieldEditTextWrap').style.display = 'none';
    document.getElementById('fieldEditFileWrap').style.display = 'none';
    if (data.kind === 'tinymce') {
      document.getElementById('fieldEditTinymceWrap').style.display = '';
      // Pré-remplit le textarea sous-jacent : si TinyMCE n'a pas fini son init au
      // moment où l'admin clique "Enregistrer", le btnSaveField fallback lit cette
      // valeur (évite de sauvegarder une chaîne vide qui effacerait le titre).
      var ta = document.getElementById('fieldEditTinymce');
      if (ta) ta.value = data.currentValue || '';
      // Attache l'écouteur 'shown.bs.modal' AVANT show() pour ne pas manquer l'event
      // (si l'event est émis de manière synchrone après show, le bind tardif rate).
      modalEl.addEventListener('shown.bs.modal', function once() {
        modalEl.removeEventListener('shown.bs.modal', once);
        if (window.AccueilEditor.initTinyMce) {
          window.AccueilEditor.initTinyMce('#fieldEditTinymce', data.currentValue || '');
        }
      });
      bsModal.show();
    } else if (data.kind === 'text') {
      document.getElementById('fieldEditTextWrap').style.display = '';
      document.getElementById('fieldEditText').value = data.currentValue || '';
      bsModal.show();
    } else if (data.kind === 'image' || data.kind === 'video') {
      document.getElementById('fieldEditFileWrap').style.display = '';
      document.getElementById('fieldEditFile').value = '';
      var hint = document.getElementById('fieldEditFileHint');
      if (data.kind === 'image') {
        hint.textContent = 'Formats : JPG, PNG, GIF, WEBP. Max ~5 Mo.';
        document.getElementById('fieldEditFile').accept = 'image/jpeg,image/png,image/gif,image/webp';
      } else {
        hint.textContent = 'Formats : MP4, WebM, OGG. Max 50 Mo.';
        document.getElementById('fieldEditFile').accept = 'video/mp4,video/webm,video/ogg';
      }
      document.getElementById('fieldEditFileCurrent').textContent = data.currentValue ? ('Fichier actuel : ' + data.currentValue) : 'Aucun fichier actuellement.';
      bsModal.show();
    }
  }

  // ── Save layout vers BDD puis recharge l'iframe ──
  var saveTimer = null;
  function saveLayoutAndReload() {
    // Marque immédiatement le badge "Modifications non publiées" visible (avant même
    // que la requête réseau ne parte — donc l'utilisateur voit le badge dès le clic).
    if (typeof setDraftState === 'function') setDraftState(true);
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function() { doSave(); }, 200);
  }
  function doSave() {
    var csrf = getCsrf();
    fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ save_accueil_layout: 1, layout: layoutData, csrf_token: csrf })
    })
    .then(function(r) { return r.json(); })
    .then(function(j) {
      if (j && j.ok) reloadIframe();
      else alert('Erreur : ' + ((j && j.err) || 'Sauvegarde échouée'));
    })
    .catch(function() { alert('Erreur réseau lors de la sauvegarde.'); });
  }

  // ── Système brouillon / publication ──
  // À chaque saveLayoutAndReload (= modif utilisateur), on a un brouillon à publier.
  // Le badge + bouton "Annuler" sont visibles. Le bouton "Publier" pousse en prod.
  function setDraftState(hasDraft) {
    window.AccueilEditor.hasDraft = !!hasDraft;
    var badge = document.getElementById('ifeDraftBadge');
    var btnDiscard = document.getElementById('btnDiscardDraft');
    if (badge)      badge.style.display      = hasDraft ? '' : 'none';
    if (btnDiscard) btnDiscard.style.display = hasDraft ? '' : 'none';
  }
  // État initial (passé depuis PHP via window.AccueilEditor.hasDraft).
  // Le suivi pendant l'édition est fait DIRECTEMENT dans saveLayoutAndReload ci-dessus.
  setDraftState(window.AccueilEditor.hasDraft);

  // Helper : reload de la page en préservant l'onglet actif (sinon le ?tab=accueil
  // n'est pas dans l'URL et on retombe sur l'onglet par défaut "personnalisation").
  function reloadPreserveTab() {
    try {
      var url = new URL(location.href);
      url.searchParams.set('tab', 'accueil');
      location.href = url.toString();
    } catch (e) {
      location.reload();
    }
  }

  // Bouton "Publier sur le site"
  var btnPublish = document.getElementById('btnPublishLayout');
  if (btnPublish) {
    btnPublish.addEventListener('click', function() {
      if (!window.AccueilEditor.hasDraft) {
        // Pas de brouillon : rien à publier
        var st = document.getElementById('layoutSaveStatus');
        if (st) { st.style.display = 'block'; st.style.color = '#64748b'; st.textContent = 'Aucune modification à publier.'; setTimeout(function(){ st.style.display='none'; }, 2500); }
        return;
      }
      if (!confirm('Publier les modifications sur le site public ? Tous les visiteurs verront la nouvelle version immédiatement.')) return;
      var status = document.getElementById('layoutSaveStatus');
      if (status) { status.style.display = 'block'; status.style.color = '#64748b'; status.textContent = 'Publication en cours…'; }
      var csrf = getCsrf();
      var fd = new FormData();
      fd.append('publish_accueil', '1');
      fd.append('csrf_token', csrf);
      fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
          if (j && j.ok) {
            setDraftState(false);
            if (status) { status.style.color = '#16a34a'; status.textContent = '✓ Publié sur le site.'; setTimeout(function(){ status.style.display='none'; }, 3000); }
            reloadIframe();
          } else {
            alert('Erreur publication : ' + ((j && j.err) || 'inconnue'));
            if (status) status.style.display = 'none';
          }
        })
        .catch(function() { alert('Erreur réseau lors de la publication.'); if (status) status.style.display = 'none'; });
    });
  }

  // Bouton "Annuler les modifications" (discard draft)
  var btnDiscard = document.getElementById('btnDiscardDraft');
  if (btnDiscard) {
    btnDiscard.addEventListener('click', function() {
      if (!confirm('Annuler TOUTES les modifications non publiées ? Le brouillon sera perdu et l\'éditeur reviendra à la version actuellement en ligne.')) return;
      var csrf = getCsrf();
      var fd = new FormData();
      fd.append('discard_accueil_draft', '1');
      fd.append('csrf_token', csrf);
      fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) {
          if (j && j.ok) {
            setDraftState(false);
            // Reload en préservant l'onglet Accueil (sinon retombe sur Personnalisation par défaut)
            reloadPreserveTab();
          } else {
            alert('Erreur annulation : ' + ((j && j.err) || 'inconnue'));
          }
        })
        .catch(function() { alert('Erreur réseau lors de l\'annulation.'); });
    });
  }
})();
