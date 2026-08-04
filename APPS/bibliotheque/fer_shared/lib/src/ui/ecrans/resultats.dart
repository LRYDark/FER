import 'package:flutter/material.dart';

import '../../api/api_erreur.dart';
import '../../course/mesures.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';
import 'partage.dart';

/// « Mes résultats » — les temps, édition par édition.
///
/// ⚠️ DEUX RÈGLES QUI NE SE NÉGOCIENT PAS, reprises du site :
///   • La MÉTHODE accompagne toujours le temps. Un temps extrapolé au GPS
///     affiché nu passerait pour une mesure à la seconde près.
///   • Un temps marqué « invalide » n'est PAS affiché comme un temps. Le
///     masquer sans rien dire laisserait croire à un oubli ; le publier ferait
///     passer une anomalie pour un résultat.
library;

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
        padding: const EdgeInsets.all(marge),
        children: <Widget>[
          for (final entree in avecResultat.entries) ...<Widget>[
            _CarteEdition(inscription: entree.key, resultat: entree.value),
            const SizedBox(height: marge),
          ],
          const _CarteConsentement(),
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

/// Consentement au suivi GPS.
///
/// ⚠️ LE RETRAIT VAUT POUR L'AVENIR : il n'efface pas les traces déjà
/// enregistrées. C'est dit noir sur blanc — laisser croire qu'un simple retrait
/// suffit à tout effacer serait une fausse déclaration.
class _CarteConsentement extends StatefulWidget {
  const _CarteConsentement();

  @override
  State<_CarteConsentement> createState() => _CarteConsentementState();
}

class _CarteConsentementState extends State<_CarteConsentement> {
  bool? _accord;
  bool _occupe = false;

  Future<void> _basculer(bool valeur) async {
    setState(() => _occupe = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final r = await PorteeSession.action(context).api.consentementGps(valeur);
      if (mounted) setState(() => _accord = r);
      messenger.showSnackBar(SnackBar(
        content: Text(r
            ? 'Suivi GPS autorisé. Vous pouvez le retirer à tout moment.'
            : 'Autorisation retirée. Aucune nouvelle trace ne sera enregistrée.'),
      ));
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final jours = PorteeSession.de(context).config?.tracesConservationJours ?? 0;

    return CarteFer(
      titre: 'Suivi GPS pendant la course',
      icone: Icons.place_outlined,
      action: _accord == null
          ? null
          : Pastille(_accord! ? 'autorisé' : 'non autorisé',
              couleur: _accord! ? Colors.green : null),
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            "Si vous l'autorisez, l'application enregistre votre position "
            'pendant la course. Sans votre accord, rien n\'est enregistré.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              Expanded(
                child: OutlinedButton(
                  onPressed: _occupe ? null : () => _basculer(false),
                  child: const Text('Retirer'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: FilledButton(
                  onPressed: _occupe ? null : () => _basculer(true),
                  child: const Text('Autoriser'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          // Le texte suit le RÉGLAGE : annoncer un effacement qui n'a pas lieu
          // (ou l'inverse) est exactement ce que ce projet s'interdit.
          Text(
            jours > 0
                ? 'Vos temps et vos résultats sont conservés : vous les '
                    'retrouverez ici chaque année. Seul le chemin suivi sur la '
                    'carte est effacé au bout de $jours jours.'
                : 'Tout est conservé d\'une année sur l\'autre : vos temps, vos '
                    'résultats et le chemin suivi sur la carte. Le retrait de '
                    "l'autorisation vaut pour l'avenir — il n'efface pas les "
                    'traces déjà enregistrées.',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline),
          ),
        ],
      ),
    );
  }
}
