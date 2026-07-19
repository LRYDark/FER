/**
 * Widget chatbot public — Forbach en Rose
 *
 * Bulle flottante en bas à droite de toutes les pages publiques.
 * - Conversation avec le moteur à règles (public/chatbot-api.php)
 * - Vérification d'inscription / d'éligibilité t-shirt par e-mail
 * - Formulaire de contact intégré (captcha Turnstile ou maths, pièces jointes)
 * - Le bouton « Contactez-nous » du footer ouvre le chat
 * - Ouverture auto via ?chat=1 (accueil du bot) ou ?chat=contact (formulaire)
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

  var emailContext = null;   // 'registration' | 'tshirt' quand le bot attend un e-mail
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
    else if (reply.action === 'contact_form')      { emailContext = null; renderContactForm(); }
    else if (reply.action === 'suggest_contact')   { emailContext = null; }
    else emailContext = null;
    if (reply.action === 'ask_email_registration' || reply.action === 'ask_email_tshirt') {
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
    if (emailContext) fields.email_context = emailContext;

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
      emailContext = emailContext || 'registration';
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
                size: 'compact',
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
  function greet(withContactForm) {
    if (started) {
      if (withContactForm) renderContactForm();
      return;
    }
    started = true;
    busy = true;
    var typing = addTyping();
    post({ action: 'ask', message: 'bonjour' }).then(function (j) {
      typing.remove();
      busy = false;
      if (j && j.ok && j.reply) handleReply(j.reply);
      else addMsg('Bonjour ! 👋 Comment puis-je vous aider ?', 'bot');
      if (withContactForm) renderContactForm();
    }).catch(function () {
      typing.remove();
      busy = false;
      addMsg('Bonjour ! 👋 Comment puis-je vous aider ?', 'bot');
      if (withContactForm) renderContactForm();
    });
  }

  function openChat(withContactForm) {
    if (!isOpen) {
      isOpen = true;
      root.classList.add('fcb-open');
      bubble.setAttribute('aria-expanded', 'true');
      setTimeout(function () { inputEl.focus(); }, 250);
    }
    greet(!!withContactForm);
  }

  function closeChat() {
    isOpen = false;
    root.classList.remove('fcb-open');
    bubble.setAttribute('aria-expanded', 'false');
  }

  bubble.addEventListener('click', function () { isOpen ? closeChat() : openChat(); });
  closeBtn.addEventListener('click', closeChat);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen) closeChat();
  });

  sendBtn.addEventListener('click', function () { send(inputEl.value); });
  inputEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); send(inputEl.value); }
  });

  /* Bouton « Contactez-nous » du footer → ouvre le chat sur le formulaire */
  document.querySelectorAll('a[href="contact"], a[href="contact.php"], .footer-contact-btn').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      openChat(true);
    });
  });

  /* Ouverture auto via URL : ?chat=1 (accueil) / ?chat=contact (formulaire) */
  try {
    var p = new URLSearchParams(window.location.search);
    var chat = p.get('chat');
    if (chat === 'contact') openChat(true);
    else if (chat) openChat(false);
  } catch (e) {}
})();
