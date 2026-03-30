/**
 * visual-editor.js — Shared utilities for visual editor pages
 * Used by mail-settings.php and future customization pages.
 */
var VisualEditor = (function(){

  // ── DOM helpers ──
  function q(s){ return document.querySelector(s); }
  function qa(s){ return document.querySelectorAll(s); }

  // ── Color helpers ──
  function hexDarken(hex, factor){
    hex = hex.replace('#','');
    var r = Math.max(0, Math.round(parseInt(hex.substring(0,2),16)*(1-factor)));
    var g = Math.max(0, Math.round(parseInt(hex.substring(2,4),16)*(1-factor)));
    var b = Math.max(0, Math.round(parseInt(hex.substring(4,6),16)*(1-factor)));
    return '#'+r.toString(16).padStart(2,'0')+g.toString(16).padStart(2,'0')+b.toString(16).padStart(2,'0');
  }

  function rgbToHex(rgb){
    if(!rgb) return '#000000';
    if(rgb.startsWith('#')) return rgb;
    var m = rgb.match(/(\d+)/g);
    if(!m || m.length < 3) return '#000000';
    return '#'+((1<<24)+(+m[0]<<16)+(+m[1]<<8)+(+m[2])).toString(16).slice(1);
  }

  function gradient(c1, c2){
    return 'linear-gradient(135deg,'+c1+' 0%,'+c2+' 100%)';
  }

  // ── Inline text editing ──
  // Makes [data-ed] elements editable on double-click
  // Returns { startEdit, stopEdit, getTexts }
  function initTextEditing(opts){
    opts = opts || {};
    var onStart = opts.onStart || function(){};
    var onStop  = opts.onStop  || function(){};
    var editing = null;

    qa('[data-ed]').forEach(function(el){
      el.addEventListener('dblclick', function(e){
        e.preventDefault(); e.stopPropagation();
        startEdit(this);
      });
      if(opts.onClick){
        el.addEventListener('click', function(e){
          e.stopPropagation();
          opts.onClick(this);
        });
      }
    });

    function startEdit(el){
      stopEdit();
      editing = el;
      el.classList.add('editing');
      el.contentEditable = 'true';
      el.focus();
      var r = document.createRange(); r.selectNodeContents(el);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      onStart(el);
    }

    function stopEdit(){
      if(!editing) return;
      editing.contentEditable = 'false';
      editing.classList.remove('editing');
      onStop(editing);
      editing = null;
    }

    document.addEventListener('keydown', function(e){
      if(!editing) return;
      if(e.key === 'Escape' || e.key === 'Enter'){
        e.preventDefault();
        stopEdit();
      }
    });

    // Collect all editable text values
    function getTexts(){
      var texts = {};
      qa('[data-ed]').forEach(function(el){
        texts[el.dataset.ed] = {
          text: el.textContent.trim(),
          font: el.style.fontFamily || '',
          size: el.style.fontSize || '',
          align: el.style.textAlign || '',
          color: el.style.color || ''
        };
      });
      return texts;
    }

    return { startEdit: startEdit, stopEdit: stopEdit, getTexts: getTexts, getEditing: function(){ return editing; } };
  }

  // ── Drag & drop for sortable sections ──
  // container: DOM element containing the sections
  // selector: CSS selector for draggable items
  // onReorder: callback after reorder
  function initDragDrop(container, selector, onReorder){
    var dragged = null;

    function bind(el){
      el.addEventListener('dragstart', function(e){
        dragged = this; this.style.opacity = '.3';
        e.dataTransfer.effectAllowed = 'move';
      });
      el.addEventListener('dragend', function(){
        this.style.opacity = '1'; dragged = null;
      });
      el.addEventListener('dragover', function(e){
        e.preventDefault();
        if(!dragged || dragged === this) return;
        var rect = this.getBoundingClientRect();
        if(e.clientY < rect.top + rect.height/2){
          container.insertBefore(dragged, this);
        } else {
          container.insertBefore(dragged, this.nextSibling);
        }
        if(onReorder) onReorder();
      });
    }

    container.querySelectorAll(selector).forEach(bind);
    return { bind: bind };
  }

  // ── Color picker binding ──
  // Binds color inputs to a config object and calls refresh
  function bindColorInputs(containerSel, config, refresh){
    qa(containerSel + ' input[type="color"]').forEach(function(inp){
      var key = inp.dataset.c || inp.dataset.cc;
      if(!key) return;
      inp.addEventListener('input', function(){
        config[key] = this.value;
        if(refresh) refresh();
      });
    });
  }

  // ── Range slider binding ──
  function bindRangeInputs(containerSel, config, refresh){
    qa(containerSel + ' input[type="range"]').forEach(function(inp){
      var key = inp.dataset.r;
      if(!key) return;
      inp.addEventListener('input', function(){
        config[key] = +this.value;
        var v = this.parentElement.querySelector('.v');
        if(v) v.textContent = this.value;
        if(refresh) refresh();
      });
    });
  }

  // ── Tab switching ──
  function initTabs(tabSel, paneSels){
    qa(tabSel).forEach(function(tab){
      tab.addEventListener('click', function(){
        qa(tabSel).forEach(function(t){ t.classList.remove('active'); });
        this.classList.add('active');
        var paneId = this.dataset.pane;
        Object.keys(paneSels).forEach(function(id){
          var el = document.getElementById(id);
          if(el) el.classList.toggle('active', id === paneId);
        });
      });
    });
  }

  // ── Public API ──
  return {
    q: q,
    qa: qa,
    hexDarken: hexDarken,
    rgbToHex: rgbToHex,
    gradient: gradient,
    initTextEditing: initTextEditing,
    initDragDrop: initDragDrop,
    bindColorInputs: bindColorInputs,
    bindRangeInputs: bindRangeInputs,
    initTabs: initTabs
  };
})();
