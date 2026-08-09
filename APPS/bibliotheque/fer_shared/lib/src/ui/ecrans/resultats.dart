/// « Mes résultats » — les temps, édition par édition.
///
/// ⚠️ DEUX RÈGLES QUI NE SE NÉGOCIENT PAS, reprises du site :
///   • La MÉTHODE accompagne toujours le temps. Un temps extrapolé au GPS
///     affiché nu passerait pour une mesure à la seconde près.
///   • Un temps marqué « invalide » n'est PAS affiché comme un temps. Le
///     masquer sans rien dire laisserait croire à un oubli ; le publier ferait
///     passer une anomalie pour un résultat.
library;

import 'package:flutter/material.dart';

import '../../course/mesures.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';
import 'partage.dart';

class EcranResultats extends StatefulWidget {
  const EcranResultats({super.key});

  @override
  State<EcranResultats> createState() => _EcranResultatsState();
}

class _EcranResultatsState extends State<EcranResultats> {
  /// Poids et taille, lus UNE fois pour toute la liste.
  ///
  /// ⚠️ CES DONNÉES NE QUITTENT JAMAIS L'APPAREIL. Le serveur ne connaît pas
  /// votre poids et n'a aucun moyen de le demander : l'estimation se calcule
  /// donc ICI, à partir de la distance, du temps et du dénivelé renvoyés par
  /// l'API. C'est aussi pourquoi le site web ne peut pas l'afficher.
  ProfilPhysique _profil = const ProfilPhysique();

  @override
  void initState() {
    super.initState();
    ProfilPhysique.charger().then((p) {
      if (mounted) setState(() => _profil = p);
    });
  }

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);

    if (!session.chronoOuvert) {
      return const RienAAfficher(
        icone: Icons.timer_off_outlined,
        titre: "Le chronométrage n'est pas ouvert",
        explication:
            'Les temps ne sont proposés qu\'autour de la course. Si vous avez '
            'déjà couru, vos temps sont conservés : vous les retrouverez ici '
            'dès la réouverture.',
      );
    }

    if (session.inscriptions.isEmpty) {
      return const RienAAfficher(
        icone: Icons.inbox_outlined,
        titre: 'Rien à afficher',
        explication:
            "Aucune inscription n'est rattachée à ce compte, il n'y a donc "
            'pas de résultat.',
      );
    }

    final avecResultat = <Inscription, Resultat?>{
      for (final i in session.inscriptions) i: session.resultatDe(i),
    };

    return RefreshIndicator(
      onRefresh: session.rafraichir,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(marge, marge, marge, margeBasListe),
        children: <Widget>[
          for (final entree in avecResultat.entries) ...<Widget>[
            _CarteEdition(
              inscription: entree.key,
              resultat: entree.value,
              profil: _profil,
            ),
            const SizedBox(height: marge),
          ],
          // ⚠️ LE CONSENTEMENT AU SUIVI GPS N'EST PLUS ICI.
          //
          // C'est un RÉGLAGE de compte, pas un résultat : il ne dépend ni de
          // l'édition affichée ni d'une course. Posé au milieu des temps, il
          // se présentait à quelqu'un venu consulter son chrono — et restait
          // introuvable à qui le cherchait exprès.
          //
          // Il vit désormais dans « Mon compte », avec les appareils et les
          // transferts, là où l'on va quand on veut changer quelque chose.
        ],
      ),
    );
  }
}

/// Une valeur est-elle reellement renseignee ? Le serveur renvoie une chaine
/// VIDE, et non `null`, pour une ville ou une equipe non saisies.
bool _rempli(String? v) => v != null && v.trim().isNotEmpty;

/// Deux champs cote a cote, alignes par le HAUT — la ou sont les libelles.
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

/// Un chiffre en grand, son libelle dessous.
class _Chiffre extends StatelessWidget {
  const _Chiffre(this.valeur, this.libelle);

  final String valeur;
  final String libelle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(valeur,
            style: theme.textTheme.titleLarge?.copyWith(
              fontWeight: FontWeight.w700,
              fontFeatures: chiffresFixes.fontFeatures,
            )),
        Text(libelle,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
      ],
    );
  }
}

class _CarteEdition extends StatelessWidget {
  const _CarteEdition({
    required this.inscription,
    required this.profil,
    this.resultat,
  });

  final Inscription inscription;
  final Resultat? resultat;
  final ProfilPhysique profil;

  /// Estimation en kilocalories, ou `null` si le poids n'est pas renseigné.
  ///
  /// ⚠️ ON N'INVENTE PAS UN POIDS MOYEN pour afficher un chiffre : sans poids,
  /// l'estimation serait celle de quelqu'un d'autre. `Calories` renvoie `—`, et
  /// on n'affiche alors rien du tout.
  String? _calories(Resultat? r) {
    if (r == null || !profil.utilisable) return null;
    final d = r.distanceM?.toDouble();
    final t = r.tempsS;
    if (d == null || t == null || d <= 0 || t <= 0) return null;

    final c = Calories(profil)
      ..ajouter(
        distanceM: d,
        secondes: t,
        deniveleM: (r.denivelePositifM ?? 0).toDouble(),
      );
    // ⚠️ LA MARGE N'EST PLUS AFFICHÉE — décision de l'organisation. Il ne reste
    // donc QUE LE TILDE pour dire que c'est une estimation : `~487 kcal`. Il
    // est désormais le dernier signe, et `Calories.libelle` est le seul endroit
    // où il se pose. Le retirer ferait passer le chiffre pour une mesure, alors
    // que l'équation ignore le terrain, le vent, la foulée et l'entraînement.
    //
    // `mentionPrecision` reste disponible dans `Calories` si l'on veut la
    // réafficher un jour — elle n'est simplement plus lue ici.
    return c.disponible ? c.libelle : null;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final r = resultat;

    return CarteFer(
      titre: 'Édition ${inscription.annee}',
      icone: Icons.emoji_events_outlined,
      action: _pastille(r),
      // ⚠️ UN FILET, PAS UN APLAT.
      //
      // Le parti pris général est de séparer par le vide. Il tient tant qu'un
      // écran présente UNE chose ; ici, il en empile trois ou quatre — une par
      // édition — chacune avec son chrono, ses chiffres et son reçu. Sans
      // limite visible, on ne sait plus où finit 2025 et où commence 2024, et
      // le regard rattache les chiffres à la mauvaise année.
      //
      // ⚠️ `fond: true` A ÉTÉ ESSAYÉ ET REJETÉ. `surfaceContainerLow` dérive de
      // l'accent : sur le rose du projet, elle donne un bloc à peine plus foncé
      // que la page — assez pour délaver le contenu, pas assez pour le séparer.
      // Le filet, lui, ne teinte rien et dit exactement où sont les bords.
      //
      // C'est le seul endroit du projet où une carte encadre un objet RÉPÉTÉ.
      // Ailleurs, elle ferait une boîte dans une boîte.
      contour: true,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            inscription.nomComplet,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline),
          ),
          if (r != null && r.chronoAffichable) ...<Widget>[
            const SizedBox(height: 12),
            Text(
              r.chrono,
              style: theme.textTheme.displaySmall?.copyWith(
                fontWeight: FontWeight.w700,
                fontFeatures: chiffresFixes.fontFeatures,
              ),
            ),
            // ⚠️ LA MENTION NE S'AFFICHE QUE SI ELLE APPORTE QUELQUE CHOSE.
            // « Balise · ±1 s » sous un chrono issu d'une vraie mesure n'est
            // qu'un mot de plus. Sur un temps approché, c'est indispensable —
            // voir `Resultat.mentionUtile`.
            if (r.mentionUtile) ...<Widget>[
              const SizedBox(height: 6),
              Row(
                children: <Widget>[
                  Icon(Icons.satellite_alt_outlined,
                      size: 15, color: theme.colorScheme.outline),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      r.mention,
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: theme.colorScheme.outline),
                    ),
                  ),
                ],
              ),
            ],
            // Distance, dénivelé et allure : les trois chiffres qu'on
            // regarde après le chrono. En petit sous le temps, ils passaient
            // pour une note de bas de page.
            /* ═══════════════════════════════════════════════════════════════
             * DEUX LIGNES DE DEUX, PAS QUATRE COLONNES.
             *
             * Quatre `Expanded` se partageaient la largeur à parts égales, mais
             * les valeurs ne font pas la même longueur : « 84 m » tient dans un
             * quart d'écran, « ~563 kcal » non. Résultat, le dernier chiffre se
             * repliait sur deux lignes et les libellés ne s'alignaient plus.
             *
             * Un `Wrap` avec des cases de DEMI-LARGEUR règle les deux : chaque
             * chiffre a la place de tenir sur une ligne, et les cases se
             * réorganisent d'elles-mêmes quand il n'y en a que deux ou trois.
             * ═══════════════════════════════════════════════════════════════ */
            if (r.distanceM != null || r.denivelePositifM != null) ...<Widget>[
              const SizedBox(height: 16),
              LayoutBuilder(
                builder: (context, contraintes) {
                  const espace = 16.0;
                  final demi = (contraintes.maxWidth - espace) / 2;
                  final chiffres = <Widget>[
                    if (r.distanceM != null)
                      _Chiffre(
                        '${(r.distanceM! / 1000).toStringAsFixed(2)
                            .replaceAll('.', ',')} km',
                        'Distance',
                      ),
                    if (r.denivelePositifM != null)
                      _Chiffre('${r.denivelePositifM} m', 'Dénivelé +'),
                    // `formaterAllure` rend `null` sur une distance nulle : on
                    // n'affiche alors rien plutôt qu'un tiret sans signification.
                    if (r.distanceM != null && r.tempsS != null)
                      _Chiffre(
                        formaterAllure(r.distanceM!.toDouble(),
                                Duration(seconds: r.tempsS!.round())) ??
                            '—',
                        'Allure /km',
                      ),
                    if (_calories(r) != null)
                      _Chiffre(_calories(r)!, 'Calories'),
                  ];
                  return Wrap(
                    spacing: espace,
                    runSpacing: 14,
                    children: <Widget>[
                      for (final c in chiffres) SizedBox(width: demi, child: c),
                    ],
                  );
                },
              ),
            ],
          ],

          /* ═══════════════════════════════════════════════════════════════════
           * LE REÇU DE L'INSCRIPTION, ICI ET PLUS DANS LA LISTE DES DOSSARDS.
           *
           * ⚠️ AFFICHÉ MÊME SANS RÉSULTAT — hors du bloc conditionnel ci-dessus.
           * Un abandon, un non-partant, une édition sans chronométrage : dans
           * ces cas, c'est la SEULE trace qu'on a participé et payé. C'est aussi
           * une preuve de paiement, et elle ne doit dépendre de rien.
           * ═══════════════════════════════════════════════════════════════════ */
          const SizedBox(height: 12),
          const Divider(),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: LigneFer('Numéro', inscription.inscriptionNo)),
              const SizedBox(width: 16),
              Expanded(
                flex: 2,
                child: LigneFer(
                  'Paiement',
                  inscription.estGratuite
                      ? 'Gratuit'
                      : '${inscription.paiementMode ?? 'Réglé'} · '
                          '${inscription.montantDu!.toStringAsFixed(2).replaceAll('.', ',')} €',
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: LigneFer('T-shirt',
                    _rempli(inscription.tshirt) ? inscription.tshirt! : '—'),
              ),
            ],
          ),
          if (_rempli(inscription.ville) || _rempli(inscription.equipe))
            _Paire(
              gauche: _rempli(inscription.ville)
                  ? LigneFer('Ville', inscription.ville!)
                  : const SizedBox.shrink(),
              droite: _rempli(inscription.equipe)
                  ? LigneFer('Équipe', inscription.equipe!)
                  : const SizedBox.shrink(),
            ),

          if (r != null && r.chronoAffichable) ...<Widget>[
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => CartePartage(
                      inscription: inscription,
                      resultat: r,
                      distanceM: r.distanceM?.toDouble(),
                      deniveleM: r.denivelePositifM?.toDouble(),
                      // L'allure se déduit du temps et de la distance
                      // OFFICIELS : la carte ne doit pas afficher un chiffre
                      // que l'écran des résultats ne montre pas.
                      allure: r.distanceM != null && r.tempsS != null
                          ? formaterAllure(r.distanceM!.toDouble(),
                              Duration(seconds: r.tempsS!.round()))
                          : null,
                      // ⚠️ AVEC LA MARGE, ET C'EST LE CAS OÙ ELLE COMPTE LE
                      // PLUS. La carte quitte l'application : elle circule dans
                      // une conversation, sans rien pour recouper. Un « ~487
                      // kcal » nu y passerait pour une mesure, et l'image ne se
                      // rattrape pas une fois partagée.
                      calories: _calories(r),
                    ),
                  ),
                ),
                icon: const Icon(Icons.ios_share, size: 18),
                label: const Text('Partager'),
              ),
            ),
          ] else if (r != null && r.statut == StatutCourse.invalide) ...<Widget>[
            const SizedBox(height: 12),
            Text(
              'Ce temps présente une anomalie et est en cours de vérification '
              "par l'organisation. Il ne s'affiche pas tant qu'il n'est pas "
              'confirmé.',
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ] else ...<Widget>[
            const SizedBox(height: 12),
            Text(
              'Chronométrage à venir.',
              style: theme.textTheme.bodyMedium
                  ?.copyWith(color: theme.colorScheme.outline),
            ),
          ],
        ],
      ),
    );
  }

  static Widget? _pastille(Resultat? r) => switch (r?.statut) {
        StatutCourse.invalide =>
          const Pastille('À vérifier', couleur: Colors.orange),
        StatutCourse.abandon => const Pastille('Abandon'),
        StatutCourse.nonPartant => const Pastille('Non partant'),
        StatutCourse.enCourse =>
          const Pastille('En course', couleur: Colors.green),
        StatutCourse.termine => const Pastille('Terminé', couleur: Colors.green),
        _ => null,
      };
}
