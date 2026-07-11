/**
 * inscription-form.js — Logique partagée des formulaires d'inscription.
 * Chargé par inc/saisie.php, inc/dashboard.php et public/register.php.
 *
 * Fournit, sous window.FERInscription :
 *   - normalizeBirthValue(str) : canonicalise une saisie de naissance
 *       • âge (1 à 3 chiffres, ≤ 120) → année = année courante − âge
 *       • année (4 chiffres)        → telle quelle
 *       • date JJ/MM/AAAA (ou -)    → JJ/MM/AAAA
 *       • sinon                      → '' (non reconnu)
 *   - normalizeBirth(fd)        : applique la canonicalisation au champ
 *                                 « naissance » d'un FormData (handlers fetch).
 *   - ageFromBirth(str)         : âge (nombre) ou null.
 *   - initForm(formEl)          : câble l'affichage dynamique de l'indice +
 *                                 du bloc « responsable légal » (mineur).
 *   - refresh(formEl)           : recalcule l'état (après pré-remplissage en édition).
 *   - composeComment(formEl)    : injecte l'autorisation du représentant légal
 *                                 dans le champ « commentaire » (idempotent).
 *
 * Volontairement en JS « vanilla » (aucune dépendance jQuery / Bootstrap) afin
 * de fonctionner aussi sur le formulaire public.
 */
(function () {
  'use strict';

  var MINOR_AGE = 18;
  var GUARDIAN_MARKER = 'Autorisation du représentant légal';

  /**
   * Canonicalise une saisie « naissance » en ÂGE (chaîne d'entier). Retourne ''
   * si non reconnu. Le champ ne stocke plus une date : on ne garde que l'âge.
   *   - un âge (1 à 3 chiffres ≤ 120)              → conservé tel quel ;
   *   - une année (AAAA, 1900..année courante)     → âge = année courante − année ;
   *   - une date complète (JJ/MM/AAAA ou AAAA/MM/JJ) → âge déduit (à l'import Excel
   *     notamment, où une vraie date de naissance peut arriver).
   */
  function normalizeBirthValue(v) {
    v = (v == null ? '' : String(v)).trim();
    if (!v) return '';
    var nowY = new Date().getFullYear();

    // Âge : 1 à 3 chiffres (≤ 120) → conservé tel quel.
    if (/^\d{1,3}$/.test(v)) {
      var age = parseInt(v, 10);
      if (age >= 0 && age <= 120) return String(age);
      return '';
    }

    // Année seule : 4 chiffres → âge = année courante − année.
    if (/^\d{4}$/.test(v)) {
      var y0 = parseInt(v, 10);
      if (y0 < 1900 || y0 > nowY) return '';
      return String(nowY - y0);
    }

    // Date complète : JJ/MM/AAAA (séparateurs / ou -) → âge déduit.
    v = v.replace(/-/g, '/').replace(/\s+/g, '');
    var p = v.split('/');
    if (p.length !== 3) return '';
    var d = p[0], m = p[1], y = p[2];
    // Saisie au format AAAA/MM/JJ → on rétablit l'ordre JJ/MM/AAAA.
    if (/^\d{4}$/.test(d)) { var t = d; d = y; y = t; }
    d = +d; m = +m; y = +y;
    if (d < 1 || d > 31 || m < 1 || m > 12 || !(y >= 1900 && y <= nowY)) return '';
    var age2 = nowY - y;
    var now = new Date();
    if (now < new Date(now.getFullYear(), m - 1, d)) age2--; // anniversaire pas encore passé
    return String(age2 < 0 ? 0 : age2);
  }

  /** Applique normalizeBirthValue au champ « naissance » d'un FormData. */
  function normalizeBirth(fd) {
    if (!fd || typeof fd.get !== 'function') return;
    var v = fd.get('naissance');
    if (v == null) return;
    if (!String(v).trim()) return; // champ laissé vide : on n'y touche pas
    var n = normalizeBirthValue(v);
    if (n) fd.set('naissance', n);
    else fd.delete('naissance');
  }

  /**
   * Calcule l'âge à partir d'une valeur stockée. Gère le NOUVEAU modèle (âge déjà
   * stocké : 1 à 3 chiffres ≤ 120 → renvoyé tel quel) ET les données héritées /
   * archives (année AAAA, date AAAA-MM-JJ ou JJ/MM/AAAA).
   * @param {string|number} b       Valeur stockée.
   * @param {number}        [refYear] Année de référence pour les données AU FORMAT
   *        année/date (archives) : ex. l'âge d'une archive 2023 se calcule sur 2023,
   *        pas sur l'année courante. Défaut : année courante.
   */
  function ageFromBirth(b, refYear) {
    if (b == null || b === '') return null;
    b = String(b).trim();
    if (!b) return null;
    var nowY = new Date().getFullYear();
    var ref = (refYear && +refYear > 1900) ? +refYear : nowY;

    // Âge déjà stocké (nouveau modèle).
    if (/^\d{1,3}$/.test(b)) {
      var a = parseInt(b, 10);
      return (a >= 0 && a <= 120) ? a : null;
    }

    // Données héritées / archives : année ou date.
    var y, m = 1, d = 1, parts, age;
    if (/^\d{4}$/.test(b)) {
      y = +b;
      if (y < 1900 || y > nowY) return null;
      age = ref - y;
    } else if (/^\d{4}-\d{2}-\d{2}$/.test(b)) {
      parts = b.split('-').map(Number); y = parts[0]; m = parts[1]; d = parts[2];
      age = ref - y;
      if (ref === nowY) { var t1 = new Date(); if (t1 < new Date(t1.getFullYear(), m - 1, d)) age--; }
    } else if (/^\d{2}\/\d{2}\/\d{4}$/.test(b)) {
      parts = b.split('/').map(Number); d = parts[0]; m = parts[1]; y = parts[2];
      age = ref - y;
      if (ref === nowY) { var t2 = new Date(); if (t2 < new Date(t2.getFullYear(), m - 1, d)) age--; }
    } else {
      return null;
    }
    // Garde-fou : un âge doit être plausible (0–120). Une date incohérente/future
    // (qui donnerait un âge négatif) ou aberrante → « pas d'âge ».
    return (age >= 0 && age <= 120) ? age : null;
  }

  /** Met à jour l'indice de saisie + l'affichage du bloc responsable légal. */
  function applyState(formEl) {
    if (!formEl) return;
    var birth = formEl.querySelector('[name="naissance"], [name$="[naissance]"]');
    var block = formEl.querySelector('[data-guardian-block]');
    var hint  = formEl.querySelector('.birthdate-hint');

    var raw  = birth ? birth.value : '';
    var norm = normalizeBirthValue(raw);
    var age  = ageFromBirth(norm);

    if (hint) {
      if (!String(raw).trim()) {
        hint.textContent = '';
      } else if (!norm || age == null) {
        hint.textContent = 'Format non reconnu — saisissez un âge ou une année (AAAA).';
      } else {
        hint.textContent = '→ âge ' + age + ' ans';
      }
    }

    var minorAge = block ? (parseInt(block.getAttribute('data-minor-age'), 10) || MINOR_AGE) : MINOR_AGE;
    var isMinor  = (age != null && age < minorAge);

    if (block) {
      // Seuil d'âge + caractère obligatoire lus sur le bloc (paramétrables côté admin).
      // Les champs personnalisés (data-guardian="custom") sont désormais À L'INTÉRIEUR
      // du bloc : ils s'affichent/se masquent avec lui, et suivent leur propre « requis ».
      var required = block.getAttribute('data-guardian-required') !== '0';
      block.style.display = isMinor ? '' : 'none';
      var inputs = block.querySelectorAll('[data-guardian]');
      for (var i = 0; i < inputs.length; i++) {
        var isCustom = inputs[i].getAttribute('data-guardian') === 'custom';
        var elReq = isCustom ? (inputs[i].getAttribute('data-guardian-req') === '1') : required;
        if (isMinor && elReq) inputs[i].setAttribute('required', 'required');
        else inputs[i].removeAttribute('required');
      }
    }
  }

  /** Câble un formulaire (idempotent). */
  function initForm(formEl) {
    if (!formEl || formEl.dataset.ferInit) return;
    formEl.dataset.ferInit = '1';
    var birth = formEl.querySelector('[name="naissance"], [name$="[naissance]"]');
    if (birth) {
      // Champ « Âge / année » : uniquement des chiffres (ni lettres ni ponctuation).
      birth.setAttribute('inputmode', 'numeric');
      birth.addEventListener('input', function () {
        var d = birth.value.replace(/\D+/g, '');
        if (d !== birth.value) birth.value = d;
      });
      // On évalue (bloc responsable) quand l'utilisateur QUITTE le champ (blur / changement),
      // jamais pendant la frappe. La vérification est reforcée à l'enregistrement.
      birth.addEventListener('change', function () { applyState(formEl); });
      birth.addEventListener('blur',   function () { applyState(formEl); });
    }
    applyState(formEl);
  }

  /**
   * À appeler à la soumission : garantit que le responsable légal est renseigné
   * pour un mineur. Synchronise d'abord l'affichage (indépendamment du debounce),
   * puis retourne false — en signalant le champ manquant — si c'est invalide.
   */
  function ensureGuardian(formEl) {
    if (!formEl) return true;
    applyState(formEl); // flush : affiche le bloc + pose `required` si mineur
    var block = formEl.querySelector('[data-guardian-block]');
    if (!block || block.style.display === 'none') return true; // pas un mineur / bloc masqué
    if (block.getAttribute('data-guardian-required') === '0') return true; // bloc facultatif
    // Nom/prénom du responsable (toujours requis si le bloc l'est) + champs
    // personnalisés dont le « requis » propre est activé.
    var inputs = block.querySelectorAll('[data-guardian]');
    for (var i = 0; i < inputs.length; i++) {
      var isCustom = inputs[i].getAttribute('data-guardian') === 'custom';
      if (isCustom && inputs[i].getAttribute('data-guardian-req') !== '1') continue; // custom facultatif
      if (!inputs[i].value.trim()) {
        inputs[i].focus();
        alert(isCustom
          ? "Inscription d'un mineur : merci de compléter les informations demandées pour le responsable légal."
          : "Inscription d'un mineur : merci d'indiquer le nom et le prénom du responsable légal.");
        return false;
      }
    }
    return true;
  }

  /**
   * Injecte l'autorisation du représentant légal dans le champ commentaire.
   * Idempotent : retire un éventuel bloc déjà présent avant de le réécrire,
   * pour ne pas dupliquer en cas de ré-enregistrement / édition.
   */
  function composeComment(formEl) {
    if (!formEl) return;
    var textarea = formEl.querySelector('[name="commentaire"], [name$="[commentaire]"]');
    if (!textarea) return;

    var birth = formEl.querySelector('[name="naissance"], [name$="[naissance]"]');
    var block = formEl.querySelector('[data-guardian-block]');
    var age = birth ? ageFromBirth(normalizeBirthValue(birth.value)) : null;
    // Seuil « mineur » paramétrable au niveau du bloc (cohérent avec applyState).
    var minorAge = block ? (parseInt(block.getAttribute('data-minor-age'), 10) || MINOR_AGE) : MINOR_AGE;
    var isMinor = (age != null && age < minorAge);

    // Texte libre = contenu actuel privé d'un éventuel bloc responsable.
    var val = textarea.value || '';
    var idx = val.indexOf(GUARDIAN_MARKER);
    if (idx >= 0) val = val.slice(0, idx);
    val = val.replace(/\s+$/, '');

    var nom = '', prenom = '';
    if (block) {
      var nEl = block.querySelector('[data-guardian="nom"]');
      var pEl = block.querySelector('[data-guardian="prenom"]');
      nom = nEl ? nEl.value.trim() : '';
      prenom = pEl ? pEl.value.trim() : '';
    }

    // Champs personnalisés du bloc responsable → lignes « Libellé : valeur ».
    // Portée LIMITÉE au bloc (ils vivent dedans) : sinon le commentaire de la personne
    // principale récupérerait aussi les champs des cartes « personne supplémentaire ».
    var extraLines = [];
    var customEls = block ? block.querySelectorAll('[data-guardian="custom"]') : [];
    for (var i = 0; i < customEls.length; i++) {
      var cv = (customEls[i].value || '').trim();
      if (cv) extraLines.push((customEls[i].getAttribute('data-guardian-key') || 'Info') + ' : ' + cv);
    }

    if (isMinor && (nom || prenom || extraLines.length)) {
      var blockTxt = GUARDIAN_MARKER + ' (mineur) : Validé\nNom : ' + nom + '\nPrénom : ' + prenom;
      if (extraLines.length) blockTxt += '\n' + extraLines.join('\n');
      textarea.value = val ? (val + '\n\n' + blockTxt) : blockTxt;
    } else {
      textarea.value = val;
    }
  }

  /** Câble automatiquement tous les formulaires concernés présents dans le DOM. */
  function autoInit() {
    var forms = document.querySelectorAll('form');
    for (var i = 0; i < forms.length; i++) {
      if (forms[i].querySelector('[name="naissance"], [data-guardian-block]')) {
        initForm(forms[i]);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

  window.FERInscription = {
    MINOR_AGE: MINOR_AGE,
    normalizeBirthValue: normalizeBirthValue,
    normalizeBirth: normalizeBirth,
    ageFromBirth: ageFromBirth,
    initForm: initForm,
    refresh: applyState,
    ensureGuardian: ensureGuardian,
    composeComment: composeComment
  };
})();
