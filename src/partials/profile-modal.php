<?php /* Modal Profil — inclus dans navbar-admin.php */ ?>

<!-- ═══════ MODAL PROFIL ═══════ -->
<div id="profileModalOverlay" class="pf-overlay" aria-hidden="true">
  <div class="pf-modal" role="dialog" aria-labelledby="pfModalTitle">

    <!-- En-tête -->
    <div class="pf-header">
      <div class="pf-header-left">
        <div class="pf-avatar"><?= strtoupper(substr($_SESSION['email'] ?? 'U', 0, 1)) ?></div>
        <div>
          <h2 class="pf-title" id="pfModalTitle">Mon profil</h2>
          <p class="pf-header-email"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></p>
        </div>
      </div>
      <button class="pf-close" id="pfClose" type="button" aria-label="Fermer">&times;</button>
    </div>

    <!-- Onglets -->
    <div class="pf-tabs" role="tablist">
      <button class="pf-tab active" data-tab="password" role="tab" aria-selected="true">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Mot de passe
      </button>
      <button class="pf-tab" data-tab="auth-methods" role="tab" aria-selected="false">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Autres méthodes de connexion
      </button>
      <button class="pf-tab" data-tab="appearance" role="tab" aria-selected="false">
        <svg viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
        Apparence
      </button>
    </div>

    <!-- Contenu -->
    <div class="pf-body">

      <!-- ══════════════════════════════════════════════
           Onglet 1 — Mot de passe
      ══════════════════════════════════════════════ -->
      <div class="pf-panel active" data-panel="password">

        <div class="pf-section-header">
          <div>
            <div class="pf-section-title">Changer le mot de passe</div>
            <p class="pf-hint">Votre nouveau mot de passe doit respecter les règles de sécurité ci-dessous.</p>
          </div>
        </div>

        <div id="pfPwdMsg" class="pf-msg" style="display:none"></div>

        <form id="pfPwdForm" novalidate>
          <input type="text" name="username" value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" autocomplete="username" style="display:none" aria-hidden="true" tabindex="-1">
          <div class="pf-grid-2">
            <div class="pf-field pf-col-full">
              <label>Mot de passe actuel</label>
              <div class="pf-input-wrap">
                <input type="password" id="pfOldPwd" class="pf-input" placeholder="Entrez votre mot de passe actuel" autocomplete="current-password" required>
                <button type="button" class="pf-eye" data-target="pfOldPwd" tabindex="-1">
                  <svg viewBox="0 0 24 24"><path d="M1 12S5 4 12 4s11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="pf-field">
              <label>Nouveau mot de passe</label>
              <div class="pf-input-wrap">
                <input type="password" id="pfNewPwd" class="pf-input" placeholder="Nouveau mot de passe" autocomplete="new-password" required>
                <button type="button" class="pf-eye" data-target="pfNewPwd" tabindex="-1">
                  <svg viewBox="0 0 24 24"><path d="M1 12S5 4 12 4s11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="pf-field">
              <label>Confirmer le nouveau mot de passe</label>
              <div class="pf-input-wrap">
                <input type="password" id="pfConfirmPwd" class="pf-input" placeholder="Confirmer le nouveau mot de passe" autocomplete="new-password" required>
                <button type="button" class="pf-eye" data-target="pfConfirmPwd" tabindex="-1">
                  <svg viewBox="0 0 24 24"><path d="M1 12S5 4 12 4s11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
          </div>

          <!-- Checklist -->
          <ul class="pf-pwd-checks">
            <li id="pfck-len"   class="pf-check"><span class="pf-ck-icon"></span>Au moins 14 caractères</li>
            <li id="pfck-upper" class="pf-check"><span class="pf-ck-icon"></span>Au moins une majuscule</li>
            <li id="pfck-digit" class="pf-check"><span class="pf-ck-icon"></span>Au moins un chiffre</li>
            <li id="pfck-spec"  class="pf-check"><span class="pf-ck-icon"></span>Au moins un caractère spécial</li>
            <li id="pfck-match" class="pf-check"><span class="pf-ck-icon"></span>Les mots de passe correspondent</li>
          </ul>

          <div class="pf-form-footer">
            <button type="submit" id="pfPwdBtn" class="pf-btn" disabled>Enregistrer le nouveau mot de passe</button>
          </div>
        </form>
      </div>

      <!-- ══════════════════════════════════════════════
           Onglet 2 — Autres méthodes de connexion
      ══════════════════════════════════════════════ -->
      <div class="pf-panel" data-panel="auth-methods">

        <!-- Méthode par défaut (visible seulement si 2+ méthodes actives) -->
        <div id="pfDefaultMethodSection" class="pf-default-card" style="display:none">
          <div class="pf-default-card-header">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 8 12 12 14 14"/></svg>
            <div>
              <div class="pf-section-title" style="margin:0">Méthode de connexion par défaut</div>
              <p class="pf-hint" style="margin:2px 0 0">Utilisée automatiquement lors de la connexion</p>
            </div>
          </div>
          <div id="pfDefaultBtns" class="pf-default-btns"></div>
        </div>

        <!-- ─── Séparateur ─── -->
        <div class="pf-divider">
          <div class="pf-divider-line"></div>
          <span>Application d'authentification</span>
          <div class="pf-divider-line"></div>
        </div>

        <!-- Section TOTP -->
        <div class="pf-method-section">

          <!-- Ligne statut TOTP -->
          <div id="pfTotpStatus" class="pf-method-row">
            <div class="pf-method-icon pf-icon-totp">
              <svg viewBox="0 0 24 24"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <div class="pf-method-info">
              <div class="pf-method-name">Authentificateur TOTP
                <span id="pfTotpBadge" class="pf-badge pf-badge-off">Désactivé</span>
              </div>
              <p class="pf-method-desc">Google Authenticator, Authy, Microsoft Authenticator…</p>
            </div>
            <div class="pf-method-actions">
              <button id="pfTotpSetupBtn" class="pf-btn pf-btn-outline pf-btn-sm" type="button">Configurer</button>
              <button id="pfTotpDisableBtn" class="pf-btn pf-btn-danger pf-btn-sm" type="button" style="display:none">Désactiver</button>
            </div>
          </div>

          <!-- Vue setup TOTP (QR + vérification) -->
          <div id="pfTotpSetup" class="pf-totp-setup" style="display:none">
            <p class="pf-hint" style="margin-bottom:16px">
              Scannez ce QR code avec votre application d'authentification, puis entrez le code à 6 chiffres pour confirmer l'activation.
            </p>
            <div class="pf-qr-row">
              <div class="pf-qr-box">
                <div id="pfQrCode"></div>
              </div>
              <div class="pf-qr-side">
                <p class="pf-hint" style="margin-bottom:6px">Ou entrez manuellement ce secret :</p>
                <code id="pfTotpSecretDisplay" class="pf-secret-code"></code>
                <p class="pf-hint" style="margin-top:12px;margin-bottom:0">Entrez ensuite le code généré par votre application pour confirmer.</p>
              </div>
            </div>
            <div id="pfTotpSetupMsg" class="pf-msg" style="display:none"></div>
            <div class="pf-totp-verify-row">
              <input type="text" id="pfTotpCode" class="pf-input pf-input-code" placeholder="000 000" maxlength="6" inputmode="numeric" pattern="[0-9]{6}">
              <button id="pfTotpVerifyBtn" class="pf-btn" type="button">Activer</button>
              <button id="pfTotpCancelBtn" class="pf-btn pf-btn-ghost" type="button">Annuler</button>
            </div>
          </div>

          <div id="pfTotpMsg" class="pf-msg" style="display:none;margin-top:8px"></div>
        </div>

        <!-- ─── Séparateur ─── -->
        <div class="pf-divider">
          <div class="pf-divider-line"></div>
          <span>Clés d'accès</span>
          <div class="pf-divider-line"></div>
        </div>

        <!-- Section Passkeys -->
        <div class="pf-method-section">
          <div class="pf-method-row" style="margin-bottom:12px">
            <div class="pf-method-icon pf-icon-passkey">
              <svg viewBox="0 0 24 24"><path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
            </div>
            <div class="pf-method-info">
              <div class="pf-method-name">Clés d'accès (Passkeys)</div>
              <p class="pf-method-desc">Empreinte digitale, Windows Hello, Face ID, clé FIDO2…</p>
            </div>
            <div class="pf-method-actions">
              <button id="pfAddPasskeyBtn" class="pf-btn pf-btn-outline pf-btn-sm" type="button">
                <svg viewBox="0 0 24 24" style="width:13px;height:13px" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Ajouter
              </button>
            </div>
          </div>
          <div id="pfPasskeyMsg" class="pf-msg" style="display:none"></div>
          <div id="pfPasskeyList" class="pf-pk-list">
            <div class="pf-loading">Chargement…</div>
          </div>
        </div>

      </div><!-- /panel auth-methods -->

      <!-- ══════════════════════════════════════════════
           Onglet 3 — Apparence (thème / accent / police, par utilisateur)
      ══════════════════════════════════════════════ -->
      <div class="pf-panel" data-panel="appearance">

        <div class="pf-section-header">
          <div>
            <div class="pf-section-title">Apparence de votre compte</div>
            <p class="pf-hint">Ces préférences ne concernent que votre compte. Le site public garde son propre thème (Réglages → Personnalisation).</p>
          </div>
        </div>

        <div id="pfAppearanceMsg" class="pf-msg" style="display:none"></div>

        <?php
          $__themeBtns = function (string $segId, string $attr, string $current) {
              $mk = function (string $val, string $label, string $svg) use ($attr, $current) {
                  $active = $current === $val ? ' class="is-active"' : '';
                  return '<button type="button" data-' . $attr . '="' . $val . '"' . $active . '>' . $svg . ' ' . $label . '</button>';
              };
              $sun  = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>';
              $moon = '<svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
              $sys  = '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>';
              echo '<div class="jr-seg" id="' . $segId . '">'
                 . $mk('light', 'Clair', $sun) . $mk('dark', 'Sombre', $moon) . $mk('system', 'Système', $sys)
                 . '</div>';
          };
        ?>

        <div class="pf-appearance-row">
          <label>Thème de l'administration</label>
          <?php $__themeBtns('pfThemeSeg', 'theme-choice', $jrTheme ?? 'light'); ?>
        </div>

        <div class="pf-appearance-row">
          <label>Thème des pages de connexion</label>
          <?php $__themeBtns('pfLoginThemeSeg', 'login-theme-choice', $jrLoginTheme ?? 'light'); ?>
          <p class="pf-hint" style="margin-top:6px">La page de connexion, de réinitialisation et de mise à jour — indépendant de l'administration.</p>
        </div>

        <div class="pf-appearance-row">
          <label>Couleur d'accent</label>
          <div class="accent-options" id="pfAccentOptions">
            <button type="button" class="accent-option<?= ($jrAccent ?? 'rose') === 'rose' ? ' is-active' : '' ?>" data-accent-set="rose"><span class="dot" style="background:<?= htmlspecialchars($jrSitePrimary ?? '#db2777') ?>"></span>Rose (site)</button>
            <button type="button" class="accent-option<?= ($jrAccent ?? '') === 'blue' ? ' is-active' : '' ?>" data-accent-set="blue"><span class="dot"></span>Bleu</button>
            <button type="button" class="accent-option<?= ($jrAccent ?? '') === 'teal' ? ' is-active' : '' ?>" data-accent-set="teal"><span class="dot"></span>Teal</button>
            <button type="button" class="accent-option<?= ($jrAccent ?? '') === 'violet' ? ' is-active' : '' ?>" data-accent-set="violet"><span class="dot"></span>Violet</button>
            <button type="button" class="accent-option<?= ($jrAccent ?? '') === 'emerald' ? ' is-active' : '' ?>" data-accent-set="emerald"><span class="dot"></span>Émeraude</button>
            <label class="accent-option<?= ($jrAccent ?? '') === 'custom' ? ' is-active' : '' ?>" id="pfAccentCustomBtn">
              <input type="color" id="pfAccentCustomInput" value="<?= htmlspecialchars($jrPrefs['admin_accent_custom'] ?? '#3D63F0') ?>">
              Personnalisée
            </label>
          </div>
        </div>

        <div class="pf-appearance-row">
          <label>Police d'écriture (admin uniquement)</label>
          <?php
            $__pfFonts = $jrFonts ?? jr_admin_fonts();
            $__pfFontLbl = fn(string $n) => $n === 'Inter' ? 'Inter (défaut)' : ($n === 'system-ui' ? 'Police du système' : $n);
            $__pfCur = $jrFont ?? 'Inter';
          ?>
          <div class="pf-font-picker" id="pfFontPicker">
            <div class="pf-font-toggle" id="pfFontToggle" role="button" tabindex="0">
              <span id="pfFontLabel" style="font-family:<?= $__pfFonts[$__pfCur][0] ?? 'inherit' ?>"><?= htmlspecialchars($__pfFontLbl($__pfCur)) ?></span>
              <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="pf-font-dropdown" id="pfFontDropdown">
              <?php foreach ($__pfFonts as $__fName => $__fDef): ?>
                <div class="pf-font-item<?= $__pfCur === $__fName ? ' active' : '' ?><?= $__fDef[2] ? ' is-custom' : '' ?>"
                     data-value="<?= htmlspecialchars($__fName) ?>"
                     style="font-family:<?= $__fDef[0] ?>"><?= htmlspecialchars($__pfFontLbl($__fName)) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
          <p class="pf-hint" style="margin-top:6px">La police du site public se règle dans Réglages → Personnalisation → Thème du site.</p>
        </div>

      </div><!-- /panel appearance -->

    </div><!-- /pf-body -->
  </div>
</div>

