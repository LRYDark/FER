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
          for (final annee in annees) ...<Widget>[
            SectionFer('$annee'),
            for (final i in parAnnee[annee]!) ...<Widget>[
              _LigneInscription(
                inscription: i,
                resultat: session.resultatDe(i),
              ),
              if (i != parAnnee[annee]!.last) const Divider(),
            ],
            const SizedBox(height: 24),
          ],
          if (inscriptions.length > 1)
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
        _Dossard(
          inscription: inscription,
          info: info,
          // ⚠️ REPLI SUR LA DATE DE L'ÉDITION. `heureDepart` reste nulle tant
          // que l'organisation n'a pas publié l'heure du coup de feu — c'est le
          // cas la plus grande partie de l'année. Sans ce repli, le dossard
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

/* ────────────────────────────── le dossard ────────────────────────────────
 *
 * L'EN-TÊTE EST UN DOSSARD, PAS UNE FICHE.
 *
 * Il n'y avait ici qu'un nom et une ligne « Dossard n° S1 » en petits
 * caractères. C'était exact, et parfaitement sans âme : rien ne distinguait
 * l'écran d'un formulaire administratif, alors qu'il s'agit de la seule chose
 * qu'on vient regarder vingt fois avant une course.
 *
 * Le numéro prend donc la place qu'il a dans la réalité — celle du dossard
 * qu'on épinglera sur son maillot — et la DATE l'accompagne. Son absence était
 * le vrai manque : un écran d'inscription à une course qui ne dit pas quand
 * elle a lieu oblige à aller la chercher ailleurs.
 *
 * ⚠️ LE COMPTE À REBOURS S'AFFICHE TOUTE L'ANNÉE ICI, contrairement à ce que
 * faisait l'ancienne carte (sept jours avant, pas plus). Le raisonnement a
 * changé avec la place : au milieu d'une liste, « J-247 » était du bruit ; sur
 * le dossard, à côté de la date, c'est précisément ce qu'on vient voir. Pour
 * une épreuve annuelle, l'attente FAIT partie de l'objet.
 * ────────────────────────────────────────────────────────────────────────── */

class _Dossard extends StatelessWidget {
  const _Dossard({required this.inscription, this.info, this.dateEdition});

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
      'SUMMARY:$titre — dossard ${inscription.inscriptionNo}',
      if (lieu.isNotEmpty) 'LOCATION:$lieu',
      'DESCRIPTION:Inscription n° ${inscription.inscriptionNo} '
          'au nom de ${inscription.nomComplet}.',
      'END:VEVENT',
      'END:VCALENDAR',
    ].join('\r\n');

    try {
      await Share.shareXFiles(<XFile>[
        XFile.fromData(
          Uint8List.fromList(utf8.encode(ics)),
          mimeType: 'text/calendar',
          name: 'forbach-en-rose-${inscription.annee}.ics',
        ),
      ]);
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

          // Le numéro, à la taille qu'il a sur un vrai dossard.
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
        // ⚠️ PLUS DE COMPTE À REBOURS ICI : il est sur le dossard, avec la date.
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
            LigneFer('Dossards et T-shirts', info!.retraitTshirt!,
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

        if (!pret) ...<Widget>[
          const SizedBox(height: 10),
          Text(
            'Le suivi de course s\'activera aux abords de la course. Votre '
            'dossard et votre QR code restent accessibles.',
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
        const SizedBox(height: 10),
        // ⚠️ MÉTHODE ET PRÉCISION VONT AVEC LE CHRONO, TOUJOURS. Un temps sans
        // sa méthode laisse croire à une mesure unique et incontestable — et
        // c'est exactement la phrase qu'on ne peut pas défendre devant un
        // classement contesté. Même formulation que l'écran Résultats : deux
        // libellés différents pour un même temps sèmeraient le doute.
        Text(
          '${resultat.methode.libelle} — ${resultat.methode.precision}'
          '${resultat.precisionS != null ? ' · ±${resultat.precisionS} s' : ''}',
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
      ],
    );
  }
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
      enfant: Column(
        children: <Widget>[
          LigneFer('Nom', i.nomComplet),
          LigneFer('Numéro de dossard', i.inscriptionNo),
          LigneFer('Édition', '${i.annee}'),
          if (_rempli(i.sexe)) LigneFer('Sexe', _sexe(i.sexe!)),
          if (i.age != null) LigneFer('Âge', '${i.age} ans'),
          if (_rempli(i.ville)) LigneFer('Ville', i.ville!),
          if (_rempli(i.tshirt)) LigneFer('T-shirt', i.tshirt!),
          if (_rempli(i.equipe)) LigneFer('Équipe', i.equipe!),
          LigneFer(
            'Paiement',
            i.estGratuite
                ? 'Gratuit'
                : '${i.paiementMode ?? 'Réglé'} · '
                    '${i.montantDu!.toStringAsFixed(2).replaceAll('.', ',')} €',
          ),
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
            return _Erreur(snap.error!, () => setState(() => _qr = _chargerQr()));
          }
          return Column(
            children: <Widget>[
              // ⚠️ FOND BLANC IMPOSÉ. En thème sombre, un QR noir sur fond
              // sombre n'est plus lisible par un lecteur — et on ne s'en rend
              // compte qu'au stand, le jour de la course.
              Container(
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
              const SizedBox(height: 12),
              Text(
                'À présenter au retrait des dossards.',
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: Theme.of(context).colorScheme.outline),
              ),
            ],
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
                    Expanded(child: detail),
                    const SizedBox(width: marge),
                    SizedBox(width: 320, child: qr),
                  ],
                ),
              )
            else ...<Widget>[
              detail,
              const SizedBox(height: marge),
              qr,
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
            segments: const <ButtonSegment<String>>[
              ButtonSegment<String>(
                  value: 'H', label: Text('Homme'), icon: Icon(Icons.male)),
              ButtonSegment<String>(
                  value: 'F', label: Text('Femme'), icon: Icon(Icons.female)),
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
          const SizedBox(height: 4),
          // ⚠️ LA MÉTHODE ACCOMPAGNE TOUJOURS LE TEMPS. Un temps extrapolé au
          // GPS affiché nu passerait pour une mesure à la seconde près.
          Text(
            '${resultat.methode.libelle}'
            '${resultat.precisionS != null ? ' · ±${resultat.precisionS} s' : ''}',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline),
          ),
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
