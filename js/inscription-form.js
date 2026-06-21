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

  /** Canonicalise une saisie de date de naissance. Retourne '' si non reconnu. */
  function normalizeBirthValue(v) {
    v = (v == null ? '' : String(v)).trim();
    if (!v) return '';

    // Âge : 1 à 3 chiffres (≤ 120) → année = année courante − âge.
    if (/^\d{1,3}$/.test(v)) {
      var age = parseInt(v, 10);
      if (age >= 0 && age <= 120) return String(new Date().getFullYear() - age);
      return '';
    }

    // Année seule : 4 chiffres.
    if (/^\d{4}$/.test(v)) return v;

    // Date complète : JJ/MM/AAAA (séparateurs / ou -).
    v = v.replace(/-/g, '/').replace(/\s+/g, '');
    var p = v.split('/');
    if (p.length !== 3) return '';
    var d = p[0].padStart(2, '0');
    var m = p[1].padStart(2, '0');
    var y = p[2].padStart(2, '0');
    // Saisie au format AAAA/MM/JJ → on rétablit l'ordre JJ/MM/AAAA.
    if (/^\d{4}$/.test(d)) { var t = d; d = y; y = t; }
    if (+d < 1 || +d > 31 || +m < 1 || +m > 12 || !/^\d{4}$/.test(y)) return '';
    return d + '/' + m + '/' + y;
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

  /** Calcule l'âge à partir d'une valeur AAAA, AAAA-MM-JJ ou JJ/MM/AAAA. */
  function ageFromBirth(b) {
    if (!b) return null;
    var y, m = 1, d = 1, parts;
    if (/^\d{4}$/.test(b)) {
      y = +b;
    } else if (/^\d{4}-\d{2}-\d{2}$/.test(b)) {
      parts = b.split('-').map(Number); y = parts[0]; m = parts[1]; d = parts[2];
    } else if (/^\d{2}\/\d{2}\/\d{4}$/.test(b)) {
      parts = b.split('/').map(Number); d = parts[0]; m = parts[1]; y = parts[2];
    } else {
      return null;
    }
    var t = new Date();
    var age = t.getFullYear() - y;
    if (t < new Date(t.getFullYear(), m - 1, d)) age--; // anniversaire pas encore passé
    return age;
  }

  /** Met à jour l'indice de saisie + l'affichage du bloc responsable légal. */
  function applyState(formEl) {
    if (!formEl) return;
    var birth = formEl.querySelector('[name="naissance"]');
    var block = formEl.querySelector('[data-guardian-block]');
    var hint  = formEl.querySelector('.birthdate-hint');

    var raw  = birth ? birth.value : '';
    var norm = normalizeBirthValue(raw);
    var age  = ageFromBirth(norm);

    if (hint) {
      if (!String(raw).trim()) {
        hint.textContent = '';
      } else if (!norm) {
        hint.textContent = 'Format non reconnu — saisissez JJ/MM/AAAA, une année ou un âge.';
      } else {
        var parts = [];
        parts.push(/^\d{4}$/.test(norm) ? ('année ' + norm) : ('né(e) le ' + norm));
        if (age != null) parts.push('âge ' + age + ' ans');
        hint.textContent = '→ ' + parts.join(' · ');
      }
    }

    if (block) {
      // Seuil d'âge + caractère obligatoire lus sur le bloc (paramétrables côté admin).
      var minorAge = parseInt(block.getAttribute('data-minor-age'), 10) || MINOR_AGE;
      var required = block.getAttribute('data-guardian-required') !== '0';
      var isMinor = (age != null && age < minorAge);
      block.style.display = isMinor ? '' : 'none';
      var inputs = block.querySelectorAll('[data-guardian]');
      for (var i = 0; i < inputs.length; i++) {
        if (isMinor && required) inputs[i].setAttribute('required', 'required');
        else inputs[i].removeAttribute('required');
      }
    }
  }

  /** Câble un formulaire (idempotent). */
  function initForm(formEl) {
    if (!formEl || formEl.dataset.ferInit) return;
    formEl.dataset.ferInit = '1';
    var birth = formEl.querySelector('[name="naissance"]');
    if (birth) {
      // On évalue (indice + bloc responsable) quand l'utilisateur QUITTE le champ
      // (blur / passage à un autre champ), jamais pendant la frappe : aucun flash.
      // La vérification est de toute façon reforcée à l'enregistrement (ensureGuardian).
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
    var inputs = block.querySelectorAll('[data-guardian]');
    for (var i = 0; i < inputs.length; i++) {
      if (!inputs[i].value.trim()) {
        inputs[i].focus();
        alert("Inscription d'un mineur : merci d'indiquer le nom et le prénom du responsable légal.");
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
    var textarea = formEl.querySelector('[name="commentaire"]');
    if (!textarea) return;

    var birth = formEl.querySelector('[name="naissance"]');
    var block = formEl.querySelector('[data-guardian-block]');
    var age = birth ? ageFromBirth(normalizeBirthValue(birth.value)) : null;
    var isMinor = (age != null && age < MINOR_AGE);

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

    if (isMinor && (nom || prenom)) {
      var blockTxt = GUARDIAN_MARKER + ' (mineur) : Validé\nNom : ' + nom + '\nPrénom : ' + prenom;
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
