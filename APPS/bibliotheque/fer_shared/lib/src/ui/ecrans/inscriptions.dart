/// « Mes inscriptions » — l'écran principal de l'application.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UNE INSCRIPTION, TROIS ÉTATS — ET C'EST TOUT LE MODÈLE.
///
///     à venir  ──▶  en cours  ──▶  terminée
///
/// L'inscription de l'édition courante occupe le haut de l'écran et change de
/// visage selon le moment : infos pratiques et compte à rebours avant, chrono
/// en direct pendant, temps et méthode après.
///
/// ⚠️ C'EST CE QUI REMPLACE L'ANCIEN ONGLET « COURSE ». Il faisait doublon avec
/// l'inscription et obligeait à faire soi-même le rapprochement entre « ma
/// course » et « mon dossard ». On ne court pas dans un onglet : on court SON
/// dossard, et le chrono se trouve donc là où on l'a laissé.
///
/// Les éditions passées suivent, en liste plate — elles n'ont plus qu'une
/// valeur d'archive.
///
/// Le cas du parent qui inscrit toute la famille sous sa propre adresse reste
/// traité : plusieurs inscriptions partagent alors un `group_id`, apparaissent
/// toutes ici, et c'est là que le transfert prend son sens.
library;

import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../api/api_erreur.dart';
import '../../course/suivi_course.dart';
import '../../models/course_app.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';
import 'course.dart';
import 'transfert.dart';

class EcranInscriptions extends StatelessWidget {
  const EcranInscriptions({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final inscriptions = session.inscriptions;

    if (inscriptions.isEmpty) {
      return RefreshIndicator(
        onRefresh: session.rafraichir,
        child: ListView(
          children: <Widget>[
            SizedBox(height: MediaQuery.sizeOf(context).height * 0.12),
            const RienAAfficher(
              icone: Icons.confirmation_number_outlined,
              titre: 'Aucune inscription rattachée',
              explication:
                  "Si vous vous êtes inscrit avec une autre adresse email, "
                  'déconnectez-vous et reconnectez-vous avec celle-ci — '
                  "c'est l'adresse qui fait le lien.",
            ),
          ],
        ),
      );
    }

    // L'inscription de l'édition en cours est traitée à part : c'est elle qui
    // porte les trois états, et c'est la seule qu'on vient voir le jour J.
    final active = session.inscriptionActive;

    // Groupement du RESTE par édition, la plus récente en premier.
    final parAnnee = <int, List<Inscription>>{};
    for (final i in inscriptions) {
      if (active != null &&
          i.annee == active.annee &&
          i.inscriptionNo == active.inscriptionNo) {
        continue;
      }
      parAnnee.putIfAbsent(i.annee, () => <Inscription>[]).add(i);
    }
    final annees = parAnnee.keys.toList()..sort((a, b) => b.compareTo(a));

    return RefreshIndicator(
      onRefresh: session.rafraichir,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(marge, 4, marge, margeBasListe),
        children: <Widget>[
          if (active != null) ...<Widget>[
            AnimatedBuilder(
              animation: session.suivi,
              builder: (context, _) => _Hero(inscription: active),
            ),
            const SizedBox(height: 28),
          ],
          /* ══════════════════════════════════════════════════════════════
           * LES ÉDITIONS PASSÉES NE SONT PLUS ICI.
           *
           * Cet écran sert à UNE chose : la course qui vient. Empiler dessous
           * les années précédentes noyait le dossard du moment sous un
           * historique qu'on ne consulte que rarement.
           *
           * Rien n'est perdu : dossard, montant payé, taille de T-shirt et
           * ville ont rejoint l'onglet « Résultats », où chaque édition passée
           * a désormais son bloc complet — le temps ET le reçu au même endroit.
           * C'est là qu'on va pour « combien j'avais payé l'an dernier ? ».
           *
           * ⚠️ C'EST POURQUOI L'ONGLET « RÉSULTATS » NE DISPARAÎT PLUS hors
           * période de chronométrage (voir accueil.dart) : sinon les éditions
           * passées deviendraient invisibles onze mois sur douze.
           *
           * Les inscriptions de l'année en cours qui NE SONT PAS la vôtre —
           * une famille inscrite sous votre adresse — restent affichées : ce
           * sont des dossards à venir, pas de l'historique.
           * ══════════════════════════════════════════════════════════════ */
          for (final annee in annees.where((a) => a == active?.annee)) ...<Widget>[
            SectionFer('Aussi inscrits sous votre adresse'),
            for (final i in parAnnee[annee]!) ...<Widget>[
              _LigneInscription(
                inscription: i,
                resultat: session.resultatDe(i),
              ),
              if (i != parAnnee[annee]!.last) const Divider(),
            ],
            const SizedBox(height: 24),
          ],
          if (annees.any((a) => a == active?.annee))
            Text(
              'Plusieurs personnes partagent votre adresse email. Pour que '
              "l'une d'elles ait son propre espace, transférez son inscription "
              'depuis sa fiche.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant),
            ),

        ],
      ),
    );
  }
}

/* ══════════════════════ L'inscription en cours, ses trois états ═══════════
 *
 * À VENIR  →  EN COURS  →  TERMINÉE
 *
 * Un seul objet, trois visages. C'est ce qui remplace l'ancien onglet
 * « Course » : on ne cherche pas son chrono dans une rubrique, on le trouve
 * sur son dossard, là où on l'a laissé.
 *
 * ⚠️ L'ORDRE DES TESTS N'EST PAS INDIFFÉRENT. « En cours » passe AVANT
 * « terminée » : le serveur peut publier un résultat pendant que le suivi
 * tourne encore (arrivée détectée par la balise, application restée ouverte).
 * Dans l'ordre inverse, le coureur verrait son temps final alors que son
 * téléphone envoie toujours des points — et croirait le suivi arrêté.
 * ═══════════════════════════════════════════════════════════════════════════ */

class _Hero extends StatelessWidget {
  const _Hero({required this.inscription});

  final Inscription inscription;

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final info = session.infoCourse;
    final suivi = session.suivi;
    final resultat = session.resultatDe(inscription);

    final enCours = suivi.etat == EtatSuivi.actif;
    final terminee = !enCours && (resultat?.chronoAffichable ?? false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        _CarteInscription(
          inscription: inscription,
          info: info,
          // ⚠️ REPLI SUR LA DATE DE L'ÉDITION. `heureDepart` reste nulle tant
          // que l'organisation n'a pas publié l'heure du coup de feu — c'est le
          // cas la plus grande partie de l'année. Sans ce repli, la carte
          // n'affichait aucune date, alors que le JOUR de la course, lui, est
          // connu de longue date. Un écran de course sans date n'a pas de sens.
          dateEdition: session.editionActive?.dateCourse,
        ),
        const SizedBox(height: 22),
        if (enCours)
          _EtatEnCours(inscription: inscription)
        else if (terminee)
          _EtatTerminee(resultat: resultat!)
        else
          _EtatAVenir(inscription: inscription, info: info),
      ],
    );
  }
}

/* ────────────────────── la carte de l'inscription ─────────────────────────
 *
 * L'EN-TÊTE EST UNE CARTE, PAS UNE FICHE.
 *
 * Il n'y avait ici qu'un nom et une ligne « n° S1 » en petits caractères.
 * C'était exact, et parfaitement sans âme : rien ne distinguait l'écran d'un
 * formulaire administratif, alors qu'il s'agit de la seule chose qu'on vient
 * regarder vingt fois avant une course.
 *
 * ⚠️ ON NE PARLE PAS DE « DOSSARD » : cette course n'en distribue pas. Le
 * numéro est celui de l'INSCRIPTION, et c'est lui que le QR encode.
 *
 * Le numéro prend donc toute sa place, et la DATE l'accompagne. Son absence était
 * le vrai manque : un écran d'inscription à une course qui ne dit pas quand
 * elle a lieu oblige à aller la chercher ailleurs.
 *
 * ⚠️ LE COMPTE À REBOURS S'AFFICHE TOUTE L'ANNÉE ICI, contrairement à ce que
 * faisait l'ancienne carte (sept jours avant, pas plus). Le raisonnement a
 * changé avec la place : au milieu d'une liste, « J-247 » était du bruit ; sur
 * le dossard, à côté de la date, c'est précisément ce qu'on vient voir. Pour
 * une épreuve annuelle, l'attente FAIT partie de l'objet.
 * ────────────────────────────────────────────────────────────────────────── */

class _CarteInscription extends StatelessWidget {
  const _CarteInscription({required this.inscription, this.info, this.dateEdition});

  final Inscription inscription;
  final InfoCourse? info;

  /// Jour de la course, quand l'heure exacte du départ n'est pas encore
  /// publiée. Sert de repli à `info.heureDepart`.
  final DateTime? dateEdition;

  static const _jours = <String>[
    'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche',
  ];
  static const _mois = <String>[
    'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
  ];

  static String _dateLongue(DateTime d) {
    final l = d.toLocal();
    return '${_jours[l.weekday - 1]} ${l.day} ${_mois[l.month - 1]} ${l.year}';
  }

  /// Deux chiffres — pour les composants d'une date iCalendar.
  static String _d2(int n) => n.toString().padLeft(2, '0');

  /// Fabrique un événement iCalendar et le remet au système.
  ///
  /// ═══════════════════════════════════════════════════════════════════════
  /// AUCUNE DÉPENDANCE NOUVELLE, ET C'EST VOULU.
  ///
  /// Le fichier est construit en mémoire (`XFile.fromData`) et passé à la
  /// feuille de partage, qui propose « Ajouter au calendrier » sur iOS comme
  /// sur Android. Une bibliothèque d'accès direct à l'agenda demanderait une
  /// autorisation de plus — lire et écrire TOUT le calendrier — pour un seul
  /// événement que l'utilisateur valide de toute façon lui-même.
  ///
  /// ⚠️ SANS HEURE DE DÉPART CONNUE, ON POSE UNE JOURNÉE ENTIÈRE
  /// (`VALUE=DATE`) plutôt qu'un horaire inventé. Un rendez-vous à 00 h 00
  /// dans l'agenda de quelqu'un serait pire que pas de rendez-vous du tout.
  ///
  /// Les heures sont écrites en UTC (suffixe `Z`) : l'agenda les replace dans
  /// le fuseau du téléphone. En heure locale nue, un coureur en déplacement
  /// verrait le départ décalé.
  /// ═══════════════════════════════════════════════════════════════════════
  Future<void> _ajouterAgenda(BuildContext context, DateTime quand) async {
    final messenger = ScaffoldMessenger.of(context);
    final titre = info?.libelle ?? 'Forbach en Rose ${inscription.annee}';
    final avecHeure = info?.heureDepart != null;

    String champDebut;
    String champFin;
    if (avecHeure) {
      final u = quand.toUtc();
      final t = '${u.year}${_d2(u.month)}${_d2(u.day)}T'
          '${_d2(u.hour)}${_d2(u.minute)}00Z';
      // Deux heures : la durée d'une marche, pas celle de la course d'un seul.
      final f = u.add(const Duration(hours: 2));
      champDebut = 'DTSTART:$t';
      champFin = 'DTEND:${f.year}${_d2(f.month)}${_d2(f.day)}T'
          '${_d2(f.hour)}${_d2(f.minute)}00Z';
    } else {
      final l = quand.toLocal();
      final j = '${l.year}${_d2(l.month)}${_d2(l.day)}';
      final lendemain = DateTime(l.year, l.month, l.day + 1);
      champDebut = 'DTSTART;VALUE=DATE:$j';
      champFin = 'DTEND;VALUE=DATE:${lendemain.year}'
          '${_d2(lendemain.month)}${_d2(lendemain.day)}';
    }

    final lieu = (info?.lieu ?? '').replaceAll(',', r'\,');
    // ⚠️ CRLF EXIGÉ par la norme iCalendar. Avec des sauts de ligne simples,
    // certains agendas refusent le fichier sans le moindre message.
    final ics = <String>[
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//Forbach en Rose//Application coureur//FR',
      'BEGIN:VEVENT',
      'UID:fer-${inscription.annee}-${inscription.inscriptionNo}@forbachenrose.fr',
      champDebut,
      champFin,
      'SUMMARY:$titre — inscription n° ${inscription.inscriptionNo}',
      if (lieu.isNotEmpty) 'LOCATION:$lieu',
      'DESCRIPTION:Inscription n° ${inscription.inscriptionNo} '
          'au nom de ${inscription.nomComplet}.',
      'END:VEVENT',
      'END:VCALENDAR',
    ].join('\r\n');

    try {
      // ⚠️ `sharePositionOrigin` EST INDISPENSABLE SUR IPAD. La feuille de
      // partage y est un popover : sans rectangle d'origine, iOS ne sait pas
      // d'où la faire sortir et l'appel lève. Sur iPhone, il est ignoré.
      final boite = context.findRenderObject() as RenderBox?;
      await Share.shareXFiles(
        <XFile>[
          XFile.fromData(
            Uint8List.fromList(utf8.encode(ics)),
            mimeType: 'text/calendar',
            name: 'forbach-en-rose-${inscription.annee}.ics',
          ),
        ],
        sharePositionOrigin: boite == null
            ? null
            : boite.localToGlobal(Offset.zero) & boite.size,
      );
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(content: Text("L'ajout à l'agenda n'a pas abouti : $e")),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final couleurs = theme.colorScheme;

    final depart = info?.heureDepart ?? dateEdition;
    // Recalculé sur la date retenue : `info.avantDepart` est nul en même temps
    // que `heureDepart`, il ne peut pas servir au repli.
    final avant = depart == null ? null : depart.difference(DateTime.now());

    return Container(
      width: double.infinity,
      // Resserré : la carte tenait trop de place pour ce qu'elle porte.
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
      decoration: BoxDecoration(
        color: couleurs.primaryContainer,
        borderRadius: BorderRadius.circular(22),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            (info?.libelle ?? 'Forbach en Rose ${inscription.annee}')
                .toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              color: couleurs.onPrimaryContainer,
              fontWeight: FontWeight.w800,
              letterSpacing: 1.2,
            ),
          ),
          const SizedBox(height: 10),

          // Le numéro en grand : c'est l'identifiant qu'on cherche.
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: <Widget>[
              Text(
                'N°',
                style: theme.textTheme.titleSmall?.copyWith(
                  // ignore: deprecated_member_use
                  color: couleurs.onPrimaryContainer.withOpacity(0.6),
                ),
              ),
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  inscription.inscriptionNo,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.displaySmall?.copyWith(
                    color: couleurs.onPrimaryContainer,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -1,
                    fontFeatures: chiffresFixes.fontFeatures,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 2),
          Text(
            inscription.nomComplet,
            style: theme.textTheme.titleMedium?.copyWith(
              color: couleurs.onPrimaryContainer,
              fontWeight: FontWeight.w600,
            ),
          ),

          if (depart != null) ...<Widget>[
            const SizedBox(height: 12),
            Divider(
              // ignore: deprecated_member_use
              color: couleurs.onPrimaryContainer.withOpacity(0.18),
              height: 1,
            ),

            // ⚠️ LA DATE EST UN BOUTON, ET ÇA DOIT SE VOIR. Une zone touchable
            // qui ne se distingue en rien du texte n'est jamais découverte : le
            // libellé « Ajouter à l'agenda » et le chevron sont là pour ça.
            InkWell(
              onTap: () => _ajouterAgenda(context, depart),
              borderRadius: BorderRadius.circular(10),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Row(
                  children: <Widget>[
                    Icon(Icons.event_outlined,
                        size: 18, color: couleurs.onPrimaryContainer),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Text(
                            _dateLongue(depart),
                            style: theme.textTheme.bodyMedium?.copyWith(
                              color: couleurs.onPrimaryContainer,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            "Ajouter à l'agenda",
                            style: theme.textTheme.labelSmall?.copyWith(
                              // ignore: deprecated_member_use
                              color: couleurs.onPrimaryContainer
                                  .withOpacity(0.65),
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (avant != null && !avant.isNegative)
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 11, vertical: 4),
                        decoration: BoxDecoration(
                          color: couleurs.onPrimaryContainer,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          avant.inDays >= 1
                              ? 'J-${avant.inDays}'
                              : '${avant.inHours} h',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: couleurs.primaryContainer,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                    const SizedBox(width: 4),
                    Icon(Icons.chevron_right,
                        size: 18,
                        // ignore: deprecated_member_use
                        color: couleurs.onPrimaryContainer.withOpacity(0.5)),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/* ─────────────────────────────── à venir ──────────────────────────────── */

class _EtatAVenir extends StatelessWidget {
  const _EtatAVenir({required this.inscription, this.info});

  final Inscription inscription;
  final InfoCourse? info;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pret = info?.chronoPret ?? false;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        // ⚠️ PLUS DE COMPTE À REBOURS ICI : il est sur la carte, avec la date.
        // Le laisser aux deux endroits ferait répéter la même information à
        // trois lignes d'écart.
        if (info != null) ...<Widget>[
          if (info!.heureDepart != null)
            LigneFer('Heure de départ', _heure(info!.heureDepart!),
                icone: Icons.flag_outlined),
          if (info!.lieu != null)
            LigneFer('Rendez-vous', info!.lieu!, icone: Icons.place_outlined),
          if (info!.distanceKm != null)
            LigneFer(
                'Distance',
                '${info!.distanceKm!.toStringAsFixed(2).replaceAll('.', ',')} km',
                icone: Icons.straighten),
          if (info!.retraitTshirt != null)
            LigneFer('Retrait des T-shirts', info!.retraitTshirt!,
                icone: Icons.checkroom_outlined),
          const SizedBox(height: 20),
        ],

        // ⚠️ « Démarrer » n'apparaît que si le chronométrage est OUVERT et que
        // l'édition a tout ce qu'il lui faut (lignes posées, heure connue).
        // Le proposer sur une édition incomplète promettrait un temps qui ne
        // serait jamais calculé.
        if (pret)
          FilledButton.icon(
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => EcranSuivi(inscription: inscription),
              ),
            ),
            icon: const Icon(Icons.play_arrow_rounded),
            label: const Text('Démarrer ma course'),
          )
        else
          OutlinedButton.icon(
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => EcranInscription(inscription: inscription),
              ),
            ),
            // ⚠️ « Voir mon inscription », PAS « voir mon dossard ». Le QR code
            // ne sert qu'à accélérer le retrait des T-shirts, l'organisation
            // peut le désactiver, et tout le monde n'en a pas. Promettre un
            // dossard derrière ce bouton ferait chercher une chose qui n'y est
            // parfois pas ; l'inscription, elle, est toujours là.
            icon: const Icon(Icons.badge_outlined),
            label: const Text('Voir mon inscription'),
          ),

        // ⚠️ LA SECONDE PHRASE A ÉTÉ RETIRÉE : « votre dossard et votre QR
        // code restent accessibles » désignait ce que le bouton juste au-dessus
        // ouvre déjà. Rassurer sur ce qu'on vient d'offrir, c'est laisser
        // croire qu'il y avait lieu de s'inquiéter.
        if (!pret) ...<Widget>[
          const SizedBox(height: 10),
          Text(
            'Le suivi de course s\'activera aux abords de la course.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ],
      ],
    );
  }

  /// L'HEURE seule — la date est déjà sur le dossard, juste au-dessus.
  static String _heure(DateTime d) {
    final l = d.toLocal();
    return '${l.hour.toString().padLeft(2, '0')} h '
        '${l.minute.toString().padLeft(2, '0')}';
  }
}

/* ─────────────────────────────── en cours ─────────────────────────────── */

class _EtatEnCours extends StatelessWidget {
  const _EtatEnCours({required this.inscription});

  final Inscription inscription;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final suivi = PorteeSession.de(context).suivi;

    return Material(
      color: theme.colorScheme.primaryContainer,
      borderRadius: BorderRadius.circular(24),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => EcranSuivi(inscription: inscription),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(22),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Container(
                    width: 9,
                    height: 9,
                    decoration: const BoxDecoration(
                        color: Colors.red, shape: BoxShape.circle),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    'COURSE EN COURS',
                    style: theme.textTheme.labelMedium?.copyWith(
                      color: theme.colorScheme.onPrimaryContainer,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 1,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              Text(
                formaterDuree(suivi.chronoCourant),
                style: theme.textTheme.displayMedium?.copyWith(
                  color: theme.colorScheme.onPrimaryContainer,
                  fontWeight: FontWeight.w700,
                  fontFeatures: chiffresFixes.fontFeatures,
                ),
              ),
              const SizedBox(height: 14),
              Row(
                children: <Widget>[
                  Expanded(
                    child: _Mesure('Distance', formaterDistance(suivi.distanceM)),
                  ),
                  Expanded(
                    child: _Mesure(
                        'Allure',
                        suivi.allureMoyenne != null
                            ? '${suivi.allureMoyenne} /km'
                            : '—'),
                  ),
                  Expanded(
                    child: _Mesure(
                        'Dénivelé +', '${suivi.denivelePositifM.round()} m'),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              // ⚠️ LA MISE EN GARDE ACCOMPAGNE LE COMPTEUR, ICI AUSSI. Cette
              // carte est vue bien plus souvent que l'écran de suivi.
              Text(
                "Votre temps final est calculé par l'organisation.",
                style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onPrimaryContainer
                        // ignore: deprecated_member_use
                        .withOpacity(0.75)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Mesure extends StatelessWidget {
  const _Mesure(this.libelle, this.valeur);

  final String libelle;
  final String valeur;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(valeur,
            style: theme.textTheme.titleMedium?.copyWith(
              color: theme.colorScheme.onPrimaryContainer,
              fontFeatures: chiffresFixes.fontFeatures,
            )),
        Text(libelle,
            style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onPrimaryContainer
                    // ignore: deprecated_member_use
                    .withOpacity(0.7))),
      ],
    );
  }
}

/* ─────────────────────────────── terminée ─────────────────────────────── */

class _EtatTerminee extends StatelessWidget {
  const _EtatTerminee({required this.resultat});

  final Resultat resultat;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: <Widget>[
            Text(
              resultat.chrono,
              style: theme.textTheme.displaySmall?.copyWith(
                fontWeight: FontWeight.w700,
                fontFeatures: chiffresFixes.fontFeatures,
              ),
            ),
            const SizedBox(width: 12),
            const Padding(
              padding: EdgeInsets.only(bottom: 8),
              child: Pastille('Terminée', icone: Icons.check_circle_outline),
            ),
          ],
        ),
        // ⚠️ LA MENTION NE S'AFFICHE QUE SI ELLE APPORTE QUELQUE CHOSE — la
        // même règle qu'à l'écran Résultats, portée par `Resultat.mentionUtile`.
        // Sur un temps issu d'une vraie mesure (balise, GPS à la ligne), il n'y
        // a aucune approximation à signaler. Sur un temps approché, elle reste
        // obligatoire : c'est elle qui permet de défendre ou de corriger un
        // classement contesté.
        if (resultat.mentionUtile) ...<Widget>[
          const SizedBox(height: 10),
          Text(
            resultat.mention,
            style: theme.textTheme.bodyMedium
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ],
      ],
    );
  }
}

/// Deux champs côte à côte, à parts égales.
///
/// ⚠️ `IntrinsicHeight` VOLONTAIREMENT ABSENT : les deux colonnes n'ont pas à
/// faire la même hauteur. Si l'une se replie sur deux lignes et l'autre non,
/// c'est sans conséquence — elles sont alignées PAR LE HAUT, là où se trouvent
/// les libellés, et c'est le seul alignement que l'œil suit.
class _Paire extends StatelessWidget {
  const _Paire({required this.gauche, required this.droite});

  final Widget gauche;
  final Widget droite;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(child: gauche),
          const SizedBox(width: 20),
          Expanded(child: droite),
        ],
      );
}

/// Le QR en plein écran, sur fond blanc.
///
/// ⚠️ FOND BLANC ET NOIR PUR, quel que soit le thème. Un lecteur de code lit un
/// contraste, pas une couleur : un QR sombre sur fond sombre ne se lit plus, et
/// on ne s'en aperçoit qu'au stand, le jour de la course, devant la file.
///
/// La page se ferme au toucher : au moment où on tend son téléphone, chercher
/// une croix est un geste de trop.
Future<void> _ouvrirQrEnGrand(BuildContext context, Uint8List png) {
  return Navigator.of(context).push(PageRouteBuilder<void>(
    opaque: false,
    barrierColor: Colors.black87,
    pageBuilder: (c, _, __) => GestureDetector(
      onTap: () => Navigator.of(c).pop(),
      child: Scaffold(
        backgroundColor: Colors.white,
        body: SafeArea(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Expanded(
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: AspectRatio(
                      aspectRatio: 1,
                      child: Image.memory(
                        png,
                        // `contain` : le QR occupe le plus grand carré possible
                        // sans jamais être déformé — un module rectangulaire ne
                        // se décode pas.
                        fit: BoxFit.contain,
                        filterQuality: FilterQuality.none,
                      ),
                    ),
                  ),
                ),
              ),
              const Padding(
                padding: EdgeInsets.only(bottom: 24),
                child: Text(
                  'Touchez pour fermer',
                  style: TextStyle(color: Colors.black54),
                ),
              ),
            ],
          ),
        ),
      ),
    ),
  ));
}

/* ───────────────────────── les éditions passées ───────────────────────── */

class _LigneInscription extends StatelessWidget {
  const _LigneInscription({required this.inscription, this.resultat});

  final Inscription inscription;
  final Resultat? resultat;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final sousTitre = <String>[
      'n° ${inscription.inscriptionNo}',
      if (inscription.tshirt != null) 'T-shirt ${inscription.tshirt}',
      if (inscription.ville != null) inscription.ville!,
    ].join(' · ');

    return InkWell(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => EcranInscription(inscription: inscription),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Row(
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(inscription.nomComplet,
                      style: theme.textTheme.titleSmall),
                  const SizedBox(height: 2),
                  Text(sousTitre,
                      style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant)),
                ],
              ),
            ),
            const SizedBox(width: 12),
            if (resultat?.chronoAffichable ?? false)
              Text(resultat!.chrono,
                  style: theme.textTheme.titleSmall
                      ?.copyWith(fontFeatures: chiffresFixes.fontFeatures))
            else if (inscription.estGratuite)
              const Pastille('Gratuit')
            else
              Pastille(
                '${inscription.montantDu!.toStringAsFixed(2).replaceAll('.', ',')} €',
              ),
            Icon(Icons.chevron_right,
                size: 20, color: theme.colorScheme.outlineVariant),
          ],
        ),
      ),
    );
  }
}

/// Une valeur est-elle réellement renseignée ?
///
/// ⚠️ `!= null` NE SUFFIT PAS. Le serveur renvoie une chaîne VIDE, et non
/// `null`, pour une ville ou une équipe non saisies. Le test sur le seul null
/// laissait donc s'afficher des lignes « Ville » et « Équipe » sans valeur —
/// et une étiquette suivie de rien ne se lit pas « non renseigné », elle se lit
/// « information perdue ».
bool _rempli(String? v) => v != null && v.trim().isNotEmpty;

/// « 04/07/2026 à 00:00 » — le même format que le site, pour qu'un coureur qui
/// compare les deux ne se demande pas s'il lit bien la même date.
String _dateHeureCourte(DateTime d) {
  final l = d.toLocal();
  String d2(int n) => n.toString().padLeft(2, '0');
  return '${d2(l.day)}/${d2(l.month)}/${l.year} à ${d2(l.hour)}:${d2(l.minute)}';
}

/* ════════════════════════════ Fiche détaillée ═══════════════════════════ */

class EcranInscription extends StatefulWidget {
  const EcranInscription({required this.inscription, super.key});

  final Inscription inscription;

  @override
  State<EcranInscription> createState() => _EcranInscriptionState();
}

class _EcranInscriptionState extends State<EcranInscription> {
  Uint8ListFuture? _qr;

  @override
  void initState() {
    super.initState();
    _qr = _chargerQr();
  }

  /// Le QR est demandé au serveur, pas fabriqué ici.
  ///
  /// ⚠️ C'EST LE MÊME GÉNÉRATEUR QUE LE MAIL DE CONFIRMATION. Un QR reconstruit
  /// côté application aurait sa propre version du contenu encodé — et le jour
  /// où les deux divergeraient, le lecteur du stand refuserait un dossard
  /// parfaitement valable.
  Uint8ListFuture _chargerQr() async {
    final api = PorteeSession.action(context).api;
    final b64 = await api.qrCode(
      widget.inscription.annee,
      widget.inscription.inscriptionNo,
    );
    return base64Decode(b64);
  }

  @override
  Widget build(BuildContext context) {
    final i = widget.inscription;
    final session = PorteeSession.de(context);
    final resultat = session.resultatDe(i);
    final large = MediaQuery.sizeOf(context).width >= 700;

    final detail = CarteFer(
      titre: "Détail de l'inscription",
      icone: Icons.badge_outlined,
      // ⚠️ `_rempli()` ET NON `!= null`. Le serveur renvoie une chaîne VIDE, et
      // non `null`, pour une ville ou une équipe non saisies : le test sur le
      // null laissait donc passer des lignes « Ville » et « Équipe » sans
      // valeur. Une étiquette suivie de rien ne se lit pas comme « non
      // renseigné » — elle se lit comme une information perdue.
      // ⚠️ DEUX COLONNES POUR LES VALEURS COURTES.
      //
      // Tout empilé sur une seule colonne, la fiche s'étirait sur un écran
      // entier pour huit informations dont la moitié tiennent en trois mots :
      // « Homme », « 26 ans », « S1 », « 2026 ». Il fallait défiler pour voir
      // ce qui aurait pu être lu d'un coup d'œil.
      //
      // Les valeurs longues — nom, ville, équipe, paiement — gardent toute la
      // largeur : les mettre en demi-colonne les ferait se replier, et on
      // retomberait sur le défaut qu'on vient de corriger.
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          LigneFer('Nom', i.nomComplet),
          _Paire(
            gauche: LigneFer('Numéro', i.inscriptionNo),
            droite: LigneFer('Édition', '${i.annee}'),
          ),
          if (_rempli(i.sexe) || i.age != null)
            _Paire(
              gauche: _rempli(i.sexe)
                  ? LigneFer('Sexe', _sexe(i.sexe!))
                  : const SizedBox.shrink(),
              droite: i.age != null
                  ? LigneFer('Âge', '${i.age} ans')
                  : const SizedBox.shrink(),
            ),
          if (_rempli(i.ville) || _rempli(i.tshirt))
            _Paire(
              gauche: _rempli(i.ville)
                  ? LigneFer('Ville', i.ville!)
                  : const SizedBox.shrink(),
              droite: _rempli(i.tshirt)
                  ? LigneFer('T-shirt', i.tshirt!)
                  : const SizedBox.shrink(),
            ),
          if (_rempli(i.equipe)) LigneFer('Équipe', i.equipe!),
          LigneFer(
            'Paiement',
            i.estGratuite
                ? 'Gratuit'
                : '${i.paiementMode ?? 'Réglé'} · '
                    '${i.montantDu!.toStringAsFixed(2).replaceAll('.', ',')} €',
          ),

          const SizedBox(height: 16),
          // ⚠️ LE TRANSFERT SE TROUVE ICI PARCE QU'ON Y PENSE ICI. Il n'existait
          // que dans « Mon compte » : quelqu'un qui regarde l'inscription de son
          // conjoint et décide de la lui rendre devait deviner qu'il fallait
          // ressortir et aller dans les réglages. C'est le même écran des deux
          // côtés — mais ouvert d'ici, l'inscription est déjà désignée, et on ne
          // risque plus de transférer celle du voisin.
          // ⚠️ DÉSACTIVÉ QUAND LES TRANSFERTS SONT FERMÉS, comme sur le site.
          // Le serveur refusait déjà — `xfer_creer()` applique la règle — mais
          // seulement après avoir laissé saisir une adresse. Un bouton actif
          // qui mène à un refus connu d'avance est une promesse qu'on ne tient
          // pas.
          Builder(builder: (context) {
            final edition = PorteeSession.de(context).editionActive;
            final fermes = edition != null && !edition.transfertsOuverts;
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                OutlinedButton.icon(
                  onPressed: fermes
                      ? null
                      : () => Navigator.of(context).push(
                            MaterialPageRoute<bool>(
                              builder: (_) => EcranTransfert(inscription: i),
                            ),
                          ),
                  icon: const Icon(Icons.swap_horiz, size: 18),
                  label: const Text('Transférer cette inscription'),
                ),
                if (fermes) ...<Widget>[
                  const SizedBox(height: 8),
                  Text(
                    edition.transfertsDeadline == null
                        ? 'Les transferts sont fermés pour cette édition.'
                        : 'La date limite de transfert est dépassée '
                            '(${_dateHeureCourte(edition.transfertsDeadline!)}). '
                            "Contactez l'organisation si c'est un cas particulier.",
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant),
                  ),
                ],
              ],
            );
          }),
        ],
      ),
    );

    final qr = CarteFer(
      titre: 'Votre QR code',
      icone: Icons.qr_code_2,
      enfant: FutureBuilder<Uint8List>(
        future: _qr,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const SizedBox(
              height: 200,
              child: Center(child: CircularProgressIndicator()),
            );
          }
          if (snap.hasError) {
            final e = snap.error;
            // ⚠️ « PAS DE QR » N'EST PAS UNE PANNE.
            //
            // Le serveur répond 409 `qr_indisponible` quand l'organisation
            // n'utilise pas les QR, quand l'inscription est gratuite, ou quand
            // elle est arrivée après la limite de t-shirts — la même règle que
            // le site et que le mail. Proposer « Réessayer » sur cette réponse
            // ferait espérer qu'un QR finisse par arriver. Le message du
            // serveur dit déjà pourquoi ; on le reprend tel quel.
            if (e is ApiErreur && e.code == 'qr_indisponible') {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(e.message,
                      style: Theme.of(context).textTheme.bodyMedium),
                  const SizedBox(height: 10),
                  Text(
                    'Votre inscription reste valable — vous êtes attendu au '
                    'départ.',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant),
                  ),
                ],
              );
            }
            return _Erreur(snap.error!, () => setState(() => _qr = _chargerQr()));
          }
          // ⚠️ `SizedBox(width: double.infinity)` EST INDISPENSABLE ICI.
          //
          // `crossAxisAlignment: center` seul ne suffisait pas : une colonne
          // prend la largeur de son plus large enfant, et se centrer dans sa
          // propre largeur ne déplace rien. La carte l'alignait ensuite à
          // gauche, et le QR restait collé au bord — d'où l'impression qu'il
          // n'était « toujours pas centré ».
          //
          // En forçant la colonne à occuper toute la largeur disponible, il y a
          // enfin de la place à distribuer de part et d'autre.
          return SizedBox(
            width: double.infinity,
            child: Column(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              // ⚠️ FOND BLANC IMPOSÉ. En thème sombre, un QR noir sur fond
              // sombre n'est plus lisible par un lecteur — et on ne s'en rend
              // compte qu'au stand, le jour de la course.
              // ⚠️ TOUCHABLE POUR L'AGRANDIR. Au stand, on tend son téléphone
              // à quelqu'un qui scanne : 200 px sur un écran incliné, avec la
              // luminosité au minimum, se lisent mal. Le plein écran met le QR
              // à la plus grande taille possible sur fond blanc.
              InkWell(
                onTap: () => _ouvrirQrEnGrand(context, snap.data!),
                borderRadius: BorderRadius.circular(12),
                child: Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Image.memory(
                    snap.data!,
                    width: 200,
                    height: 200,
                    // Un QR agrandi doit rester net : l'interpolation lisserait
                    // les modules et le rendrait illisible.
                    filterQuality: FilterQuality.none,
                  ),
                ),
              ),
              const SizedBox(height: 12),
              // Le bouton « Afficher en grand » a été retiré : l'image est
              // elle-même touchable, et la phrase ci-dessous le dit. Un bouton
              // pour ce qu'on obtient déjà en touchant l'objet fait doublon.
              Text(
                'Touchez le code pour l\'afficher en grand.\n'
                'À présenter au retrait des T-shirts.',
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: Theme.of(context).colorScheme.outline),
              ),
            ],
            ),
          );
        },
      ),
    );

    return Scaffold(
      appBar: AppBar(title: Text('Édition ${i.annee}')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(marge),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            // Sur tablette, les deux cartes tiennent côte à côte ; sur
            // téléphone elles s'empilent. Aucune donnée n'est masquée d'un
            // format à l'autre.
            if (large)
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    SizedBox(width: 320, child: qr),
                    const SizedBox(width: marge),
                    Expanded(child: detail),
                  ],
                ),
              )
            else ...<Widget>[
              // ⚠️ LE QR PASSE DEVANT LA FICHE. C'est ce qu'on vient chercher
              // au stand de retrait, téléphone à la main, souvent en file :
              // le faire défiler sous huit lignes d'état civil ajoutait un
              // geste au pire moment. La fiche, elle, se consulte au calme.
              qr,
              const SizedBox(height: marge),
              detail,
            ],
            if (resultat != null) ...<Widget>[
              const SizedBox(height: marge),
              _CarteResultat(resultat: resultat),
            ],
            const SizedBox(height: marge),
            _CarteCorrection(inscription: i),
          ],
        ),
      ),
    );
  }

  static String _sexe(String s) => switch (s.toUpperCase()) {
        'H' || 'M' => 'Homme',
        'F' => 'Femme',
        _ => 'Autre',
      };
}

/// Correction du sexe et de l'âge — les deux seuls champs modifiables.
///
/// Le serveur refuse la modification sur une édition archivée ou après le
/// départ. L'application NE REJOUE PAS ces règles : elle envoie et affiche le
/// refus. Deux jeux de règles finiraient par diverger, et c'est celui du
/// serveur qui compte.
class _CarteCorrection extends StatefulWidget {
  const _CarteCorrection({required this.inscription});

  final Inscription inscription;

  @override
  State<_CarteCorrection> createState() => _CarteCorrectionState();
}

class _CarteCorrectionState extends State<_CarteCorrection> {
  late String? _sexe = widget.inscription.sexe;
  late final TextEditingController _age =
      TextEditingController(text: widget.inscription.age?.toString() ?? '');
  bool _ouvert = false;
  bool _occupe = false;

  @override
  void dispose() {
    _age.dispose();
    super.dispose();
  }

  Future<void> _envoyer() async {
    setState(() => _occupe = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final r = await PorteeSession.action(context).api.majInscription(
            widget.inscription.annee,
            widget.inscription.inscriptionNo,
            sexe: _sexe,
            age: _age.text.trim().isEmpty ? null : _age.text.trim(),
          );
      if (!mounted) return;
      await PorteeSession.action(context).rafraichir();
      messenger.showSnackBar(
        SnackBar(content: Text(r['message'] as String? ?? 'Enregistré.')),
      );
      if (mounted) setState(() => _ouvert = false);
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    // ⚠️ FERMÉ UNE FOIS LE DÉPART DONNÉ, comme sur le site.
    //
    // `pprofile_majInscription()` refuse déjà — sexe et âge déterminent la
    // catégorie de classement, les changer en pleine course reviendrait à
    // changer de catégorie. Mais le refus n'arrivait qu'après la saisie.
    final partie = PorteeSession.de(context).infoCourse?.partie ?? false;
    if (partie) {
      return Align(
        alignment: Alignment.centerLeft,
        child: Text(
          'La course a démarré : le sexe et l\'âge ne sont plus modifiables. '
          "Contactez l'organisation pour toute correction.",
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant),
        ),
      );
    }

    if (!_ouvert) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          onPressed: () => setState(() => _ouvert = true),
          icon: const Icon(Icons.edit_outlined),
          label: const Text('Changer mes informations'),
        ),
      );
    }
    return CarteFer(
      titre: 'Changer mes informations',
      icone: Icons.edit_outlined,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          // ⚠️ UN CHOIX SEGMENTÉ, PAS UNE LISTE DÉROULANTE.
          //
          // La liste déroulante ouvrait une fenêtre système grise, sans rapport
          // avec le reste de l'écran, pour choisir entre DEUX valeurs. Un menu
          // se justifie à partir de cinq ou six entrées ; en dessous, il ajoute
          // un geste et masque le contenu pour rien.
          //
          // Les deux options sont ici visibles d'emblée, et l'on voit du même
          // coup d'œil laquelle est retenue.
          Text('Sexe',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant)),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            // ⚠️ « AUTRE » MANQUAIT, ET LA BASE L'ACCEPTAIT DÉJÀ :
            // `enum('H','F','Autre')`, validé par le serveur. Ne pas l'offrir
            // obligeait une partie des coureurs à se ranger dans une case qui
            // n'est pas la leur — pour un champ qui ne sert qu'au classement
            // par catégorie.
            //
            // Les icônes disparaissent : il n'en existe pas de juste pour la
            // troisième option, et deux entrées illustrées sur trois se lisent
            // comme un oubli.
            segments: const <ButtonSegment<String>>[
              ButtonSegment<String>(value: 'H', label: Text('Homme')),
              ButtonSegment<String>(value: 'F', label: Text('Femme')),
              ButtonSegment<String>(value: 'Autre', label: Text('Autre')),
            ],
            selected: <String>{if (_sexe != null) _sexe!},
            // Rien de sélectionné tant que le serveur n'a rien renvoyé : on
            // n'invente pas une valeur par défaut sur cette donnée-là.
            emptySelectionAllowed: true,
            showSelectedIcon: false,
            onSelectionChanged: _occupe
                ? null
                : (s) => setState(() => _sexe = s.isEmpty ? null : s.first),
          ),
          const SizedBox(height: 16),
          Text('Âge',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant)),
          const SizedBox(height: 8),
          TextField(
            controller: _age,
            enabled: !_occupe,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(hintText: 'en années'),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _occupe ? null : _envoyer,
            child: const Text('Enregistrer'),
          ),
          TextButton(
            onPressed: _occupe ? null : () => setState(() => _ouvert = false),
            child: const Text('Annuler'),
          ),
        ],
      ),
    );
  }
}

class _CarteResultat extends StatelessWidget {
  const _CarteResultat({required this.resultat});

  final Resultat resultat;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return CarteFer(
      titre: 'Votre temps',
      icone: Icons.timer_outlined,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            resultat.chrono,
            style: theme.textTheme.displaySmall?.copyWith(
              fontWeight: FontWeight.w700,
              fontFeatures: chiffresFixes.fontFeatures,
            ),
          ),
          // Même règle que partout : voir `Resultat.mentionUtile`.
          if (resultat.mentionUtile) ...<Widget>[
            const SizedBox(height: 4),
            Text(
              resultat.mention,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.outline),
            ),
          ],
        ],
      ),
    );
  }
}

class _Erreur extends StatelessWidget {
  const _Erreur(this.erreur, this.reessayer);

  final Object erreur;
  final VoidCallback reessayer;

  @override
  Widget build(BuildContext context) {
    final message =
        erreur is ApiErreur ? (erreur as ApiErreur).message : '$erreur';
    return Column(
      children: <Widget>[
        Text(message, textAlign: TextAlign.center),
        const SizedBox(height: 8),
        TextButton(onPressed: reessayer, child: const Text('Réessayer')),
      ],
    );
  }
}

typedef Uint8ListFuture = Future<Uint8List>;
