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

class EcranResultats extends StatelessWidget {
  const EcranResultats({super.key});

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
            _CarteEdition(inscription: entree.key, resultat: entree.value),
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

class _CarteEdition extends StatelessWidget {
  const _CarteEdition({required this.inscription, this.resultat});

  final Inscription inscription;
  final Resultat? resultat;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final r = resultat;

    return CarteFer(
      titre: 'Édition ${inscription.annee}',
      icone: Icons.emoji_events_outlined,
      action: _pastille(r),
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            '${inscription.nomComplet} · n° ${inscription.inscriptionNo}',
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
            const SizedBox(height: 4),
            Row(
              children: <Widget>[
                Icon(
                  r.methode.estApproche
                      ? Icons.satellite_alt_outlined
                      : Icons.sensors,
                  size: 15,
                  color: theme.colorScheme.outline,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    '${r.methode.libelle} — ${r.methode.precision}'
                    '${r.precisionS != null ? ' · ±${r.precisionS} s' : ''}',
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: theme.colorScheme.outline),
                  ),
                ),
              ],
            ),
            if (r.distanceM != null || r.denivelePositifM != null) ...<Widget>[
              const SizedBox(height: 8),
              Wrap(
                spacing: 14,
                children: <Widget>[
                  if (r.distanceM != null)
                    Text('${(r.distanceM! / 1000).toStringAsFixed(2)
                        .replaceAll('.', ',')} km',
                        style: theme.textTheme.bodySmall),
                  if (r.denivelePositifM != null)
                    Text('${r.denivelePositifM} m D+',
                        style: theme.textTheme.bodySmall),
                ],
              ),
            ],
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
