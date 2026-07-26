# Test manuel de non-régression — garde-fou « catch-all » des mails

> **Pourquoi ce test.** La base de recette contient de vraies adresses d'inscrits.
> Un seul chemin d'envoi oublié suffit à écrire à des personnes réelles. Cette
> procédure vérifie que **tous** les points d'envoi passent par le garde-fou.

## 1. Mise en place

1. Administration → **Mails** → onglet *Gmail / SMTP* → carte **Garde-fou « catch-all »**.
2. Renseigner l'adresse de redirection (une boîte que vous relevez), cocher
   **Activer le garde-fou**, enregistrer.
3. Vérifier que le bandeau passe en orange : `ACTIF — mails redirigés`.
4. Vider (ou noter la taille de) `storage/logs/logs_mail_catchall.log`.

Rappel de configuration — les deux clés vivent dans `config/config.enc` :

| Clé | Valeur | Effet |
|---|---|---|
| `MAIL_CATCHALL` | adresse unique | destinataire de substitution |
| `MAIL_CATCHALL_ACTIF` | `1` / `0` | actif / désactivé |
| *(clé absente)* | — | **actif** (choix fail-safe) |

## 2. Critère de réussite (identique pour chaque cas)

Pour chaque envoi déclenché, une ligne doit apparaître dans
`storage/logs/logs_mail_catchall.log` :

```
[2026-07-26 14:03:11] REDIRIGE | reels=jean@exemple.fr | vers=dev@exemple.fr | sujet=[TEST → jean@exemple.fr] Inscription enregistrée - Forbach en Rose
```

et le mail reçu dans la boîte de redirection doit porter le sujet préfixé
`[TEST → <destinataire réel>]`.

❌ **Échec** = un envoi qui n'apparaît pas dans ce journal, ou un mail reçu par le
destinataire réel. Dans ce cas, le point d'envoi concerné contourne
`sendMail()` / `sendMailSmtp()` et doit être corrigé.

## 3. Cas à déclencher

À exécuter **deux fois** : une fois avec le fournisseur **Gmail** actif, une fois
avec le fournisseur **SMTP** actif (les deux couches sont indépendantes).

| # | Mail | Comment le déclencher | Source |
|---|---|---|---|
| 1 | Mail de test Gmail | Mails → Gmail → *Mail test* | `inc/mail-settings.php` |
| 2 | Mail de test SMTP | Mails → SMTP → *Envoyer un mail test* | `inc/mail-settings.php` |
| 3 | Confirmation d'inscription (public) | `public/register.php` — inscrire 1 personne | `public/register.php:236` |
| 4 | Récap d'inscription groupée | `public/register.php` — inscrire 2+ personnes | `public/register.php:232` |
| 5 | Inscription créée depuis l'admin | Saisie → nouvelle inscription | `src/content/registrations_core.php:325` |
| 6 | Renvoi de mail d'inscription | Dashboard → renvoyer le mail | `src/content/registrations_core.php:620` |
| 7 | Inscription via l'API admin | Dashboard → ajout d'inscription | `admin-api.php:1932` / `:2523` |
| 8 | Récap groupé via l'API admin | Dashboard → ajout groupé | `admin-api.php:2584` |
| 9 | Envoi groupé (BCC) | Mails → onglet *Envoi* → sélectionner plusieurs destinataires | `inc/send-mail.php:50` |
| 10 | Newsletter « nouvel article » | Actualités → publier avec *Prévenir les abonnés* | `src/mail/newsletter.php:188` |
| 11 | Formulaire de contact | `public/contact.php` | `public/contact.php:138` |
| 12 | Assistant (chatbot) — 2 envois | `public/chatbot-api.php` (question sans réponse / contact) | `public/chatbot-api.php:168`, `:309` |
| 13 | Mention dans un commentaire | Commenter une actualité avec `@…` | `public/news_action.php` |
| 14 | Demande de partenariat | Formulaire partenaire | `admin-api.php` (notif_partner) |
| 15 | Ban IP automatique | Notification admin | `admin-api.php:249` |
| 16 | Alerte 2FA | Notification admin | `admin-api.php:513` |
| 17 | Compte verrouillé (3 tentatives) | Notification admin | `admin-api.php:580` |
| 18 | **Code de vérification 2FA (admin)** | Se connecter à l'administration avec 2FA par mail | `admin-api.php:165` |
| 19 | Réinitialisation de mot de passe admin | `reset-password.php` | `admin-api.php:992` / `:1426` |
| 20 | Création d'un compte administrateur | Utilisateurs → créer | `admin-api.php:1614` |

> ⚠️ **Cas 18 et 19 — conséquence à connaître.** Garde-fou actif, les codes 2FA et
> les liens de réinitialisation des comptes **d'administration** partent eux aussi
> vers l'adresse de redirection. C'est voulu (aucune exception au garde-fou), mais
> il faut pouvoir relever cette boîte pour se connecter sur l'environnement de
> recette.

## 4. Cas d'erreur à vérifier

| Situation | Comportement attendu |
|---|---|
| Garde-fou actif, `MAIL_CATCHALL` vide ou invalide | **Aucun mail n'est envoyé** ; ligne `BLOQUE …` dans le journal ; l'appelant reçoit `false` et affiche son message d'échec habituel |
| Clé `MAIL_CATCHALL_ACTIF` absente de `config.enc` | Garde-fou **actif** ; bandeau « Aucun réglage enregistré » dans l'administration |
| Garde-fou désactivé (`0`) | Aucune ligne de journal, destinataire réel, sujet non préfixé |
| Fournisseur SMTP actif | Le préfixe `[TEST → …]` n'apparaît **qu'une seule fois** dans le sujet (idempotence entre `sendMail()` et `sendMailSmtp()`) |

## 5. Retour en production

Décocher **Activer le garde-fou** (ou poser `MAIL_CATCHALL_ACTIF=0` dans
`config/config.enc`). Vérifier que le bandeau repasse au vert
`Désactivé — envois réels` **avant** tout envoi de masse.
