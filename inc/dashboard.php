<?php
require '../src/core/config.php';
require_once '../src/security/csrf.php';
requirePage('dashboard');
$role = currentRole();
$canCreateReg  = canDoAction('dashboard.create_registration');
$canBulkCreate = canDoAction('dashboard.bulk_create');
$canEditReg    = canDoAction('dashboard.edit_registration');
$canDeleteReg  = canDoAction('dashboard.delete_registration');
// Présence de la colonne de cases à cocher (actions groupées). Défini tôt car
// utilisé à la fois dans le CSS (largeur de colonne) et la config DataTable.
$cbCol = ($canEditReg || $canDeleteReg) ? 1 : 0;
$canArchive    = canDoAction('dashboard.archive');
// dashboard.import_excel : l'import manuel a été déplacé dans Réglages → Import AssoConnect.
$canExportXls  = canDoAction('dashboard.export_excel');
$canScanQr     = canDoAction('dashboard.scan_qr');
// Le mode "Remise T-shirts" est accessible si on peut scanner OU si on peut éditer
$canTshirtMode = $canScanQr || $canEditReg;

// Charger les données pour la navbar
require __DIR__ . '/../src/partials/navbar-data.php';

/* ══════════════════ LE DÉPART DE LA COURSE ══════════════════════════════════
 *
 * Traité ICI, avant le moindre octet de sortie : chaque action se termine par
 * une redirection, ce qui évite qu'un rafraîchissement de page redonne le
 * départ. Le jour J, une double soumission accidentelle ne pardonnerait pas.
 *
 * L'affichage, lui, est dans src/partials/depart-course.php.
 * ───────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../src/content/chrono.php';
require_once __DIR__ . '/../src/content/course.php';
require_once __DIR__ . '/../src/content/content-log.php';

/* Droit dédié, et non emprunté aux transferts : un appui recalcule TOUS les
   temps de l'édition et fait sonner tous les téléphones. Ce n'est pas le même
   geste que corriger un transfert. */
$departPeutAgir = canDoAction('dashboard.depart') || currentRole() === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_depart'])) {
    if (!csrf_verify()) {
        addToast('danger', 'Session expirée. Rechargez la page et réessayez.');
    } elseif (!$departPeutAgir) {
        addToast('danger', "Vous n'avez pas le droit de donner le départ.");
    } else {
        $annee  = course_anneeActive($pdo);
        $action = (string) $_POST['action_depart'];

        if ($action === 'donner' || $action === 'corriger') {
            // « corriger » fournit un instant précis (heure locale saisie) ;
            // « donner » prend l'instant du clic.
            $quand = null;
            if ($action === 'corriger' && !empty($_POST['depart_instant'])) {
                $quand = course_heureDepartUtc((string) $_POST['depart_instant']);
                if ($quand === null) addToast('danger', 'Heure illisible.');
            }
            if ($action === 'donner' || $quand !== null) {
                $r = chrono_donnerDepart($pdo, $annee, $quand);
                if ($r['ok']) {
                    addToast('success', ($action === 'donner' ? 'Départ donné' : 'Départ corrigé')
                        . ' — ' . (int) ($r['recalcules'] ?? 0) . ' résultat(s) recalculé(s).');
                    logContentAction($pdo, 'chrono', 'update', null,
                        'Départ ' . $action . ' : ' . $r['instant'], 'chrono');

                    /* La notification part avec le départ, pas séparément : on
                       n'a pas le temps de faire deux gestes au coup de sifflet.
                       Uniquement au VRAI départ — corriger l'heure a posteriori
                       ne doit pas refaire sonner tous les téléphones. */
                    if ($action === 'donner') {
                        require_once __DIR__ . '/../src/content/notifications.php';
                        $n = notif_enregistrer($pdo, [
                            'annee'             => $annee,
                            'type'              => 'course',
                            'titre'             => 'La course est partie !',
                            'message'           => 'Bonne marche à toutes et à tous. '
                                                 . 'Gardez l\'application ouverte pour que '
                                                 . 'votre temps soit enregistré.',
                            'afficher_dans_app' => 1,
                            'epingle'           => 0,
                        ], null, currentUserId());

                        if (!empty($n['ok']) && isset($n['id'])) {
                            $p = notif_envoyerPush($pdo, (int) $n['id']);
                            addToast($p['ok'] ? 'success' : 'warning',
                                $p['ok']
                                    ? 'Notification envoyée à ' . $p['envoyes'] . ' appareil(s).'
                                    : ($p['erreur'] ?? "La notification n'est pas partie."));
                        }
                    }
                } else {
                    addToast('danger', $r['erreur'] ?? 'Échec.');
                }
            }
        }
        elseif ($action === 'annuler') {
            $r = chrono_annulerDepart($pdo, $annee);
            addToast($r['ok'] ? 'success' : 'danger', $r['ok']
                ? 'Départ annulé — ' . (int) ($r['recalcules'] ?? 0) . ' résultat(s) recalculé(s).'
                : ($r['erreur'] ?? 'Échec.'));
            if ($r['ok']) {
                logContentAction($pdo, 'chrono', 'update', null, 'Départ annulé', 'chrono');
            }
        }
        elseif ($action === 'decaler') {
            $r = chrono_decalerPrevu($pdo, (int) ($_POST['decalage_min'] ?? 0), $annee);
            addToast($r['ok'] ? 'success' : 'danger', $r['ok']
                ? 'Heure prévue décalée — ' . (int) ($r['recalcules'] ?? 0) . ' résultat(s) recalculé(s).'
                : ($r['erreur'] ?? 'Échec.'));
        }
        elseif ($action === 'recalculer') {
            $n = chrono_recomputeEdition($pdo, $annee);
            addToast('success', "$n résultat(s) recalculé(s).");
        }
    }
    header('Location: dashboard.php');
    exit;
}

/* ── Purge quotidienne des données périmées (lot 7) ──────────────────────────
 * Déclenchée par la première ouverture du tableau de bord de la journée, et au
 * plus une fois par jour (verrou atomique par fichier).
 *
 * POURQUOI ICI PLUTÔT QU'UN CRON : le site tourne chez un hébergeur mutualisé.
 * Exiger la configuration d'une tâche planifiée, c'est garantir qu'elle sera
 * oubliée, ou perdue au prochain déménagement — et une politique de
 * confidentialité qui promet un effacement jamais exécuté est une fausse
 * déclaration. Le jour où un cron existe, il peut appeler purge_run()
 * directement : les deux mécanismes cohabitent sans se gêner.
 *
 * Coût en temps normal : un file_exists(). Rien de plus. */
require_once __DIR__ . '/../src/content/purges.php';
purge_quotidienne($pdo);

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assoconnectJs      = $data['assoconnect_js']     ?? null;
$assoconnectIframe  = $data['assoconnect_iframe'] ?? null;
$title  = $data['title']   ?? '';
$picture= $data['picture'] ?? '';  

$qrcode_mail_mode = $data['qrcode_mail_mode'] ?? 'none';
$qrcode_mail_limit = (int) ($data['qrcode_mail_limit'] ?? 0);
$highlightLimit = ($qrcode_mail_mode === 'first_x' && $qrcode_mail_limit > 0) ? $qrcode_mail_limit : 0;
$registration_fee = (float) ($data['registration_fee'] ?? 0);
// Âge seuil « enfant » (paramétrable) — alimente les libellés « -N ans ».
$childAge = (int) ($data['child_age_threshold'] ?? 12);

// Préférences d'interface de l'utilisateur (mode « Remise T-shirts », etc.) LUES
// CÔTÉ SERVEUR : l'état est appliqué dès le rendu initial → aucun « flash » de bascule
// au chargement (contrairement à un chargement asynchrone via fetch).
$uiPrefs = [];
try {
    $uidPref = $_SESSION['uid'] ?? (function_exists('currentUserId') ? currentUserId() : null);
    if ($uidPref) {
        $stPref = $pdo->prepare('SELECT ui_prefs FROM users WHERE id = ?');
        $stPref->execute([$uidPref]);
        $rawPref = $stPref->fetchColumn();
        if ($rawPref) { $decPref = json_decode($rawPref, true); if (is_array($decPref)) $uiPrefs = $decPref; }
    }
} catch (\Throwable $e) { /* colonne ui_prefs absente (avant migration) → défauts */ }
$initTshirtMode  = !empty($uiPrefs['tshirt_mode']);
$initShowComment = !empty($uiPrefs['tshirt_show_comment']);

// Champs dynamiques
require_once '../src/content/form_fields.php';
$adminFields = getActiveFields($pdo, 'admin');
// Tous les champs actifs (pour les colonnes DataTable)
$stmtAllFields = $pdo->prepare('SELECT * FROM forms WHERE active = 1 ORDER BY sort_order ASC');
$stmtAllFields->execute();
$allActiveFields = $stmtAllFields->fetchAll(PDO::FETCH_ASSOC);

// Champs pour le formulaire "Ajout multiple" (saisie en lot).
// Seul le mode de paiement est réellement commun à tout le lot.
// `email` ET `entreprise` sont volontairement ABSENTS de cette liste : ils sont
// saisis/mappés PAR PERSONNE. L'entreprise peut différer d'une ligne à l'autre
// (ou être vide = particulier) ; un champ « entreprise commune » facultatif de
// l'en-tête sert juste à pré-remplir les lignes laissées vides.
$bulkSharedCols = ['paiement_mode'];
$bulkRowFields = [];
if ($canBulkCreate) {
    // Vérifie que la migration a été appliquée (update.php). Si la colonne
    // n'existe pas, on désactive proprement l'onglet "Ajout multiple" plutôt
    // que de provoquer une fatal error au chargement du dashboard.
    $bulkMigrationOk = false;
    try {
        $pdo->query('SELECT visible_saisie_multiple FROM forms LIMIT 0');
        $bulkMigrationOk = true;
    } catch (\PDOException $e) {
        $canBulkCreate = false;
    }

    if ($bulkMigrationOk) {
        $rawBulkFields = getActiveFields($pdo, 'bulk');
        foreach ($rawBulkFields as $bf) {
            if (!in_array($bf['bdd_column'] ?? '', $bulkSharedCols, true)) {
                $bulkRowFields[] = $bf;
            }
        }
        // Garantit que nom + prenom sont toujours présents dans le formulaire bulk,
        // même si l'admin a oublié de les cocher en "Bulk visible". Sans ces 2
        // champs, aucune inscription ne peut être créée (rejet par l'API).
        $bulkRowCols = array_column($bulkRowFields, 'bdd_column');
        foreach (['prenom' => 'Prénom', 'nom' => 'Nom'] as $col => $label) {
            if (!in_array($col, $bulkRowCols, true)) {
                $stmtFb = $pdo->prepare('SELECT * FROM forms WHERE bdd_column = ? LIMIT 1');
                $stmtFb->execute([$col]);
                $f = $stmtFb->fetch(PDO::FETCH_ASSOC);
                if ($f) {
                    $f['required_saisie_multiple'] = 1;
                    array_unshift($bulkRowFields, $f);
                }
            }
        }

        // Garantit la présence des champs `email` et `entreprise` PAR PERSONNE
        // (mappables depuis l'Excel), même si l'admin ne les a pas cochés
        // "Bulk visible". Tous deux facultatifs : une ligne sans email est
        // inscrite sans envoi ; une ligne sans entreprise = particulier (ou
        // récupère l'« entreprise commune » facultative de l'en-tête).
        foreach (['email', 'entreprise'] as $bulkOptCol) {
            if (!in_array($bulkOptCol, array_column($bulkRowFields, 'bdd_column'), true)) {
                $stmtOpt = $pdo->prepare('SELECT * FROM forms WHERE bdd_column = ? LIMIT 1');
                $stmtOpt->execute([$bulkOptCol]);
                $fOpt = $stmtOpt->fetch(PDO::FETCH_ASSOC);
                if ($fOpt) {
                    $fOpt['required_saisie_multiple'] = 0;
                    $bulkRowFields[] = $fOpt;
                }
            }
        }

        // Cas particulier de montant_du : la colonne est `active=0` par défaut
        // (sa valeur est auto-calculée d'après le paiement dans les autres
        // contextes), donc getActiveFields() ne la renvoie pas même si l'admin
        // a coché "Bulk visible". On l'ajoute manuellement ici si présente
        // avec visible_saisie_multiple=1 en BDD.
        if (!in_array('montant_du', array_column($bulkRowFields, 'bdd_column'), true)) {
            $stmtMd = $pdo->prepare('SELECT * FROM forms WHERE bdd_column = ? AND visible_saisie_multiple = 1 LIMIT 1');
            $stmtMd->execute(['montant_du']);
            $mdField = $stmtMd->fetch(PDO::FETCH_ASSOC);
            if ($mdField) {
                $bulkRowFields[] = $mdField;
            }
        }
    }
}

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord</title>
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<!-- ─── CSS ─── -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link href="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/colreorder/1.7.0/css/colReorder.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .card-dashboard{margin-top:1rem;border-radius:1.25rem;box-shadow:0 0 25px rgba(0,0,0,.1)}
  .quick-search{max-width:450px;width:50%;margin:0 auto .75rem;position:sticky;top:0;z-index:40}
  .statCard{min-width:180px}
  /* Harmonisation des cartes de stats : même hauteur, même style de libellé/valeur. */
  .statCard .card-body{display:flex;flex-direction:column;justify-content:center;min-height:104px;padding:1rem .75rem}
  .statCard .stat-label{font-size:.78rem;font-weight:600;color: var(--ink-dim);text-transform:uppercase;letter-spacing:.03em;line-height:1.25;margin:0 0 .4rem;min-height:2.2em;display:flex;align-items:center;justify-content:center}
  .statCard .stat-value{font-size:1.85rem;font-weight:700;line-height:1.1;color: var(--ink);margin:0}
  .statCard .stat-value--sm{font-size:1rem;font-weight:600;line-height:1.3}
  /* Cartes de stats — grille responsive + style harmonisé (dashboard ≡ stats). */
  .stats-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:.75rem;align-items:stretch}
  .stats-cards .statCard{min-width:0;margin:0}
  .stats-cards .statCard .card-body{display:flex;flex-direction:column;justify-content:center;min-height:104px;padding:1rem .75rem}
  .stats-cards .statCard .stat-label{font-size:.72rem;font-weight:600;color: var(--ink-dim);text-transform:uppercase;letter-spacing:.03em;line-height:1.25;margin:0 0 .4rem;display:flex;align-items:center;justify-content:center}
  .stats-cards .statCard .stat-value{font-size:1.85rem;font-weight:700;line-height:1.1;color: var(--ink);margin:0}
  .stats-cards .statCard .stat-value--sm{font-size:1rem;font-weight:600;line-height:1.3}
  .stats-cards .reg-stats-more{align-self:center;white-space:nowrap}
  @media (max-width:575.98px){
    .stats-cards{grid-template-columns:repeat(2,1fr);gap:.55rem}
    .stats-cards .statCard:nth-child(3){grid-column:1/-1}
    .stats-cards .statCard .card-body{min-height:82px;padding:.7rem .5rem}
    .stats-cards .statCard .stat-value{font-size:1.5rem}
    .stats-cards .reg-stats-more{grid-column:1/-1;justify-self:center;width:auto;padding-left:1.4rem;padding-right:1.4rem;margin-top:.35rem}
    .quick-search{width:90%}
  }
  .hide-stats #stats {display: none !important;}
  .dashboard-actions .btn-rose{
    background:linear-gradient(135deg,var(--primary, #f42182),var(--primary-hover, #db2777))!important;
    color:var(--primary-text, #fff)!important;
    border:none!important;
  }
  .dashboard-actions .btn-rose:hover,
  .dashboard-actions .btn-rose:focus{
    background:linear-gradient(135deg,var(--primary, #f42182),var(--primary-hover, #be185d))!important;
    color:var(--primary-text, #fff)!important;
  }
  .dashboard-actions .btn-success{
    background:#22c55e!important;
    color:#fff!important;
    border-color:#22c55e!important;
  }
  .dashboard-actions .btn-success:hover,
  .dashboard-actions .btn-success:focus{
    background:#16a34a!important;
    color:#fff!important;
    border-color:#16a34a!important;
  }
  .dashboard-actions .btn-secondary{
    background:#64748b!important;
    color:#fff!important;
    border-color: var(--ink-dim)!important;
  }
  .dashboard-actions .btn-secondary:hover,
  .dashboard-actions .btn-secondary:focus{
    background:#475569!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-info{
    background:#0ea5e9!important;
    color:#fff!important;
    border-color:#0ea5e9!important;
  }
  .dashboard-actions .btn-info:hover,
  .dashboard-actions .btn-info:focus{
    background:#0284c7!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-danger{
    background:#ef4444!important;
    color:#fff!important;
    border-color:#ef4444!important;
  }
  .dashboard-actions .btn-danger:hover,
  .dashboard-actions .btn-danger:focus{
    background:#dc2626!important;
    color:#fff!important;
  }
  .dashboard-actions .btn-warning{
    background:#f59e0b!important;
    color:#16171d!important;
    border-color:#f59e0b!important;
  }
  .dashboard-actions .btn-warning:hover,
  .dashboard-actions .btn-warning:focus{
    background:#d97706!important;
    color:#fff!important;
    border-color:#d97706!important;
  }
  
/* ═══ Tableau dashboard — style OpenCloud Rose ═══ */

/* ── Spécifique « inscriptions » : marquage d'éligibilité + légende ──
   (Le look général du tableau + les composants entonnoir/resize/colonnes sont
   désormais centralisés dans le style global admin de navbar-admin.php.) */

/* Éligibles (X premiers) : liseré rose à gauche, fond normal. */
#oc-content #tbl tbody tr.first-750 td:first-child {
  box-shadow: inset 3px 0 0 0 var(--primary, #f42182);   /* liseré rose = éligible */
}
/* Non-éligibles (montant dû = 0) : léger fond gris pour les distinguer. */
#oc-content #tbl tbody tr.row-unpaid td { background: var(--surface-2); }
#oc-content #tbl tbody tr.row-unpaid:hover td { background-color: var(--surface-2); }

/* Légende des couleurs du tableau */
.tbl-legend {
  display: flex; flex-wrap: wrap; align-items: center; gap: 18px;
  margin: 14px 2px 4px; font-size: 12px; color: var(--ink-dim);
}
.tbl-legend .lg-item { display: inline-flex; align-items: center; gap: 7px; }
.tbl-legend .lg-bar {
  width: 4px; height: 15px; border-radius: 2px; background: var(--primary, #f42182); display: inline-block;
}
.tbl-legend .lg-muted { color: var(--ink-faint); }

/* ═══ Onglets du modal "Nouvel inscrit" (rose theme) ═══ */
#addModalTabs {
  border-bottom: 2px solid var(--border);
  margin: 0;
  padding: 0 1.25rem;
}
#addModalTabs .nav-link {
  color: #94818a;
  background: transparent;
  border: none;
  border-bottom: 3px solid transparent;
  border-radius: 0;
  padding: 12px 18px;
  margin-bottom: -2px;
  font-weight: 600;
  font-size: 14px;
  transition: all 0.15s ease;
}
#addModalTabs .nav-link:hover {
  color: var(--primary, #f42182);
  border-bottom-color: #fbcfe8;
}
#addModalTabs .nav-link.active {
  color: var(--primary, #f42182);
  border-bottom-color: var(--primary, #f42182);
  background: transparent;
}

/* ═══ Ajout multiple : tableau compact type tableur (palette sobre) ═══ */
/* En Ajout multiple, le modal s'élargit pour laisser respirer le tableau. */
#addModal .modal-dialog.bulk-wide { max-width: 95vw; }
.bulk-table-wrap {
  max-height: 58vh;
  overflow: auto;
  border: 1px solid var(--border);
  border-radius: 10px;
}
table.bulk-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  margin: 0;
  font-size: 13px;
}
table.bulk-table thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--surface-2);
  color: var(--ink-dim);
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  padding: 8px;
  white-space: nowrap;
  text-align: left;
  border-bottom: 1px solid var(--border);
}
table.bulk-table tbody td {
  padding: 4px 6px;
  border-bottom: 1px solid var(--surface-2);
  vertical-align: middle;
}
table.bulk-table tbody tr:last-child td { border-bottom: 0; }
table.bulk-table tbody tr:hover td { background: var(--surface-2); }
table.bulk-table .bulk-field {
  font-size: 13px;
  padding: 5px 8px;
  height: auto;
  min-width: 120px;
}
table.bulk-table textarea.bulk-field { min-height: 34px; min-width: 160px; }
table.bulk-table td.col-num {
  width: 40px;
  text-align: center;
  font-weight: 700;
  color: var(--ink-faint);
  background: var(--surface-2);
}
table.bulk-table th.col-num { width: 40px; text-align: center; }
table.bulk-table td.col-actions, table.bulk-table th.col-actions { width: 44px; text-align: center; }
.bulk-row-remove {
  padding: 2px 7px !important;
  font-size: 12px !important;
  line-height: 1.2 !important;
}
/* Colonnes secondaires (commentaire, date d'inscription) masquées par défaut ;
   révélées via le bouton « Plus de colonnes ». */
.bulk-col-optional { display: none; }
.bulk-show-optional .bulk-col-optional { display: table-cell; }

/* Bloc « Réglages du lot » : fond sobre, léger liseré rose en accent. */
#bulkSharedBlock { background: var(--surface-2); border: 1px solid var(--border); border-left: 3px solid var(--primary, #f42182); }
/* Résumé replié cliquable. */
#bulkSharedSummary {
  display: none;
  align-items: center;
  gap: 10px;
  width: 100%;
  text-align: left;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-left: 3px solid var(--primary, #f42182);
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 13px;
  color: var(--ink-dim);
  cursor: pointer;
}
#bulkSharedSummary:hover { background: var(--surface-2); }
#bulkSharedSummary .bss-sep { color: var(--ink-faint); }
#bulkSharedSummary .bss-edit { margin-left: auto; font-size: 12px; color: var(--primary, #f42182); }

/* ═══ Ajout multiple : correspondance colonnes Excel ↔ champs ═══ */
#bulkMapView .bulk-map-target {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: 8px;
  margin-bottom: 8px;
  background: var(--surface);
}
#bulkMapView .bulk-map-target-label {
  flex: 0 0 38%;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-dim);
}
.bulk-map-dropzone {
  flex: 1 1 auto;
  min-height: 38px;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 6px;
  border: 1px dashed var(--border-strong);
  border-radius: 6px;
  background: var(--surface-2);
  transition: border-color .15s, background .15s;
}
.bulk-map-pool {
  min-height: 120px;
  display: flex;
  flex-wrap: wrap;
  align-content: flex-start;
  gap: 6px;
  padding: 8px;
  border: 1px dashed var(--border-strong);
  border-radius: 8px;
  background: var(--surface-2);
}
.bulk-map-dropzone.drag-over,
.bulk-map-pool.drag-over {
  border-color: var(--primary, #f42182);
  background: var(--accent-soft);
}
.bulk-map-placeholder {
  font-size: 12px;
  color: var(--ink-faint);
  font-style: italic;
}
.bulk-map-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  font-weight: 500;
  color: var(--ink);
  background: var(--surface);
  border: 1px solid #fbcfe8;
  border-radius: 6px;
  padding: 4px 8px;
  cursor: grab;
  box-shadow: 0 1px 2px rgba(0,0,0,.06);
}
.bulk-map-chip .bi { color: var(--primary, #f42182); cursor: grab; }
.bulk-map-chip.dragging { opacity: .4; }
/* Badge « auto » : colonne pré-reliée automatiquement (par ressemblance de nom). */
.bulk-map-chip.bulk-chip-auto::after{content:'auto';font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;background: color-mix(in srgb, var(--info) 15%, var(--surface));color:#1d4ed8;border-radius:6px;padding:1px 5px;margin-left:6px;vertical-align:middle}

/* ── Flux de correspondance : fichier (gauche) → vos champs (droite) ── */
.bulk-map-flow{display:flex;gap:.9rem;align-items:flex-start}
.bulk-map-flow .bulk-map-side-pool{flex:0 0 34%;order:1}
.bulk-map-flow .bulk-map-arrow{order:2;align-self:center;flex:0 0 auto;text-align:center;color:var(--primary,#db2777)}
.bulk-map-flow .bulk-map-side-targets{flex:1 1 auto;min-width:0;order:3}
.bulk-map-arrow i{font-size:2.8rem;display:block;line-height:1}
.bulk-map-arrow span{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--primary,#db2777);margin-top:2px;display:block}
.bulk-map-step{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:var(--primary,#db2777);color:#fff;font-size:11px;font-weight:700;margin-right:5px}
@media (max-width:767px){
  .bulk-map-flow{flex-direction:column}
  .bulk-map-flow .bulk-map-side-pool,.bulk-map-flow .bulk-map-side-targets{flex:1 1 auto;width:100%}
  .bulk-map-flow .bulk-map-arrow{transform:rotate(90deg);margin:.15rem 0}
}

/* Commentaire : éditeur de texte libre + boutons d'insertion de colonnes */
#bulkMapView .bulk-map-target-comment { display: block; }
.bulk-map-hint {
  font-size: 11px;
  font-weight: 400;
  color: var(--ink-faint);
  margin-top: 2px;
}
.bulk-comment-editor { margin-top: 8px; }
.bulk-comment-rich {
  min-height: 92px;
  width: 100%;
  height: auto;
  font-size: 13px;
  line-height: 1.9;
  padding: 8px 10px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.bulk-comment-rich:empty::before {
  content: attr(data-placeholder);
  color: var(--ink-faint);
  font-style: italic;
}
.bulk-comment-rich.drag-over { border-color: var(--primary, #f42182); background: var(--accent-soft); }
/* Card « colonne » intégrée dans le texte (déplaçable) */
.ce-chip {
  display: inline-block;
  font-size: 12px;
  font-weight: 600;
  color: var(--primary, #f42182);
  background: var(--accent-soft);
  border: 1px solid #fbcfe8;
  border-radius: 6px;
  padding: 0 7px;
  margin: 0 2px;
  cursor: grab;
  user-select: none;
  white-space: nowrap;
  vertical-align: 1px;
}
.ce-chip::before { content: "⋮⋮"; letter-spacing: -2px; margin-right: 4px; opacity: .5; }
.ce-chip.dragging { opacity: .4; }

/* ColReorder : curseur « déplaçable » sur la 1re ligne d'en-tête (spécifique à ce
   tableau ; les styles resize / bouton Colonnes / dtcr sont globaux). */
#tbl thead tr:first-child th { cursor: grab; }
#tbl thead tr:first-child th:active { cursor: grabbing; }

/* Colonne de cases à cocher (actions groupées) : étroite et centrée. autoWidth
   étant désactivé, on force la largeur ici sinon la colonne s'étire inutilement.
   On cible la classe ET la 1re colonne par position (#tbl ... :first-child) car
   DataTables n'applique pas toujours la className à l'en-tête <th> ; la colonne
   est épinglée à gauche (ColReorder fixedColumnsLeft) donc :first-child est fiable. */
<?php if($cbCol): ?>
#tbl th.bulk-cb-col, #tbl td.bulk-cb-col,
#tbl thead tr th:first-child, #tbl tbody tr td:first-child {
  width: 32px !important;
  min-width: 32px !important;
  max-width: 32px !important;
  padding-left: 10px !important;   /* espace entre la case et le bord du tableau */
  padding-right: 2px !important;
  text-align: left !important;
  white-space: nowrap;
}
#tbl thead tr th:first-child { cursor: default; } /* colonne fixe : pas de poignée de déplacement */
#tbl .bulk-cb-col .form-check-input,
#tbl tbody tr td:first-child .form-check-input,
#tbl thead tr th:first-child .form-check-input { margin: 0; cursor: pointer; }
<?php endif; ?>

/* Colonne « ID » (numéro de séquence) : un petit numéro, on la resserre. */
#tbl th.col-seq, #tbl td.col-seq {
  width: 46px !important;
  min-width: 46px !important;
  max-width: 46px !important;
  padding-left: 6px !important;
  padding-right: 6px !important;
}

/* « Show X entries » (DataTables) : garder tout sur une ligne et éviter que la
   flèche du <select> ne chevauche le « 10 » (le select hérite parfois de
   .form-select = block + width:100%, d'où l'empilement Show / 10 / entries). */
#tbl_length label { display: inline-flex; align-items: center; gap: 6px; margin: 0; white-space: nowrap; font-size: 13px; color: var(--ink-dim); font-weight: 400; }
#tbl_length select, #tbl_length .form-select {
  display: inline-block !important;
  width: auto !important;
  min-width: 64px;
  padding: 5px 30px 5px 10px;   /* place à droite pour la flèche */
  font-size: 13px;
  border: 1px solid var(--border-strong);
  border-radius: 6px;
  background-color: var(--surface);
  background-position: right 9px center;  /* repositionne la flèche (Bootstrap .form-select) */
}

/* ═══ Boutons action dans le tableau (.action-buttons est global) ═══ */
.btn-delete{
  background:#e63946;
  background:linear-gradient(135deg,#e63946 0%,#c5303d 100%);
}
.btn-delete:hover{
  background:linear-gradient(135deg,#c5303d 0%,#a32634 100%);
  box-shadow:0 3px 6px rgba(230,57,70,.35);
}

.xl-modal .modal-dialog {
  max-width: 1300px;
}

</style>
</head>

<body>

<?php include __DIR__ . '/../src/partials/navbar-admin.php'; ?>

<!-- ═════════ MAIN ═════════ -->
  <div>

    <?php /* Le départ, tout en haut et impossible à manquer — mais seulement
             dans la fenêtre où il sert. Le partial se retire lui-même le reste
             de l'année. */ ?>
    <?php include __DIR__ . '/../src/partials/depart-course.php'; ?>

    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
      <h1 class="mb-0 fw-bold"><i class="bi bi-house me-2"></i>Inscriptions</h1>

      <div class="dashboard-actions d-none d-lg-flex flex-wrap gap-2">
        <?php if($canCreateReg): ?>
          <button class="btn btn-rose"      data-bs-toggle="modal" data-bs-target="#addModal">Nouvel inscrit</button>
        <?php endif; ?>
        <?php // Bascule (visible uniquement en mode « Remise T-shirts ») : affiche ou non la colonne Commentaire. ?>
        <button id="toggleCommentTS" type="button" class="btn btn-outline-secondary" style="display:none">
          <i class="bi bi-toggle-off me-1"></i>Afficher commentaire
        </button>
        <?php // Import Excel AssoConnect déplacé vers Réglages → onglet « Import AssoConnect » (droit dashboard.import_excel) ?>
        <?php if($canExportXls): ?>
          <button id="btnExport" class="btn btn-info">Export Excel</button>
            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.getElementById('btnExport').addEventListener('click', () => {
              // simple redirection => déclenche le téléchargement
              window.location = '../admin-api.php?route=export-excel';
            });
            </script>
          <?php endif; ?>
          <?php if($canArchive): ?>
            <button id="btnArchiveNow" class="btn btn-danger">Archiver&nbsp;<?= date('Y') ?></button>

            <script nonce="<?= $GLOBALS['csp_nonce'] ?>">
            document.getElementById('btnArchiveNow').addEventListener('click', async () => {
              if (!confirm('Tout archiver et réinitialiser les inscriptions ?')) return;

              const btn = document.getElementById('btnArchiveNow');
              const originalText = btn.innerHTML;
              btn.disabled = true;
              btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Archivage en cours…';

              try {
                const _ct = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const res  = await fetch('../admin-api.php?route=archive-current', {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {'X-CSRF-TOKEN': _ct}
                });
                const json = await res.json();
                if (json.ok) {
                  alert(`${json.archived} inscription(s) archivées (${json.year}).`);
                  location.reload();
                } else {
                  alert('Erreur archivage : ' + JSON.stringify(json));
                  btn.disabled = false;
                  btn.innerHTML = originalText;
                }
              } catch (e) {
                alert('Erreur réseau : ' + e.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
              }
            });
            </script>
        <?php endif; ?>
      </div>
    </div>

    <!-- stats -->
    <div id="stats" class="stats-cards mb-4"></div>
    <?php include __DIR__ . '/../src/partials/_stats-more-modal.php'; ?>

    <input id="quickSearch" class="form-control quick-search" placeholder="Recherche rapide">
    <?php if($canEditReg || $canDeleteReg): ?>
    <!-- Barre d'actions groupées : visible dès qu'au moins une inscription est cochée -->
    <div id="bulkBar" class="bulk-bar d-none align-items-center gap-2 my-2 p-2 rounded" style="background:var(--accent-soft);border:1px solid #fbcfe8">
      <span class="me-1"><i class="bi bi-check2-square me-1"></i><b id="bulkCount">0</b> sélectionné(s)</span>
      <?php if($canEditReg): ?>
      <button type="button" id="bulkEditBtn" class="btn btn-sm btn-rose"><i class="bi bi-pencil me-1"></i>Modifier la sélection</button>
      <?php endif; ?>
      <?php if($canDeleteReg): ?>
      <button type="button" id="bulkDeleteBtn" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1"></i>Supprimer la sélection</button>
      <?php endif; ?>
      <button type="button" id="bulkClearBtn" class="btn btn-sm btn-link text-muted text-decoration-none ms-auto">Désélectionner</button>
    </div>
    <?php endif; ?>
    <div class="fer-tbl-wrap is-loading" id="dashTblWrap">
      <div class="fer-tbl-spinner" aria-hidden="true"></div>
      <div class="table-responsive">
        <table id="tbl" class="table fer-table table-sm w-100"></table>
      </div>
    </div>
    <?php if ($highlightLimit > 0): ?>
    <div class="tbl-legend">
      <span class="lg-item"><span class="lg-bar"></span><?= (int)$highlightLimit ?> premiers &mdash; &eacute;ligibles T-shirt</span>
      <span class="lg-item lg-muted">sans liser&eacute; = non &eacute;ligible</span>
    </div>
    <?php endif; ?>
  </div>

<?php include __DIR__ . '/../src/partials/admin-footer.php'; ?>

<!-- ═════════ MODALES ═════════ -->

<!-- Autres modales existantes... -->
<div class="modal fade xl-modal" id="addModal" tabindex="-1"><div class="modal-dialog <?= $canBulkCreate ? 'modal-xl' : '' ?>">
  <div class="modal-content"><div class="modal-header pb-0 border-0">
    <h5 class="modal-title">Nouvel inscrit</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>

    <?php if ($canBulkCreate): ?>
    <div class="d-flex justify-content-between align-items-end px-3">
      <ul class="nav nav-tabs flex-grow-1" id="addModalTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="tab-single-btn" data-bs-toggle="tab" data-bs-target="#tab-single" type="button" role="tab">Inscrit unique</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tab-bulk-btn" data-bs-toggle="tab" data-bs-target="#tab-bulk" type="button" role="tab">Ajout multiple</button>
        </li>
      </ul>
      <!-- Import Excel accessible directement au niveau des onglets -->
      <button type="button" id="bulkImportExcelBtn" class="btn btn-outline-success btn-sm mb-2"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
    </div>
    <?php endif; ?>

    <div class="tab-content">
      <!-- ───── ONGLET 1 : INSCRIT UNIQUE (formulaire existant) ───── -->
      <div class="tab-pane fade show active" id="tab-single" role="tabpanel">
        <form id="fAdd">
          <div class="modal-body row g-2">
            <input type="hidden" name="origine" value="Admin">
            <?php // Obligation du formulaire « Nouvel inscrit » : pilotée par la colonne
                  // `required_admin` UNIQUEMENT pour le rôle admin. Les autres rôles autorisés
                  // (user, saisie, qui ont aussi dashboard.create_registration) conservent
                  // l'obligation générale `required` définie dans « Gestion des champs ». ?>
            <?php foreach ($adminFields as $f):
              if ($role !== 'admin') { $f['required_admin'] = $f['required'] ?? 0; }
            ?>
              <?= renderFormField($f, '', false, 'admin') ?>
            <?php endforeach; ?>
            <div class="col-md-6">
              <label class="form-label">Paiement <span style="color:#ef4444">*</span></label>
              <select name="paiement_mode" class="form-select paiement-select" required>
                <option value="" disabled selected hidden>Choisir…</option>
                <option value="CB">CB</option>
                <option value="espece">Espèce</option>
                <option value="cheque">Chèque</option>
                <option value="virement">Virement</option>
                <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
                <option value="enfant_tshirt">Enfant -<?= $childAge ?> ans (avec T-shirt)</option>
              </select>
              <div class="montant-du-display mt-2" style="display:none;font-size:14px;font-weight:600;color: var(--ink)"></div>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Enregistrer</button></div>
        </form>
      </div>

      <?php if ($canBulkCreate): ?>
      <!-- ───── ONGLET 2 : AJOUT MULTIPLE (saisie en lot) ───── -->
      <div class="tab-pane fade" id="tab-bulk" role="tabpanel">
        <form id="fBulkAdd">
          <input type="hidden" name="origine" value="Admin">
          <div class="modal-body">
            <!-- Réglages du lot : ouvert au départ, puis repliable en résumé cliquable -->
            <div id="bulkSharedBlock" class="mb-3 p-3 rounded">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <strong style="font-size:13px;color: var(--ink-dim);"><i class="bi bi-sliders me-1"></i>Réglages du lot</strong>
                <button type="button" id="bulkSharedCollapse" class="btn btn-sm btn-link text-decoration-none p-0 text-muted">
                  Replier <i class="bi bi-chevron-up ms-1" style="font-size:11px;"></i>
                </button>
              </div>
              <div class="row g-3 align-items-start">
                <!-- Paiement -->
                <div class="col-md-4">
                  <label class="form-label mb-1">Paiement <span style="color:#ef4444">*</span></label>
                  <select name="shared_paiement_mode" class="form-select">
                    <option value="" disabled selected hidden>Choisir…</option>
                    <option value="CB">CB</option>
                    <option value="espece">Espèce</option>
                    <option value="cheque">Chèque</option>
                    <option value="virement">Virement</option>
                    <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
                    <option value="enfant_tshirt">Enfant -<?= $childAge ?> ans (avec T-shirt)</option>
                  </select>
                </div>
                <!-- Entreprise / Groupe commune -->
                <div class="col-md-4">
                  <label class="form-label mb-1">Entreprise / Groupe</label>
                  <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" id="sharedEntrepriseToggle" role="switch">
                    <label class="form-check-label" for="sharedEntrepriseToggle" style="font-size:13px;">Commune à tous</label>
                  </div>
                  <input type="text" name="shared_entreprise" class="form-control form-control-sm" placeholder="Nom entreprise / groupe / famille" style="display:none">
                  <div class="form-text" style="font-size:11px;">Sinon : par personne (ou particulier).</div>
                </div>
                <!-- Mode d'envoi des mails -->
                <div class="col-md-4">
                  <label class="form-label mb-1">Envoi des mails</label>
                  <div class="d-flex flex-column gap-1">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="mail_mode" id="mailModeRecap" value="recap" checked>
                      <label class="form-check-label" for="mailModeRecap" style="font-size:13px;">Récap groupé — 1 seul mail</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="mail_mode" id="mailModeIndiv" value="individual">
                      <label class="form-check-label" for="mailModeIndiv" style="font-size:13px;">Individuel — 1 mail par personne</label>
                    </div>
                  </div>
                  <div id="recapEmailWrap" class="mt-2">
                    <input type="email" name="recap_email" class="form-control form-control-sm" placeholder="Email de contact pour le récap">
                  </div>
                </div>
              </div>
            </div>
            <!-- Résumé replié du lot (cliquer pour rouvrir les réglages) -->
            <button type="button" id="bulkSharedSummary" class="mb-3">
              <i class="bi bi-sliders"></i>
              <span id="bssPaiement">Paiement à définir</span>
              <span class="bss-sep">·</span>
              <span id="bssEnt">Entreprise par personne</span>
              <span class="bss-sep">·</span>
              <span id="bssMail">Récap groupé</span>
              <span class="bss-edit"><i class="bi bi-pencil"></i> Modifier</span>
            </button>

            <!-- Liste dynamique des personnes -->
            <input type="file" id="bulkExcelFile" accept=".xlsx,.xls" style="display:none">
            <?php
              // Colonnes « secondaires » masquées par défaut dans le tableau (rarement
              // utilisées) : révélées via le bouton « Plus de colonnes ».
              $bulkOptionalCols = ['commentaire', 'date_inscription'];
              $bulkHasOptional  = false;
              foreach ($bulkRowFields as $bf) {
                  if (in_array($bf['bdd_column'] ?? '', $bulkOptionalCols, true)) { $bulkHasOptional = true; break; }
              }
            ?>
            <div id="bulkEditView">
              <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <strong class="text-muted" style="font-size:13px;">Personnes à inscrire</strong>
                <div class="d-flex gap-1 flex-wrap">
                  <?php if ($bulkHasOptional): ?>
                  <button type="button" id="bulkToggleOptional" class="btn btn-outline-secondary btn-sm"><i class="bi bi-layout-three-columns"></i> Plus de colonnes</button>
                  <?php endif; ?>
                  <button type="button" id="bulkDuplicateBtn" class="btn btn-outline-secondary btn-sm"><i class="bi bi-files"></i> Dupliquer</button>
                  <button type="button" id="bulkAddBtn" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> Ajouter</button>
                </div>
              </div>
              <div class="bulk-table-wrap">
                <table class="bulk-table" id="bulkTable">
                  <thead>
                    <tr>
                      <th class="col-num">#</th>
                      <?php foreach ($bulkRowFields as $bf):
                        if (empty($bf['bdd_column']) || ($bf['field_type'] ?? '') === 'guardian') continue;
                        $bdd  = htmlspecialchars($bf['bdd_column']);
                        $lbl  = htmlspecialchars($bf['label']);
                        $req  = (int) ($bf['required_saisie_multiple'] ?? 0);
                        $star = $req ? ' <span style="color:#ef4444">*</span>' : '';
                        $optCls = in_array($bf['bdd_column'], $bulkOptionalCols, true) ? ' bulk-col-optional' : '';
                      ?>
                      <th data-col-bdd="<?= $bdd ?>" class="<?= trim($optCls) ?>"><?= $lbl.$star ?></th>
                      <?php endforeach; ?>
                      <th class="col-actions"></th>
                    </tr>
                  </thead>
                  <tbody id="bulkRows"></tbody>
                </table>
              </div>
            </div>

            <!-- Vue de correspondance : colonnes du fichier Excel ↔ champs « Personnes à inscrire » -->
            <div id="bulkMapView" style="display:none">
              <div class="alert alert-info py-2 px-3" style="font-size:13px">
                <i class="bi bi-info-circle me-1"></i>
                Glissez chaque colonne de votre fichier sous le champ correspondant. Les champs marqués <span style="color:#ef4444">*</span> sont obligatoires : leur colonne doit être reliée — sauf « Montant dû », facultatif (calculé d'après le paiement ou le tarif de la course).
              </div>
              <div class="bulk-map-flow">
                <div class="bulk-map-side bulk-map-side-targets">
                  <strong class="text-muted d-block mb-2" style="font-size:13px;"><span class="bulk-map-step">2</span>Vos champs <span class="fw-normal" style="font-size:11px;color: var(--ink-faint)">— déposez la colonne sur le bon champ</span></strong>
                  <div id="bulkMapTargets">
                    <?php foreach ($bulkRowFields as $bf):
                      if (empty($bf['bdd_column']) || ($bf['field_type'] ?? '') === 'guardian') continue;
                      $mreq  = (int) ($bf['required_saisie_multiple'] ?? 0);
                      $mbdd  = htmlspecialchars($bf['bdd_column']);
                      $mlbl  = htmlspecialchars($bf['label']);
                      $mstar = $mreq ? ' <span style="color:#ef4444">*</span>' : '';
                      // Le commentaire est un modèle de texte libre : on écrit ce qu'on
                      // veut et on clique une colonne pour insérer sa valeur (jeton [Colonne]).
                      $mComment = (($bf['bdd_column'] ?? '') === 'commentaire');
                    ?>
                      <?php if ($mComment): ?>
                      <div class="bulk-map-target bulk-map-target-comment" data-bdd="<?= $mbdd ?>" data-required="<?= $mreq ?>">
                        <div class="bulk-map-target-label"><?= $mlbl ?><?= $mstar ?>
                          <div class="bulk-map-hint">Écrivez librement, puis glissez une colonne depuis « Colonnes de votre fichier » dans le texte. Les cards se déplacent ensuite à la souris.</div>
                        </div>
                        <div class="bulk-comment-editor">
                          <div class="bulk-comment-rich form-control" contenteditable="true" data-placeholder="Écrivez ici… puis glissez une colonne du fichier pour insérer sa valeur."></div>
                        </div>
                      </div>
                      <?php else: ?>
                      <div class="bulk-map-target" data-bdd="<?= $mbdd ?>" data-required="<?= $mreq ?>">
                        <div class="bulk-map-target-label"><?= $mlbl ?><?= $mstar ?></div>
                        <div class="bulk-map-dropzone" data-target-drop>
                          <span class="bulk-map-placeholder">Déposez une colonne ici</span>
                        </div>
                      </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="bulk-map-arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i><span>glisser</span></div>
                <div class="bulk-map-side bulk-map-side-pool">
                  <strong class="text-muted d-block" style="font-size:13px;"><span class="bulk-map-step">1</span>Colonnes de votre fichier</strong>
                  <div class="bulk-map-hint" style="font-size:11px;margin:2px 0 6px;">Glissez une colonne vers vos champs →</div>
                  <input type="text" id="bulkMapPoolSearch" class="form-control form-control-sm mb-2" placeholder="Rechercher une colonne…" autocomplete="off">
                  <div id="bulkMapPool" class="bulk-map-pool">
                    <span class="bulk-map-placeholder">Les colonnes de votre fichier apparaîtront ici.</span>
                  </div>
                </div>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="button" id="bulkMapCancel" class="btn btn-outline-secondary btn-sm">Annuler l'import</button>
                <div>
                  <span id="bulkMapInfo" class="text-muted small me-2"></span>
                  <button type="button" id="bulkMapGenerate" class="btn btn-rose btn-sm"><i class="bi bi-magic me-1"></i>Générer les cards</button>
                </div>
              </div>
            </div>

            <div id="bulkProgress" class="mt-3" style="display:none">
              <div id="bulkProgressLog" style="max-height:200px;overflow-y:auto;font-size:13px;background:var(--surface-2);border-radius:8px;padding:12px;font-family:monospace;"></div>
              <div id="bulkRecap" class="mt-3" style="display:none"></div>
            </div>
          </div>
          <div class="modal-footer justify-content-between">
            <span id="bulkSummary" class="text-muted small"></span>
            <div>
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
              <button type="button" id="btnBulkClose" class="btn btn-primary" style="display:none">Fermer et actualiser</button>
              <button type="submit" id="btnBulkSubmit" class="btn btn-rose">Valider les saisies</button>
            </div>
          </div>
        </form>
      </div>

      <!-- Template caché pour générer une nouvelle ligne d'inscrit (ligne de tableau) -->
      <template id="bulkRowTemplate">
        <tr class="bulk-row">
          <td class="col-num"><span class="bulk-row-num bulk-row-title">#1</span></td>
          <?php foreach ($bulkRowFields as $bf):
            if (empty($bf['bdd_column']) || ($bf['field_type'] ?? '') === 'guardian') continue; // pas de colonne BDD en mode bulk
            $req = (int) ($bf['required_saisie_multiple'] ?? 0);
            $type = $bf['field_type'] ?? 'text';
            $bdd  = htmlspecialchars($bf['bdd_column']);
            $reqAttr = $req ? ' data-required="1"' : '';
            $optCls  = in_array($bf['bdd_column'], $bulkOptionalCols, true) ? ' bulk-col-optional' : '';
          ?>
            <td data-bdd="<?= $bdd ?>" class="<?= trim($optCls) ?>">
              <?php if ($type === 'select'):
                $opts = array_map('trim', explode(',', $bf['options_list'] ?? '')); ?>
                <select data-bdd="<?= $bdd ?>" class="form-select form-select-sm bulk-field"<?= $reqAttr ?>>
                  <option value="">—</option>
                  <?php foreach ($opts as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif (($bf['bdd_column'] ?? '') === 'naissance'): ?>
                <input type="text" inputmode="numeric" autocomplete="off" data-bdd="<?= $bdd ?>" class="form-control form-control-sm bulk-field bulk-birthdate" placeholder="Âge / année"<?= $reqAttr ?>>
              <?php elseif ($type === 'date'): ?>
                <input type="date" data-bdd="<?= $bdd ?>" class="form-control form-control-sm bulk-field"<?= $reqAttr ?>>
              <?php elseif ($type === 'number'): ?>
                <input type="number" step="0.01" min="0" data-bdd="<?= $bdd ?>" class="form-control form-control-sm bulk-field<?= $bdd === 'montant_du' ? ' bulk-montant' : '' ?>"<?= $reqAttr ?><?= $bdd === 'montant_du' ? ' value="' . htmlspecialchars((string) $registration_fee) . '"' : '' ?>>
              <?php elseif ($type === 'email'): ?>
                <input type="email" data-bdd="<?= $bdd ?>" class="form-control form-control-sm bulk-field"<?= $reqAttr ?>>
              <?php elseif (($bf['bdd_column'] ?? '') === 'commentaire' || $type === 'textarea'): ?>
                <textarea data-bdd="<?= $bdd ?>" class="form-control form-control-sm bulk-field" rows="1"<?= $reqAttr ?>></textarea>
              <?php else: ?>
                <input type="text" data-bdd="<?= $bdd ?>" class="form-control form-control-sm bulk-field"<?= $reqAttr ?>>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="col-actions"><button type="button" class="btn btn-sm btn-outline-danger bulk-row-remove" title="Retirer cette personne"><i class="bi bi-x-lg"></i></button></td>
        </tr>
      </template>
      <?php endif; ?>
    </div>
  </div>
</div></div>

<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Modifier l'inscription</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fEdit">
      <div class="modal-body row g-2">
        <input type="hidden" name="id">
        <input type="hidden" name="origine" value="Admin">
        <?php foreach ($adminFields as $f): ?>
          <?php /* « Autorisation parentale (mineur) » : déjà renseignée à l'inscription → masquée ici. */ ?>
          <?php if (($f['field_type'] ?? '') === 'guardian') continue; ?>
          <?php /* Obligation cohérente avec le formulaire « Nouvel inscrit » : la colonne
                   « Admin requis » (required_admin) ne s'applique qu'au rôle admin ; les autres
                   rôles autorisés à éditer conservent l'obligation générale `required`. */ ?>
          <?php if ($role !== 'admin') { $f['required_admin'] = $f['required'] ?? 0; } ?>
          <?php /* L'email est facultatif dans ce modal admin, même s'il est requis ailleurs. */ ?>
          <?= renderFormField($f, '', ($f['bdd_column'] ?? '') === 'email', 'admin') ?>
        <?php endforeach; ?>
        <div class="col-md-6">
          <label class="form-label">Paiement</label>
          <select name="paiement_mode" class="form-select paiement-select">
            <option value="CB">CB</option>
            <option value="espece">Espèce</option>
            <option value="cheque">Chèque</option>
            <option value="virement">Virement</option>
            <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
            <option value="enfant_tshirt">Enfant -<?= $childAge ?> ans (avec T-shirt)</option>
          </select>
          <div class="montant-du-display mt-2" style="display:none;font-size:14px;font-weight:600;color: var(--ink)"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button class="btn btn-rose">Sauvegarder</button></div>
    </form>
  </div></div></div>

<?php if($canEditReg): ?>
<!-- Modal « Modifier la sélection » : modification groupée des inscriptions cochées.
     Chaque champ a une case « appliquer ? » ; seuls les champs cochés sont écrasés.
     Nom et Prénom sont volontairement exclus (jamais communs à plusieurs inscrits). -->
<div class="modal fade" id="bulkEditModal" tabindex="-1"><div class="modal-dialog modal-lg">
  <div class="modal-content"><div class="modal-header">
    <h5 class="modal-title">Modifier la sélection (<span id="bulkEditCount">0</span> inscription·s)</h5>
    <button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="fBulkEdit">
      <div class="modal-body">
        <p class="text-muted small mb-3"><i class="bi bi-info-circle me-1"></i>Cochez les champs à modifier : seuls les champs cochés seront appliqués à <b>toutes</b> les inscriptions sélectionnées. Les autres restent inchangés.</p>
        <div class="row g-3">
          <?php foreach ($adminFields as $f):
            $bc = $f['bdd_column'] ?? '';
            if ($bc === '' || $bc === 'nom' || $bc === 'prenom') continue; ?>
          <div class="col-12 bulk-field-row" data-col="<?= htmlspecialchars($bc, ENT_QUOTES) ?>">
            <div class="form-check mb-1">
              <input class="form-check-input bulk-apply" type="checkbox" id="ba_<?= htmlspecialchars($bc, ENT_QUOTES) ?>">
              <label class="form-check-label fw-semibold" for="ba_<?= htmlspecialchars($bc, ENT_QUOTES) ?>">Modifier « <?= htmlspecialchars($f['label']) ?> »</label>
            </div>
            <div class="bulk-field-input ps-4"><?= renderFormField($f, '', true, 'admin') ?></div>
          </div>
          <?php endforeach; ?>
          <!-- Paiement (champ système : recalcule le montant dû à l'application) -->
          <div class="col-12 bulk-field-row" data-col="paiement_mode">
            <div class="form-check mb-1">
              <input class="form-check-input bulk-apply" type="checkbox" id="ba_paiement_mode">
              <label class="form-check-label fw-semibold" for="ba_paiement_mode">Modifier « Paiement »</label>
            </div>
            <div class="bulk-field-input ps-4">
              <div class="col-md-6">
                <select name="paiement_mode" class="form-select">
                  <option value="CB">CB</option>
                  <option value="espece">Espèce</option>
                  <option value="cheque">Chèque</option>
                  <option value="virement">Virement</option>
                  <option value="gratuit">Gratuit / Enfant -<?= $childAge ?> ans (sans T-shirt)</option>
                  <option value="enfant_tshirt">Enfant -<?= $childAge ?> ans (avec T-shirt)</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <button class="btn btn-rose" type="submit">Appliquer à la sélection</button>
      </div>
    </form>
  </div></div></div>
<?php endif; ?>

<?php // Modale « Import Excel AssoConnect » déplacée vers inc/setting.php (onglet Import AssoConnect). ?>

<!-- QR Scanner Modal -->
<style>
  #qrScanModal .modal-dialog { max-width: 600px; }
  #qrScanModal .qr-size-btn {
    min-width: 60px; min-height: 52px;
    font-size: 1.1rem; font-weight: 700;
    border-radius: 12px; border-width: 2px;
  }
  #qrScanModal .qr-size-btn.active.btn-primary { transform: scale(1.08); box-shadow: 0 4px 12px rgba(13,110,253,.35); }
  @media (max-width: 576px) {
    #qrScanModal .modal-dialog { margin: 0; max-width: 100%; height: 100%; }
    #qrScanModal .modal-content { border-radius: 0; min-height: 100dvh; }
    #qrScanModal .modal-body { padding: 1rem; }
    #qrScanModal .qr-size-btn { min-width: 52px; min-height: 56px; font-size: 1.15rem; flex: 1 1 0; }
    #qrScanModal #qrManualInput { font-size: 1.1rem; height: 48px; }
    #qrScanModal #qrManualBtn { font-size: 1.1rem; height: 48px; padding: 0 1.2rem; }
    #qrScanModal #qrPersonName { font-size: 1.2rem; }
  }
  @media (min-width: 577px) and (max-width: 992px) {
    #qrScanModal .modal-dialog { max-width: 90%; }
    #qrScanModal .qr-size-btn { min-width: 70px; min-height: 54px; flex: 1 1 0; }
  }
  /* Tablette en portrait : limite la hauteur de la caméra pour laisser la place au champ manuel */
  @media (orientation: portrait) and (min-width: 577px) and (max-width: 1100px) {
    #qrScanModal #qrReader {
      max-width: 320px;
      margin: 0 auto;
      overflow: hidden;
    }
    #qrScanModal #qrReader video {
      width: 100% !important;
      height: auto !important;
      max-height: 45vh !important;
      object-fit: cover;
    }
  }
</style>
<div class="modal fade" id="qrScanModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Remise T-shirt</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Scanner zone -->
        <div id="qrReader" style="width:100%"></div>

        <!-- Manual input -->
        <div class="text-center mt-3" id="qrManualZone">
          <p class="text-muted mb-1" style="font-size:13px">Ou saisir le N° d'inscription :</p>
          <div class="input-group" style="max-width:320px;margin:0 auto">
            <input type="text" id="qrManualInput" class="form-control" placeholder="N° inscription (ex: E21800406)">
            <button id="qrManualBtn" class="btn btn-primary">OK</button>
          </div>
        </div>

        <!-- Result card (hidden by default) -->
        <div id="qrPersonCard" class="mt-3" style="display:none">
          <hr>
          <!-- Eligibility badge -->
          <div id="qrEligibility" class="text-center mb-3"></div>

          <!-- Person info -->
          <div class="text-center mb-3">
            <div class="fw-bold fs-4" id="qrPersonName"></div>
            <span class="text-muted" id="qrPersonNo"></span>
            <span id="qrPersonVille" class="badge bg-secondary ms-2"></span>
          </div>

          <!-- T-shirt selector -->
          <div id="qrTshirtZone">
            <label class="form-label fw-semibold text-center d-block mb-2">Taille T-shirt :</label>
            <div class="d-flex flex-wrap gap-2 justify-content-center" id="qrTshirtBtns">
              <button class="btn btn-outline-dark qr-size-btn" data-size="XS">XS</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="S">S</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="M">M</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="L">L</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="XL">XL</button>
              <button class="btn btn-outline-dark qr-size-btn" data-size="XXL">XXL</button>
            </div>
            <div id="qrSaveStatus" class="mt-2 text-center" style="display:none"></div>
          </div>
          <hr>
          <div class="text-center">
            <button id="qrNextScan" class="btn btn-primary"><i class="bi bi-qr-code-scan me-2"></i>Scanner suivant</button>
          </div>
        </div>

        <!-- Carte GROUPE (QR groupé) : liste tous les inscrits du groupe pour valider
             les tailles d'un coup. Affichée à la place de #qrPersonCard pour un QR « G:… ». -->
        <div id="qrGroupCard" class="mt-3" style="display:none">
          <hr>
          <div id="qrGroupTitle" class="fw-bold text-center mb-3"></div>
          <div id="qrGroupList"></div>
          <hr>
          <div class="text-center">
            <button id="qrGroupNext" class="btn btn-primary"><i class="bi bi-qr-code-scan me-2"></i>Scanner suivant</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═════════ JS ═════════ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="../js/inscription-form.js?v=7" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
<script src="../js/reg-stats.js?v=3" nonce="<?= $GLOBALS['csp_nonce'] ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.10/datatables.min.js" integrity="sha384-3wB6mhez87GBdPpEqKMU2wAH2Cjcvj8ynU/n7blM/JW4BLpVD0aTrx4ZE7IwFLSH" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/colreorder/1.7.0/js/dataTables.colReorder.min.js"></script>
<?php // SheetJS (XLSX) retiré : l'import manuel (seul usage client) a migré vers Réglages → Import AssoConnect. Le bulk « Ajout multiple » parse côté serveur. ?>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script nonce="<?= $GLOBALS['csp_nonce'] ?>">
const _csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const userRole = '<?= $role ?>';
const canEditReg     = <?= $canEditReg ? 'true' : 'false' ?>;
const canScanQr      = <?= $canScanQr ? 'true' : 'false' ?>;
const canTshirtMode  = <?= $canTshirtMode ? 'true' : 'false' ?>;
const registrationFee = <?= json_encode($registration_fee) ?>;
const childAge = <?= json_encode($childAge) ?>; // âge seuil « enfant » pour les libellés « -N ans »
let tableData = []; // Pour stocker les données triées par date

/* ══ Affichage dynamique du « Montant dû » sous le select paiement ══ */
function formatMontant(n){
  var v = parseFloat(n);
  if(!isFinite(v)) v = 0;
  return v.toFixed(2).replace(/\.00$/,'') + ' €';
}
function updateMontantDisplay(selectEl){
  var wrap = selectEl.closest('.col-md-6');
  if(!wrap) return;
  var disp = wrap.querySelector('.montant-du-display');
  if(!disp) return;
  var val = selectEl.value;
  if(!val){ disp.style.display='none'; disp.textContent=''; return; }
  // Dans le modal d'édition, le montant représente ce qui a déjà été payé
  // (l'inscription existe déjà), pas une somme à régler.
  var isEdit = !!selectEl.closest('#editModal');
  var labelDu  = isEdit ? 'Montant payé' : 'Montant dû';
  var labelDue = isEdit ? 'Montant payé' : 'Montant total dû';
  if(val === 'gratuit'){
    disp.style.display='block';
    disp.innerHTML = labelDu + ' : <span style="color:#16a34a">'+formatMontant(0)+'</span>';
  } else {
    disp.style.display='block';
    disp.innerHTML = labelDue + ' : <span style="color:var(--primary, #f42182)">'+formatMontant(registrationFee)+'</span>';
  }
}
document.addEventListener('change', function(e){
  if(e.target && e.target.matches('.paiement-select')) updateMontantDisplay(e.target);
});
// Réinitialise l'affichage à l'ouverture des modales
['addModal','editModal'].forEach(function(id){
  var m = document.getElementById(id);
  if(!m) return;
  m.addEventListener('shown.bs.modal', function(){
    var sel = m.querySelector('.paiement-select');
    if(sel) updateMontantDisplay(sel);
  });
});

/* ══ Rang « payant » : numéro d'ordre en ignorant les non-payés ══ */
// Renvoie le rang chronologique d'un inscrit en ne comptant que ceux ayant
// effectivement payé (montant_du > 0). Les non-payés sont écartés du décompte.
function computePaidRank(allRows, inscriptionNo){
  var sorted = allRows.slice().sort(function(a,b){
    return new Date(a.created_at) - new Date(b.created_at);
  });
  var paidCount = 0;
  for (var i = 0; i < sorted.length; i++){
    var paid = parseFloat(sorted[i].montant_du) > 0;
    if (paid) paidCount++;
    if (String(sorted[i].inscription_no) === String(inscriptionNo)){
      return paid ? paidCount : -1; // -1 si non payé
    }
  }
  return -1;
}

/* ══ Outils ════ */
// normalizeBirth est fourni par js/inscription-form.js (window.FERInscription).
const normalizeBirth = (fd) => { if (window.FERInscription) FERInscription.normalizeBirth(fd); };

/* ══ DataTable ════ */
// État initial fourni par le serveur (users.ui_prefs) → appliqué dès le rendu, sans flash.
let tshirtMode = <?= json_encode($initTshirtMode) ?>;
let showCommentTshirt = <?= json_encode($initShowComment) ?>; // colonne Commentaire (mode T-shirt)
function refreshButtons(){
  // Libellé = l'ACTION du bouton (ce vers quoi il bascule), pas l'état courant.
  var t = tshirtMode ? 'Mode standard' : 'Remise T-shirts';
  $('#modeTS, #modeTS_m').text(t);
  var sp = document.querySelector('#ocModeToggle span'); // vrai bouton (navbar)
  if (sp) sp.textContent = t;
}
refreshButtons();

// Pagination compacte type "1 ... 4 5 6 ... 12"
$.fn.dataTable.ext.pager.numbers_length = 7;

const tbl=$('#tbl').DataTable({
  ajax:{
    url:'../admin-api.php?route=registrations',
    dataSrc: function(json) {
      // Trier les données par date d'ajout (du plus ancien au plus récent)
      tableData = json.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
      return tableData;
    }
  },
  columns:[
    <?php if($canEditReg || $canDeleteReg): ?>
    // Colonne de sélection (actions groupées). Le titre contient la case « tout
    // cocher » (page affichée). Chaque ligne porte une case avec l'id de l'inscription.
    {data:null, orderable:false, searchable:false, className:'text-center bulk-cb-col', width:'12px',
      title:'<input type="checkbox" class="form-check-input bulk-all" title="Tout cocher (page affichée)">',
      render:function(data,type,row){
        if(type!=='display') return '';
        return '<input type="checkbox" class="form-check-input bulk-cb" value="'+row.id+'">';
      }
    },
    <?php endif; ?>
    {data:'id',visible:false},
    {data: null, title: 'ID', width: '46px', className: 'text-center col-seq', orderable: false, defaultContent: ''},
    {data:'inscription_no',title:'N°'},
    <?php foreach ($allActiveFields as $af):
      if (empty($af['bdd_column'])) continue; // pas une colonne BDD (ex. autorisation parentale)
      $col = $af['bdd_column'];
      // created_at (« Date ajout ») et date_inscription (« Date d'inscription ») sont rendus
      // plus bas en colonnes dédiées, formatées sans heure : on saute leur colonne dynamique
      // générique qui afficherait le timestamp brut en doublon.
      if ($col === 'created_at' || $col === 'date_inscription') continue;
      $lbl = htmlspecialchars($af['label'], ENT_QUOTES);
      if ($col === 'tshirt_size'): ?>
    {data:'tshirt_size',title:'<?= $lbl ?>',render:(v,t,r)=>{
      if(t!=='display') return v??''; if(!tshirtMode) return v??'';
      // Le dropdown est interactif si l'utilisateur peut éditer OU s'il a le droit scanner QR
      if(!canEditReg && !canScanQr) return `<span class="text-muted" style="font-style:italic;opacity:.6">${v||'-'}</span>`;
      const sz=<?= json_encode(array_map('trim', explode(',', $af['options_list'] ?? '-,XS,S,M,L,XL,XXL'))) ?>;
      return `<select class="form-select form-select-sm tshirt-dd" data-id="${r.id}">${sz.map(s=>`<option${s===v?' selected':''}>${s}</option>`).join('')}</select>`;
    }},
      <?php elseif ($col === 'naissance'): ?>
    {data:'naissance',title:'<?= $lbl ?>',defaultContent:'',render:function(val,type){
      // Colonne « Âge » : valeur stockée = âge (nouveau modèle) ou donnée héritée
      // (année/date). Conversion → âge. Tri/filtre sur l'âge numérique.
      var age=(window.FERInscription)?FERInscription.ageFromBirth(val):null;
      if(type!=='display') return (age==null?'':age);
      return (!age?'–':(age+' ans')); // jamais « 0 ans » → tiret
    }},
      <?php else: ?>
    {data:'<?= $col ?>',title:'<?= $lbl ?>',defaultContent:'',render:$.fn.dataTable.render.text()},
      <?php endif; ?>
    <?php endforeach; ?>
    {data:'paiement_mode',title:'Paiement',defaultContent:'',render:function(val,type){
      // Display : libellé convivial. Filter/sort/recherche : valeur brute
      // (sinon les filtres et la recherche par regex ^gratuit$ ne fonctionnent plus).
      if(type==='display'){
        if(!val) return '';
        var lc = String(val).toLowerCase();
        if(lc === 'gratuit') return `Gratuit/-${childAge}ans`;
        if(lc === 'enfant_tshirt') return 'en ligne (CB)'; // legacy : la catégorie est désormais dans Prestation
        return val;
      }
      return val;
    }},
    {data:'montant_du', title:'Montant', className:'text-start text-nowrap', defaultContent:'0', render:function(val,type){
      // Affichage formaté « 12 € » ; pour le tri/filtre/recherche on garde la valeur
      // brute (sinon le filtre par colonne « ^12$ » ne correspondrait pas à « 12 € »).
      if(type!=='display') return val;
      var n = parseFloat(val);
      if(!isFinite(n)) n = 0;
      return n.toFixed(2).replace(/\.00$/,'') + ' €';
    }},
    {data:'created_at', title:'Date ajout', render:function(val,type){
      if(type==='display'||type==='filter'){ if(!val) return ''; return new Date(val).toLocaleDateString('fr-FR'); }
      return val;
    }, width:'110px', className:'text-nowrap text-center'},
    {data:'date_inscription', title:'Date d\'inscription', defaultContent:'', render:function(val,type){
      if(type==='display'||type==='filter'){ if(!val) return ''; return new Date(val).toLocaleDateString('fr-FR'); }
      return val;
    }, width:'120px', className:'text-nowrap text-center'},
    {data:'origine',title:'Origine',defaultContent:'',render:$.fn.dataTable.render.text()},
    {data:'prestation',title:'Prestation',defaultContent:'',render:function(val,type,row){
      if(type==='display'){
        var lc = String(val||'').toLowerCase();
        if(lc === 'enfant_tshirt')  return `Enfant -${childAge} +T-shirt`;
        if(lc === 'enfant_gratuit') return `Enfant -${childAge} (gratuit sans t-shirt)`;
        if(lc === 'tarif_unique')   return 'Tarif unique';
        // Repli (anciens inscrits sans prestation) : déduire du mode de paiement.
        var pm = String((row && row.paiement_mode) || '').toLowerCase();
        if(pm === 'gratuit') return `Enfant -${childAge} (gratuit)`;
        if(pm === 'enfant_tshirt') return `Enfant -${childAge} +T-shirt`;
        return val ? val : 'Tarif unique';
      }
      return val;
    }}
    <?php if($canEditReg || $canDeleteReg): ?>,
    {
      data:null,
      title:'Actions',
      orderable:false,
      className:'text-center',
      width:'120px',
      render: function(data, type, row) {
        let buttons = '';
        <?php if($canEditReg): ?>
        buttons += '<button class="btn btn-sm btn-outline-primary edit me-1" title="Modifier"><i class="bi bi-pencil"></i></button>';
        <?php endif; ?>
        <?php if($canDeleteReg): ?>
        buttons += '<button class="btn btn-sm btn-outline-danger delete-row" title="Supprimer"><i class="bi bi-trash3"></i></button>';
        <?php endif; ?>
        return `<div class="action-buttons">${buttons}</div>`;
      }
    }
    <?php endif; ?>
  ],
  dom:'lrtip',
  autoWidth:false,
  orderCellsTop:true,
  language:{
    emptyTable:    'Aucune inscription enregistrée pour le moment.',
    zeroRecords:   'Aucun résultat.',
    loadingRecords:'Chargement…',
    lengthMenu:    'Afficher _MENU_ inscriptions',
    info:          '_START_ à _END_ sur _TOTAL_',
    infoEmpty:     '0 sur 0',
    infoFiltered:  '(filtré sur _MAX_)',
    paginate:      { first: '«', previous: '‹', next: '›', last: '»' }
  },
  // Réordonnancement des colonnes par glisser-déposer des en-têtes. L'ordre choisi
  // est sauvegardé par utilisateur (route ui-prefs) et réappliqué au chargement.
  <?php
    // Index de la colonne created_at (« Date ajout »), recalculé pour rester juste
    // quel que soit le nombre de champs dynamiques ET la présence de la colonne de
    // cases à cocher. Disposition : [cases], id(hidden), ID, N°, [champs dyn], Paiement, Montant, created_at.
    $dynCount = 0;
    foreach ($allActiveFields as $af) {
        if (empty($af['bdd_column'])) continue;
        if ($af['bdd_column'] === 'created_at' || $af['bdd_column'] === 'date_inscription') continue;
        $dynCount++;
    }
    // $cbCol est défini en haut du fichier (présence de la colonne de cases).
    $createdAtIdx = 5 + $dynCount + $cbCol;
  ?>
  // Réordonnancement par glisser-déposer ; la colonne de cases (si présente) reste fixée à gauche.
  colReorder:<?= $cbCol ? '{ fixedColumnsLeft: 1 }' : 'true' ?>,
  order: [[<?= $createdAtIdx ?>, 'asc']], // Trier par date d'ajout par défaut (colonne created_at)
  rowCallback: function (row, data, _displayNum, displayIndex) {
    // Numéro séquentiel affiché dans la colonne « ID ». On cible la cellule par sa
    // classe (.col-seq) et non par sa position : robuste si l'utilisateur déplace
    // une autre colonne avant elle via ColReorder.
    $('td.col-seq', row).text(displayIndex + 1);
  },
  drawCallback: function(){
    // Surlignage « X premiers » : on ne compte que les inscrits qui ont payé
    // (montant_du > 0). Les non-payés ne consomment pas un slot T-shirt.
    var api = this.api();
    var hlLimit = <?= (int) $highlightLimit ?>;
    var paidCount = 0;
    $('#tbl tbody tr').each(function(){
      var d = api.row(this).data();
      if(!d){ return; }
      var paid = parseFloat(d.montant_du) > 0;
      if(paid) paidCount++;
      $(this).toggleClass('first-750', paid && hlLimit > 0 && paidCount <= hlLimit);
      $(this).toggleClass('row-unpaid', !paid);
    });
  },
  initComplete:function(){
    buildColumnFilters(this.api());
    updateStats(this.api().data().toArray());
  }
});

tbl.on('xhr.dt',(e,s,json)=>{
  if(json) {
    tableData = json.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    updateStats(tableData);
  }
});

/* ══ Préférences d'interface (serveur, par utilisateur) — chargeur mutualisé ══
 * Toutes les prefs d'affichage du tableau (ordre, visibilité, largeurs des colonnes)
 * sont stockées dans users.ui_prefs via la route `ui-prefs`. On mutualise un seul
 * GET (mémoïsé) et un POST de fusion, réutilisés par l'ordre, la visibilité et les
 * largeurs. Le localStorage sert de cache de repli (hors-ligne) + source de migration. */
let _uiPrefs = null;        // objet de prefs résolu (null tant que non chargé)
let _uiPrefsPromise = null; // promesse mémoïsée du GET
function loadUiPrefs(){
  if(_uiPrefsPromise) return _uiPrefsPromise;
  _uiPrefsPromise = fetch('../admin-api.php?route=ui-prefs')
    .then(r=>r.json())
    .then(p=>{ _uiPrefs = (p && typeof p==='object') ? p : {}; return _uiPrefs; })
    .catch(()=>{ _uiPrefs = {}; return _uiPrefs; });
  return _uiPrefsPromise;
}
function saveUiPref(patch){
  if(_uiPrefs && typeof _uiPrefs==='object') Object.assign(_uiPrefs, patch); // copie mémoire à jour
  return fetch('../admin-api.php?route=ui-prefs',{
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':_csrfToken},
    body: JSON.stringify(patch)
  }).catch(()=>{});
}

/* ══ Ordre des colonnes mémorisé par utilisateur (ColReorder + route ui-prefs) ══ */
let _applyingColOrder = false; // évite de re-sauvegarder pendant l'application de l'ordre chargé
// Restaure l'ordre enregistré (silencieux si rien / si le nombre de colonnes a changé,
// p. ex. après ajout/suppression d'un champ depuis « Gestion des champs »).
loadUiPrefs().then(p=>{
  const ord = p && p.dashboard_col_order;
  if(Array.isArray(ord) && ord.length === tbl.columns().count()){
    _applyingColOrder = true;
    try { tbl.colReorder.order(ord, true); } catch(_){}
    _applyingColOrder = false;
  }
});
// À chaque déplacement de colonne : les entonnoirs suivent le <th> automatiquement.
// On ferme juste un éventuel popover ouvert (son ancrage a bougé) puis on sauvegarde.
tbl.on('column-reorder', function(){
  if(_colFilterPop) _colFilterPop.classList.remove('show');
  buildColumnFilters(tbl);
  if(_applyingColOrder) return;
  saveUiPref({ dashboard_col_order: tbl.colReorder.order() });
});


// Événements pour détecter l'ouverture/fermeture des menus t-shirt
$('#tbl').on('mousedown', '.tshirt-dd', function(e) {
  isDropdownOpen = true;
});

$('#tbl').on('focus', '.tshirt-dd', function() {
  isDropdownOpen = true;
});

$('#tbl').on('blur change', '.tshirt-dd', function() {
  setTimeout(() => {
    isDropdownOpen = false;
  }, 150);
});

$(document).on('click', function(e) {
  if (!$(e.target).hasClass('tshirt-dd')) {
    setTimeout(() => {
      isDropdownOpen = false;
    }, 100);
  }
});

// Variables pour gérer le refresh automatique
let refreshInterval;
let isDropdownOpen = false;

// Fonction pour démarrer le refresh automatique
function startAutoRefresh() {
  refreshInterval = setInterval(() => {
    if (!isDropdownOpen) {
      tbl.ajax.reload(null, false);
    }
  }, 5000);
}

startAutoRefresh();
$('#quickSearch').on('keyup',function(){tbl.search(this.value).draw();});

/* ══ Stats ════
 * Calcul + rendu délégués au module partagé js/reg-stats.js (window.FERRegStats),
 * commun au dashboard et à la page Statistiques (mêmes indicateurs, aucune
 * duplication). Le dashboard affiche 3 cartes ; le bouton « Autres stats » ouvre
 * un modal avec la totalité des indicateurs. */
let _lastRegStats = null;
function updateStats(data){
  _lastRegStats = FERRegStats.compute(data); // saison en cours → année courante
  FERRegStats.renderMainCards(document.getElementById('stats'), _lastRegStats);
  if(tshirtMode) $('#stats').hide(); else $('#stats').show();
}
// Ouverture du modal « Autres stats » : recalcule le corps à partir du dernier lot.
$(document).on('click', '[data-reg-stats-more]', function(){
  if(!_lastRegStats) return;
  FERRegStats.renderModalBody(document.getElementById('statsMoreBody'), _lastRegStats, { childAge: childAge });
  new bootstrap.Modal('#statsMoreModal').show();
});

/* ══ Filtres par colonne — entonnoir dans l'en-tête + popover ════
 * Remplace l'ancienne ligne de filtres clonée. Chaque colonne filtrable porte une
 * icône entonnoir dans son <th> ; un clic ouvre un popover listant les valeurs.
 * Les entonnoirs vivent dans le <th> : ils suivent automatiquement le
 * réordonnancement (ColReorder) et le masquage de colonnes, sans recalcul d'index. */
// Détection des colonnes filtrables par TITRE (libellés usuels) OU par clé de
// données (bdd_column) — robuste même si l'admin a renommé un libellé.
var FILTERABLE_TITLES = ['T-shirt','Taille T-shirt','Sexe','Paiement','Prestation','Entreprise','Origine','Ville','Montant'];
var FILTERABLE_DATA   = ['tshirt_size','sexe','paiement_mode','prestation','entreprise','origine','ville','montant_du'];
// Filtres d'en-tête par colonne (entonnoir + popover) — version locale.
function isFilterableCol(title, dataKey){
  return FILTERABLE_TITLES.indexOf(title) !== -1
      || (dataKey && FILTERABLE_DATA.indexOf(dataKey) !== -1);
}
var _activeColFilter = {};   // clé de données (bdd_column) -> valeur filtrée active
var _colFilterPop = null;

// Exécute cb(colApi) sur la colonne dont la source de données vaut dataKey.
// Robuste au réordonnancement (ColReorder) : aucun index numérique n'est mémorisé,
// donc un filtre reste lié à la bonne colonne même après un déplacement d'en-tête.
function withColByData(api, dataKey, cb){
  var done = false;
  api.columns().every(function(){
    if(!done && this.dataSrc() === dataKey){ cb(this); done = true; }
  });
  return done;
}

function getColFilterPop(){
  if(_colFilterPop) return _colFilterPop;
  _colFilterPop = document.createElement('div');
  _colFilterPop.className = 'col-filter-pop';
  document.body.appendChild(_colFilterPop);
  // Fermeture au clic extérieur / au défilement / redimensionnement.
  document.addEventListener('click', function(e){
    if(_colFilterPop.classList.contains('show')
       && !_colFilterPop.contains(e.target)
       && !$(e.target).closest('.col-filter-btn').length){
      _colFilterPop.classList.remove('show');
    }
  });
  window.addEventListener('resize', function(){ _colFilterPop.classList.remove('show'); });
  return _colFilterPop;
}

// Libellé convivial pour les catégories « Paiement »/« Prestation » (valeur brute conservée).
function friendlyFilterLabel(title, v){
  var s = String(v);
  if(title === 'Paiement'){
    var lcv = s.toLowerCase();
    if(lcv === 'gratuit') return `Gratuit/-${childAge}ans`;
    if(lcv === 'enfant_tshirt') return 'en ligne (CB)';
  } else if(title === 'Prestation'){
    var lcp = s.toLowerCase();
    if(lcp === 'tarif_unique') return 'Tarif unique';
    if(lcp === 'enfant_gratuit') return `Enfant -${childAge} (gratuit sans t-shirt)`;
    if(lcp === 'enfant_tshirt') return `Enfant -${childAge} +T-shirt`;
  } else if(title === 'Montant'){
    var n = parseFloat(s); if(!isFinite(n)) n = 0;
    return n.toFixed(2).replace(/\.00$/,'') + ' €';   // affiché « 12 € », filtre sur la valeur brute
  }
  return s;
}

function applyColFilter(api, dataKey, val, btn){
  withColByData(api, dataKey, function(col){
    if(val){
      var esc = String(val).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      col.search('^'+esc+'$', true, false).draw();
    } else {
      col.search('', true, false).draw();
    }
  });
  if(val){ _activeColFilter[dataKey] = val; if(btn) btn.classList.add('active'); }
  else { delete _activeColFilter[dataKey]; if(btn) btn.classList.remove('active'); }
}

function openColFilter(api, dataKey, title, btn){
  var pop = getColFilterPop();
  pop._forCol = dataKey;
  pop.innerHTML = '';
  var head = document.createElement('div');
  head.className = 'cfp-head';
  head.textContent = 'Filtrer : ' + title;
  pop.appendChild(head);

  var current = _activeColFilter[dataKey] != null ? String(_activeColFilter[dataKey]) : '';
  function addOpt(val, label){
    var o = document.createElement('div');
    o.className = 'cfp-opt' + (String(val) === current ? ' active' : '');
    var ic = document.createElement('i'); ic.className = 'bi bi-check2';
    var sp = document.createElement('span'); sp.textContent = label;
    o.appendChild(ic); o.appendChild(sp);
    o.addEventListener('click', function(e){
      e.stopPropagation();
      applyColFilter(api, dataKey, val, btn);
      pop.classList.remove('show');
    });
    pop.appendChild(o);
  }
  addOpt('', 'Tous');
  var seen = {};
  withColByData(api, dataKey, function(col){
    col.data().unique().sort().each(function(v){
      if(v === null || v === undefined || v === '') return;
      if(seen[v]) return; seen[v] = 1;
      addOpt(v, friendlyFilterLabel(title, v));
    });
  });

  // Positionnement (fixed) sous l'entonnoir, recadré dans la fenêtre.
  var r = btn.getBoundingClientRect();
  pop.style.visibility = 'hidden';
  pop.classList.add('show');
  var pw = pop.offsetWidth, ph = pop.offsetHeight;
  var left = Math.min(r.left, window.innerWidth - pw - 12);
  var top = r.bottom + 6;
  if(top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
  pop.style.left = Math.max(8, left) + 'px';
  pop.style.top = top + 'px';
  pop.style.visibility = '';
}

// Pose/maj des entonnoirs sur les en-têtes filtrables. Idempotent : sûr à rappeler
// après réordonnancement, changement de visibilité ou rechargement AJAX.
function buildColumnFilters(api){
  api.columns().every(function(){
    var th = this.header();
    if(!th) return;
    var title = $(th).text().trim();   // l'icône (pseudo-élément) n'est pas dans .text()
    var dataKey = this.dataSrc();      // clé de données immuable (bdd_column)
    var existing = th.querySelector('.col-filter-btn');
    if(!isFilterableCol(title, dataKey)){
      if(existing) existing.remove();   // libellé devenu non-filtrable (ex. renommage)
      return;
    }
    var btn = existing;
    if(!btn){
      btn = document.createElement('span');
      btn.className = 'col-filter-btn';
      btn.title = 'Filtrer';
      btn.innerHTML = '<i class="bi bi-funnel"></i>';
      btn.addEventListener('mousedown', function(e){ e.stopPropagation(); }); // n'amorce pas le tri/déplacement
      // dataKey est capturé dans le closure : immuable, il reste correct même après
      // un réordonnancement de colonnes (l'entonnoir suit son <th> et sa clé).
      btn.addEventListener('click', function(e){
        e.stopPropagation(); e.preventDefault();                              // ne déclenche pas le tri
        var pop = getColFilterPop();
        if(pop.classList.contains('show') && pop._forCol === dataKey){ pop.classList.remove('show'); return; }
        openColFilter(api, dataKey, title, btn);
      });
      th.appendChild(btn);
    }
    if(_activeColFilter[dataKey] != null) btn.classList.add('active');
    else btn.classList.remove('active');
  });
}
// Wrapper : réutilisé par les points d'appel (restore visibilité, toggle colonnes).
function rebuildDashFilters(){ try { buildColumnFilters(tbl); } catch(e) {} }

/* ══ Bascule Remise T-shirts ════ */
function applyTshirtMode() {
  const hideHeaders = ['Sexe', 'Téléphone', 'Email', 'Naissance', 'Paiement', 'Montant', 'Entreprise', 'Date ajout', 'Origine', 'Actions'];
  // Masquage par clé de données : robuste même si l'admin renomme la colonne
  // (le libellé « Commentaire » est paramétrable, contrairement à son bdd_column).
  const hideData = ['prestation', 'naissance', 'ville', 'date_inscription'];
  const aoColumns = tbl.settings()[0].aoColumns;
  tbl.columns().every(function () {
    const h = $(this.header()).text().trim();
    const d = aoColumns[this.index()].data;
    // Colonne Commentaire : masquée en mode T-shirt sauf si la bascule est activée.
    if (d === 'commentaire') {
      this.visible(!tshirtMode || showCommentTshirt, false);
    } else if (hideHeaders.includes(h) || hideData.includes(d)) {
      this.visible(!tshirtMode, false);
    }
  });
  // Masque les entonnoirs de filtre en mode T-shirt (et ferme un popover ouvert).
  $('.col-filter-btn').toggle(!tshirtMode);
  if(tshirtMode && _colFilterPop) _colFilterPop.classList.remove('show');
  if (tshirtMode) {
    $('body').addClass('hide-stats');
    $('#btnExport, #btnArchiveNow').hide();
    $('#colToggleWrap').hide();
    $('#toggleCommentTS').show();
  } else {
    $('body').removeClass('hide-stats');
    $('#btnExport, #btnArchiveNow').show();
    $('#colToggleWrap').show();
    $('#toggleCommentTS').hide();
    updateStats(tbl.data().toArray());
  }
  tbl.rows().invalidate().draw(false);
}
$('#modeTS, #modeTS_m').on('click', function () {
  tshirtMode = !tshirtMode;
  refreshButtons();
  applyTshirtMode();
  saveUiPref({ tshirt_mode: tshirtMode }); // mémorisé par utilisateur (persiste au refresh)
  if (this.id === 'modeTS_m') {bootstrap.Offcanvas.getInstance('#menuMobile').hide();}
});
// Bascule « Afficher commentaire » : n'a d'effet qu'en mode T-shirt.
$('#toggleCommentTS').on('click', function () {
  showCommentTshirt = !showCommentTshirt;
  $(this).toggleClass('active', showCommentTshirt);
  $(this).find('i').attr('class', (showCommentTshirt ? 'bi bi-toggle-on' : 'bi bi-toggle-off') + ' me-1');
  applyTshirtMode();
  saveUiPref({ tshirt_show_comment: showCommentTshirt }); // persiste au refresh
});
// État initial (déjà résolu côté serveur) : on reflète la bascule commentaire et on
// applique le mode dès maintenant — aucun flash, aucun aller-retour réseau.
$('#toggleCommentTS').toggleClass('active', showCommentTshirt)
  .find('i').attr('class', (showCommentTshirt ? 'bi bi-toggle-on' : 'bi bi-toggle-off') + ' me-1');
applyTshirtMode();

/* ══ MAJ taille T-shirt ════
 * On confirme chaque enregistrement par un toast (« Taille X enregistrée pour … »)
 * pour être certain que la remise est bien sauvegardée ; en cas d'échec (droit,
 * réseau, serveur) un toast d'erreur explicite s'affiche. */
$('#tbl').on('change','.tshirt-dd',function(){
  if(!canEditReg && !canScanQr) {
    alert('Vous n\'avez pas les droits pour modifier les tailles de t-shirts.');
    return;
  }
  const dd   = this;
  const size = dd.value;
  const id   = dd.dataset.id;
  const rowData = tbl.row($(dd).closest('tr')).data() || {};
  const who = (`${rowData.prenom||''} ${rowData.nom||''}`).trim() || ('n°' + (rowData.inscription_no || id));
  fetch('../admin-api.php?route=registrations',{
    method:'PUT',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},
    body:new URLSearchParams({id, tshirt_size:size})
  })
  .then(r => r.json().catch(() => ({})).then(j => ({ ok: r.ok, j })))
  .then(({ ok, j }) => {
    if (!(ok && j && j.ok)) throw new Error((j && (j.err || j.message)) || 'Enregistrement refusé');
    rowData.tshirt_size = size; // garde la donnée en mémoire cohérente (stats / redraw)
    const label = (size && size !== '-') ? ('Taille ' + size + ' enregistrée') : 'Taille retirée';
    if (window.showToast) showToast(label + ' pour ' + who, 'success', 3000);
  })
  .catch(err => {
    if (window.showToast) showToast('Échec : taille non enregistrée pour ' + who + ' (' + err.message + ')', 'danger', 6000);
    else alert('Échec de l\'enregistrement de la taille : ' + err.message);
  });
});

/* ══ SUPPRESSION ════ */

/* Rattachements de l'espace coureur pour une ou plusieurs inscriptions.
   Les tables de l'espace coureur désignent un coureur par (année, n° d'inscription)
   et n'ont donc AUCUNE clé étrangère vers registrations : MySQL ne peut pas
   s'opposer à la suppression. On avertit avant, plutôt que d'orpheliner un chrono
   ou un compte en silence. En cas d'échec de l'appel, on n'empêche rien. */
function fetchRegistrationLinks(ids) {
  return fetch('../admin-api.php?route=registrations&links=1&ids=' + encodeURIComponent(ids.join(',')), {
    headers: {'X-CSRF-TOKEN': _csrfToken}
  })
    .then(r => r.json())
    .catch(() => ({ ok: false }));
}

/* Phrase d'avertissement, ou chaîne vide s'il n'y a rien à signaler. */
function linksWarning(info) {
  if (!info || !info.ok) return '';
  const bouts = [];
  if (info.resultats > 0) bouts.push(info.resultats > 1 ? `${info.resultats} résultats de course enregistrés` : 'un résultat de course enregistré');
  if (info.comptes   > 0) bouts.push(info.comptes   > 1 ? `${info.comptes} comptes coureurs rattachés`      : 'un compte coureur rattaché');
  if (!bouts.length) return '';
  return `\n\n⚠️ Attention : cette sélection a ${bouts.join(' et ')}.\n`
       + `Ces données ne seront pas supprimées mais deviendront orphelines`
       + ` (repérables via update.php → Contrôle d'intégrité).`;
}

$('#tbl').on('click', '.delete-row', function() {
  const row = tbl.row($(this).closest('tr'));
  const data = row.data();

  fetchRegistrationLinks([data.id]).then(info => {
    if (!confirm(`Êtes-vous sûr de vouloir supprimer l'inscription de ${data.prenom} ${data.nom} ?`
                 + linksWarning(info))) {
      return;
    }
    deleteRegistrationRow(row, data);
  });
});

function deleteRegistrationRow(row, data) {
  fetch('../admin-api.php?route=registrations', {
    method: 'DELETE',
    headers: {'Content-Type': 'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},
    body: new URLSearchParams({id: data.id})
  })
  .then(response => response.json())
  .then(result => {
    if (result.success || result.ok) {
      // Supprimer la ligne du tableau
      row.remove().draw(false);
      // Mettre à jour les statistiques
      updateStats(tbl.data().toArray());
      alert('Inscription supprimée avec succès');
    } else {
      alert('Erreur lors de la suppression : ' + (result.message || 'Erreur inconnue'));
    }
  })
  .catch(error => {
    console.error('Erreur:', error);
    alert('Erreur de communication avec le serveur');
  });
}

<?php if($canEditReg || $canDeleteReg): ?>
/* ══ ACTIONS GROUPÉES (sélection multiple) ════════════════════════════════
 * Les ids cochés sont mémorisés dans `bulkSel`. Les cases sont réappliquées à
 * chaque redraw (tri/filtre/pagination) car DataTables re-rend les lignes.
 */
const bulkSel = new Set();
function refreshBulkBar(){
  const n = bulkSel.size;
  $('#bulkCount').text(n);
  $('#bulkEditCount').text(n);
  $('#bulkBar').toggleClass('d-none', n===0).toggleClass('d-flex', n>0);
}
function clearBulkSel(){
  bulkSel.clear();
  $('#tbl tbody .bulk-cb').prop('checked', false);
  $('.bulk-all').prop('checked', false).prop('indeterminate', false);
  refreshBulkBar();
}
// Met à jour l'état de la case « tout cocher » d'après les lignes de la page.
function syncBulkAll(){
  const $cb = $('#tbl tbody .bulk-cb');
  const total = $cb.length, checked = $cb.filter(':checked').length;
  $('.bulk-all').prop('checked', total>0 && checked===total)
               .prop('indeterminate', checked>0 && checked<total);
}
$('#tbl').on('change','.bulk-cb',function(){
  const id = parseInt(this.value,10);
  if(this.checked) bulkSel.add(id); else bulkSel.delete(id);
  syncBulkAll(); refreshBulkBar();
});
$('#tbl').on('change','.bulk-all',function(){
  const on = this.checked;
  $('#tbl tbody .bulk-cb').each(function(){
    this.checked = on;
    const id = parseInt(this.value,10);
    if(on) bulkSel.add(id); else bulkSel.delete(id);
  });
  syncBulkAll(); refreshBulkBar();
});
// Après chaque redraw : recoche les lignes présentes dans la sélection.
tbl.on('draw', function(){
  $('#tbl tbody .bulk-cb').each(function(){ this.checked = bulkSel.has(parseInt(this.value,10)); });
  syncBulkAll(); refreshBulkBar();
});
$('#bulkClearBtn').on('click', clearBulkSel);

<?php if($canEditReg): ?>
/* — Modification groupée — */
// Active/désactive l'input d'un champ selon sa case « appliquer ».
$('#fBulkEdit').on('change','.bulk-apply',function(){
  $(this).closest('.bulk-field-row').find('.bulk-field-input').find('input,select,textarea')
         .prop('disabled', !this.checked);
});
$('#bulkEditBtn').on('click',function(){
  if(bulkSel.size===0){ alert('Sélectionnez au moins une inscription.'); return; }
  // Réinitialise : tout décoché → tous les inputs désactivés.
  $('#fBulkEdit .bulk-apply').prop('checked', false).trigger('change');
  $('#bulkEditCount').text(bulkSel.size);
  new bootstrap.Modal('#bulkEditModal').show();
});
$('#fBulkEdit').on('submit',function(e){
  e.preventDefault();
  if(bulkSel.size===0){ alert('Aucune inscription sélectionnée.'); return; }
  const fields = {};
  $('#fBulkEdit .bulk-field-row').each(function(){
    if(!$(this).find('.bulk-apply').is(':checked')) return;
    const $inp = $(this).find('.bulk-field-input [name]').first();
    if($inp.length) fields[$inp.attr('name')] = $inp.val();
  });
  if(Object.keys(fields).length===0){ alert('Cochez au moins un champ à modifier.'); return; }
  const ids = [...bulkSel];
  fetch('../admin-api.php?route=registrations-bulk',{
    method:'PUT',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':_csrfToken},
    body: JSON.stringify({ ids, fields })
  }).then(r=>r.json()).then(j=>{
    if(j.ok){
      bootstrap.Modal.getInstance('#bulkEditModal').hide();
      clearBulkSel();
      tbl.ajax.reload(null,false);
      alert((j.updated||0)+' inscription(s) modifiée(s).');
    } else { alert('Erreur : '+(j.err||'inconnue')); }
  }).catch(()=>alert('Erreur de communication avec le serveur.'));
});
<?php endif; ?>

<?php if($canDeleteReg): ?>
/* — Suppression groupée — */
$('#bulkDeleteBtn').on('click',function(){
  if(bulkSel.size===0){ alert('Sélectionnez au moins une inscription.'); return; }
  const ids = [...bulkSel];
  // Même avertissement que la suppression unitaire, agrégé sur la sélection.
  fetchRegistrationLinks(ids).then(info=>{
    if(!confirm('Supprimer '+ids.length+' inscription(s) ? Cette action est irréversible.'
                + linksWarning(info))) return;
    bulkDeleteRegistrations(ids);
  });
});

function bulkDeleteRegistrations(ids){
  fetch('../admin-api.php?route=registrations-bulk',{
    method:'DELETE',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':_csrfToken},
    body: JSON.stringify({ ids })
  }).then(r=>r.json()).then(j=>{
    if(j.ok){
      clearBulkSel();
      tbl.ajax.reload(null,false);
      alert((j.deleted||0)+' inscription(s) supprimée(s).');
    } else { alert('Erreur : '+(j.err||'inconnue')); }
  }).catch(()=>alert('Erreur de communication avec le serveur.'));
}
<?php endif; ?>
<?php endif; ?>

/* ══ AJOUT ════ */
$('#fAdd').on('submit',e=>{
  e.preventDefault();
  if(window.FERInscription && !FERInscription.ensureGuardian(e.target)) return;
  if(window.FERInscription) FERInscription.composeComment(e.target);
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../admin-api.php?route=registrations',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':_csrfToken},body:JSON.stringify(Object.fromEntries(fd))})
  .then(r=>r.json()).then(j=>{
    if(j.inscription_no){
      e.target.reset();
      $('#fAdd [name="nom"]').focus();
      // Reset affichage montant
      var _selAdd = document.querySelector('#fAdd .paiement-select');
      if(_selAdd){ var _wA = _selAdd.closest('.col-md-6'); var _dA = _wA && _wA.querySelector('.montant-du-display'); if(_dA){ _dA.style.display='none'; } }
      // Recharge la table puis affiche le toast avec le rang « payant » à jour
      tbl.ajax.reload(function(){ showInscriptionToast(j.inscription_no); }, false);
    }
  });
});

/* Toast d'ajout : n° d'inscription + rang de l'inscrit + éligibilité T-shirt */
function showInscriptionToast(inscriptionNo){
  const hlLimit = <?= (int)$highlightLimit ?>;
  // Numéro brut (toutes inscriptions confondues, payées ou non)
  const seqNo = parseInt(String(inscriptionNo).replace(/[^0-9]/g,'')) || 0;
  // Rang « payant » : on ne compte que les inscrits ayant payé (montant_du > 0).
  // Si l'inscrit vient d'être créé comme « gratuit » → rang = -1 (non éligible).
  const paidRank = computePaidRank(tableData, inscriptionNo);
  const isPaid = paidRank > 0;

  let html = '<div>Inscription <strong>n°'+inscriptionNo+'</strong> enregistrée&nbsp;!</div>'
           + '<div style="font-size:20px;font-weight:800;margin-top:6px;line-height:1.2">'
           +   seqNo+'<sup>e</sup> inscrit</div>';
  if(!isPaid){
    html += `<div style="font-size:12px;margin-top:4px;opacity:.95">Gratuit / Enfant -${childAge} ans — non éligible T-shirt</div>`;
  } else if(hlLimit>0){
    html += paidRank<=hlLimit
      ? '<div style="font-size:12px;margin-top:4px;opacity:.95">&#10003; '+paidRank+'ᵉ inscrit payant / '+hlLimit+' — éligible T-shirt</div>'
      : '<div style="font-size:12px;margin-top:4px;opacity:.95">'+paidRank+'ᵉ inscrit payant — au-delà des '+hlLimit+' premiers, non éligible T-shirt</div>';
  }
  showToast(html, 'success', 9000);
}


<?php if ($canBulkCreate): ?>
/* ══ AJOUT MULTIPLE — saisie en lot ════════════════════════════ */
(function() {
  const tmpl       = document.getElementById('bulkRowTemplate');
  const container  = document.getElementById('bulkRows');
  const addBtn     = document.getElementById('bulkAddBtn');
  const dupBtn     = document.getElementById('bulkDuplicateBtn');
  const summary    = document.getElementById('bulkSummary');
  const submitBtn  = document.getElementById('btnBulkSubmit');
  const closeBtn   = document.getElementById('btnBulkClose');
  const progress   = document.getElementById('bulkProgress');
  const logDiv     = document.getElementById('bulkProgressLog');
  const recapDiv   = document.getElementById('bulkRecap');
  const defaultFee = <?= json_encode((float) $registration_fee) ?>;
  const MAX_BULK   = 50; // Limite côté serveur : api.php route bulk-create
  // Tarif enfant selon l'âge (aperçu côté client ; le serveur reste l'autorité).
  const childPricingEnabled = <?= !empty($data['child_pricing_enabled']) ? 'true' : 'false' ?>;
  const childAge            = <?= (int) ($data['child_age_threshold'] ?? 12) ?>;
  const childAmount         = <?= json_encode((float) ($data['child_amount'] ?? 0)) ?>;
  // Applique le montant enfant à une ligne si l'âge saisi est sous le seuil.
  // Retourne true si le tarif enfant a été appliqué (sinon laisse la valeur en place).
  function applyChildMontantToRow(rowEl) {
    if (!childPricingEnabled) return false;
    const birthEl = rowEl.querySelector('.bulk-field[data-bdd="naissance"]');
    const montEl  = rowEl.querySelector('.bulk-montant');
    if (!birthEl || !montEl) return false;
    const age = (window.FERInscription && FERInscription.ageFromBirth)
      ? FERInscription.ageFromBirth(FERInscription.normalizeBirthValue(birthEl.value)) : null;
    if (age !== null && age < childAge) { montEl.value = String(childAmount); return true; }
    return false;
  }

  if (!tmpl || !container) return;

  // Mode d'envoi des mails. En mode « récap groupé », l'email par personne n'a
  // plus de sens : on masque le champ « Email » de chaque ligne ET sa cible de
  // mapping Excel, et on affiche le champ « email de contact » du récap.
  const recapEmailWrap = document.getElementById('recapEmailWrap');
  // Masque/affiche une colonne entière du tableau (en-tête + cellules) par champ.
  function setBulkColVisible(bdd, visible) {
    const disp = visible ? '' : 'none';
    document.querySelectorAll('#bulkTable [data-col-bdd="' + bdd + '"], #bulkRows td[data-bdd="' + bdd + '"]')
      .forEach(el => { el.style.display = disp; });
  }
  function isRecapMode() {
    return document.querySelector('#fBulkAdd input[name="mail_mode"]:checked')?.value === 'recap';
  }
  function applyMailModeUI() {
    const isRecap = isRecapMode();
    if (recapEmailWrap) recapEmailWrap.style.display = isRecap ? '' : 'none';
    // En récap groupé, l'email par personne n'a plus de sens : on masque la colonne.
    setBulkColVisible('email', !isRecap);
    // Cible « Email » de l'écran de correspondance Excel
    const mapEmail = document.querySelector('#bulkMapTargets .bulk-map-target[data-bdd="email"]');
    if (mapEmail) mapEmail.style.display = isRecap ? 'none' : '';
    updateSharedSummary();
  }
  document.querySelectorAll('#fBulkAdd input[name="mail_mode"]').forEach(radio => {
    radio.addEventListener('change', applyMailModeUI);
  });

  // Entreprise commune (interrupteur). ON : une seule entreprise saisie dans
  // l'en-tête, la colonne « Entreprise » disparaît du tableau (et du mapping Excel).
  // OFF : entreprise par personne (entreprises/associations/familles différentes).
  const sharedEntrepriseToggle = document.getElementById('sharedEntrepriseToggle');
  const sharedEntrepriseInput   = document.querySelector('#fBulkAdd [name="shared_entreprise"]');
  function isCommonEntreprise() {
    return !!(sharedEntrepriseToggle && sharedEntrepriseToggle.checked);
  }
  function applyEntrepriseModeUI() {
    const common = isCommonEntreprise();
    if (sharedEntrepriseInput) {
      sharedEntrepriseInput.style.display = common ? '' : 'none';
      if (!common) sharedEntrepriseInput.value = ''; // évite un résidu en mode par personne
    }
    setBulkColVisible('entreprise', !common);
    // Cible « Entreprise » de l'écran de correspondance Excel
    const mapEnt = document.querySelector('#bulkMapTargets .bulk-map-target[data-bdd="entreprise"]');
    if (mapEnt) mapEnt.style.display = common ? 'none' : '';
    updateSharedSummary();
  }
  if (sharedEntrepriseToggle) {
    sharedEntrepriseToggle.addEventListener('change', applyEntrepriseModeUI);
  }

  // ── Bloc « Réglages du lot » : ouvert au départ, se replie en résumé cliquable ──
  const bulkSharedBlock    = document.getElementById('bulkSharedBlock');
  const bulkSharedSummary  = document.getElementById('bulkSharedSummary');
  const bulkSharedCollapse = document.getElementById('bulkSharedCollapse');
  const sharedPaiementSel  = document.querySelector('#fBulkAdd [name="shared_paiement_mode"]');
  function setSharedCollapsed(collapsed) {
    if (!bulkSharedBlock || !bulkSharedSummary) return;
    bulkSharedBlock.style.display   = collapsed ? 'none' : '';
    bulkSharedSummary.style.display = collapsed ? 'flex' : 'none';
  }
  function updateSharedSummary() {
    const bp = document.getElementById('bssPaiement');
    if (bp) {
      const txt = (sharedPaiementSel && sharedPaiementSel.value)
        ? (sharedPaiementSel.options[sharedPaiementSel.selectedIndex]?.text || sharedPaiementSel.value)
        : 'à définir';
      bp.textContent = 'Paiement : ' + txt;
    }
    const be = document.getElementById('bssEnt');
    if (be) be.textContent = isCommonEntreprise()
      ? ('Entreprise : ' + ((sharedEntrepriseInput && sharedEntrepriseInput.value.trim()) || 'commune'))
      : 'Entreprise par personne';
    const bm = document.getElementById('bssMail');
    if (bm) bm.textContent = isRecapMode() ? 'Récap groupé' : 'Mail individuel';
  }
  if (bulkSharedCollapse) bulkSharedCollapse.addEventListener('click', () => { setSharedCollapsed(true); _sharedAutoCollapsed = true; });
  // Réouverture manuelle : on RÉ-ARME l'auto-repli pour qu'il se replie de nouveau
  // dès qu'on retourne saisir dans le tableau.
  if (bulkSharedSummary)  bulkSharedSummary.addEventListener('click', () => { setSharedCollapsed(false); _sharedAutoCollapsed = false; });
  if (sharedPaiementSel)  sharedPaiementSel.addEventListener('change', updateSharedSummary);
  if (sharedEntrepriseInput) sharedEntrepriseInput.addEventListener('input', updateSharedSummary);
  // Auto-repli dès qu'on commence à saisir une personne dans le tableau.
  let _sharedAutoCollapsed = false;
  container.addEventListener('focusin', () => {
    if (_sharedAutoCollapsed) return;
    _sharedAutoCollapsed = true;
    setSharedCollapsed(true);
  });

  // Bouton « Plus / Moins de colonnes » : révèle les colonnes secondaires du tableau.
  const bulkToggleOptional = document.getElementById('bulkToggleOptional');
  const bulkTable          = document.getElementById('bulkTable');
  if (bulkToggleOptional && bulkTable) {
    bulkToggleOptional.addEventListener('click', () => {
      const on = bulkTable.classList.toggle('bulk-show-optional');
      bulkToggleOptional.innerHTML = on
        ? '<i class="bi bi-layout-three-columns"></i> Moins de colonnes'
        : '<i class="bi bi-layout-three-columns"></i> Plus de colonnes';
    });
  }

  function renumber() {
    const rows = container.querySelectorAll('.bulk-row');
    rows.forEach((r, i) => {
      const t = r.querySelector('.bulk-row-title');
      // Numérotation décroissante : la ligne du HAUT porte le plus grand numéro,
      // la n°1 (la plus ancienne) est tout en bas.
      if (t) t.textContent = '#' + (rows.length - i);
      // Le bouton "retirer" est désactivé s'il ne reste qu'une personne
      const rm = r.querySelector('.bulk-row-remove');
      if (rm) rm.disabled = (rows.length <= 1);
    });
    updateSummary();
    updateAddButtons();
    applyMailModeUI();
    applyEntrepriseModeUI();
  }

  function updateAddButtons() {
    const count = container.querySelectorAll('.bulk-row').length;
    const atMax = count >= MAX_BULK;
    addBtn.disabled = atMax;
    dupBtn.disabled = atMax;
    addBtn.title = atMax ? 'Limite de ' + MAX_BULK + ' inscrits atteinte' : '';
    dupBtn.title = atMax ? 'Limite de ' + MAX_BULK + ' inscrits atteinte' : '';
  }

  function updateSummary() {
    const rows = container.querySelectorAll('.bulk-row');
    const sharedSel = document.querySelector('#fBulkAdd [name="shared_paiement_mode"]');
    const isGratuit = sharedSel && sharedSel.value === 'gratuit';
    let total = 0;
    rows.forEach(r => {
      const m = r.querySelector('.bulk-montant');
      let v;
      if (m) {
        v = parseFloat(m.value);
        if (isNaN(v)) v = 0;
      } else {
        // Pas de champ Montant dû dans la ligne (non bulk-visible)
        // → montant calculé serveur-side d'après le paiement partagé
        v = isGratuit ? 0 : defaultFee;
      }
      total += v;
    });
    const max = ' / ' + MAX_BULK + ' max';
    summary.textContent = rows.length + ' personne(s)' + max + ' — Total : ' + total.toFixed(2).replace(/\.00$/, '') + ' €';
  }

  function addRow(sourceRow, doFocus) {
    // Refuse l'ajout au-delà de la limite
    if (container.querySelectorAll('.bulk-row').length >= MAX_BULK) return null;
    const clone = tmpl.content.firstElementChild.cloneNode(true);
    // Si on duplique, recopier les valeurs SAUF nom/prénom/email (propres à chacun).
    // L'entreprise est, elle, recopiée (souvent la même d'une ligne à l'autre).
    if (sourceRow) {
      const skip = ['nom', 'prenom', 'email'];
      clone.querySelectorAll('.bulk-field').forEach(f => {
        const bdd = f.dataset.bdd;
        if (skip.includes(bdd)) return;
        const src = sourceRow.querySelector('.bulk-field[data-bdd="' + bdd + '"]');
        if (src) f.value = src.value;
      });
    }
    // Nouvelle ligne EN HAUT : le champ vide à remplir reste toujours visible en tête
    // (numérotation décroissante N…1, la n°1 étant la plus ancienne, tout en bas).
    container.insertBefore(clone, container.firstChild);
    applyChildMontantToRow(clone); // si duplication d'une ligne « enfant » avec naissance
    renumber();
    if (doFocus) { const first = clone.querySelector('.bulk-field'); if (first) first.focus(); }
    return clone;
  }

  container.addEventListener('click', e => {
    const btn = e.target.closest('.bulk-row-remove');
    if (!btn) return;
    const row = btn.closest('.bulk-row');
    if (container.querySelectorAll('.bulk-row').length <= 1) return;
    row.remove();
    renumber();
  });

  container.addEventListener('input', e => {
    if (e.target.classList.contains('bulk-montant')) updateSummary();
    // Retire le surlignage rouge « champ obligatoire » dès qu'on saisit quelque chose.
    if (e.target.classList.contains('bulk-field') && e.target.value.trim() !== '') {
      e.target.classList.remove('is-invalid');
    }
  });

  // Champ « naissance » intelligent : à la perte de focus, on canonicalise
  // l'âge / l'année / la date saisi (même logique que le formulaire classique).
  container.addEventListener('focusout', e => {
    if (!e.target.classList || !e.target.classList.contains('bulk-birthdate')) return;
    const raw = e.target.value.trim();
    if (!raw || !window.FERInscription) return;
    const n = FERInscription.normalizeBirthValue(raw);
    if (n) e.target.value = n; // reconnu → forme canonique ; sinon on laisse pour correction
    // Tarif enfant : si l'âge saisi est sous le seuil, applique le montant enfant.
    const row = e.target.closest('.bulk-row');
    if (row && applyChildMontantToRow(row)) updateSummary();
  });

  addBtn.addEventListener('click', () => addRow(null, true));
  dupBtn.addEventListener('click', () => {
    // Duplique la ligne du haut (la plus récente), la copie apparaît elle aussi en haut.
    addRow(container.querySelector('.bulk-row') || null, true);
  });

  /* ══ IMPORT EXCEL → correspondance colonnes ↔ champs → cards ══════════ */
  const excelBtn    = document.getElementById('bulkImportExcelBtn');
  const fileInput   = document.getElementById('bulkExcelFile');
  const editView    = document.getElementById('bulkEditView');
  const mapView     = document.getElementById('bulkMapView');
  const mapTargets  = document.getElementById('bulkMapTargets');
  const mapPool     = document.getElementById('bulkMapPool');
  const mapCancel   = document.getElementById('bulkMapCancel');
  const mapGenerate = document.getElementById('bulkMapGenerate');
  const mapInfo     = document.getElementById('bulkMapInfo');
  const poolSearch  = document.getElementById('bulkMapPoolSearch');
  // Filtre le « mur » de colonnes du fichier par leur nom (seules celles du pool).
  if (poolSearch) poolSearch.addEventListener('input', () => {
    const q = normLabel(poolSearch.value);
    mapPool.querySelectorAll('.bulk-map-chip').forEach(c => {
      const lbl = normLabel(excelColumns[c.dataset.colIndex]);
      c.style.display = (!q || lbl.indexOf(q) !== -1) ? '' : 'none';
    });
  });

  let excelColumns = []; // libellés des colonnes du fichier
  let excelRows    = []; // lignes de données (tableaux alignés sur excelColumns)

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = (s == null ? '' : String(s));
    return d.innerHTML;
  }
  // Normalise un libellé pour comparaison (sans accents/casse/ponctuation).
  function normLabel(s) {
    return (s == null ? '' : String(s))
      .normalize("NFD").replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9 ]/gi, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
  }
  // Convertit une date affichée (JJ/MM/AAAA, AAAA-MM-JJ…) en ISO pour <input type=date>.
  function toISODate(v) {
    v = (v == null ? '' : String(v)).trim();
    if (!v) return '';
    if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0, 10);
    let m = v.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/);
    if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    m = v.match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/);
    if (m) return m[1] + '-' + m[2].padStart(2, '0') + '-' + m[3].padStart(2, '0');
    return ''; // format non reconnu : on laisse vide (le champ date refuse le reste)
  }

  function zoneChip(zone) { return zone ? zone.querySelector('.bulk-map-chip') : null; }

  function refreshMap() {
    const zones = [].concat(
      Array.from(mapTargets.querySelectorAll('[data-target-drop]')),
      [mapPool]
    );
    zones.forEach(z => {
      const ph = z.querySelector('.bulk-map-placeholder');
      if (ph) ph.style.display = z.querySelector('.bulk-map-chip') ? 'none' : '';
    });
    const assigned = mapTargets.querySelectorAll('.bulk-map-chip').length;
    mapInfo.textContent = assigned + '/' + excelColumns.length + ' colonne(s) reliée(s)';
  }

  function placeChip(zone, chip) {
    // Une cible ne contient qu'une seule colonne : si occupée, l'ancienne repart au pool.
    if (zone.matches('[data-target-drop]')) {
      const existing = zoneChip(zone);
      if (existing && existing !== chip) mapPool.appendChild(existing);
    }
    zone.appendChild(chip);
    refreshMap();
  }

  /* ─── Éditeur de commentaire : texte libre + cards « colonne » déplaçables ─── */
  // Les cards se glissent depuis « Colonnes de votre fichier » (#bulkMapPool) ;
  // une card déjà dans l'éditeur se déplace par glisser interne.

  // Range (point d'insertion) à partir de coordonnées écran, cross-browser.
  function caretRangeFromPoint(x, y) {
    if (document.caretRangeFromPoint) return document.caretRangeFromPoint(x, y);
    if (document.caretPositionFromPoint) {
      const p = document.caretPositionFromPoint(x, y);
      if (p) { const r = document.createRange(); r.setStart(p.offsetNode, p.offset); r.collapse(true); return r; }
    }
    return null;
  }

  // Crée une card « colonne » insérable dans le texte (atomique + déplaçable).
  function makeEditorChip(index) {
    const chip = document.createElement('span');
    chip.className = 'ce-chip';
    chip.contentEditable = 'false';
    chip.draggable = true;
    chip.dataset.colIndex = index;
    chip.textContent = excelColumns[index];
    chip.addEventListener('dragstart', e => {
      e.dataTransfer.setData('text/plain', String(index));
      e.dataTransfer.effectAllowed = 'move';
      chip.classList.add('dragging'); // marque le déplacement interne
    });
    chip.addEventListener('dragend', () => chip.classList.remove('dragging'));
    return chip;
  }

  // Insère un nœud à un Range donné (ou en fin d'éditeur) et place le curseur après.
  function insertNodeAt(editor, range, node) {
    if (!range || !editor.contains(range.startContainer)) {
      range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false); // fin de l'éditeur
    }
    // Ne jamais insérer À L'INTÉRIEUR d'une card : on se place juste après.
    const host = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
    const insideChip = host && host.closest ? host.closest('.ce-chip') : null;
    if (insideChip) { range = document.createRange(); range.setStartAfter(insideChip); range.collapse(true); }

    range.insertNode(node); // si `node` existe déjà dans le DOM, il est DÉPLACÉ ici
    const sp = document.createTextNode('​'); // espace nul → permet de taper juste après
    if (node.nextSibling) node.parentNode.insertBefore(sp, node.nextSibling);
    else node.parentNode.appendChild(sp);
    const sel = window.getSelection();
    const after = document.createRange();
    after.setStartAfter(sp); after.collapse(true);
    sel.removeAllRanges(); sel.addRange(after);
  }

  function wireEditor(editor) {
    editor.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      editor.classList.add('drag-over');
    });
    editor.addEventListener('dragleave', () => editor.classList.remove('drag-over'));
    editor.addEventListener('drop', e => {
      e.preventDefault();
      editor.classList.remove('drag-over');
      const idx = e.dataTransfer.getData('text/plain');
      if (idx === '') return;
      const range = caretRangeFromPoint(e.clientX, e.clientY);
      // Une card déjà dans l'éditeur (.ce-chip.dragging) = déplacement interne.
      // Sinon, le glisser vient de « Colonnes de votre fichier » → nouvelle card.
      const moving = editor.querySelector('.ce-chip.dragging');
      if (moving) insertNodeAt(editor, range, moving);
      else        insertNodeAt(editor, range, makeEditorChip(parseInt(idx, 10)));
    });
  }

  // Sérialise l'éditeur : texte + sauts de ligne ; chaque card → jeton index.
  function serializeEditor(editor) {
    let out = '';
    (function walk(node) {
      node.childNodes.forEach(child => {
        if (child.nodeType === 3) {                       // nœud texte
          out += child.nodeValue.replace(/​/g, '');
        } else if (child.nodeType === 1) {                // élément
          if (child.classList && child.classList.contains('ce-chip')) {
            out += '' + child.dataset.colIndex + '';
          } else if (child.tagName === 'BR') {
            out += '\n';
          } else if (/^(DIV|P)$/.test(child.tagName)) {
            if (out && !out.endsWith('\n')) out += '\n';
            walk(child);
          } else {
            walk(child);
          }
        }
      });
    })(editor);
    return out;
  }

  function makeChip(index) {
    const chip = document.createElement('div');
    chip.className = 'bulk-map-chip';
    chip.draggable = true;
    chip.dataset.colIndex = index;
    chip.innerHTML = '<i class="bi bi-grip-vertical"></i> ' + escapeHtml(excelColumns[index]);
    chip.addEventListener('dragstart', e => {
      chip.classList.remove('bulk-chip-auto'); // l'utilisateur reprend la main → plus « auto »
      e.dataTransfer.setData('text/plain', String(index));
      e.dataTransfer.effectAllowed = 'move';
      chip.classList.add('dragging');
    });
    chip.addEventListener('dragend', () => chip.classList.remove('dragging'));
    return chip;
  }

  function wireZone(zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      const idx = e.dataTransfer.getData('text/plain');
      const chip = mapView.querySelector('.bulk-map-chip[data-col-index="' + idx + '"]');
      if (chip) placeChip(zone, chip);
    });
  }

  // Pré-relie les colonnes dont le nom ressemble à un champ (synonymes courants).
  function autoMap() {
    const synonyms = {
      nom:         ['nom', 'lastname', 'last name', 'name', 'famille'],
      prenom:      ['prenom', 'firstname', 'first name', 'surname'],
      email:       ['email', 'mail', 'courriel', 'e mail', 'adresse mail', 'adresse email'],
      tel:         ['tel', 'telephone', 'phone', 'mobile', 'portable', 'gsm', 'telephone mobile'],
      naissance:   ['naissance', 'date de naissance', 'birth', 'birthday', 'ddn', 'annee de naissance', 'age', 'annee'],
      ville:       ['ville', 'city', 'commune', 'localite'],
      sexe:        ['sexe', 'genre', 'gender', 'civilite'],
      tshirt_size: ['taille', 'tshirt', 't shirt', 'size', 'taille tshirt', 'taille t shirt'],
      montant_du:  ['montant', 'montant du', 'prix', 'tarif', 'amount'],
      entreprise:  ['entreprise', 'groupe', 'societe', 'equipe', 'team', 'organisation', 'nom de l equipe']
    };
    // Score de correspondance entre un champ et une colonne. Les MOTS ENTIERS priment ;
    // une sous-chaîne courte ne suffit pas → évite le piège « préNOM » ⊃ « nom ».
    function scoreMatch(cands, colLabel) {
      const cl = normLabel(colLabel);
      if (!cl) return 0;
      const cw = cl.split(' ').filter(Boolean);
      let best = 0;
      cands.forEach(cand => {
        if (!cand) return;
        const candMulti = cand.indexOf(' ') !== -1;
        if (cl === cand)                                     best = Math.max(best, 100);
        else if (cw[0] === cand)                             best = Math.max(best, 84 - cw.length); // commence par le mot
        else if (!candMulti && cw.indexOf(cand) !== -1)      best = Math.max(best, 64 - cw.length); // mot entier présent
        else if (candMulti && cl.indexOf(cand) !== -1)       best = Math.max(best, 54 - cw.length); // libellé multi-mots présent
        else if (cand.length >= 5 && cl.indexOf(cand) !== -1) best = Math.max(best, 40 - cw.length); // sous-chaîne (mots longs)
      });
      return best;
    }
    const targets = Array.from(mapTargets.querySelectorAll('.bulk-map-target')).filter(t => {
      if (t.dataset.bdd === 'commentaire') return false; // multi-colonnes : choix manuel
      const z = t.querySelector('[data-target-drop]');
      return z && !zoneChip(z);
    });
    const chips = Array.from(mapPool.querySelectorAll('.bulk-map-chip'));
    // Toutes les paires (champ, colonne) avec un score > 0, meilleures d'abord.
    const pairs = [];
    targets.forEach(t => {
      const cands = (synonyms[t.dataset.bdd] || [])
        .concat([normLabel(t.dataset.bdd), normLabel(t.querySelector('.bulk-map-target-label').textContent)])
        .filter(Boolean);
      chips.forEach(c => {
        const sc = scoreMatch(cands, excelColumns[c.dataset.colIndex]);
        if (sc > 0) pairs.push({ t: t, c: c, sc: sc });
      });
    });
    pairs.sort((a, b) => b.sc - a.sc);
    // Affectation gloutonne : chaque champ ET chaque colonne au plus une fois.
    const usedT = new Set(), usedC = new Set();
    pairs.forEach(p => {
      if (usedT.has(p.t) || usedC.has(p.c)) return;
      usedT.add(p.t); usedC.add(p.c);
      p.c.classList.add('bulk-chip-auto');
      placeChip(p.t.querySelector('[data-target-drop]'), p.c);
    });
  }

  function openMapView() {
    // Réinitialise les zones, recrée les puces dans le pool, puis auto-mappe.
    mapTargets.querySelectorAll('.bulk-map-chip').forEach(c => c.remove());
    mapPool.querySelectorAll('.bulk-map-chip').forEach(c => c.remove());
    excelColumns.forEach((_, i) => mapPool.appendChild(makeChip(i)));
    if (poolSearch) poolSearch.value = ''; // repart d'une recherche vide à chaque import
    autoMap();
    refreshMap();
    // Commentaire : on repart d'un éditeur vierge à chaque import.
    const editor = mapTargets.querySelector('.bulk-comment-rich');
    if (editor) editor.innerHTML = '';
    editView.style.display = 'none';
    mapView.style.display = '';
    // La vue de correspondance a besoin de hauteur : on replie « Réglages du lot »
    // en résumé (l'utilisateur peut le rouvrir d'un clic).
    setSharedCollapsed(true); _sharedAutoCollapsed = true; updateSharedSummary();
    // En vue de correspondance, « Valider les saisies » n'a pas de sens :
    // on l'utilise via « Générer les cards ». On le masque le temps du mapping.
    if (submitBtn) submitBtn.style.display = 'none';
  }

  function closeMapView() {
    mapView.style.display = 'none';
    editView.style.display = '';
    // Retour à l'édition des cards : le bouton de validation réapparaît.
    if (submitBtn) submitBtn.style.display = 'inline-block';
  }

  function setFieldValue(field, value) {
    value = (value == null ? '' : String(value)).trim();
    if (field.tagName === 'SELECT') {
      const opt = Array.from(field.options).find(o =>
        normLabel(o.value) === normLabel(value) || normLabel(o.textContent) === normLabel(value));
      field.value = opt ? opt.value : '';
    } else if (field.type === 'date') {
      field.value = toISODate(value);
    } else {
      field.value = value;
    }
  }

  if (excelBtn && fileInput && mapView) {
    // Câblage des zones de dépôt (une seule fois : le DOM des cibles est fixe).
    mapTargets.querySelectorAll('[data-target-drop]').forEach(wireZone);
    wireZone(mapPool);
    const commentEditor = mapTargets.querySelector('.bulk-comment-rich');
    if (commentEditor) wireEditor(commentEditor);

    excelBtn.addEventListener('click', () => {
      // Le bouton est au niveau des onglets : on bascule sur « Ajout multiple » au besoin.
      const bt = document.getElementById('tab-bulk-btn');
      if (bt && !bt.classList.contains('active') && window.bootstrap) {
        bootstrap.Tab.getOrCreateInstance(bt).show();
      }
      fileInput.click();
    });

    fileInput.addEventListener('change', async () => {
      const file = fileInput.files[0];
      if (!file) return;
      const orig = excelBtn.innerHTML;
      excelBtn.disabled = true;
      excelBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
      try {
        const fd = new FormData();
        fd.append('file', file);
        const res = await fetch('../admin-api.php?route=bulk-parse-excel', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': _csrfToken },
          body: fd,
          credentials: 'same-origin'
        });
        const j = await res.json();
        if (!res.ok || !j.ok) throw new Error(j.error || (res.status + ' ' + res.statusText));
        excelColumns = j.columns || [];
        excelRows    = j.rows || [];
        if (!excelColumns.length || !excelRows.length) throw new Error('Aucune donnée exploitable dans le fichier.');
        openMapView();
      } catch (err) {
        alert('Import Excel : ' + err.message);
      } finally {
        excelBtn.disabled = false;
        excelBtn.innerHTML = orig;
        fileInput.value = ''; // permet de réimporter le même fichier
      }
    });

    mapCancel.addEventListener('click', closeMapView);

    mapGenerate.addEventListener('click', () => {
      // Les champs obligatoires DOIVENT avoir une colonne reliée.
      // Exception : « montant_du » — jamais bloquant, même marqué obligatoire,
      // car il est dérivé du mode de paiement (ou du tarif de la course défini
      // dans les réglages) côté serveur.
      const missing = [];
      mapTargets.querySelectorAll('.bulk-map-target').forEach(t => {
        // « montant_du » dérivé serveur ; « commentaire » = modèle texte libre (pas de dropzone).
        if (t.dataset.bdd === 'montant_du' || t.dataset.bdd === 'commentaire') return;
        if (t.dataset.required === '1' && !zoneChip(t.querySelector('[data-target-drop]'))) {
          missing.push(t.querySelector('.bulk-map-target-label').textContent.replace('*', '').trim());
        }
      });
      if (missing.length) {
        alert('Colonne(s) obligatoire(s) non reliée(s) :\n• ' + missing.join('\n• '));
        return;
      }
      // Construit la correspondance champ → index de colonne.
      // Le commentaire est traité à part : un modèle texte libre avec des jetons [Colonne].
      const map = {};
      mapTargets.querySelectorAll('.bulk-map-target').forEach(t => {
        if (t.dataset.bdd === 'commentaire') return; // géré via le modèle de texte
        const chip = zoneChip(t.querySelector('[data-target-drop]'));
        if (chip) map[t.dataset.bdd] = parseInt(chip.dataset.colIndex, 10);
      });

      const commentEditor = mapTargets.querySelector('.bulk-comment-rich');
      const commentTpl = commentEditor ? serializeEditor(commentEditor) : '';
      // « A du contenu » = il reste du texte une fois les cards retirées, OU il y a au moins une card.
      const commentHasContent = commentTpl.replace(/\d+/g, 'x').trim() !== '';

      // Pour une ligne du fichier : remplace chaque card (jeton index)
      // par sa valeur. Une ligne ne contenant que des cards TOUTES vides est
      // supprimée (évite un « Âge : » orphelin) ; le texte fixe est conservé.
      function buildComment(cells) {
        return commentTpl.split('\n').map(line => {
          let hadToken = false, hadValue = false;
          const out = line.replace(/(\d+)/g, (_, i) => {
            hadToken = true;
            const val = (cells[+i] == null ? '' : String(cells[+i])).trim();
            if (val) hadValue = true;
            return val;
          });
          return (hadToken && !hadValue) ? null : out;
        }).filter(l => l !== null).join('\n');
      }

      // Remplace les cards existantes par une card par ligne du fichier.
      container.innerHTML = '';
      excelRows.forEach(cells => {
        addRow();
        const row = container.firstElementChild; // nouvelle ligne insérée en tête
        if (!row) return;
        Object.keys(map).forEach(bdd => {
          const field = row.querySelector('.bulk-field[data-bdd="' + bdd + '"]');
          if (field) setFieldValue(field, cells[map[bdd]]);
        });
        // Naissance importée : canonicalise âge/année/date pour l'afficher proprement.
        const bField = row.querySelector('.bulk-field.bulk-birthdate');
        if (bField && bField.value.trim() && window.FERInscription) {
          const n = FERInscription.normalizeBirthValue(bField.value.trim());
          if (n) bField.value = n;
        }
        if (commentHasContent) {
          const cField = row.querySelector('.bulk-field[data-bdd="commentaire"]');
          if (cField) cField.value = buildComment(cells);
        }
      });
      renumber();
      // Révèle les colonnes secondaires si l'import les a remplies (sinon données cachées).
      const bt = document.getElementById('bulkTable');
      const btBtn = document.getElementById('bulkToggleOptional');
      if (bt && (commentHasContent || map['date_inscription'] != null) && !bt.classList.contains('bulk-show-optional')) {
        bt.classList.add('bulk-show-optional');
        if (btBtn) btBtn.innerHTML = '<i class="bi bi-layout-three-columns"></i> Moins de colonnes';
      }
      closeMapView();
    });
  }

  // Si le paiement partagé passe à "gratuit", on met tous les montants à 0
  const sharedPaiement = document.querySelector('#fBulkAdd [name="shared_paiement_mode"]');
  if (sharedPaiement) {
    sharedPaiement.addEventListener('change', () => {
      const isGratuit = sharedPaiement.value === 'gratuit';
      container.querySelectorAll('.bulk-montant').forEach(m => {
        m.value = isGratuit ? '0' : defaultFee;
      });
      // Réapplique le tarif enfant par ligne (prioritaire sur la valeur ci-dessus).
      container.querySelectorAll('.bulk-row').forEach(applyChildMontantToRow);
      updateSummary();
    });
  }

  // Réinitialise le formulaire à chaque ouverture/fermeture du modal
  document.getElementById('addModal').addEventListener('show.bs.modal', () => {
    // Si le panneau bulk n'a aucune ligne, on en ajoute une
    if (container.querySelectorAll('.bulk-row').length === 0) addRow();
    // Ouverture sur l'onglet « Inscrit unique » : largeur normale.
    const dlg = document.querySelector('#addModal .modal-dialog');
    if (dlg) dlg.classList.remove('bulk-wide');
    // Réglages du lot toujours ouverts à l'ouverture ; état d'auto-repli remis à zéro.
    _sharedAutoCollapsed = false;
    setSharedCollapsed(false);
    applyMailModeUI();       // récap par défaut → masque la colonne « Email »
    applyEntrepriseModeUI();
    updateSharedSummary();
  });

  // ── Bascule « Inscrit unique » → « Ajout multiple » ──
  // Si des infos ont déjà été saisies dans le formulaire simple, on les transfère
  // dans une ligne du tableau (évite de tout retaper), puis on vide le simple.
  function bulkRowIsEmpty(rowEl) {
    return !Array.from(rowEl.querySelectorAll('.bulk-field')).some(f =>
      !f.classList.contains('bulk-montant') && (f.value || '').trim() !== '');
  }
  const addModalDialog = document.querySelector('#addModal .modal-dialog');
  const tabSingleBtn = document.getElementById('tab-single-btn');
  if (tabSingleBtn) {
    tabSingleBtn.addEventListener('shown.bs.tab', () => {
      if (addModalDialog) addModalDialog.classList.remove('bulk-wide');
    });
  }
  const tabBulkBtn = document.getElementById('tab-bulk-btn');
  if (tabBulkBtn) {
    tabBulkBtn.addEventListener('shown.bs.tab', () => {
      // Élargit le modal à 95 % en Ajout multiple.
      if (addModalDialog) addModalDialog.classList.add('bulk-wide');
      const single = document.getElementById('fAdd');
      if (!single) return;
      // Valeurs non vides du formulaire simple (name = colonne BDD).
      const vals = {};
      let hasPerson = false;
      single.querySelectorAll('[name]').forEach(el => {
        const n = el.name;
        if (!n || n === 'origine') return;
        const v = (el.value || '').trim();
        if (!v) return;
        vals[n] = v;
        if (['nom','prenom','email','tel','ville','entreprise','naissance'].includes(n)) hasPerson = true;
      });
      if (!hasPerson) return; // rien de significatif à transférer

      // Paiement → réglage partagé du lot.
      if (vals.paiement_mode && sharedPaiementSel) {
        sharedPaiementSel.value = vals.paiement_mode;
        updateSharedSummary();
      }
      // Cible : la 1re ligne vide, sinon une nouvelle ligne.
      if (container.querySelectorAll('.bulk-row').length === 0) addRow();
      let target = Array.from(container.querySelectorAll('.bulk-row')).find(bulkRowIsEmpty);
      if (!target) { addRow(); target = container.firstElementChild; }
      if (target) {
        target.querySelectorAll('.bulk-field').forEach(f => {
          const bdd = f.dataset.bdd;
          if (bdd && bdd !== 'paiement_mode' && vals[bdd] != null) setFieldValue(f, vals[bdd]);
        });
        const bEl = target.querySelector('.bulk-birthdate');
        if (bEl && bEl.value.trim() && window.FERInscription) {
          const nrm = FERInscription.normalizeBirthValue(bEl.value.trim());
          if (nrm) bEl.value = nrm;
        }
        applyChildMontantToRow(target);
      }
      updateSummary();
      // Vide le formulaire simple : les infos sont désormais dans le tableau.
      single.reset();
      if (window.FERInscription && FERInscription.refresh) FERInscription.refresh(single);
    });
  }
  document.getElementById('addModal').addEventListener('hidden.bs.modal', () => {
    const f = document.getElementById('fBulkAdd');
    if (f) f.reset();
    if (mapView) { excelColumns = []; excelRows = []; closeMapView(); }
    container.innerHTML = '';
    progress.style.display = 'none';
    logDiv.innerHTML = '';
    recapDiv.style.display = 'none';
    submitBtn.style.display = 'inline-block';
    submitBtn.disabled = false;
    submitBtn.innerHTML = 'Valider les saisies';
    closeBtn.style.display = 'none';
    addRow();
  });

  function bulkLog(icon, text, color) {
    const line = document.createElement('div');
    line.style.cssText = 'padding:2px 0;color:' + (color || '#333');
    line.innerHTML = icon + ' ' + text;
    logDiv.appendChild(line);
    logDiv.scrollTop = logDiv.scrollHeight;
  }

  document.getElementById('fBulkAdd').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;

    const mailMode  = form.querySelector('input[name="mail_mode"]:checked')?.value || 'individual';
    const recapEmail = form.recap_email ? form.recap_email.value.trim() : '';

    // En mode récap groupé, l'email de contact est obligatoire et doit être valide.
    if (mailMode === 'recap') {
      if (!recapEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(recapEmail)) {
        // Ré-ouvre les réglages du lot pour que le champ soit visible.
        setSharedCollapsed(false);
        alert('Indiquez un email de contact valide pour le récap groupé.');
        if (form.recap_email) form.recap_email.focus();
        return;
      }
    }

    // Paiement obligatoire (validé en JS car le bloc peut être replié/masqué).
    if (!form.shared_paiement_mode.value) {
      setSharedCollapsed(false);
      alert('Choisissez un mode de paiement pour le lot.');
      form.shared_paiement_mode.focus();
      return;
    }

    const shared = {
      entreprise:    form.shared_entreprise.value.trim(),
      paiement_mode: form.shared_paiement_mode.value,
      origine:       form.origine.value,
      mail_mode:     mailMode,
      recap_email:   recapEmail,
    };

    const rows = [];
    // Affichage = plus récent en haut, mais on ENVOIE dans l'ordre d'entrée (#1 d'abord,
    // en bas) pour que la numérotation des inscriptions suive l'ordre de saisie / du fichier.
    Array.from(container.querySelectorAll('.bulk-row')).reverse().forEach(rowEl => {
      const row = {};
      rowEl.querySelectorAll('.bulk-field').forEach(f => {
        row[f.dataset.bdd] = f.value.trim();
      });
      // Naissance : canonicalise l'âge / l'année / la date avant envoi (comme le
      // formulaire classique). Non reconnu → '' (le serveur valide l'obligatoire).
      if (row.naissance && window.FERInscription) {
        row.naissance = FERInscription.normalizeBirthValue(row.naissance);
      }
      rows.push(row);
    });

    if (rows.length === 0) {
      alert('Ajoutez au moins une personne avant de valider.');
      return;
    }

    // Validation des champs obligatoires (data-required) : on bloque l'ENVOI COMPLET
    // tant qu'un champ requis visible est vide (nom/prénom, ou tout champ marqué
    // « Bulk requis » dans Gestion des champs). Rien n'est créé tant que tout n'est
    // pas rempli. Les champs masqués (entreprise commune / email en récap) sont
    // ignorés car non saisissables — et ne sont de toute façon pas obligatoires.
    let firstInvalid = null, invalidCount = 0;
    container.querySelectorAll('.bulk-row').forEach(rowEl => {
      rowEl.querySelectorAll('.bulk-field[data-required="1"]').forEach(f => {
        const col = f.closest('td');
        const hidden = col && col.style.display === 'none';
        if (!hidden && f.value.trim() === '') {
          f.classList.add('is-invalid');
          invalidCount++;
          if (!firstInvalid) firstInvalid = f;
        } else {
          f.classList.remove('is-invalid');
        }
      });
    });
    if (invalidCount > 0) {
      alert('Veuillez remplir tous les champs obligatoires (' + invalidCount + ' manquant(s)) avant de valider.\nAucune inscription n\'est créée tant que tout n\'est pas renseigné.');
      if (firstInvalid) { firstInvalid.focus(); firstInvalid.scrollIntoView({block:'center', behavior:'smooth'}); }
      return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Validation…';
    progress.style.display = 'block';
    logDiv.innerHTML = '';
    recapDiv.style.display = 'none';
    bulkLog('⏳', 'Envoi de ' + rows.length + ' inscription(s)…', '#666');

    try {
      const res = await fetch('../admin-api.php?route=bulk-create', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
        body:    JSON.stringify({ shared, rows }),
        credentials: 'same-origin'
      });

      if (!res.ok) {
        let msg = res.status + ' ' + res.statusText;
        try {
          const j = await res.json();
          if (j && (j.error || j.err)) msg = j.error || j.err;
        } catch (_) {}
        throw new Error(msg);
      }

      const j = await res.json();
      const created = j.created || 0;
      const skipped = (j.errors || []).length;

      bulkLog('✅', '<strong>' + created + '</strong> inscription(s) créée(s)', '#198754');
      (j.errors || []).forEach(err => {
        bulkLog('⚠️', 'Ligne ' + (err.index + 1) + ' ignorée : ' + err.reason, '#e67e22');
      });
      if (j.mail_mode === 'recap') {
        if (j.recap_sent) bulkLog('📧', 'Mail récapitulatif envoyé à ' + (j.recap_email || ''), '#0d6efd');
        else if (j.recap_error) bulkLog('❌', 'Mail récap échoué : ' + j.recap_error, '#dc3545');
      } else {
        const mailsSent = j.mails_sent || 0;
        const mailsSkipped = j.mails_skipped || 0;
        if (mailsSent > 0) bulkLog('📧', mailsSent + ' mail(s) de confirmation envoyé(s)', '#0d6efd');
        if (mailsSkipped > 0) bulkLog('ℹ️', mailsSkipped + ' inscrit(s) sans email : aucun mail envoyé', '#6c757d');
        (j.mail_errors || []).forEach(me => {
          bulkLog('❌', 'Mail échoué (' + (me.email || '?') + ') : ' + me.reason, '#dc3545');
        });
      }

      recapDiv.style.display = 'block';
      recapDiv.innerHTML = '<div class="d-flex gap-3 flex-wrap">'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-success"><div class="text-muted" style="font-size:12px">Créées</div><div class="fw-bold fs-5 text-success">' + created + '</div></div>'
        + '<div class="border rounded px-3 py-2 text-center flex-fill border-warning"><div class="text-muted" style="font-size:12px">Ignorées</div><div class="fw-bold fs-5 text-warning">' + skipped + '</div></div>'
        + '</div>';

      submitBtn.style.display = 'none';
      closeBtn.style.display = 'inline-block';
    } catch (err) {
      bulkLog('❌', 'Erreur : ' + err.message, '#dc3545');
      submitBtn.disabled = false;
      submitBtn.innerHTML = 'Valider les saisies';
    }
  });

  closeBtn.addEventListener('click', () => {
    bootstrap.Modal.getInstance('#addModal').hide();
    tbl.ajax.reload(null, false);
  });
})();
<?php endif; ?>

/* ══ ÉDITION ════ */
// Pré-remplit puis ouvre le modal « Modifier l'inscription » à partir des données
// d'une ligne. Réutilisé par le bouton crayon ET le double-clic sur la ligne.
function openEditModal(d){
  if(!d) return;
  Object.entries(d).forEach(([k,v])=>$('#fEdit [name="'+k+'"]').val(v));
  // date_inscription est un datetime complet ; le champ <input type="date"> n'accepte
  // que AAAA-MM-JJ → on tronque pour l'afficher correctement.
  if(d.date_inscription) $('#fEdit [name="date_inscription"]').val(String(d.date_inscription).slice(0,10));
  // La catégorie « enfant t-shirt » est stockée avec paiement_mode='en ligne (CB)' :
  // on resélectionne le bon choix du menu d'après la prestation.
  if(String(d.prestation||'').toLowerCase()==='enfant_tshirt'){
    $('#fEdit [name="paiement_mode"]').val('enfant_tshirt');
  }
  if(window.FERInscription) FERInscription.refresh(document.getElementById('fEdit'));
  new bootstrap.Modal('#editModal').show();
}
$('#tbl').on('click','button.edit',function(){
  openEditModal(tbl.row($(this).closest('tr')).data());
});
<?php if($canEditReg): ?>
// Double-clic sur une ligne = ouvrir le modal de modification (réservé aux
// utilisateurs ayant le droit dashboard.edit_registration). On ignore le double-clic
// déclenché sur les contrôles interactifs d'une ligne (boutons, menus t-shirt, liens).
$('#tbl tbody').on('dblclick','tr',function(e){
  if($(e.target).closest('button, a, select, input, .action-buttons').length) return;
  const row=tbl.row(this);
  if(row && row.data()) openEditModal(row.data());
});
<?php endif; ?>
$('#fEdit').on('submit',e=>{
  e.preventDefault();
  if(window.FERInscription && !FERInscription.ensureGuardian(e.target)) return;
  if(window.FERInscription) FERInscription.composeComment(e.target);
  const fd=new FormData(e.target); normalizeBirth(fd);
  fetch('../admin-api.php?route=registrations',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':_csrfToken},body:new URLSearchParams(fd)})
  .then(()=>{tbl.ajax.reload(null,false); bootstrap.Modal.getInstance('#editModal').hide();});
});

/* Import Excel AssoConnect : déplacé vers inc/setting.php (onglet Import AssoConnect, droit dashboard.import_excel). */


/* ══ Colonnes redimensionnables + toggle visibilité ════ */
(function() {
  var table = document.getElementById('tbl');
  if (!table) return;
  var uid = <?= json_encode($_SESSION['uid'] ?? 0) ?>;
  var storageKeyVis = 'fer_col_vis_' + uid;
  var storageKeyW = 'fer_col_w_' + uid;

  // Colonnes de tête NON gérées par le toggle/resize :
  //   - la colonne de cases à cocher (actions groupées), si présente   → CB (0 ou 1)
  //   - la colonne `id` masquée                                        → +1
  // Le toggle de visibilité reste indexé à partir de la 1re colonne réelle (« ID »),
  // donc l'état localStorage déjà sauvegardé reste compatible (même 1re colonne).
  var CB = <?= $cbCol ?>;                 // colonnes de cases à cocher en tête (0 ou 1)
  var FIRST_TOGGLE_COL = CB + 1;          // 1re colonne togglable (saute cases + id masqué)

  // ── Identification STABLE des colonnes (robuste au déplacement ColReorder) ──
  // On n'utilise plus la POSITION d'une colonne (qui change quand l'utilisateur
  // déplace les en-têtes via ColReorder) mais une CLÉ stable : sa source de
  // données (`data`) ou, à défaut (colonnes sans data : « ID », « Actions »),
  // son libellé d'en-tête. Visibilité et largeurs sont donc mémorisées/appliquées
  // par clé, jamais par index → masquer « Email » masque toujours Email, même
  // après l'avoir déplacée.
  function colKey(idx) {
    var src = tbl.column(idx).dataSrc();
    if (src) return 'd:' + src;
    var th = tbl.column(idx).header();
    return 'h:' + ($(th).text().trim() || ('col' + idx));
  }
  // Colonnes « structurelles » jamais proposées au masquage/redimensionnement :
  // la colonne id technique (masquée) et la colonne de cases (actions groupées).
  function isStructuralCol(idx) {
    if (tbl.column(idx).dataSrc() === 'id') return true;
    var th = tbl.column(idx).header();
    if (th && th.querySelector && th.querySelector('.bulk-all')) return true;
    return false;
  }
  // Index COURANT d'une colonne d'après sa clé stable (-1 si introuvable).
  function colIdxByKey(key) {
    var found = -1;
    tbl.columns().every(function(idx) {
      if (found === -1 && colKey(idx) === key) found = idx;
    });
    return found;
  }
  // Colonnes togglables, dans l'ordre du tableau, avec leur clé stable + libellé.
  var toggleCols = [];
  tbl.columns().every(function(idx) {
    if (isStructuralCol(idx)) return;
    toggleCols.push({ key: colKey(idx), label: $(this.header()).text().trim() || 'Col ' + idx });
  });

  // Lecture défensive d'un objet JSON du localStorage (cache de repli).
  function readLocalJSON(key) {
    try { var v = JSON.parse(localStorage.getItem(key)); return (v && typeof v === 'object') ? v : null; }
    catch (e) { return null; }
  }
  // Migration unique localStorage → serveur : si le serveur n'a pas encore la pref
  // mais que le navigateur en a une (ancien stockage local), on la pousse au serveur.
  function migrateColPrefs() {
    if (!_uiPrefs || typeof _uiPrefs !== 'object') return;
    var patch = {};
    if (_uiPrefs.dashboard_col_vis == null) {
      var lv = readLocalJSON(storageKeyVis);
      if (lv) { _uiPrefs.dashboard_col_vis = lv; patch.dashboard_col_vis = lv; }
    }
    if (_uiPrefs.dashboard_col_widths == null) {
      var lw = readLocalJSON(storageKeyW);
      if (lw) { _uiPrefs.dashboard_col_widths = lw; patch.dashboard_col_widths = lw; }
    }
    if (Object.keys(patch).length) saveUiPref(patch);
  }

  // ── Restore column visibility (serveur prioritaire, repli localStorage) ──
  function restoreVisibility() {
    try {
      // En mode « Remise T-shirts », la visibilité des colonnes est pilotée par
      // applyTshirtMode() : on ne réapplique PAS les prefs standard par-dessus
      // (sinon les colonnes masquées réapparaîtraient → flash au chargement).
      if (typeof tshirtMode !== 'undefined' && tshirtMode) return;
      var saved = (_uiPrefs && _uiPrefs.dashboard_col_vis) || readLocalJSON(storageKeyVis);
      if (!saved || typeof tbl === 'undefined') return;
      var keys = Object.keys(saved);
      // Rétrocompat : l'ancien format mémorisait par INDEX numérique (offset depuis
      // la 1re colonne togglable). On le convertit vers la clé stable correspondante.
      var legacy = keys.length > 0 && keys.every(function(k){ return /^\d+$/.test(k); });
      keys.forEach(function(k) {
        var key = legacy ? (toggleCols[parseInt(k, 10)] && toggleCols[parseInt(k, 10)].key) : k;
        if (!key) return;
        var ci = colIdxByKey(key);
        if (ci !== -1) tbl.column(ci).visible(saved[k]);
      });
      if (legacy) saveVisibility(); // ré-enregistre au format par clé (migration unique)
      // Réinstalle les entonnoirs sur les colonnes redevenues visibles.
      setTimeout(function() { try { rebuildDashFilters(); } catch(e) {} }, 100);
    } catch(e) {}
  }

  function saveVisibility() {
    try {
      var vis = {};
      toggleCols.forEach(function(c) {
        var ci = colIdxByKey(c.key);
        if (ci !== -1) vis[c.key] = tbl.column(ci).visible();
      });
      localStorage.setItem(storageKeyVis, JSON.stringify(vis)); // cache de repli
      saveUiPref({ dashboard_col_vis: vis });                   // serveur (autorité)
    } catch(e) {}
  }

  // ── Restore column widths (serveur prioritaire, repli localStorage) ──
  function restoreWidths() {
    try {
      var saved = (_uiPrefs && _uiPrefs.dashboard_col_widths) || readLocalJSON(storageKeyW);
      if (!saved) return;
      var keys = Object.keys(saved);
      var legacy = keys.length > 0 && keys.every(function(k){ return /^\d+$/.test(k); });
      if (legacy) {
        // Ancien format : largeur indexée par position (colonnes visibles, hors
        // cases). On mappe chaque position sur la colonne visible correspondante.
        var j = 0;
        tbl.columns().every(function(idx) {
          if (isStructuralCol(idx) || !tbl.column(idx).visible()) return;
          var w = saved[j++]; var th = tbl.column(idx).header();
          if (w && th) { th.style.width = w; th.style.minWidth = w; }
        });
      } else {
        tbl.columns().every(function(idx) {
          var w = saved[colKey(idx)]; var th = tbl.column(idx).header();
          if (w && th) { th.style.width = w; th.style.minWidth = w; }
        });
      }
    } catch(e) {}
  }

  function saveWidths() {
    try {
      var widths = {};
      tbl.columns().every(function(idx) {
        if (isStructuralCol(idx)) return;   // saute cases + id masqué
        var th = tbl.column(idx).header();
        if (th && th.style.width) widths[colKey(idx)] = th.style.width;
      });
      localStorage.setItem(storageKeyW, JSON.stringify(widths)); // cache de repli
      saveUiPref({ dashboard_col_widths: widths });              // serveur (autorité)
    } catch(e) {}
  }

  // ── Column resize handles ──
  // ── Largeur "fixe" : chaque colonne respecte SA largeur ──
  // Sans ça (table-layout:auto + .w-100), agrandir une colonne quand il n'y a pas
  // encore de scroll horizontal force le navigateur à redistribuer la largeur sur
  // les voisines (effet accordéon). On fige une largeur explicite par colonne, on
  // passe en table-layout:fixed et on laisse le tableau dépasser (scroll géré par
  // .table-responsive) au lieu de comprimer les autres colonnes.
  function syncTableWidth() {
    var totalReal = 0;
    table.querySelectorAll('thead tr:first-child th').forEach(function(th) {
      if (th.classList.contains('fer-spacer-cell')) return;   // la cellule tampon ne compte pas
      totalReal += parseFloat(th.style.width) || th.offsetWidth;
    });
    // Largeur = somme EXACTE des vraies colonnes (table-layout:fixed) : aucune
    // redistribution, donc on peut réduire une colonne librement. On force au
    // minimum la largeur de la page → la cellule « tampon » (largeur auto) absorbe
    // le reste pour atteindre le bord droit. Si les colonnes dépassent la page, le
    // tampon tombe à 0 et la scroll-barre revient.
    var box = table.parentElement;                 // .table-responsive
    var pageW = box ? box.clientWidth : totalReal;
    table.style.minWidth = '';
    table.style.width = Math.max(pageW, totalReal) + 'px';
  }
  // Cellule « tampon » en fin de ligne : remplit l'espace à droite (largeur auto)
  // pour que l'en-tête sombre et les lignes atteignent le bord de la page. Ni
  // triable, ni redimensionnable ; ignorée par DataTables (hors de ses colonnes).
  function ensureSpacer() {
    var headRow = table.querySelector('thead tr:first-child');
    if (headRow) {
      var th = headRow.querySelector('.fer-spacer-cell');
      if (!th) { th = document.createElement('th'); th.className = 'fer-spacer-cell'; th.setAttribute('aria-hidden', 'true'); }
      headRow.appendChild(th);                     // (re)place toujours en dernier
    }
    table.querySelectorAll('tbody tr').forEach(function(tr) {
      if (tr.querySelector('td[colspan]')) return; // ligne « aucune donnée » : on laisse
      var td = tr.querySelector('.fer-spacer-cell');
      if (!td) { td = document.createElement('td'); td.className = 'fer-spacer-cell'; }
      tr.appendChild(td);
    });
  }
  // Largeur minimale d'une colonne = largeur EXACTE de son en-tête (titre + flèche
  // de tri + entonnoir de filtre + paddings réels). On clone le <th> hors écran et
  // on le laisse se réduire à son contenu : pas d'estimation, donc aucune marge
  // inutile réservée. En dessous, on bloque (jamais de chevauchement sur le voisin).
  function minColWidth(th) {
    var cs = getComputedStyle(th);
    var clone = th.cloneNode(true);
    clone.querySelectorAll('.col-resize').forEach(function(el) { el.remove(); });
    clone.removeAttribute('class');            // pas de .sorting/.dataTable → aucune contrainte de grille
    // Mesuré HORS du <table> (sinon table-layout:fixed empêche le clone de se
    // réduire à son contenu → mesure faussée, trop large). On reproduit police,
    // casse et paddings réels ; la place de la flèche de tri est déjà dans le padding.
    clone.style.cssText =
      'position:absolute;left:-9999px;top:0;visibility:hidden;display:inline-block;' +
      'width:auto;min-width:0;max-width:none;white-space:nowrap;box-sizing:content-box;' +
      'font-weight:' + cs.fontWeight + ';font-size:' + cs.fontSize + ';font-family:' + cs.fontFamily + ';' +
      'letter-spacing:' + cs.letterSpacing + ';text-transform:' + cs.textTransform + ';' +
      'padding-left:' + cs.paddingLeft + ';padding-right:' + cs.paddingRight + ';';
    var host = th.closest('#oc-content') || document.body;
    host.appendChild(clone);
    var w = clone.getBoundingClientRect().width;
    clone.remove();
    return Math.max(30, Math.ceil(w) + 2);
  }
  function freezeLayout() {
    table.querySelectorAll('thead tr:first-child th').forEach(function(th) {
      if (th.classList.contains('fer-spacer-cell')) return;   // tampon : largeur auto, on n'y touche pas
      if (!th.style.width) { var w = th.offsetWidth + 'px'; th.style.width = w; th.style.minWidth = w; }
    });
    table.classList.remove('w-100');
    table.style.tableLayout = 'fixed';
    syncTableWidth();
  }
  function initResize() {
    ensureSpacer();
    var ths = table.querySelectorAll('thead tr:first-child th');
    ths.forEach(function(th, i) {
      if (th.classList.contains('fer-spacer-cell')) return;   // pas de poignée sur le tampon
      if (i < CB) return;                 // pas de redimensionnement sur la colonne de cases
      if (th.querySelector('.col-resize')) return;
      var handle = document.createElement('div');
      handle.className = 'col-resize';
      th.appendChild(handle);

      handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var startX = e.pageX, startW = th.offsetWidth, moved = false;
        // Redimensionnement simple et fluide : on ne touche QU'À cette colonne.
        // Le tableau s'élargit/rétrécit en conséquence (scroll-barre si besoin) et
        // ne descend jamais sous la largeur de la page (cf. syncTableWidth). La
        // colonne ne passe pas sous la largeur de son en-tête (thMin).
        var thMin = minColWidth(th);
        handle.classList.add('active');

        function onMove(e2) {
          moved = true;
          var newW = Math.max(thMin, startW + e2.pageX - startX);
          th.style.width = newW + 'px';
          th.style.minWidth = newW + 'px';
          syncTableWidth();
        }
        function onUp() {
          handle.classList.remove('active');
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          saveWidths();
          // Si la souris a été relâchée hors de la poignée, le navigateur émet un
          // « click » sur le <th> → DataTables trierait la colonne. On avale ce
          // seul clic (capture, supprimé juste après) pour ne pas changer le tri.
          if (moved) {
            var swallow = function(ev) { ev.stopPropagation(); ev.preventDefault(); };
            th.addEventListener('click', swallow, true);
            setTimeout(function() { th.removeEventListener('click', swallow, true); }, 0);
          }
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    });
    restoreWidths();
    freezeLayout();
  }

  // ── Column toggle button ──
  function buildColToggle() {
    var lengthEl = document.querySelector('#tbl_length');
    if (!lengthEl || document.getElementById('colToggleWrap')) return;

    // Create a bar above the table: Show X entries (left) ... Colonnes (right)
    var bar = document.createElement('div');
    bar.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;';

    // Move lengthEl into the bar
    var lengthParent = lengthEl.parentElement;
    bar.appendChild(lengthEl);

    var wrap = document.createElement('div');
    wrap.className = 'col-toggle-wrap';
    wrap.id = 'colToggleWrap';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'col-toggle-btn';
    btn.innerHTML = '<i class="bi bi-layout-three-columns"></i> Colonnes';

    var dropdown = document.createElement('div');
    dropdown.className = 'col-toggle-dropdown';

    // En-tête « titre » identique aux popovers de filtre (cohérence visuelle).
    var head = document.createElement('div');
    head.className = 'cfp-head';
    head.textContent = 'Afficher / masquer';
    dropdown.appendChild(head);

    toggleCols.forEach(function(c) {
      var label = document.createElement('label');
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      var ci = colIdxByKey(c.key);
      cb.checked = ci === -1 ? true : tbl.column(ci).visible();
      cb.style.accentColor = 'var(--accent)';
      cb.addEventListener('change', function() {
        // Index résolu À CHAQUE clic via la clé stable → robuste au déplacement.
        var idxNow = colIdxByKey(c.key);
        if (idxNow !== -1) tbl.column(idxNow).visible(this.checked);
        saveVisibility();
        // Réinstalle l'entonnoir si la colonne redevient visible.
        try { rebuildDashFilters(); } catch(e) {}
      });
      label.appendChild(cb);
      label.appendChild(document.createTextNode(' ' + c.label));
      dropdown.appendChild(label);
    });

    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      dropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
      if (!wrap.contains(e.target)) dropdown.classList.remove('show');
    });

    wrap.appendChild(btn);
    wrap.appendChild(dropdown);
    bar.appendChild(wrap);

    // Insère la barre AU-DESSUS du tableau, mais HORS du conteneur à défilement
    // horizontal (.table-responsive). Sinon la barre vit dans la zone qui défile :
    // elle s'étire à la largeur (large) du tableau, donc le bouton « Colonnes »
    // collé à droite part au milieu de la page quand on scrolle horizontalement.
    // En la plaçant avant .table-responsive, elle reste à la largeur de la page
    // (bouton toujours à droite) tout en gardant l'ordre : recherche → barre → tableau.
    var tableEl = document.getElementById('tbl');
    var scrollBox = tableEl.closest('.table-responsive')
                 || tableEl.closest('.dataTables_scrollBody')
                 || tableEl.closest('.dataTables_wrapper')
                 || tableEl.parentElement;
    scrollBox.parentElement.insertBefore(bar, scrollBox);
  }

  // ── Sort le pied de tableau (info "Showing X to Y" + pagination) HORS du
  //    conteneur à défilement horizontal (.table-responsive), pour qu'il reste
  //    à la largeur de la page. Seul le tableau défile, pas l'info/pagination. ──
  function moveTableFooterOut() {
    var tableEl = document.getElementById('tbl');
    if (!tableEl) return;
    var scrollBox = tableEl.closest('.table-responsive');
    if (!scrollBox) return;
    var info     = document.getElementById('tbl_info');
    var paginate = document.getElementById('tbl_paginate');
    if (!info && !paginate) return;

    var footer = document.getElementById('tblFooterBar');
    if (!footer) {
      footer = document.createElement('div');
      footer.id = 'tblFooterBar';
      footer.style.cssText = 'display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:8px;';
      scrollBox.parentElement.insertBefore(footer, scrollBox.nextSibling); // juste après le tableau scrollable
    }
    if (info     && info.parentElement     !== footer) footer.appendChild(info);     // gauche
    if (paginate && paginate.parentElement !== footer) footer.appendChild(paginate); // droite
  }

  // ── Init ──
  if (typeof $ !== 'undefined' && $.fn.dataTable) {
    // ── Voile de chargement : on dévoile le tableau une fois tout en place ──
    function revealTbl() {
      var w = document.getElementById('dashTblWrap');
      if (w) requestAnimationFrame(function() { w.classList.remove('is-loading'); });
    }
    setTimeout(revealTbl, 6000);   // filet de sécurité si init.dt n'émet jamais

    $('#tbl').on('init.dt', function() {
      // On attend les prefs serveur puis on migre (une fois) le localStorage existant,
      // avant d'appliquer la visibilité et de construire le sélecteur de colonnes.
      loadUiPrefs().then(function() {
        migrateColPrefs();
        restoreVisibility();
        buildColToggle();
        moveTableFooterOut();
        initResize();
        revealTbl();
      });
    });
    $('#tbl').on('draw.dt', function() { moveTableFooterOut(); initResize(); });
  }
})();

/* ══ QR CODE SCANNER — Remise T-shirt ════ */
(function(){
  var html5QrCode = null;
  var qrModal = document.getElementById('qrScanModal');
  var highlightLimit = <?= (int)$highlightLimit ?>;
  var scannerRunning = false;
  var lastScannedNo = null;

  function hideScanner() {
    document.getElementById('qrReader').style.display = 'none';
    document.getElementById('qrManualZone').style.display = 'none';
  }

  function showScanner() {
    document.getElementById('qrReader').style.display = '';
    document.getElementById('qrManualZone').style.display = '';
  }

  function resetPersonCard() {
    document.getElementById('qrPersonCard').style.display = 'none';
    document.getElementById('qrGroupCard').style.display = 'none';
    document.getElementById('qrSaveStatus').style.display = 'none';
    document.querySelectorAll('.qr-size-btn').forEach(function(b){ b.classList.remove('btn-primary','active'); b.classList.add('btn-outline-dark'); });
    lastScannedNo = null;
    showScanner();
  }

  // Rang « payant » + éligibilité T-shirt pour un inscrit, dans l'ensemble trié.
  function computeEligibility(person, sorted) {
    var paidCount = 0, paidRank = -1;
    for (var i = 0; i < sorted.length; i++) {
      if (parseFloat(sorted[i].montant_du) > 0) paidCount++;
      if (String(sorted[i].inscription_no) === String(person.inscription_no)) {
        paidRank = (parseFloat(person.montant_du) > 0) ? paidCount : -1;
        break;
      }
    }
    var isPaid = paidRank > 0;
    var eligible = isPaid && ((highlightLimit === 0) || (paidRank <= highlightLimit));
    return { isPaid: isPaid, eligible: eligible, paidRank: paidRank };
  }

  // Petit badge d'éligibilité (HTML) réutilisé par la vue single ET la vue groupe.
  function eligibilityHtml(e) {
    if (!e.isPaid) return '<span class="badge bg-danger">Non éligible (non payé)</span>';
    if (e.eligible) return '<span class="badge bg-success">Éligible T-shirt' + (highlightLimit > 0 ? ' ('+e.paidRank+'/'+highlightLimit+')' : '') + '</span>';
    return '<span class="badge bg-danger">Non éligible ('+e.paidRank+'ᵉ, limite '+highlightLimit+')</span>';
  }

  var SIZE_OPTS = ['-','XS','S','M','L','XL','XXL'];

  // QR « groupé » (G:<group_id>) → affiche TOUS les membres du groupe.
  function lookupGroup(groupId) {
    hideScanner();
    document.getElementById('qrPersonCard').style.display = 'none';
    var allData = tbl.data().toArray();
    var sorted  = allData.slice().sort(function(a,b){ return new Date(a.created_at) - new Date(b.created_at); });
    var members = allData.filter(function(p){ return p.group_id && String(p.group_id) === String(groupId); });

    var card = document.getElementById('qrGroupCard');
    var list = document.getElementById('qrGroupList');
    var title = document.getElementById('qrGroupTitle');
    card.style.display = 'block';

    if (!members.length) {
      title.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle me-1"></i>Groupe introuvable</div>';
      list.innerHTML = '';
      return;
    }
    title.innerHTML = '<i class="bi bi-people-fill me-2"></i>Groupe — ' + members.length + ' inscrit(s)';
    list.innerHTML = members.map(function(p){
      // 🔒 [SEC-XSS] Échappement des PII (nom/prénom) injectées ici en innerHTML :
      // un inscrit malveillant pourrait sinon exécuter du JS dans la session du staff
      // au moment du scan groupé (remise t-shirts). Échappeur inline auto-suffisant.
      var esc = function(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; };
      var e = computeEligibility(p, sorted);
      var cur = p.tshirt_size || '-';
      var opts = SIZE_OPTS.map(function(s){ return '<option'+(s===cur?' selected':'')+'>'+esc(s)+'</option>'; }).join('');
      var warn = (cur !== '-') ? '<span class="badge bg-warning text-dark ms-1">déjà : '+esc(cur)+'</span>' : '';
      return '<div class="qr-grp-row d-flex align-items-center gap-2 border rounded p-2 mb-2" data-id="'+esc(p.id)+'">'
           +   '<div class="flex-grow-1" style="min-width:0">'
           +     '<div class="fw-semibold text-truncate">'+esc(((p.prenom||'')+' '+(p.nom||'')).trim())+' <span class="text-muted small">N°'+esc(p.inscription_no)+'</span></div>'
           +     '<div class="small">'+eligibilityHtml(e)+' '+warn+'</div>'
           +   '</div>'
           +   '<select class="form-select form-select-sm qr-grp-size" style="width:88px;flex:0 0 auto">'+opts+'</select>'
           +   '<span class="qr-grp-status" style="width:26px;flex:0 0 auto;text-align:center"></span>'
           + '</div>';
    }).join('');
  }

  function lookupPerson(no) {
    no = String(no).trim();
    if (!no || no === lastScannedNo) return;
    lastScannedNo = no;

    // QR groupé « G:<group_id> » → vue multi-inscrits.
    if (/^G:/.test(no)) { lookupGroup(no.slice(2)); return; }

    // Find in DataTable data
    var allData = tbl.data().toArray();
    var person = null;
    var paidRank = -1;

    // Tri chronologique pour calculer le rang « payant » (cohérent avec
    // l'éligibilité T-shirt : on ignore les inscrits non-payés).
    var sorted = allData.slice().sort(function(a,b){
      return new Date(a.created_at) - new Date(b.created_at);
    });
    var paidCount = 0;
    for (var i = 0; i < sorted.length; i++) {
      var ino = String(sorted[i].inscription_no);
      var paidHere = parseFloat(sorted[i].montant_du) > 0;
      if (paidHere) paidCount++;
      // Correspondance exacte ou sans préfixe (compatibilité anciens QR codes)
      if (ino === no || ino === 'E' + no || ino === 'S' + no) {
        person = sorted[i];
        paidRank = paidHere ? paidCount : -1;
        lastScannedNo = ino;
        break;
      }
    }

    if (!person) {
      hideScanner();
      document.getElementById('qrPersonCard').style.display = 'block';
      // 🔒 [SEC-XSS] Échapper le contenu scanné (un QR malveillant pourrait injecter du HTML)
      document.getElementById('qrEligibility').innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle me-1"></i>Inscription N°' + (function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;})(no) + ' introuvable</div>';
      document.getElementById('qrPersonName').textContent = '';
      document.getElementById('qrPersonNo').textContent = '';
      document.getElementById('qrPersonVille').textContent = '';
      document.getElementById('qrTshirtZone').style.display = 'none';
      return;
    }

    // Hide scanner, show person card
    hideScanner();
    document.getElementById('qrPersonCard').style.display = 'block';
    document.getElementById('qrTshirtZone').style.display = 'block';
    document.getElementById('qrSaveStatus').style.display = 'none';
    document.getElementById('qrPersonName').textContent = (person.prenom || '') + ' ' + (person.nom || '');
    document.getElementById('qrPersonNo').textContent = 'N°' + person.inscription_no;
    document.getElementById('qrPersonVille').textContent = person.ville || '';

    // Eligibility :
    //   - non-payé (montant_du = 0) → jamais éligible
    //   - payé : éligible si dans les X premiers PAYANTS (ou si pas de limite)
    var isPaid = paidRank > 0;
    var eligible = isPaid && ((highlightLimit === 0) || (paidRank <= highlightLimit));
    var eligDiv = document.getElementById('qrEligibility');
    if (!isPaid) {
      eligDiv.innerHTML = `<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-2"></i><strong>Non éligible T-shirt</strong> — inscription non payée (Gratuit / Enfant -${childAge} ans)</div>`;
    } else if (eligible) {
      eligDiv.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle-fill me-2"></i><strong>Éligible T-shirt</strong>' + (highlightLimit > 0 ? ' — '+paidRank+'ᵉ inscrit payant / ' + highlightLimit : '') + '</div>';
    } else {
      eligDiv.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="bi bi-x-circle-fill me-2"></i><strong>Non éligible T-shirt</strong> — '+paidRank+'ᵉ inscrit payant (limite : ' + highlightLimit + ')</div>';
    }

    // Avertissement si déjà scanné (taille déjà renseignée)
    var currentSize = person.tshirt_size || '-';
    if (currentSize !== '-') {
      var warnDiv = document.getElementById('qrSaveStatus');
      warnDiv.style.display = 'block';
      warnDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0" style="font-size:15px;font-weight:600"><i class="bi bi-exclamation-triangle-fill me-2"></i>Attention : déjà scanné (taille ' + currentSize + ')</div>';
    }

    // Highlight current size
    document.querySelectorAll('.qr-size-btn').forEach(function(b){
      b.classList.remove('btn-primary','active');
      b.classList.add('btn-outline-dark');
      if (b.dataset.size === currentSize) {
        b.classList.remove('btn-outline-dark');
        b.classList.add('btn-primary','active');
      }
    });

    // Also highlight in main table
    document.getElementById('quickSearch').value = String(no);
    tbl.search(String(no)).draw();
  }

  // T-shirt size buttons
  document.getElementById('qrTshirtBtns').addEventListener('click', function(e){
    var btn = e.target.closest('.qr-size-btn');
    if (!btn || !lastScannedNo) return;

    var size = btn.dataset.size;

    // Find the person's DB id
    var allData = tbl.data().toArray();
    var person = allData.find(function(p){ return String(p.inscription_no) === String(lastScannedNo); });
    if (!person) return;

    // Highlight button
    document.querySelectorAll('.qr-size-btn').forEach(function(b){ b.classList.remove('btn-primary','active'); b.classList.add('btn-outline-dark'); });
    btn.classList.remove('btn-outline-dark');
    btn.classList.add('btn-primary','active');

    // Save to DB
    var status = document.getElementById('qrSaveStatus');
    status.style.display = 'block';
    status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Sauvegarde…</span>';

    fetch('../admin-api.php?route=registrations', {
      method: 'PUT',
      headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken},
      body: new URLSearchParams({id: person.id, tshirt_size: size})
    }).then(function(){
      status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><strong>' + size + '</strong> enregistré pour ' + (person.prenom||'') + ' ' + (person.nom||'') + '</span>';
      tbl.ajax.reload(null, false);
      // Auto-reset after 1s for next scan
      setTimeout(function(){
        resetPersonCard();
        document.getElementById('qrManualInput').value = '';
        document.getElementById('qrManualInput').focus();
      }, 1000);
    }).catch(function(){
      status.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Erreur de sauvegarde</span>';
    });
  });

  // Groupe : enregistrement de la taille par membre (au changement de sélecteur).
  document.getElementById('qrGroupList').addEventListener('change', function(e){
    var sel = e.target.closest('.qr-grp-size');
    if (!sel) return;
    var row = sel.closest('.qr-grp-row');
    var id  = row && row.dataset.id;
    if (!id) return;
    var size = sel.value;
    var st = row.querySelector('.qr-grp-status');
    st.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    fetch('../admin-api.php?route=registrations', {
      method: 'PUT',
      headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': _csrfToken},
      body: new URLSearchParams({id: id, tshirt_size: size})
    })
    .then(function(r){ return r.json().catch(function(){ return {}; }).then(function(j){ return {ok:r.ok, j:j}; }); })
    .then(function(o){
      if (!(o.ok && o.j && o.j.ok)) throw new Error((o.j && (o.j.err||o.j.message)) || 'refus');
      st.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
      tbl.ajax.reload(null, false);
    })
    .catch(function(){ st.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>'; });
  });
  document.getElementById('qrGroupNext').addEventListener('click', function(){
    resetPersonCard();
    var mi = document.getElementById('qrManualInput');
    if (mi) { mi.value = ''; mi.focus(); }
  });

  // « Scanner QR » ouvre désormais la page complète unifiée (public/remise-tshirts.php,
  // mode admin) au lieu de ce modal — on laisse le lien href suivre son cours.
  // (Ancien modal conservé plus bas mais non déclenché ; le bouton navigue via href.)

  // Start scanner on modal open
  qrModal.addEventListener('shown.bs.modal', function(){
    resetPersonCard();
    document.getElementById('qrManualInput').value = '';
    html5QrCode = new Html5Qrcode('qrReader');
    html5QrCode.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 250, height: 250 } },
      function onScanSuccess(decodedText) {
        var val = decodedText.trim();
        if (val) lookupPerson(val);
      },
      function onScanFailure() {}
    ).then(function(){ scannerRunning = true; })
    .catch(function(){
      scannerRunning = false;
      document.getElementById('qrReader').innerHTML =
        '<div class="alert alert-warning text-center py-3">' +
        '<i class="bi bi-camera-video-off d-block mb-2" style="font-size:2rem"></i>' +
        'Caméra non disponible. Utilisez la saisie manuelle.</div>';
    });
  });

  // Stop scanner on modal close
  qrModal.addEventListener('hidden.bs.modal', function(){
    if (html5QrCode && scannerRunning) {
      html5QrCode.stop().catch(function(){});
      scannerRunning = false;
    }
    if (html5QrCode) { html5QrCode.clear(); html5QrCode = null; }
    resetPersonCard();
    // Clear search
    document.getElementById('quickSearch').value = '';
    tbl.search('').draw();
  });

  // Next scan button
  document.getElementById('qrNextScan').addEventListener('click', function(){
    resetPersonCard();
    document.getElementById('qrManualInput').value = '';
    showScanner();
  });

  // Manual input
  document.getElementById('qrManualBtn').addEventListener('click', function(){
    var val = document.getElementById('qrManualInput').value;
    if (val) lookupPerson(val);
  });
  document.getElementById('qrManualInput').addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('qrManualBtn').click(); }
  });
})();
</script>
</body>
</html>
