<?php
/**
 * recherche-admin.php — Trouver un réglage sans savoir où il est rangé.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LE PROBLÈME QUE ÇA RÉSOUT
 *
 * L'administration compte une douzaine d'onglets de réglages et une vingtaine
 * d'écrans. Replier les cartes rend la lecture plus calme, mais n'aide pas à
 * TROUVER : il faut encore deviner dans quel onglet une chose est rangée. On
 * cherche « smtp » et on ouvre trois onglets avant de tomber sur « Fournisseur ».
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * L'INDEX EST ÉCRIT À LA MAIN, ET C'EST LE SEUL MOYEN
 *
 * Aucune extraction automatique ne devinera que « stats », « statistique »,
 * « fréquentation » et « visites » désignent la même page, ni que « configuration
 * mail » doit mener à « Fournisseur ». Ce sont les MOTS QU'ON TAPE qui comptent,
 * pas ceux qui sont écrits à l'écran.
 *
 * ⚠️ UN INDEX QUI DÉRIVE EST PIRE QUE PAS D'INDEX. Si un réglage est ajouté sans
 * être indexé, la recherche répond « rien trouvé » et on en conclut que la
 * fonction n'existe pas. docs/test-integrite.php refuse tout onglet de Réglages
 * ou toute page d'administration absent de cet index.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * ⚠️ LE FILTRAGE PAR DROITS SE FAIT ICI, PAS DANS LE NAVIGATEUR
 *
 * Ce qu'on n'a pas le droit de voir n'est pas envoyé. Filtrer côté JavaScript
 * révélerait l'existence d'écrans à qui n'y a pas accès — il suffirait de lire
 * la source de la page. On réutilise `$jrCanSee` de navbar-admin.php : le même
 * mécanisme que la barre latérale, pas un second qui divergerait.
 *
 * Attendu : $jrCanSee, défini par navbar-admin.php qui inclut ce fichier.
 */

/**
 * L'index.
 *
 * titre : ce qui s'affiche ;  ou : le chemin, pour se repérer ;
 * url   : où aller ;          ancre : la carte à surligner à l'arrivée ;
 * mots  : les synonymes — c'est LA colonne qui fait la qualité de la recherche ;
 * droit : même forme que la barre latérale.
 */
$rechercheIndex = [

    /* ── Course et chronométrage ─────────────────────────────────────────── */

    /* ⚠️ EN TÊTE DE L'INDEX, ET CE N'EST PAS ARBITRAIRE. À score égal, c'est
       l'ordre d'écriture qui départage. Taper « depart » le jour de la course
       doit proposer le BOUTON avant les coordonnées GPS : c'est la seule entrée
       de tout cet index qui se cherche en urgence, un chronomètre à la main. */
    ['titre' => 'Donner le départ de la course',
     'ou' => 'Tableau de bord', 'url' => 'dashboard.php', 'ancre' => 'carteDepart',
     'mots' => 'depart start top demarrer course commencer retard decaler annuler chrono',
     'droit' => ['page' => 'dashboard', 'action' => 'dashboard.depart']],

    ['titre' => 'Informations de course',
     'ou' => 'Réglages → Course', 'url' => 'setting.php?tab=course', 'ancre' => 'carteCourse',
     'mots' => 'course date heure depart lieu rendez-vous rdv adresse distance km horaires
                village edition annee libelle retrait tshirt dossard inscriptions sur place',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.course']],

    ['titre' => "Lignes de départ et d'arrivée (GPS)",
     'ou' => 'Réglages → Course', 'url' => 'setting.php?tab=course', 'ancre' => 'coursePosition',
     'mots' => 'gps coordonnees latitude longitude ligne depart arrivee geofence
                temps minimum plausible chrono position carte',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.course']],

    ['titre' => 'Chronométrage : activer, corriger, valider',
     'ou' => 'Résultats', 'url' => 'resultats.php', 'ancre' => 'carteChrono',
     'mots' => 'chrono chronometrage temps resultat resultats classement balise beacon
                detection valider recalculer corriger activer desactiver',
     'droit' => ['page' => 'dashboard', 'action' => 'dashboard.transfers']],

    /* ── Applications mobiles ────────────────────────────────────────────── */
    ['titre' => 'Messages et notifications aux coureurs',
     'ou' => 'Applications', 'url' => 'applications.php', 'ancre' => 'carteMessages',
     'mots' => 'notification notifications message messages push annonce alerte coureur
                application mobile appli telephone sonner diffusion',
     'droit' => ['page' => 'setting']],

    ['titre' => 'Firebase (faire sonner les téléphones)',
     'ou' => 'Applications', 'url' => 'applications.php', 'ancre' => 'carteReglagesApp',
     'mots' => 'firebase fcm push cle compte service json google apns notification
                telephone sonner configuration',
     'droit' => ['page' => 'setting']],

    ['titre' => "Réveil de l'application avant la course",
     'ou' => 'Applications', 'url' => 'applications.php', 'ancre' => 'carteReglagesApp',
     'mots' => 'reveil rappel alarme avant depart minutes notification locale',
     'droit' => ['page' => 'setting']],

    ['titre' => 'API mobile et documentation',
     'ou' => 'Réglages → API', 'url' => 'setting.php?tab=api', 'ancre' => 'carteApiMobile',
     'mots' => 'api mobile v1 application token jeton version minimale documentation
                store ios android magasin',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.api']],

    ['titre' => 'API externe (applications tierces)',
     'ou' => 'Réglages → API', 'url' => 'setting.php?tab=api', 'ancre' => 'carteApiExterne',
     'mots' => 'api externe token identifiant cle partenaire integration webservice
                export inscrits',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.api']],

    /* ── Emails ──────────────────────────────────────────────────────────── */
    ['titre' => "Serveur d'envoi (SMTP / Gmail)",
     'ou' => 'Emails → Fournisseur', 'url' => 'mail-settings.php?pane=google', 'ancre' => '',
     'mots' => 'smtp mail email courriel envoi serveur gmail google fournisseur port
                ssl tls authentification expediteur configuration mail oauth',
     'droit' => ['page' => 'mail-settings', 'action' => 'mail.write']],

    ['titre' => 'Envoyer un email aux inscrits',
     'ou' => 'Emails → Envoi de mail', 'url' => 'mail-settings.php?pane=envoi', 'ancre' => '',
     'mots' => 'envoyer mail email message inscrits groupe campagne',
     'droit' => ['page' => 'mail-settings', 'action' => 'mail.send']],

    ['titre' => "Modèle et contenu de l'email",
     'ou' => 'Emails → Template', 'url' => 'mail-settings.php?pane=template', 'ancre' => '',
     'mots' => 'template gabarit modele mail email contenu mise en page couleur logo
                confirmation inscription qr code',
     'droit' => ['page' => 'mail-settings', 'action' => 'mail.write']],

    ['titre' => 'Alertes par email (aux administrateurs)',
     'ou' => 'Emails → Alertes', 'url' => 'mail-settings.php?pane=notifications', 'ancre' => '',
     'mots' => 'alerte alertes notification admin administrateur destinataire prevenir
                connexion bannissement 2fa verrouillage contact partenaire',
     'droit' => ['page' => 'mail-settings', 'action' => 'mail.write']],

    ['titre' => 'Abonnés à la newsletter',
     'ou' => 'Emails → Newsletter', 'url' => 'mail-settings.php?pane=newsletter', 'ancre' => '',
     'mots' => 'newsletter abonne abonnes desabonnement liste diffusion',
     'droit' => ['page' => 'mail-settings', 'action' => 'mail.newsletter']],

    ['titre' => 'Catch-all des emails (mode test)',
     'ou' => 'Emails → Fournisseur', 'url' => 'mail-settings.php?pane=google', 'ancre' => '',
     'mots' => 'catchall catch-all test redirection mail email securite garde-fou',
     'droit' => ['roles' => ['admin']]],

    /* ── Inscriptions ────────────────────────────────────────────────────── */
    ['titre' => "Paramètres d'inscription (tarif, ouverture)",
     'ou' => 'Réglages → Inscription', 'url' => 'setting.php?tab=inscription', 'ancre' => 'carteInscriptionParams',
     'mots' => 'inscription tarif prix montant euro gratuit enfant age ouverture fermeture
                date automatique qr code limite distance km',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.inscription']],

    ['titre' => "En-tête de la page d'inscription",
     'ou' => 'Réglages → Inscription', 'url' => 'setting.php?tab=inscription', 'ancre' => 'carteInscriptionHeader',
     'mots' => 'entete titre inscription page bandeau image',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.inscription']],

    ['titre' => 'Message « inscriptions fermées »',
     'ou' => 'Réglages → Inscription', 'url' => 'setting.php?tab=inscription', 'ancre' => 'carteInscriptionFermee',
     'mots' => 'fermee ferme message complet stop inscription texte',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.inscription']],

    ['titre' => "Champs du formulaire d'inscription",
     'ou' => 'Réglages → Formulaire', 'url' => 'setting.php?tab=formulaire', 'ancre' => '',
     'mots' => 'formulaire champ champs personnalise ajouter question tshirt taille
                obligatoire liste deroulante',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.formulaire']],

    ['titre' => 'Import AssoConnect et Excel',
     'ou' => 'Réglages → AssoConnect', 'url' => 'setting.php?tab=import_auto', 'ancre' => 'carteImportManuel',
     'mots' => 'import excel assoconnect xlsx fichier importer inscrits synchronisation',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.import_auto']],

    ['titre' => "Configuration de l'import (cron, liaison, colonnes)",
     'ou' => 'Réglages → AssoConnect', 'url' => 'setting.php?tab=import_auto', 'ancre' => 'modalConfigImport',
     'mots' => 'cron automatique tache planifiee liaison assoconnect domaine csp
                correspondance colonne mapping excel configuration import',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.import_auto']],

    /* ── Coureurs ────────────────────────────────────────────────────────── */
    ['titre' => 'Comptes coureurs',
     'ou' => 'Espace coureur', 'url' => 'comptes-coureurs.php', 'ancre' => '',
     'mots' => 'compte comptes coureur espace connexion appareil revoquer desactiver
                email adresse participant',
     'droit' => ['page' => 'dashboard', 'action' => 'dashboard.participants']],

    ['titre' => "Transferts d'inscription",
     'ou' => 'Espace coureur', 'url' => 'transferts.php', 'ancre' => '',
     'mots' => 'transfert transferer ceder inscription changer titulaire dossard',
     'droit' => ['page' => 'dashboard', 'action' => 'dashboard.transfers']],

    /* ── Contenu du site ─────────────────────────────────────────────────── */
    ['titre' => "Page d'accueil",
     'ou' => 'Contenu', 'url' => 'setting.php?tab=accueil', 'ancre' => '',
     'mots' => 'accueil page mise en page hero video bandeau flash info titre
                partenaire image editeur visuel',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.accueil']],

    ['titre' => 'Parcours',
     'ou' => 'Réglages → Parcours', 'url' => 'setting.php?tab=parcours', 'ancre' => '',
     'mots' => 'parcours trace carte image description galerie itineraire',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.parcours']],

    ['titre' => 'Règlement de la course',
     'ou' => 'Réglages → Réglementation', 'url' => 'setting.php?tab=reglementation', 'ancre' => '',
     'mots' => 'reglement reglementation regle condition participation texte',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.reglementation']],

    ['titre' => 'Mentions légales et confidentialité',
     'ou' => 'Réglages → Pages légales', 'url' => 'setting.php?tab=legal', 'ancre' => '',
     'mots' => 'legal mention mentions confidentialite rgpd politique cgu cgv
                donnees personnelles juridique',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.legal']],

    ['titre' => 'Logos, couleurs et thème',
     'ou' => 'Réglages → Personnalisation', 'url' => 'setting.php?tab=personnalisation', 'ancre' => 'carteTheme',
     'mots' => 'logo couleur theme personnalisation charte police arrondi navbar
                footer sombre clair rose apparence design',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.personnalisation']],

    ['titre' => 'Actualités',
     'ou' => 'Contenu', 'url' => 'news.php', 'ancre' => '',
     'mots' => 'actualite actualites news article publication blog',
     'droit' => ['page' => 'news']],

    ['titre' => 'Albums photos',
     'ou' => 'Contenu', 'url' => 'albums.php', 'ancre' => '',
     'mots' => 'album albums photo photos image galerie annee',
     'droit' => ['page' => 'albums']],

    ['titre' => 'Partenaires',
     'ou' => 'Contenu', 'url' => 'partners.php', 'ancre' => '',
     'mots' => 'partenaire partenaires sponsor logo soutien',
     'droit' => ['page' => 'partners']],

    ['titre' => 'Timeline',
     'ou' => 'Contenu', 'url' => 'timeline.php', 'ancre' => '',
     'mots' => 'timeline chronologie histoire evenement date',
     'droit' => ['page' => 'timeline']],

    ['titre' => 'Assistant virtuel et FAQ',
     'ou' => 'Contenu', 'url' => 'assistant.php', 'ancre' => '',
     'mots' => 'assistant chatbot bot faq question reponse aide horaires rendez-vous',
     'droit' => ['page' => 'assistant']],

    /* ── Statistiques ────────────────────────────────────────────────────── */
    ['titre' => 'Statistiques des inscriptions',
     'ou' => 'Suivi', 'url' => 'stats.php', 'ancre' => '',
     'mots' => 'stat stats statistique statistiques chiffre graphique inscription
                repartition age sexe ville evolution',
     'droit' => ['page' => 'stats']],

    ['titre' => 'Visites du site',
     'ou' => 'Suivi', 'url' => 'page_stats.php', 'ancre' => '',
     'mots' => 'visite visites frequentation audience trafic page vue statistique
                stats visiteur',
     'droit' => ['page' => 'page_stats']],

    /* ── Sécurité et système ─────────────────────────────────────────────── */
    ['titre' => 'Utilisateurs et droits',
     'ou' => 'Sécurité & système', 'url' => 'utilisateurs.php', 'ancre' => '',
     'mots' => 'utilisateur utilisateurs droit droits permission role compte admin
                acces mot de passe 2fa',
     'droit' => ['roles' => ['admin']]],

    ['titre' => 'Connexions et sécurité',
     'ou' => 'Sécurité & système', 'url' => 'connexions.php', 'ancre' => '',
     'mots' => 'connexion connexions securite tentative echec ip bannissement blocage
                2fa historique',
     'droit' => ['page' => 'connexions']],

    ['titre' => 'Journaux système',
     'ou' => 'Sécurité & système', 'url' => 'logs.php', 'ancre' => '',
     'mots' => 'log logs journal journaux erreur php debug historique fichier trace',
     'droit' => ['page' => 'logs']],

    ['titre' => 'Données personnelles et purges',
     'ou' => 'Sécurité & système', 'url' => 'rgpd.php', 'ancre' => '',
     'mots' => 'rgpd donnee donnees personnelle purge effacement conservation duree
                gps trace suppression confidentialite',
     'droit' => ['roles' => ['admin']]],

    ['titre' => 'Mode maintenance',
     'ou' => 'Réglages → Maintenance', 'url' => 'setting.php?tab=maintenance', 'ancre' => '',
     'mots' => 'maintenance fermer site indisponible message session expiration
                deconnexion duree',
     'droit' => ['page' => 'setting', 'action' => 'settings.tab.maintenance']],

    ['titre' => 'QR codes',
     'ou' => 'Suivi', 'url' => 'qr_code.php', 'ancre' => '',
     'mots' => 'qr code scan dossard retrait tshirt verification',
     'droit' => ['page' => 'qr_code']],

    /* ── Écrans réservés à un rôle ───────────────────────────────────────── */
    ['titre' => 'Saisie des inscriptions',
     'ou' => 'Saisie', 'url' => 'saisie.php?tab=inscriptions', 'ancre' => '',
     'mots' => 'saisie saisir inscrire inscription manuelle guichet sur place benevole',
     'droit' => ['roles' => ['saisie']]],

    ['titre' => 'Accès bénévoles (remise des T-shirts)',
     'ou' => 'Sécurité & système', 'url' => 'tshirt-access.php', 'ancre' => '',
     'mots' => 'benevole benevoles acces tshirt t-shirt remise demande valider
                refuser appareil revoquer scan',
     'droit' => ['roles' => ['admin']]],
];

/* ⚠️ FILTRAGE CÔTÉ SERVEUR. Ce qui n'est pas accessible n'est pas envoyé au
   navigateur : filtrer en JavaScript révélerait l'existence des écrans à qui n'y
   a pas droit, il suffirait de lire la source de la page. */
$rechercheVisible = [];
foreach ($rechercheIndex as $e) {
    if (!$jrCanSee($e['droit'])) continue;
    $rechercheVisible[] = [
        't' => $e['titre'],
        'o' => $e['ou'],
        'u' => $e['url'] . ($e['ancre'] !== '' ? '#' . $e['ancre'] : ''),
        // Titre + chemin + synonymes : on cherche dans les trois. Taper
        // « emails » doit trouver les entrées dont seul le chemin le contient.
        'm' => mb_strtolower($e['titre'] . ' ' . $e['ou'] . ' '
                           . preg_replace('/\s+/', ' ', $e['mots'])),
    ];
}
?>

<div class="jr-recherche">
  <i class="bi bi-search"></i>
  <?php /* ⚠️ LE LIBELLÉ DOIT DIRE CE QU'ON CHERCHE. Le tableau de bord a déjà
           une recherche, qui porte sur les INSCRITS. Deux champs « Rechercher… »
           dans la même application, et on tape un nom de famille ici en se
           demandant pourquoi rien ne sort. */ ?>
  <?php /* Sans les points de suspension : dans une barre latérale étroite, ils
           suffisaient à faire tronquer « réglage » — et le libellé perdait
           justement ce qui le distingue de la recherche d'inscrits. */ ?>
  <input type="search" id="jrRecherche" autocomplete="off" spellcheck="false"
         placeholder="Rechercher un réglage"
         title="Rechercher un réglage ou un écran (Ctrl K)"
         aria-label="Rechercher un réglage ou un écran">
</div>
<div class="jr-resultats" id="jrResultats" hidden></div>

<style>
/* ⚠️ HAUTEUR FIXE, ET NON DÉDUITE DU REMPLISSAGE.
   Avec un padding vertical, la barre grossit avec la taille de police et finit
   plus épaisse que les entrées de menu qu'elle surplombe — c'est ce qui la
   rendait lourde. 32 px : la même hauteur qu'un item du menu. */
/* ⚠️ AUCUNE MARGE HORIZONTALE. La barre latérale applique déjà son propre
   retrait (`padding: … var(--sp-4)`), et les entrées du menu n'ont pas de marge
   en plus. En ajouter une ici rendait la barre plus étroite que tout ce qui se
   trouve en dessous — c'est ce qui la faisait paraître rétrécie.
   Rayon et retrait interne repris des items : l'icône tombe pile sous les
   leurs. */
.jr-recherche{position:relative;display:flex;align-items:center;gap:var(--sp-3);
  margin:0 0 var(--sp-2);padding:0 var(--sp-3);height:34px;
  border-radius:var(--radius-m);
  background:var(--surface-2);
  /* Bordure transparente au repos : elle réserve sa place, donc rien ne saute
     à la prise de focus. Une bordure visible en permanence alourdit pour rien. */
  border:1px solid transparent;
  transition:background-color .15s,border-color .15s}
.jr-recherche:hover{background:var(--surface)}
.jr-recherche:focus-within{background:var(--surface);border-color:var(--accent)}
.jr-recherche i{color:var(--ink-faint);font-size:12px;flex:none;line-height:1}
.jr-recherche input{flex:1;min-width:0;height:100%;padding:0;border:0;
  background:transparent;color:var(--ink);font:inherit;font-size:12.5px;outline:none}
.jr-recherche input::placeholder{color:var(--ink-faint);opacity:1}
/* ⚠️ PLUS DE BADGE « Ctrl K » : il prenait un quart de la largeur pour une
   information lue une seule fois. Le raccourci FONCTIONNE TOUJOURS — il est
   simplement annoncé par l'infobulle du champ au lieu d'occuper la place du
   texte qu'on tape. */

/* ⚠️ PAS DE TROISIÈME CARTE.
   La barre latérale est déjà une carte, le champ en est une autre : encadrer
   les résultats en ajoutait une troisième, emboîtée dans les deux premières.
   Trois bords arrondis et trois ombres pour cinq liens.
   Les résultats sont des liens de navigation, exactement comme le menu : ils
   PRENNENT SA PLACE le temps de la recherche, et il revient dès qu'on efface.
   Ni fond, ni bordure, ni ombre — rien à emboîter. */
.jr-resultats{margin:0;padding:0;background:none;border:0;box-shadow:none}
/* Mêmes retraits et même rayon que .jr-nav a.item : les résultats se lisent
   comme des entrées de menu, parce qu'ils en sont. */
.jr-resultats a{display:block;padding:0.42rem var(--sp-3);border-radius:var(--radius-m);
  text-decoration:none;color:var(--ink-dim)}
.jr-resultats a:hover,.jr-resultats a.is-cible{background:var(--accent-soft);color:var(--ink)}
/* ⚠️ UNE SEULE LIGNE, coupée par des points de suspension. « Paramètres
   d'inscription (tarif, ouverture) » sur deux lignes doublait la hauteur d'une
   entrée et cassait le rythme de la liste. */
.jr-resultats .t{display:block;font-size:var(--fs-small,12.5px);font-weight:550;
  line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jr-resultats .o{display:block;font-size:10px;color:var(--ink-faint);line-height:1.3;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jr-resultats .vide{padding:0.42rem var(--sp-3);font-size:11.5px;color:var(--ink-faint)}
/* Le menu s'efface pendant la recherche : deux listes de liens l'une sous
   l'autre demanderaient de choisir laquelle on lit. */
.jr-nav.is-recherche > nav{display:none}

/* ⚠️ LES RÉSULTATS DOIVENT REPRENDRE LE RÔLE DU MENU DANS LA COLONNE.
   `.jr-nav nav` porte `flex:1` : c'est LUI qui pousse le bloc utilisateur et le
   numéro de version tout en bas. En le masquant sans que rien ne prenne sa
   place, le pied de la barre latérale remontait se coller sous les résultats.
   `min-height:0` est indispensable dans une colonne flex, sinon le défilement
   ne se déclenche jamais et la liste déborde. */
.jr-nav.is-recherche > .jr-resultats{flex:1;min-height:0;overflow-y:auto}

/* Surlignage de la carte atteinte. Deux secondes : le temps de la voir, pas
   assez pour gêner la lecture ensuite. */
@keyframes jrCible{0%{box-shadow:0 0 0 3px var(--accent)}100%{box-shadow:0 0 0 0 transparent}}
.jr-cible{animation:jrCible 2s ease-out;border-radius:var(--radius-l,16px)}
</style>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function () {
  var INDEX = <?= json_encode($rechercheVisible, JSON_UNESCAPED_UNICODE) ?>;
  var champ = document.getElementById('jrRecherche');
  var boite = document.getElementById('jrResultats');
  if (!champ || !boite) return;

  /* Accents et casse ignorés des DEUX côtés : sans ça, « reglementation » ne
     trouve pas « Réglementation », et c'est justement comme ça qu'on tape
     quand on est pressé. */
  function normaliser(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }
  INDEX.forEach(function (e) {
    e.n  = normaliser(e.m);      // titre + chemin + synonymes
    e.nt = normaliser(e.t);      // le titre seul, pour le classement
  });

  var cible = -1;

  /** Le mot commence-t-il un mot du texte ? (et non « au milieu d'un mot ») */
  function debutDeMot(foin, m) {
    return foin.indexOf(m) === 0 || foin.indexOf(' ' + m) !== -1;
  }

  /**
   * ⚠️ SANS CLASSEMENT, LA RECHERCHE EST INUTILISABLE SUR LES MOTS COURTS.
   *
   * Taper « sta » remontait six résultats, tous par correspondance AU MILIEU
   * d'un mot — di(sta)nce, (sta)rt, as(sista)nt — et « Statistiques » arrivait
   * cinquième. On voyait du bruit avant la réponse.
   *
   * Deux règles, dans cet ordre :
   *   1. On ne garde que les entrées où CHAQUE mot tapé commence un mot. Si
   *      rien ne correspond ainsi, on retombe sur la correspondance libre —
   *      mieux vaut du bruit que « aucun résultat ».
   *   2. Ce qui reste est classé : titre d'abord, synonymes ensuite.
   */
  function chercher(q) {
    q = normaliser(q).trim();
    if (!q) return [];

    // Tous les mots présents, dans n'importe quel ordre : « mail config » et
    // « config mail » donnent la même chose.
    var mots = q.split(/\s+/);

    var stricts = INDEX.filter(function (e) {
      return mots.every(function (m) { return debutDeMot(e.n, m); });
    });
    var res = stricts.length ? stricts : INDEX.filter(function (e) {
      return mots.every(function (m) { return e.n.indexOf(m) !== -1; });
    });

    return res.map(function (e) {
      var s = 0;
      mots.forEach(function (m) {
        if (e.nt.indexOf(m) === 0)        s += 100;  // le titre COMMENCE par le mot
        else if (debutDeMot(e.nt, m))     s += 60;   // début d'un mot du titre
        else if (e.nt.indexOf(m) !== -1)  s += 25;   // ailleurs dans le titre
        else if (debutDeMot(e.n, m))      s += 12;   // début d'un synonyme
        else                              s += 3;    // au fond du texte
      });
      return {e: e, s: s};
    }).sort(function (a, b) {
      // À score égal, l'ordre de l'index fait foi : il est rangé par thème,
      // et deux résultats équivalents ne doivent pas s'échanger d'une frappe
      // à l'autre.
      return b.s - a.s;
    }).slice(0, 8).map(function (x) { return x.e; });
  }

  // La barre latérale : c'est elle qui porte la bascule menu / résultats.
  var nav = champ.closest('.jr-nav');

  function afficher() {
    var res = chercher(champ.value);
    cible = -1;

    // Champ vide : le menu reprend sa place, comme s'il n'était jamais parti.
    if (!champ.value.trim()) {
      boite.hidden = true;
      boite.innerHTML = '';
      if (nav) nav.classList.remove('is-recherche');
      return;
    }
    if (nav) nav.classList.add('is-recherche');

    if (!res.length) {
      boite.innerHTML = '<div class="vide">Aucun réglage ne correspond.</div>';
      boite.hidden = false;
      return;
    }
    boite.innerHTML = res.map(function (e) {
      return '<a href="' + e.u + '"><span class="t"></span><span class="o"></span></a>';
    }).join('');
    // Texte posé par textContent et non par innerHTML : l'index est écrit par
    // nous, mais on ne prend pas l'habitude d'injecter du HTML.
    boite.querySelectorAll('a').forEach(function (a, i) {
      a.querySelector('.t').textContent = res[i].t;
      a.querySelector('.o').textContent = res[i].o;
    });
    boite.hidden = false;
  }

  function deplacer(pas) {
    var liens = boite.querySelectorAll('a');
    if (!liens.length) return;
    if (cible >= 0) liens[cible].classList.remove('is-cible');
    cible = (cible + pas + liens.length) % liens.length;
    liens[cible].classList.add('is-cible');
    liens[cible].scrollIntoView({block: 'nearest'});
  }

  champ.addEventListener('input', afficher);

  champ.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); deplacer(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); deplacer(-1); }
    else if (e.key === 'Enter') {
      var liens = boite.querySelectorAll('a');
      // Sans sélection explicite, on prend le premier : c'est ce qu'on attend
      // quand on tape trois lettres et qu'on valide sans regarder.
      var choix = liens[cible >= 0 ? cible : 0];
      if (choix) { e.preventDefault(); window.location = choix.href; }
    } else if (e.key === 'Escape') {
      champ.value = ''; afficher(); champ.blur();
    }
  });

  /* ⚠️ PAS DE « FERMETURE AU CLIC AILLEURS », et c'est délibéré.
     Les résultats ne sont pas une fenêtre par-dessus le menu : ils SONT le menu
     pendant la recherche. Les masquer au premier clic ailleurs laisserait une
     barre latérale vide, avec un champ encore rempli. On les garde jusqu'à ce
     que le champ soit vidé — par la croix, par Échap, ou à la main. */

  // Ctrl+K / ⌘K : le raccourci que tout le monde connaît.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      champ.focus();
      champ.select();
    }
  });

  /* ═══════ ARRIVÉE SUR LA CIBLE ═══════════════════════════════════════════
     Ouvrir le bon onglet ne suffit pas : celui d'AssoConnect contient sept
     cartes. On déroule ce qui est replié, on fait défiler jusqu'à l'élément,
     et on le surligne deux secondes. Sans ça, la recherche mène « à peu près »
     au bon endroit, ce qui oblige à chercher une seconde fois. */
  var ancre = window.location.hash ? window.location.hash.slice(1) : '';
  if (!ancre) return;

  // Laisse le temps aux onglets (?tab=) de se mettre en place.
  setTimeout(function () {
    var el = document.getElementById(ancre);
    if (!el) return;

    // Un modal Bootstrap : on l'ouvre, il n'y a rien à faire défiler.
    if (el.classList.contains('modal') && window.bootstrap) {
      new bootstrap.Modal(el).show();
      return;
    }
    // Un repli : on le déroule, sinon la cible reste invisible.
    if (el.classList.contains('collapse') && window.bootstrap) {
      new bootstrap.Collapse(el, {toggle: false}).show();
    }
    el.scrollIntoView({behavior: 'smooth', block: 'center'});
    el.classList.add('jr-cible');
    setTimeout(function () { el.classList.remove('jr-cible'); }, 2100);
  }, 220);
})();
</script>
