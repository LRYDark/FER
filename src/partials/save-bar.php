<?php
/**
 * Barre d'enregistrement flottante — composant partagé.
 * ---------------------------------------------------------------------------
 * Un seul bouton « Enregistrer » pour tout l'écran visible, à la place des
 * boutons « Sauvegarder » semés dans chaque carte.
 *
 * ELLE SAIT FAIRE DEUX CHOSES, parce que les écrans ne se ressemblent pas :
 *
 *  1. UN SEUL FORMULAIRE  —  <form class="oc-tabform" data-save-flags="a=1|b=1">
 *     L'écran a pu être fusionné en un formulaire unique (Réglages). On lui
 *     ajoute les drapeaux et on l'envoie d'un coup : tous les gestionnaires
 *     PHP s'enchaînent dans le même cycle.
 *
 *  2. PLUSIEURS FORMULAIRES  —  <form data-oc-save="nom_du_bouton">
 *     L'écran NE PEUT PAS être fusionné : ses volets mêlent des enregistrements
 *     et des actions indépendantes (connexion Google, suppression d'un abonné)
 *     qui ont chacune leur propre formulaire. Imbriquer serait du HTML invalide.
 *     La barre envoie alors chaque formulaire MODIFIÉ un par un en fetch(),
 *     chacun avec le nom de bouton qu'il portait avant, puis recharge.
 *     Le jeton CSRF est stable pour la session : les envois successifs passent.
 *
 * Dans les deux cas, AUCUN gestionnaire PHP n'est modifié.
 *
 * Options à définir avant l'inclusion :
 *   $saveBarSite = true;  → ajoute « Enregistrer et voir le site »
 *                           (la page doit gérer $_POST['oc_goto_site']).
 */
$saveBarSite = !empty($saveBarSite);
?>
<div class="oc-savebar" id="ocSaveBar" hidden>
  <div class="left">
    <button type="button" class="oc-btn is-ghost" id="ocResetBtn"
            data-confirm="Annuler les modifications non enregistrées de cet écran ?">
      <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
      Réinitialiser
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
  var libelleSave = btnSave.innerHTML;

  /* Un formulaire n'est retenu que s'il est RÉELLEMENT visible : les écrans à
     volets gardent tous les autres dans le document, masqués. */
  function visible(el) { return !!(el.offsetParent || el.getClientRects().length); }

  /* ⚠️ PORTÉE RECALCULÉE À CHAQUE FOIS, JAMAIS FIGÉE AU CHARGEMENT.
     Un formulaire peut être replié (bloc « collapse » de la page
     Applications) : figer la liste au départ l'aurait exclu pour de bon, et
     une clé saisie puis repliée n'aurait jamais été enregistrée — en silence.
     D'où la règle « visible OU déjà modifié ». Un volet inactif, lui, ne peut
     pas être modifié : il reste donc hors de portée. */
  function scope() {
    var un = Array.prototype.filter.call(document.querySelectorAll('form.oc-tabform'), visible);
    if (un.length) return { mode: 'unique', liste: un.slice(0, 1) };
    var tous = Array.prototype.slice.call(document.querySelectorAll('form[data-oc-save]'));
    return {
      mode: 'multiple',
      liste: tous.filter(function (f) { return visible(f) || compterForm(f) > 0; })
    };
  }

  if (!document.querySelector('form.oc-tabform, form[data-oc-save]')) return;
  bar.hidden = false;
  document.body.classList.add('oc-has-savebar');

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

  function compterForm(f) {
    var n = 0;
    Array.prototype.forEach.call(f.elements, function (el) {
      if (el.type === 'submit' || el.type === 'button' || el.type === 'hidden') return;
      if (estModifie(el)) n++;
    });
    editeurs().forEach(function (ed) {
      if (f.contains(ed.getElement()) && ed.isDirty && ed.isDirty()) n++;
    });
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

  function compter() {
    var n = scope().liste.reduce(function (t, f) { return t + compterForm(f); }, 0);
    if (enAttenteHorsFormulaire()) n++;
    return n;
  }

  var enCours = false;
  function rafraichir() {
    if (enCours) return;
    var n = compter();
    /* Rien à signaler = on ne signale rien. Une pastille « Tout est
       enregistré » en permanence occupe la barre pour dire qu'il ne s'est
       rien passé ; l'état au repos, c'est l'absence de message. */
    etat.hidden = (n === 0);
    /* Un brouillon de mise en page seul ne se décrit pas comme « 1
       modification » : on nomme ce qui est en attente. */
    if (n === 1 && enAttenteHorsFormulaire()) {
      txt.textContent = 'Mise en page non publiée';
    } else {
      txt.textContent = n + ' modification' + (n > 1 ? 's' : '') + ' non enregistrée' + (n > 1 ? 's' : '');
    }
    btnSave.disabled = btnReset.disabled = (n === 0);
    if (btnSite) btnSite.disabled = (n === 0);
  }

  /* Écoute sur le document, pas sur chaque formulaire : les événements
     remontent, et un formulaire affiché après coup est pris en compte sans
     qu'on ait à le rebrancher. */
  /* Point d'entrée pour les pages qui modifient l'état hors formulaire
     (l'éditeur d'accueil appelle ceci quand son brouillon change). */
  window.ocRefreshSaveBar = rafraichir;

  document.addEventListener('input',  rafraichir);
  document.addEventListener('change', rafraichir);
  rafraichir();

  // Les éditeurs s'initialisent après nous : on repasse brancher les nouveaux.
  setInterval(function () {
    editeurs().forEach(function (ed) {
      if (ed._ocBranche) return;
      ed._ocBranche = true;
      ed.on('input change keyup SetContent Undo Redo', rafraichir);
    });
  }, 1500);

  function debutEnvoi() {
    enCours = true;
    etat.hidden = false;
    etat.dataset.state = 'saving';
    txt.textContent = 'Enregistrement…';
    btnSave.disabled = btnReset.disabled = true;
    if (btnSite) btnSite.disabled = true;
    btnSave.innerHTML = '<span class="oc-spin"></span>Enregistrement…';
    /* TinyMCE se branche sur l'événement `submit`, que form.submit() et
       fetch() ne déclenchent PAS : sans ce triggerSave(), on renverrait
       l'ancien texte. */
    if (window.tinymce && tinymce.triggerSave) tinymce.triggerSave();
  }

  function echec(message) {
    enCours = false;
    etat.hidden = false;
    etat.dataset.state = 'error';
    txt.textContent = message;
    btnSave.innerHTML = libelleSave;
    btnSave.disabled = btnReset.disabled = false;
    if (btnSite) btnSite.disabled = false;
  }

  // ── Mode 1 : un seul formulaire, un seul envoi ───────────────────────────
  function envoyerUnique(versLeSite) {
    var f = scope().liste[0];
    debutEnvoi();
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

  /* ── Mode 2 : plusieurs formulaires, envoyés l'un après l'autre ──────────
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
    if (scope().mode === 'unique') envoyerUnique(versLeSite);
    else envoyerMultiple();
  }

  btnSave.addEventListener('click', function () { envoyer(false); });
  if (btnSite) btnSite.addEventListener('click', function () { envoyer(true); });

  // Réinitialiser = revenir aux valeurs du serveur, sans recharger la page.
  btnReset.addEventListener('click', function () {
    scope().liste.forEach(function (f) {
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
    var champs = scope().liste.reduce(function (t, f) { return t + compterForm(f); }, 0);
    if (champs > 0) { e.preventDefault(); e.returnValue = ''; }
  });
})();
</script>
