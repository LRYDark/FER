/**
 * Widget chatbot public — Forbach en Rose
 *
 * Bulle flottante en bas à droite de toutes les pages publiques.
 * - Conversation avec le moteur à règles (public/chatbot-api.php)
 * - Vérification d'inscription / d'éligibilité t-shirt par e-mail
 * - Formulaire de contact intégré (captcha Turnstile ou maths, pièces jointes)
 * - Le bouton « Contactez-nous » du footer ouvre le chat (accueil + questions)
 * - Ouverture auto via ?chat=1 ou ?chat=contact (accueil du bot)
 * - Bulle déplaçable (appui long sur mobile, glisser à la souris), position mémorisée
 * - Teaser d'accroche : apparaît 2 s après le chargement, disparaît après 10 s
 *
 * Aucune dépendance. Configuration lue sur #ferChatbot (data-attributes).
 */
(function () {
  'use strict';

  var root = document.getElementById('ferChatbot');
  if (!root) return;

  var API   = root.dataset.api   || 'chatbot-api.php';
  var CSRF  = root.dataset.csrf  || '';
  var ADMIN_API = root.dataset.adminApi || '../admin-api.php';

  /* ══════════ Éléments ══════════ */
  var bubble  = root.querySelector('.fcb-bubble');
  var win     = root.querySelector('.fcb-window');
  var msgsEl  = root.querySelector('.fcb-messages');
  var inputEl = root.querySelector('.fcb-input');
  var sendBtn = root.querySelector('.fcb-send');
  var closeBtn= root.querySelector('.fcb-close');
  var homeBtn = root.querySelector('.fcb-home');

  var emailContext = null;      // 'registration' | 'tshirt' | 'qrcode' quand le bot attend un e-mail
  var lastEmailContext = null;  // dernier contexte utilisé (pour « Réessayer »)
  var isOpen = false;
  var started = false;
  var busy = false;

  /* ══════════ Utilitaires ══════════ */
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
  function scrollBottom() { msgsEl.scrollTop = msgsEl.scrollHeight; }

  function addMsg(html, who) {
    var m = document.createElement('div');
    m.className = 'fcb-msg ' + (who === 'user' ? 'fcb-user' : 'fcb-bot');
    m.innerHTML = html; // bot: HTML sûr construit serveur ; user: passé par esc()
    msgsEl.appendChild(m);
    scrollBottom();
    return m;
  }

  function addTyping() {
    var m = document.createElement('div');
    m.className = 'fcb-msg fcb-bot fcb-typing';
    m.innerHTML = '<span></span><span></span><span></span>';
    msgsEl.appendChild(m);
    scrollBottom();
    return m;
  }

  function addQuickReplies(items) {
    if (!items || !items.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'fcb-quick';
    items.forEach(function (label) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'fcb-chip';
      b.textContent = label;
      b.addEventListener('click', function () {
        wrap.remove();
        handleQuick(label);
      });
      wrap.appendChild(b);
    });
    msgsEl.appendChild(wrap);
    scrollBottom();
  }

  function clearQuickReplies() {
    msgsEl.querySelectorAll('.fcb-quick').forEach(function (q) { q.remove(); });
  }

  /* ══════════ Réseau ══════════ */
  function post(fields, files) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    if (files) files.forEach(function (f) { fd.append('attachments[]', f); });
    return fetch(API, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
  }

  /* ══════════ Flux conversationnel ══════════ */
  function handleReply(reply) {
    addMsg(reply.text, 'bot');
    if (reply.action === 'ask_email_registration') emailContext = 'registration';
    else if (reply.action === 'ask_email_tshirt')  emailContext = 'tshirt';
    else if (reply.action === 'ask_email_qrcode')  emailContext = 'qrcode';
    else if (reply.action === 'contact_form')      { emailContext = null; renderContactForm(); }
    else if (reply.action === 'suggest_contact')   { emailContext = null; }
    else emailContext = null;
    if (reply.action === 'ask_email_registration' || reply.action === 'ask_email_tshirt' || reply.action === 'ask_email_qrcode') {
      inputEl.setAttribute('placeholder', 'votre@email.fr');
      inputEl.setAttribute('type', 'email');
    } else {
      inputEl.setAttribute('placeholder', 'Écrivez votre question…');
      inputEl.setAttribute('type', 'text');
    }
    addQuickReplies(reply.quick);
  }

  function send(text) {
    if (busy) return;
    var msg = (text || '').trim();
    if (!msg) return;
    clearQuickReplies();
    addMsg(esc(msg), 'user');
    inputEl.value = '';
    busy = true;
    var typing = addTyping();

    // base64 : les champs POST accentués sont altérés par le WAF/serveur
    // (même contournement que le formulaire de contact historique)
    var fields = { action: 'ask', message: btoa(unescape(encodeURIComponent(msg))) };
    if (emailContext) { lastEmailContext = emailContext; fields.email_context = emailContext; }

    post(fields).then(function (j) {
      typing.remove();
      busy = false;
      if (j && j.ok && j.reply) handleReply(j.reply);
      else addMsg(esc((j && j.message) || 'Petit souci technique — réessayez.'), 'bot');
    }).catch(function () {
      typing.remove();
      busy = false;
      addMsg('Impossible de joindre l\'assistant — vérifiez votre connexion.', 'bot');
    });
  }

  /* Réponses rapides : certaines déclenchent une action directe */
  function handleQuick(label) {
    var l = label.toLowerCase();
    if (l.indexOf('nous écrire') !== -1 || l.indexOf('écrire') !== -1) {
      addMsg(esc(label), 'user');
      addMsg('Avec plaisir ! Remplissez ce petit formulaire, nous vous répondrons par e-mail. 💗', 'bot');
      renderContactForm();
      return;
    }
    if (l.indexOf('réessayer') !== -1) {
      addMsg(esc(label), 'user');
      addMsg('Pas de souci — indiquez-moi l\'adresse e-mail à vérifier :', 'bot');
      // Conserver le contexte précédent (registration par défaut)
      emailContext = emailContext || lastEmailContext || 'registration';
      inputEl.setAttribute('placeholder', 'votre@email.fr');
      return;
    }
    send(label.replace(/^[^\wÀ-ÿ]+\s*/, '')); // retire l'emoji de tête
  }

  /* ══════════ Formulaire de contact intégré ══════════ */
  var captchaState = { mode: null, tsWidgetId: null, tsLoading: null, didFallback: false };

  function ensureTurnstileScript() {
    if (window.turnstile) return Promise.resolve();
    if (captchaState.tsLoading) return captchaState.tsLoading;
    captchaState.tsLoading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      s.async = true; s.defer = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { captchaState.tsLoading = null; reject(new Error('load')); };
      document.head.appendChild(s);
    });
    return captchaState.tsLoading;
  }

  function renderContactForm() {
    clearQuickReplies();
    // Un seul formulaire à la fois
    var old = msgsEl.querySelector('.fcb-form-card');
    if (old) old.remove();

    var card = document.createElement('div');
    card.className = 'fcb-msg fcb-bot fcb-form-card';
    card.innerHTML =
      '<form class="fcb-form" novalidate>' +
        '<label>Nom complet<input type="text" name="nom" required autocomplete="name"></label>' +
        '<label>Adresse e-mail<input type="email" name="email" required autocomplete="email"></label>' +
        '<label>Sujet<input type="text" name="sujet" required></label>' +
        '<label>Message<textarea name="message" rows="4" required></textarea></label>' +
        '<label class="fcb-att-label">Pièces jointes <small>(facultatif — 3 max, 5 Mo/fichier)</small>' +
          '<input type="file" name="attachments" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.txt,image/*,application/pdf">' +
        '</label>' +
        '<ul class="fcb-att-list" aria-live="polite"></ul>' +
        '<div class="fcb-captcha">' +
          '<div class="fcb-ts-box" style="display:none"></div>' +
          '<div class="fcb-math-box" style="display:none">' +
            '<div class="fcb-math-q">Chargement…</div>' +
            '<div class="fcb-math-row">' +
              '<input type="text" class="fcb-math-a" inputmode="numeric" autocomplete="off" placeholder="Votre réponse">' +
              '<button type="button" class="fcb-math-reload" title="Nouvelle question">↻</button>' +
            '</div>' +
          '</div>' +
          '<div class="fcb-captcha-err"></div>' +
        '</div>' +
        '<button type="submit" class="fcb-submit" disabled>Envoyer le message</button>' +
      '</form>';
    msgsEl.appendChild(card);
    scrollBottom();

    var form = card.querySelector('form');
    var submitBtn = form.querySelector('.fcb-submit');
    var tsBox = form.querySelector('.fcb-ts-box');
    var mathBox = form.querySelector('.fcb-math-box');
    var mathQ = form.querySelector('.fcb-math-q');
    var mathA = form.querySelector('.fcb-math-a');
    var errEl = form.querySelector('.fcb-captcha-err');
    var fileInput = form.querySelector('input[type="file"]');
    var attList = form.querySelector('.fcb-att-list');

    var captchaToken = '', turnstileToken = '';
    var files = [];
    captchaState.didFallback = false;

    function setError(m) { errEl.textContent = m || ''; }
    function setValid(ok) { submitBtn.disabled = !ok; }

    /* Pièces jointes : liste + suppression */
    function renderFiles() {
      attList.innerHTML = '';
      files.forEach(function (f, i) {
        var li = document.createElement('li');
        var over = f.size > 5 * 1024 * 1024;
        li.innerHTML = '<span class="fcb-att-name">' + esc(f.name) + '</span>' +
                       '<span class="fcb-att-size">' + (f.size < 1048576 ? Math.round(f.size / 1024) + ' Ko' : (f.size / 1048576).toFixed(1) + ' Mo') + (over ? ' ⚠️' : '') + '</span>';
        var rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'fcb-att-rm'; rm.textContent = '×';
        rm.addEventListener('click', function () { files.splice(i, 1); renderFiles(); });
        li.appendChild(rm);
        attList.appendChild(li);
      });
      scrollBottom();
    }
    fileInput.addEventListener('change', function () {
      Array.prototype.forEach.call(fileInput.files, function (f) {
        if (files.length >= 3) return;
        var dup = files.some(function (ex) { return ex.name === f.name && ex.size === f.size; });
        if (!dup) files.push(f);
      });
      fileInput.value = '';
      renderFiles();
    });

    /* Captcha : même mécanisme que l'ancienne page contact */
    function switchToMathFallback(reason) {
      if (captchaState.didFallback) { setError(reason || 'Échec du captcha. Réessayez.'); return; }
      captchaState.didFallback = true;
      setError('Vérification indisponible — bascule sur un captcha de secours…');
      if (captchaState.tsWidgetId !== null && window.turnstile) {
        try { window.turnstile.remove(captchaState.tsWidgetId); } catch (e) {}
        captchaState.tsWidgetId = null;
      }
      fetch(ADMIN_API + '?route=partner-captcha-init&fallback=1')
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok || j.mode !== 'math') throw new Error('fallback');
          captchaState.mode = 'math';
          tsBox.style.display = 'none';
          mathBox.style.display = 'block';
          captchaToken = j.token; turnstileToken = '';
          mathQ.textContent = j.question;
          mathA.value = '';
          setError('');
          scrollBottom();
        })
        .catch(function () { setError('Impossible d\'afficher le captcha de secours. Réessayez plus tard.'); });
    }

    function initCaptcha() {
      setError(''); setValid(false);
      captchaState.didFallback = false;
      captchaToken = ''; turnstileToken = '';
      fetch(ADMIN_API + '?route=partner-captcha-init')
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error('init');
          captchaState.mode = j.mode;
          if (j.mode === 'turnstile') {
            tsBox.style.display = 'block';
            mathBox.style.display = 'none';
            ensureTurnstileScript().then(function () {
              if (captchaState.tsWidgetId !== null) {
                try { window.turnstile.remove(captchaState.tsWidgetId); } catch (e) {}
                captchaState.tsWidgetId = null;
              }
              captchaState.tsWidgetId = window.turnstile.render(tsBox, {
                sitekey: j.sitekey,
                theme: document.body.classList.contains('dark-theme') ? 'dark' : 'light',
                size: 'flexible',
                callback: function (t) { turnstileToken = t; setValid(true); setError(''); },
                'error-callback':   function () { turnstileToken = ''; setValid(false); switchToMathFallback('Échec du captcha Cloudflare.'); },
                'expired-callback': function () { turnstileToken = ''; setValid(false); setError('Captcha expiré. Réessayez.'); }
              });
              scrollBottom();
            }).catch(function () { switchToMathFallback('Impossible de charger Cloudflare.'); });
          } else {
            tsBox.style.display = 'none';
            mathBox.style.display = 'block';
            captchaToken = j.token;
            mathQ.textContent = j.question;
            mathA.value = '';
            scrollBottom();
          }
        })
        .catch(function () { setError('Impossible d\'initialiser le captcha. Réessayez.'); });
    }
    mathA.addEventListener('input', function () {
      if (captchaState.mode === 'math') setValid(mathA.value.trim().length > 0);
    });
    form.querySelector('.fcb-math-reload').addEventListener('click', initCaptcha);

    /* Envoi */
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (submitBtn.disabled) return;
      var nom = form.nom.value.trim(), email = form.email.value.trim(),
          sujet = form.sujet.value.trim(), message = form.message.value.trim();
      if (!nom || !email || !sujet || !message) { setError('Veuillez remplir tous les champs.'); return; }
      submitBtn.disabled = true;
      submitBtn.textContent = 'Envoi en cours…';

      var fields = {
        action: 'contact_send',
        nom: nom, email: email, sujet: sujet,
        // base64 : contournement du WAF (comme l'ancienne page contact)
        message: btoa(unescape(encodeURIComponent(message))),
        turnstile_token: turnstileToken,
        captcha_token: captchaToken,
        captcha_answer: captchaState.mode === 'math' ? mathA.value.trim() : ''
      };
      post(fields, files).then(function (j) {
        if (j && j.ok) {
          card.remove();
          addMsg('✅ ' + esc(j.message), 'bot');
          addQuickReplies(['✅ Mon inscription', '📍 Lieu & horaires']);
        } else {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Envoyer le message';
          setError((j && j.message) || 'Erreur — réessayez.');
          initCaptcha(); // captcha consommé
        }
      }).catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Envoyer le message';
        setError('Erreur réseau — réessayez.');
        initCaptcha();
      });
    });

    initCaptcha();
  }

  /* ══════════ Ouverture / fermeture ══════════ */
  /* Toujours accueillir avec les questions rapides — le formulaire de contact
     n'apparaît que sur demande (chip « Nous écrire » ou intention détectée). */
  function greet() {
    if (started) return;
    started = true;
    busy = true;
    var typing = addTyping();
    post({ action: 'ask', message: 'bonjour' }).then(function (j) {
      typing.remove();
      busy = false;
      if (j && j.ok && j.reply) handleReply(j.reply);
      else addMsg('Bonjour ! 👋 Comment puis-je vous aider ?', 'bot');
    }).catch(function () {
      typing.remove();
      busy = false;
      addMsg('Bonjour ! 👋 Comment puis-je vous aider ?', 'bot');
    });
  }

  /* Verrou du défilement de l'arrière-plan (mobile plein écran uniquement).
     Body en position:fixed avec compensation du scroll → l'iOS/Android ne
     défile plus la page derrière, et on restaure la position à la fermeture. */
  var savedScrollY = 0;
  function lockBody() {
    if (window.innerWidth > 480 || document.body.classList.contains('fcb-body-lock')) return;
    savedScrollY = window.scrollY || window.pageYOffset || 0;
    document.body.style.top = -savedScrollY + 'px';
    document.body.classList.add('fcb-body-lock');
  }
  function unlockBody() {
    if (!document.body.classList.contains('fcb-body-lock')) return;
    document.body.classList.remove('fcb-body-lock');
    document.body.style.top = '';
    window.scrollTo(0, savedScrollY);
  }

  function openChat() {
    hideTeaser();
    if (!isOpen) {
      isOpen = true;
      updateWindowSide();
      root.classList.add('fcb-open');
      bubble.setAttribute('aria-expanded', 'true');
      lockBody();
      setTimeout(function () { inputEl.focus(); }, 250);
    }
    greet();
  }

  function closeChat() {
    isOpen = false;
    root.classList.remove('fcb-open');
    bubble.setAttribute('aria-expanded', 'false');
    unlockBody();
  }

  bubble.addEventListener('click', function () {
    if (wasDragged) return; // fin de glisser-déposer, pas un clic
    isOpen ? closeChat() : openChat();
  });
  closeBtn.addEventListener('click', closeChat);

  /* Bouton accueil de l'en-tête : revient au menu principal à tout moment
     (mêmes questions rapides que l'accueil du moteur) */
  var MENU_QUICK = ['✅ Mon inscription', '🎽 T-shirt', '💶 Tarifs', '📩 QR code non reçu', '📍 Lieu & horaires', '❓ Voir la FAQ', '✉️ Nous écrire'];
  if (homeBtn) homeBtn.addEventListener('click', function () {
    if (busy) return;
    clearQuickReplies();
    emailContext = null;
    inputEl.setAttribute('placeholder', 'Écrivez votre question…');
    inputEl.setAttribute('type', 'text');
    addMsg('On reprend depuis le début ! Que souhaitez-vous savoir ? 😊', 'bot');
    addQuickReplies(MENU_QUICK);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen) closeChat();
  });

  sendBtn.addEventListener('click', function () { send(inputEl.value); });
  inputEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); send(inputEl.value); }
  });

  /* ══════════ Bulle déplaçable (appui long puis glisser) ══════════ */
  var POS_KEY = 'fer_chatbot_pos';
  var drag = null, wasDragged = false, holdTimer = null;

  function clampPos(x, y) {
    var m = 8, w = bubble.offsetWidth || 60, h = bubble.offsetHeight || 60;
    return {
      x: Math.min(Math.max(x, m), window.innerWidth  - w - m),
      y: Math.min(Math.max(y, m), window.innerHeight - h - m)
    };
  }
  /* La fenêtre s'ancre du bon côté selon la position de la bulle */
  function updateWindowSide() {
    var r = bubble.getBoundingClientRect();
    root.classList.toggle('fcb-win-left',  r.left + r.width / 2 < window.innerWidth / 2);
    root.classList.toggle('fcb-win-below', r.top  + r.height / 2 < window.innerHeight / 2);
  }
  function applyPos(x, y) {
    var p = clampPos(x, y);
    root.style.left = p.x + 'px';
    root.style.top = p.y + 'px';
    root.style.right = 'auto';
    root.style.bottom = 'auto';
    updateWindowSide();
  }
  /* Position mémorisée en fractions de l'écran (s'adapte à toutes les tailles) */
  function savePos() {
    var r = bubble.getBoundingClientRect();
    try {
      localStorage.setItem(POS_KEY, JSON.stringify({
        xf: r.left / Math.max(1, window.innerWidth  - r.width),
        yf: r.top  / Math.max(1, window.innerHeight - r.height)
      }));
    } catch (e) {}
  }
  function restorePos() {
    var p = null;
    try { p = JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch (e) {}
    if (!p || typeof p.xf !== 'number' || typeof p.yf !== 'number') return;
    var r = bubble.getBoundingClientRect();
    applyPos(p.xf * (window.innerWidth - r.width), p.yf * (window.innerHeight - r.height));
  }

  function activateDrag() {
    if (!drag) return;
    drag.active = true;
    root.classList.add('fcb-dragging');
    try { bubble.setPointerCapture(drag.id); } catch (e) {}
    if (navigator.vibrate) { try { navigator.vibrate(15); } catch (e) {} }
    hideTeaser();
  }
  bubble.addEventListener('pointerdown', function (e) {
    if (e.button && e.button !== 0) return;
    var br = bubble.getBoundingClientRect();
    drag = { id: e.pointerId, sx: e.clientX, sy: e.clientY, ox: br.left, oy: br.top,
             active: false, touch: e.pointerType !== 'mouse' };
    // Tactile : appui long (300 ms sans bouger) pour saisir la bulle
    if (drag.touch) holdTimer = setTimeout(activateDrag, 300);
  });
  bubble.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;
    if (!drag.active) {
      var dist = Math.hypot(dx, dy);
      if (drag.touch) {
        if (dist > 10) { clearTimeout(holdTimer); drag = null; } // bougé avant l'appui long
      } else if (dist > 6) {
        activateDrag(); // souris : le glisser suffit
      }
      return;
    }
    e.preventDefault();
    applyPos(drag.ox + dx, drag.oy + dy);
  });
  function endDrag() {
    clearTimeout(holdTimer);
    if (drag && drag.active) {
      savePos();
      wasDragged = true;
      setTimeout(function () { wasDragged = false; }, 0); // absorbe le click qui suit
    }
    drag = null;
    root.classList.remove('fcb-dragging');
  }
  bubble.addEventListener('pointerup', endDrag);
  bubble.addEventListener('pointercancel', endDrag);
  bubble.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  window.addEventListener('resize', function () {
    if (root.style.left) restorePos(); else updateWindowSide();
  });
  restorePos();

  /* ══════════ Teaser d'accroche ══════════
     S'affiche à CHAQUE chargement de page (~2 s après) et disparaît tout seul
     au bout de 10 s (il reviendra à la page suivante). SEULE la fermeture
     volontaire via la croix (✕) le masque pendant 1 semaine. */
  var TEASER_KEY = 'fer_chatbot_teaser_dismissed_until';
  var teaser = root.querySelector('.fcb-teaser');
  var teaserTimers = [];

  function hideTeaser(dismissWeek) {
    if (!teaser) return;
    teaserTimers.forEach(clearTimeout);
    teaserTimers = [];
    if (!teaser.hidden) {
      teaser.classList.remove('fcb-teaser-in');
      teaserTimers.push(setTimeout(function () { teaser.hidden = true; }, 250));
    }
    // Fermeture volontaire (✕) → on ne remontre plus le teaser pendant 7 jours
    if (dismissWeek) { try { localStorage.setItem(TEASER_KEY, String(Date.now() + 7 * 24 * 3600 * 1000)); } catch (e) {} }
  }
  if (teaser) {
    var dismissedUntil = 0;
    try { dismissedUntil = parseInt(localStorage.getItem(TEASER_KEY) || '0', 10) || 0; } catch (e) {}
    if (Date.now() > dismissedUntil) {
      teaserTimers.push(setTimeout(function () {
        if (isOpen || (drag && drag.active)) return;
        updateWindowSide();       // oriente la queue (au-dessus / en dessous du bouton)
        teaser.hidden = false;
        requestAnimationFrame(function () { teaser.classList.add('fcb-teaser-in'); });
        teaserTimers.push(setTimeout(function () { hideTeaser(false); }, 10000));
      }, 2000));
    }
    teaser.addEventListener('click', function (e) {
      var isClose = !!e.target.closest('.fcb-teaser-close');
      hideTeaser(isClose);        // ✕ → masqué 1 semaine ; clic sur le corps → ouvre le chat
      if (!isClose) openChat();
    });
  }

  /* Bouton « Contactez-nous » du footer → ouvre le chat (accueil + questions,
     le formulaire vient via « ✉️ Nous écrire ») */
  document.querySelectorAll('a[href="contact"], a[href="contact.php"], .footer-contact-btn').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      openChat();
    });
  });

  /* Ouverture via URL : ?chat=1 ou ?chat=contact (lien profond volontaire).
     Le paramètre est retiré de l'URL aussitôt — un rechargement de la page
     ne rouvre donc JAMAIS le chat tout seul. */
  try {
    var p = new URLSearchParams(window.location.search);
    if (p.get('chat')) {
      openChat();
      p.delete('chat');
      var qs = p.toString();
      history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
    }
  } catch (e) {}
})();
