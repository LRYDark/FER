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

    </div><!-- /pf-body -->
  </div>
</div>

<style>
/* ═══ Profile modal ═══════════════════════════════════════════════════════ */
.pf-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.55); z-index: 9000;
  align-items: center; justify-content: center; padding: 12px;
}
.pf-overlay.open { display: flex; }

.pf-modal {
  background: var(--oc-bg, #fff);
  border-radius: 18px; width: 100%; max-width: 680px;
  max-height: 92vh; display: flex; flex-direction: column;
  box-shadow: 0 24px 80px rgba(0,0,0,.28); overflow: hidden;
}

/* ── En-tête ── */
.pf-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 24px 18px;
  border-bottom: 1px solid var(--oc-border, #e5e7eb); flex-shrink: 0;
}
.pf-header-left { display: flex; align-items: center; gap: 14px; }
.pf-avatar {
  width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--primary, #db2777), var(--primary-hover, #9d174d));
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; font-weight: 700; color: #fff;
}
.pf-title { font-size: 17px; font-weight: 700; margin: 0; color: var(--oc-text, #111827); }
.pf-header-email { font-size: 13px; color: var(--oc-text-muted, #6b7280); margin: 2px 0 0; }
.pf-close {
  background: none; border: none; cursor: pointer;
  font-size: 24px; line-height: 1; color: var(--oc-text-muted, #9ca3af); padding: 2px 6px;
  border-radius: 6px; transition: background .15s, color .15s;
}
.pf-close:hover { background: var(--oc-border,#f3f4f6); color: var(--oc-text,#111827); }

/* ── Onglets ── */
.pf-tabs {
  display: flex; border-bottom: 1px solid var(--oc-border, #e5e7eb);
  flex-shrink: 0; overflow-x: auto; scrollbar-width: none; padding: 0 8px;
}
.pf-tabs::-webkit-scrollbar { display: none; }
.pf-tab {
  display: flex; align-items: center; gap: 7px;
  padding: 13px 18px; background: none; border: none;
  border-bottom: 2px solid transparent; font-size: 13px; font-weight: 500;
  cursor: pointer; color: var(--oc-text-muted, #6b7280); white-space: nowrap;
  transition: color .15s, border-color .15s;
}
.pf-tab svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pf-tab:hover { color: var(--oc-primary, #db2777); }
.pf-tab.active { color: var(--oc-primary, #db2777); border-bottom-color: var(--oc-primary, #db2777); }

/* ── Corps ── */
.pf-body { overflow-y: auto; flex: 1; }
.pf-panel { display: none; padding: 24px 28px; }
.pf-panel.active { display: block; }

.pf-section-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
.pf-section-title { font-size: 14px; font-weight: 600; color: var(--oc-text, #111827); margin-bottom: 4px; }
.pf-hint { font-size: 13px; color: var(--oc-text-muted, #6b7280); margin-bottom: 16px; line-height: 1.5; }
.pf-form-footer { margin-top: 20px; }

/* ── Grille 2 colonnes ── */
.pf-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.pf-col-full { grid-column: 1 / -1; }
@media (max-width: 520px) { .pf-grid-2 { grid-template-columns: 1fr; } .pf-col-full { grid-column: 1; } }

/* ── Champs ── */
.pf-field { margin-bottom: 14px; }
.pf-field label { display: block; font-size: 13px; font-weight: 500; color: var(--oc-text,#111827); margin-bottom: 6px; }
.pf-input-wrap { position: relative; }
.pf-input {
  width: 100%; box-sizing: border-box;
  padding: 9px 36px 9px 12px; border-radius: 8px;
  border: 1px solid var(--oc-border,#d1d5db); font-size: 14px;
  background: var(--oc-bg,#fff); color: var(--oc-text,#111827);
  transition: border-color .15s;
}
.pf-input:focus { outline: none; border-color: var(--oc-primary,#db2777); }
.pf-input-code { letter-spacing: 8px; text-align: center; font-size: 22px; font-weight: 600; padding: 10px 12px; }
.pf-eye {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; padding: 4px;
  color: var(--oc-text-muted,#9ca3af);
}
.pf-eye svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; display: block; }
.pf-eye:hover { color: var(--oc-text,#374151); }

/* ── Checklist MDP ── */
.pf-pwd-checks {
  list-style: none; margin: 0 0 4px; padding: 8px 12px;
  background: var(--oc-bg-muted,#f8fafc); border-radius: 8px;
  border: 1px solid var(--oc-border,#e5e7eb);
  display: grid; grid-template-columns: 1fr 1fr; gap: 5px 16px;
}
@media (max-width: 520px) { .pf-pwd-checks { grid-template-columns: 1fr; } }
.pf-check { font-size: 12px; color: var(--oc-text-muted,#9ca3af); display: flex; align-items: center; gap: 6px; }
.pf-check.ok { color: #16a34a; }
.pf-ck-icon { width: 14px; height: 14px; border-radius: 50%; border: 1.5px solid currentColor; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 9px; }
.pf-check.ok .pf-ck-icon::before { content: '✓'; }
.pf-check:not(.ok) .pf-ck-icon::before { content: ''; }

/* ── Boutons ── */
.pf-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 9px 20px; border-radius: 8px; font-size: 14px; font-weight: 600;
  cursor: pointer; border: none;
  background: var(--oc-primary,#db2777); color: #fff;
  transition: opacity .15s;
}
.pf-btn:disabled { opacity: .45; cursor: not-allowed; }
.pf-btn:not(:disabled):hover { opacity: .88; }
.pf-btn-sm { padding: 6px 14px; font-size: 13px; }
.pf-btn-outline { background: transparent; border: 1.5px solid var(--oc-primary,#db2777); color: var(--oc-primary,#db2777); }
.pf-btn-outline:not(:disabled):hover { background: rgba(219,39,119,.07); }
.pf-btn-danger { background: #ef4444; }
.pf-btn-danger:not(:disabled):hover { background: #dc2626; opacity: 1; }
.pf-btn-ghost { background: transparent; color: var(--oc-text-muted,#6b7280); border: 1px solid var(--oc-border,#d1d5db); }
.pf-btn-ghost:not(:disabled):hover { background: var(--oc-border,#f3f4f6); }
.pf-btn-row { display: flex; gap: 10px; flex-wrap: wrap; }

/* ── Messages ── */
.pf-msg { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
.pf-msg.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.pf-msg.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* ── Badges ── */
.pf-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-left: 8px; vertical-align: middle; }
.pf-badge-on  { background: #dcfce7; color: #166534; }
.pf-badge-off { background: #f1f5f9; color: #64748b; }

/* ── Méthode par défaut ── */
.pf-default-card {
  background: linear-gradient(135deg, rgba(219,39,119,.06), rgba(147,51,234,.06));
  border: 1px solid rgba(219,39,119,.2);
  border-radius: 12px; padding: 16px 18px; margin-bottom: 4px;
}
.pf-default-card-header { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
.pf-default-card-header svg { width: 20px; height: 20px; stroke: var(--oc-primary,#db2777); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; margin-top: 2px; }
.pf-default-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.pf-default-btn {
  display: flex; align-items: center; gap: 7px;
  padding: 8px 16px; border-radius: 20px; cursor: pointer;
  font-size: 13px; font-weight: 500;
  border: 1.5px solid var(--oc-border,#d1d5db);
  background: var(--oc-bg,#fff); color: var(--oc-text-muted,#6b7280);
  transition: all .15s;
}
.pf-default-btn svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pf-default-btn:hover { border-color: var(--oc-primary,#db2777); color: var(--oc-primary,#db2777); }
.pf-default-btn.active { background: var(--oc-primary,#db2777); color: #fff; border-color: var(--oc-primary,#db2777); }

/* ── Séparateurs ── */
.pf-divider { display: flex; align-items: center; gap: 12px; margin: 20px 0 16px; }
.pf-divider-line { flex: 1; height: 1px; background: var(--oc-border,#e5e7eb); }
.pf-divider span { font-size: 11px; font-weight: 600; color: var(--oc-text-muted,#9ca3af); text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }

/* ── Sections méthode (ligne icône + info + actions) ── */
.pf-method-section { margin-bottom: 4px; }
.pf-method-row {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 14px; border-radius: 10px;
  background: var(--oc-bg-muted,#f8fafc);
  border: 1px solid var(--oc-border,#e5e7eb);
}
.pf-method-icon {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.pf-icon-totp    { background: rgba(219,39,119,.1); }
.pf-icon-totp    svg { stroke: var(--oc-primary,#db2777); }
.pf-icon-passkey { background: rgba(79,70,229,.1); }
.pf-icon-passkey svg { stroke: #4f46e5; }
.pf-method-icon svg { width: 20px; height: 20px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pf-method-info { flex: 1; min-width: 0; }
.pf-method-name { font-size: 14px; font-weight: 600; color: var(--oc-text,#111827); }
.pf-method-desc { font-size: 12px; color: var(--oc-text-muted,#6b7280); margin: 3px 0 0; }
.pf-method-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* ── Setup TOTP ── */
.pf-totp-setup { padding: 16px; background: var(--oc-bg-muted,#f8fafc); border-radius: 10px; margin-top: 10px; border: 1px solid var(--oc-border,#e5e7eb); }
.pf-qr-row { display: flex; gap: 20px; align-items: flex-start; margin-bottom: 16px; }
@media (max-width: 480px) { .pf-qr-row { flex-direction: column; align-items: center; } }
.pf-qr-box { flex-shrink: 0; background: #fff; padding: 10px; border-radius: 8px; border: 1px solid var(--oc-border,#e5e7eb); }
.pf-qr-box img, .pf-qr-box canvas { display: block; }
.pf-qr-side { flex: 1; }
.pf-secret-code {
  display: block; margin-top: 4px; font-family: monospace; font-size: 13px; letter-spacing: 2px;
  background: #fff; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--oc-border,#e2e8f0);
  user-select: all; word-break: break-all; line-height: 1.6;
}
.pf-totp-verify-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.pf-totp-verify-row .pf-input { max-width: 160px; }

/* ── Liste passkeys ── */
.pf-pk-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 4px; }
.pf-pk-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; border-radius: 10px;
  border: 1px solid var(--oc-border,#e5e7eb); background: var(--oc-bg,#fff);
}
.pf-pk-icon { width: 34px; height: 34px; border-radius: 8px; background: rgba(79,70,229,.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pf-pk-icon svg { width: 16px; height: 16px; stroke: #4f46e5; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pf-pk-item-info { flex: 1; min-width: 0; }
.pf-pk-name { font-size: 14px; font-weight: 500; color: var(--oc-text,#111827); }
.pf-pk-date { font-size: 12px; color: var(--oc-text-muted,#6b7280); margin-top: 2px; }
.pf-pk-delete {
  background: none; border: none; cursor: pointer;
  color: var(--oc-text-muted,#9ca3af); padding: 6px; border-radius: 6px;
  transition: color .15s, background .15s; flex-shrink: 0;
}
.pf-pk-delete:hover { color: #ef4444; background: #fef2f2; }
.pf-pk-delete svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; display: block; }
.pf-loading, .pf-pk-empty { color: var(--oc-text-muted,#9ca3af); font-size: 13px; font-style: italic; padding: 4px 0; }
</style>
