<?php
/**
 * Barre d'action flottante — composant partagé.
 * ---------------------------------------------------------------------------
 * Un seul bouton en bas de l'écran, à la place des boutons semés dans chaque
 * carte. CE QU'IL FAIT DÉPEND DE L'ÉCRAN VISIBLE, et c'est tout l'objet de ce
 * fichier : un écran d'envoi de mail n'a rien à « enregistrer », un écran sans
 * réglage n'a besoin d'aucune barre, et un éditeur dont le travail ne vit pas
 * dans des champs de formulaire doit quand même pouvoir être enregistré.
 *
 * ⚠️ CE QUI SE PASSAIT AVANT, ET QUI A COÛTÉ CHER :
 *   • Le volet « Envoi de mail » affichait « Enregistrer », grisé pour
 *     toujours — le bouton d'envoi, lui, était ailleurs dans la page.
 *   • Le volet « Template » ne s'enregistrait jamais : ses couleurs, ses
 *     textes et son ordre de sections ne vivent pas dans des champs nommés,
 *     la barre ne voyait donc AUCUNE modification et restait grisée.
 *   • Pire, les écrans qui remplissaient leurs champs cachés dans un écouteur
 *     `submit` les perdaient en silence : ni fetch() ni form.submit() ne
 *     déclenchent cet événement. L'heure de départ de la course, saisie
 *     depuis Applications, partait vide.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LES TROIS MODES, choisis d'après ce qui est VISIBLE à l'écran
 *
 *  1. ACTION  —  <form data-oc-action="Envoyer le mail">
 *     L'écran ne se sauvegarde pas, il agit. La barre porte le libellé
 *     demandé, sans compteur ni annulation, et le clic appelle
 *     requestSubmit() : la validation native ET le gestionnaire de la page
 *     s'exécutent, exactement comme un clic sur son propre bouton.
 *     Un formulaire d'action visible l'emporte sur tout le reste.
 *
 *  2. FORMULAIRE UNIQUE  —  <form class="oc-tabform" data-save-flags="a=1|b=1">
 *     L'écran a été fusionné en un seul formulaire (Réglages). On lui ajoute
 *     les drapeaux et on l'envoie d'un coup : tous les gestionnaires PHP
 *     s'enchaînent dans le même cycle.
 *
 *  3. PLUSIEURS FORMULAIRES  —  <form data-oc-save="nom_du_bouton">
 *     L'écran NE PEUT PAS être fusionné : ses volets mêlent des
 *     enregistrements et des actions indépendantes (connexion Google,
 *     suppression d'un abonné) qui ont chacune leur formulaire. Imbriquer
 *     serait du HTML invalide. La barre envoie alors chaque formulaire
 *     MODIFIÉ un par un en fetch(), chacun avec le nom de bouton qu'il
 *     portait avant, puis recharge.
 *
 * Dans les trois cas, AUCUN gestionnaire PHP n'est modifié.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LES DEUX POINTS D'ACCROCHE POUR LES ÉCRANS QUI SORTENT DU MOULE
 *
 *  • data-oc-dirty="nomDeFonctionGlobale"
 *    Pour un écran dont le travail ne vit pas dans ses champs : un éditeur
 *    visuel, une mise en page, du contenteditable. La fonction rend `true`
 *    quand il y a quelque chose à enregistrer. Elle S'AJOUTE au décompte des
 *    champs, elle ne le remplace pas — choisir une image reste vu.
 *
 *  • événement `oc:serialize`, déclenché sur le formulaire AVANT tout envoi.
 *    ⚠️ C'est le remplaçant OBLIGATOIRE de l'écouteur `submit` pour tout ce
 *    qui prépare des champs cachés : ni fetch() ni form.submit() ne
 *    déclenchent `submit`. Un écran qui remplit ses champs à l'envoi doit
 *    écouter LES DEUX (`submit` pour son propre bouton, `oc:serialize` pour
 *    la barre) — sinon il s'enregistre vide, sans rien signaler.
 *
 * Une page peut aussi signaler un travail en attente hors formulaire via
 * window.ocPendingChanges() (l'éditeur d'accueil s'en sert pour son brouillon).
 *
 * Options à définir avant l'inclusion :
 *   $saveBarSite = true;  → ajoute « Enregistrer et voir le site »
 *                           (la page doit gérer $_POST['oc_goto_site']).
 */
$saveBarSite = !empty($saveBarSite);

/* ⚠️ EN LECTURE SEULE, PAS DE BARRE DU TOUT.
 *
 * Le garde-fou de admin-footer.php désactive les <button type="submit"> et
 * pose un écouteur `submit` qui annule l'envoi. Il ne voit RIEN de cette
 * barre : son bouton est un type="button", et elle envoie par fetch() ou
 * form.submit(), que l'événement `submit` ne suit pas. Un compte en lecture
 * seule se voyait donc proposer « Enregistrer », et le serveur répondait 403
 * — la bonne réponse, mais après coup et sans qu'on comprenne pourquoi.
 *
 * On coupe côté PHP et non en JavaScript : la classe `app-readonly` n'est
 * posée sur <body> que par admin-footer.php, inclus APRÈS cette barre. La
 * lire ici aurait toujours donné « non ». */
if (!empty($pageReadOnly)) return;
?>
<div class="oc-savebar" id="ocSaveBar" hidden>
  <div class="left">
    <?php /* ⚠️ « Annuler les modifications », et NON « Réinitialiser » : ce
             bouton ne rétablit aucun réglage d'usine, il jette ce qu'on vient
             de taper et rend l'écran tel que le serveur l'a envoyé. Sous son
             ancien nom il promettait un retour aux valeurs par défaut qui
             n'existe nulle part. Il ne s'affiche QUE lorsqu'il y a quelque
             chose à annuler : partout ailleurs il occupait la barre sans rien
             pouvoir faire. */ ?>
    <button type="button" class="oc-btn is-ghost" id="ocResetBtn" hidden
            data-confirm="Annuler les modifications non enregistrées de cet écran ?">
      <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
      Annuler les modifications
    </button>
  </div>
  <div class="right">
    <span class="oc-state" id="ocSaveState" data-state="dirty" hidden><span class="dot"></span><span class="txt"></span></span>
    <?php if ($saveBarSite): ?>
      <button type="button" class="oc-btn" id="ocSaveSiteBtn">Enregistrer et voir le site</button>
    <?php endif; ?>
    <button type="button" class="oc-btn is-primary" id="ocSaveBtn">Enregistrer</button>
  </div>
</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function () {
  var bar = document.getElementById('ocSaveBar');
  if (!bar) return;

  var etat     = document.getElementById('ocSaveState');
  var txt      = etat.querySelector('.txt');
  var btnSave  = document.getElementById('ocSaveBtn');
  var btnSite  = document.getElementById('ocSaveSiteBtn');
  var btnReset = document.getElementById('ocResetBtn');
  var gauche   = bar.querySelector('.left');
  var libelleSave = btnSave.innerHTML;

  /* ⚠️ LA COLONNE DE GAUCHE DISPARAÎT AVEC SON SEUL BOUTON.
     Elle ne contient que « Annuler les modifications », masqué la plupart du
     temps. Vide, elle restait pourtant un élément flex de la barre — et sur
     mobile, où elle passe en `flex: 1 1 100%`, elle occupait une LIGNE ENTIÈRE
     de hauteur nulle : la gouttière de 16 px de la barre s'ajoutait alors
     au-dessus des boutons, qui n'avaient que les 12 px de remplissage en
     dessous. D'où deux fois plus d'air au-dessus qu'en dessous, et autant de
     hauteur perdue sur l'écran le plus étroit.
     `hidden` seul ne suffirait pas : `.oc-savebar .left { display: flex }` le
     réécrit — le CSS pose donc `.left[hidden] { display: none }`. */
  function majReset(cache) {
    btnReset.hidden = cache;
    if (gauche) gauche.hidden = cache;
  }
  majReset(true);

  function tableau(l) { return Array.prototype.slice.call(l); }

  /* Un formulaire n'est retenu que s'il est RÉELLEMENT visible : les écrans à
     volets gardent tous les autres dans le document, masqués. */
  function visible(el) { return !!(el.offsetParent || el.getClientRects().length); }

  /* ⚠️ PORTÉE RECALCULÉE À CHAQUE FOIS, JAMAIS FIGÉE AU CHARGEMENT.
     Elle change quand on passe d'un volet à l'autre, et un formulaire peut
     être replié (bloc « collapse » de la page Applications) : figer la liste
     au départ l'aurait exclu pour de bon, et une clé saisie puis repliée
     n'aurait jamais été enregistrée — en silence. D'où la règle « visible OU
     déjà modifié ». Un volet inactif, lui, ne peut pas être modifié : il
     reste donc hors de portée. */
  function scope() {
    var tous  = tableau(document.querySelectorAll('form[data-oc-save]'));
    var sales = tous.filter(function (f) { return compterForm(f) > 0; });

    /* ⚠️ UN ÉCRAN D'ACTION NE PREND LA BARRE QUE SI RIEN N'ATTEND D'ÊTRE
       ENREGISTRÉ. Sans cette réserve, retoucher le template puis passer au
       volet d'envoi rendait ce travail inatteignable — la barre ne parlait
       plus que d'envoyer — et l'avertissement de sortie de page ne le voyait
       plus non plus : les modifications disparaissaient sans un mot. */
    var actions = tableau(document.querySelectorAll('form[data-oc-action]')).filter(visible);
    if (actions.length && !sales.length && !enAttenteHorsFormulaire()) {
      return { mode: 'action', liste: actions.slice(0, 1) };
    }

    var un = tableau(document.querySelectorAll('form.oc-tabform')).filter(visible);
    if (un.length) return { mode: 'unique', liste: un.slice(0, 1) };

    return {
      mode: 'multiple',
      liste: tous.filter(function (f) { return visible(f) || compterForm(f) > 0; })
    };
  }

  /* ⚠️ COMPARAISON AUX VALEURS PAR DÉFAUT DU DOCUMENT, pas à un instantané
     pris au chargement : un champ modifié puis remis à sa valeur d'origine
     redevient « propre », ce qu'un instantané ne saurait pas faire. */
  function estModifie(el) {
    if (el.disabled || !el.name) return false;
    if (el.type === 'file')     return el.files && el.files.length > 0;
    if (el.type === 'checkbox' || el.type === 'radio') return el.checked !== el.defaultChecked;
    if (el.tagName === 'SELECT') {
      return Array.prototype.some.call(el.options, function (o) { return o.selected !== o.defaultSelected; });
    }
    return el.value !== el.defaultValue;
  }

  /* Les éditeurs riches ne recopient leur contenu dans le <textarea> qu'à
     l'envoi : sans ça, retoucher un texte laissait la barre muette. */
  function editeurs() {
    try { return (window.tinymce && tinymce.editors) ? Array.prototype.slice.call(tinymce.editors) : []; }
    catch (e) { return []; }
  }

  /* Le point d'accroche des écrans dont le travail ne vit pas dans les champs
     — le template des mails : couleurs, textes, ordre des sections. */
  function horsChamps(f) {
    var nom = f.getAttribute('data-oc-dirty');
    if (!nom) return false;
    try { return typeof window[nom] === 'function' && !!window[nom](); }
    catch (e) { return false; }
  }

  function compterForm(f) {
    var n = 0;
    Array.prototype.forEach.call(f.elements, function (el) {
      if (el.type === 'submit' || el.type === 'button') return;
      /* ⚠️ LES CHAMPS CACHÉS SONT IGNORÉS PAR DÉFAUT, ET C'EST VOULU : ils
         portent surtout des drapeaux d'état (onglet actif, identifiant de la
         carte en cours d'édition) qui changent sans qu'aucun réglage n'ait
         bougé — les compter aurait annoncé « 1 modification » à la seule
         ouverture d'une fenêtre d'édition.
         SAUF quand le champ caché EST le réglage : le sélecteur de police de
         Réglages → Personnalisation écrit dans un <input type="hidden">, et
         choisir une police ne réveillait donc jamais la barre. Ces champs-là
         se marquent data-oc-watch. */
      if (el.type === 'hidden' && !el.hasAttribute('data-oc-watch')) return;
      if (estModifie(el)) n++;
    });
    editeurs().forEach(function (ed) {
      if (f.contains(ed.getElement()) && ed.isDirty && ed.isDirty()) n++;
    });
    if (horsChamps(f)) n++;
    return n;
  }

  /* ⚠️ TOUT NE PASSE PAS PAR UN CHAMP DE FORMULAIRE.
     L'éditeur d'accueil enregistre ses changements de mise en page dans un
     brouillon, en direct : aucun champ ne bouge, la barre ne voyait donc rien
     et « Enregistrer » restait grisé — la publication devenait impossible.
     Une page peut signaler un travail en attente via window.ocPendingChanges. */
  function enAttenteHorsFormulaire() {
    try { return (typeof window.ocPendingChanges === 'function') && !!window.ocPendingChanges(); }
    catch (e) { return false; }
  }

  function compter(s) {
    var n = s.liste.reduce(function (t, f) { return t + compterForm(f); }, 0);
    if (enAttenteHorsFormulaire()) n++;
    return n;
  }

  function afficher(oui) {
    bar.hidden = !oui;
    document.body.classList.toggle('oc-has-savebar', oui);
  }

  var enCours = false;
  var enErreur = false;   // voir echec() : un message d'échec ne s'efface pas seul
  var modeCourant = null;
  var actionLancee = false;   // voir agir() : garde anti double-envoi

  function rafraichir() {
    /* ⚠️ UN ÉCHEC RESTE À L'ÉCRAN JUSQU'À CE QU'ON Y TOUCHE. Le relevé
       périodique plus bas rappelle cette fonction toutes les 1,5 s : sans
       cette réserve, « Échec sur … » était remplacé par le décompte des
       modifications avant même d'avoir été lu. */
    if (enCours || enErreur) return;
    var s = scope();

    /* ── Écran d'action : rien à enregistrer, une chose à faire ── */
    if (s.mode === 'action') {
      if (modeCourant !== 'action') {
        btnSave.innerHTML = s.liste[0].getAttribute('data-oc-action') || libelleSave;
        modeCourant = 'action';
      }
      majReset(true);
      if (btnSite) btnSite.hidden = true;
      etat.hidden = true;
      btnSave.disabled = actionLancee;
      afficher(true);
      return;
    }

    if (modeCourant === 'action') btnSave.innerHTML = libelleSave;
    modeCourant = s.mode;
    if (btnSite) btnSite.hidden = false;

    /* ⚠️ AUCUN FORMULAIRE À ENREGISTRER = AUCUNE BARRE. Le volet « Envoi de
       mail » et celui des abonnés newsletter n'en ont pas ; leur laisser un
       « Enregistrer » grisé faisait croire à un écran cassé. */
    if (!s.liste.length) { afficher(false); return; }
    afficher(true);

    var n = compter(s);
    /* Rien à signaler = on ne signale rien. Une pastille « Tout est
       enregistré » en permanence occupe la barre pour dire qu'il ne s'est
       rien passé ; l'état au repos, c'est l'absence de message. */
    etat.hidden = (n === 0);
    /* Après un échec, la pastille reste rouge tant qu'on n'a rien retouché :
       dès qu'on repart en saisie, elle redevient un simple décompte — sinon
       le compteur s'affichait en rouge, comme si l'erreur durait encore. */
    etat.dataset.state = 'dirty';
    /* Un brouillon de mise en page seul ne se décrit pas comme « 1
       modification » : on nomme ce qui est en attente. */
    if (n === 1 && enAttenteHorsFormulaire()) {
      txt.textContent = 'Mise en page non publiée';
    } else {
      txt.textContent = n + ' modification' + (n > 1 ? 's' : '') + ' non enregistrée' + (n > 1 ? 's' : '');
    }
    btnSave.disabled = (n === 0);
    majReset(n === 0);
    if (btnSite) btnSite.disabled = (n === 0);
  }

  /* Point d'entrée pour les pages qui modifient l'état hors formulaire
     (l'éditeur d'accueil appelle ceci quand son brouillon change). */
  window.ocRefreshSaveBar = rafraichir;

  /* ⚠️ UN SEUL CALCUL PAR IMAGE, PAS UN PAR ÉVÉNEMENT. Tirer un curseur de
     l'éditeur de template émet des dizaines d'événements `input` par seconde,
     et chacun relance le relevé complet de l'écran (le template compare son
     empreinte, ce qui parcourt tous ses éléments). Sans ce regroupement,
     l'éditeur devenait pâteux sous la main. */
  var planifie = false;
  function planifier() {
    enErreur = false;   // on repart en saisie : le message d'échec a fait son temps
    if (planifie) return;
    planifie = true;
    var tour = function () { planifie = false; rafraichir(); };
    /* ⚠️ requestAnimationFrame doit être appelé SUR window : détaché dans une
       variable, le navigateur refuse l'appel (« Illegal invocation »). */
    if (window.requestAnimationFrame) window.requestAnimationFrame(tour);
    else setTimeout(tour, 16);
  }

  /* Écoute sur le document, pas sur chaque formulaire : les événements
     remontent, et un formulaire affiché après coup est pris en compte sans
     qu'on ait à le rebrancher. */
  document.addEventListener('input',  planifier);
  document.addEventListener('change', planifier);
  /* Changer de volet est un CLIC, pas une saisie : sans ceci la barre gardait
     l'apparence du volet précédent. */
  document.addEventListener('click', planifier);
  rafraichir();

  // Les éditeurs s'initialisent après nous : on repasse brancher les nouveaux.
  setInterval(function () {
    editeurs().forEach(function (ed) {
      if (ed._ocBranche) return;
      ed._ocBranche = true;
      ed.on('input change keyup SetContent Undo Redo', planifier);
    });
    rafraichir();   // filet : un volet ouvert par du code n'émet ni clic ni saisie
  }, 1500);

  /* ⚠️ NI fetch() NI form.submit() NE DÉCLENCHENT L'ÉVÉNEMENT `submit`.
     Les écrans qui préparent leurs champs cachés au moment de l'envoi doivent
     donc être prévenus autrement — faute de quoi ils s'enregistrent vides,
     sans la moindre erreur à l'écran. */
  function serialiser(f) {
    try { f.dispatchEvent(new CustomEvent('oc:serialize', { bubbles: true })); }
    catch (e) {
      try {
        var ev = document.createEvent('Event');
        ev.initEvent('oc:serialize', true, true);
        f.dispatchEvent(ev);
      } catch (e2) {}
    }
  }

  function debutEnvoi() {
    enCours = true;
    enErreur = false;
    etat.hidden = false;
    etat.dataset.state = 'saving';
    txt.textContent = 'Enregistrement…';
    btnSave.disabled = true;
    majReset(true);
    if (btnSite) btnSite.disabled = true;
    btnSave.innerHTML = '<span class="oc-spin"></span>Enregistrement…';
    /* TinyMCE se branche sur l'événement `submit`, que form.submit() et
       fetch() ne déclenchent PAS : sans ce triggerSave(), on renverrait
       l'ancien texte. */
    if (window.tinymce && tinymce.triggerSave) tinymce.triggerSave();
  }

  function echec(message) {
    enCours = false;
    enErreur = true;
    etat.hidden = false;
    etat.dataset.state = 'error';
    txt.textContent = message;
    btnSave.innerHTML = libelleSave;
    btnSave.disabled = false;
    majReset(false);
    if (btnSite) btnSite.disabled = false;
  }

  // ── Mode 1 : écran d'action ──────────────────────────────────────────────
  function agir() {
    var f = scope().liste[0];
    if (!f || actionLancee) return;

    /* ⚠️ UN MAIL ENVOYÉ NE SE RATTRAPE PAS. Le gestionnaire de la page part en
       fetch() puis recharge : entre les deux, un second clic renverrait tout à
       une deuxième fois. On neutralise donc le bouton — mais SEULEMENT une
       fois la validation native passée, sinon un objet manquant le laisserait
       grisé pour rien. Et on le rend au bout de quelques secondes, faute de
       quoi un refus du serveur ou une coupure réseau laisserait l'écran mort
       sans que rien ne l'explique. */
    if (!f._ocArmeAction) {
      f._ocArmeAction = true;
      f.addEventListener('submit', function () {
        actionLancee = true;
        rafraichir();
        setTimeout(function () { actionLancee = false; rafraichir(); }, 5000);
      });
      /* La page peut refuser l'envoi elle-même (« aucun destinataire ») : le
         formulaire a bien émis `submit`, mais rien n'est parti. Dès qu'on
         retouche l'écran, le bouton revient — sans attendre les cinq
         secondes, qui ne sont là que comme filet. Un envoi réussi, lui,
         recharge la page avant tout autre clic. */
      f.addEventListener('click', function () {
        if (actionLancee) { actionLancee = false; rafraichir(); }
      });
    }

    /* requestSubmit() et non submit() : on VEUT la validation native et
       l'écouteur `submit` de la page — c'est lui qui fait le travail. */
    if (f.requestSubmit) f.requestSubmit();
    else f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }

  // ── Mode 2 : un seul formulaire, un seul envoi ───────────────────────────
  function envoyerUnique(versLeSite) {
    var f = scope().liste[0];
    debutEnvoi();
    serialiser(f);
    (f.dataset.saveFlags || '').split('|').forEach(function (paire) {
      if (!paire) return;
      var i = paire.indexOf('=');
      var champ = document.createElement('input');
      champ.type  = 'hidden';
      champ.name  = i < 0 ? paire : paire.slice(0, i);
      champ.value = i < 0 ? '1'   : paire.slice(i + 1);
      f.appendChild(champ);
    });
    if (versLeSite) {
      var vers = document.createElement('input');
      vers.type = 'hidden'; vers.name = 'oc_goto_site'; vers.value = '1';
      f.appendChild(vers);
    }
    /* form.submit() et non un clic sur un bouton : on saute la validation
       native, qui porterait sur TOUT l'écran — y compris les champs d'un
       formulaire d'ajout laissé vide. Les boutons d'action la gardent. */
    f.submit();
  }

  /* ── Mode 3 : plusieurs formulaires, envoyés l'un après l'autre ──────────
   *
   * ⚠️ LES FORMULAIRES QUI PARTAGENT UN MÊME NOM DE BOUTON SONT ENVOYÉS
   * ENSEMBLE, EN UN SEUL POST.
   *
   * Sur l'écran Emails, deux cartes (« Paramètres Gmail » et « Coordonnées »)
   * postent toutes les deux `google=1`, mais chacune ne porte que la moitié
   * des champs — or le gestionnaire écrit les quatre colonnes d'un coup avec
   * `$_POST['client_id'] ?? ''`. Envoyer la carte Coordonnées seule EFFACE
   * donc le Client ID et le Secret Google. En fusionnant les champs des deux
   * cartes dans le même envoi, le gestionnaire les retrouve tous. */
  function envoyerMultiple() {
    var groupes = {};
    scope().liste.forEach(function (f) {
      var nom = f.dataset.ocSave;
      (groupes[nom] = groupes[nom] || []).push(f);
    });

    // Un groupe part dès qu'UN de ses formulaires est modifié — mais il part
    // avec les champs de TOUS, y compris ceux qu'on n'a pas touchés.
    var aEnvoyer = Object.keys(groupes).filter(function (nom) {
      return groupes[nom].some(function (f) { return compterForm(f) > 0; });
    });
    if (!aEnvoyer.length) return;
    debutEnvoi();

    var jeton = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    (function suivant(i) {
      if (i >= aEnvoyer.length) { location.reload(); return; }
      var nom = aEnvoyer[i];
      var donnees = new FormData();
      groupes[nom].forEach(function (f) {
        serialiser(f);   // ⚠️ AVANT le FormData : c'est là qu'arrivent les champs cachés
        new FormData(f).forEach(function (v, k) { donnees.append(k, v); });
      });
      // Le nom du bouton d'origine : c'est lui qui déclenche le bon
      // gestionnaire PHP, exactement comme le clic d'avant.
      donnees.append(nom, '1');
      if (jeton && !donnees.has('csrf_token')) donnees.append('csrf_token', jeton);

      fetch(window.location.href, {
        method: 'POST',
        body: donnees,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        suivant(i + 1);
      }).catch(function () {
        echec('Échec sur « ' + nom + ' » — rien de plus n’a été envoyé.');
      });
    })(0);
  }

  function envoyer(versLeSite) {
    if (enCours) return;
    var mode = scope().mode;
    if (mode === 'action')      agir();
    else if (mode === 'unique') envoyerUnique(versLeSite);
    else                        envoyerMultiple();
  }

  btnSave.addEventListener('click', function () { envoyer(false); });
  if (btnSite) btnSite.addEventListener('click', function () { envoyer(true); });

  // Annuler = revenir à ce que le serveur a envoyé, sans rien enregistrer.
  btnReset.addEventListener('click', function () {
    var s = scope();
    /* ⚠️ form.reset() NE SAIT DÉFAIRE QUE DES CHAMPS. Un écran dont le travail
       vit ailleurs (le template des mails : couleurs, textes, ordre des
       sections) resterait modifié à l'écran tout en s'annonçant propre — on ne
       saurait plus ce qui part à l'enregistrement. Là, seul un rechargement
       dit la vérité, et il ne coûte rien : on vient de confirmer qu'on jette. */
    if (s.liste.some(function (f) { return horsChamps(f); })) {
      /* enCours avant le rechargement : sinon le garde-fou `beforeunload`
         redemandait « quitter la page ? » juste après qu'on ait confirmé
         « annuler les modifications ». Deux questions pour une décision. */
      enCours = true;
      location.reload();
      return;
    }

    s.liste.forEach(function (f) {
      f.reset();
      // form.reset() ignore les éditeurs riches : on les remet explicitement.
      editeurs().forEach(function (ed) {
        if (f.contains(ed.getElement()) && ed.resetContent) ed.resetContent();
      });
    });
    rafraichir();
  });

  // Garde-fou : quitter l'écran en laissant des modifications non enregistrées.
  /* ⚠️ L AVERTISSEMENT NE PORTE QUE SUR LES CHAMPS NON ENVOYÉS.
     Un brouillon de mise en page est DÉJÀ enregistré côté serveur — il attend
     seulement d être publié. Le compter ici aurait fait apparaître « quitter
     la page ? » à chaque navigation tant qu un brouillon existe, pour un
     travail qui n est pas en danger. */
  window.addEventListener('beforeunload', function (e) {
    if (enCours) return;
    var s = scope();

    /* ⚠️ JAMAIS D'AVERTISSEMENT SUR UN ÉCRAN D'ACTION. Le texte d'un mail en
       cours de rédaction n'est pas un réglage « non enregistré » : c'est la
       charge de l'action elle-même, et l'écran recharge la page dès l'envoi
       réussi. Sans cette réserve, cliquer sur « Envoyer le mail » déclenchait
       « Vos modifications risquent de ne pas être enregistrées » au moment
       précis où le mail venait de partir — la question la plus absurde
       possible. Un travail réellement en attente ailleurs reste couvert :
       scope() ne rend 'action' que si RIEN n'attend d'être enregistré. */
    if (s.mode === 'action') return;

    var champs = s.liste.reduce(function (t, f) { return t + compterForm(f); }, 0);
    if (champs > 0) { e.preventDefault(); e.returnValue = ''; }
  });
})();
</script>
