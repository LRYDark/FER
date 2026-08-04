import 'package:fer_shared/fer_shared.dart';
import 'package:flutter/material.dart';
import 'package:wear_plus/wear_plus.dart';

/// Compagnon de course sur Wear OS.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UN ÉCRAN, TROIS CHOSES : LE CHRONO, LE DOSSARD, LES DEUX BOUTONS.
///
/// Pas de navigation, pas d'onglets, aucun réglage. Ce qui se règle se règle
/// sur le téléphone ; ici on marche, et on touche un bouton large avec un doigt
/// qui tremble.
///
/// ⚠️ LE CHRONO AFFICHÉ N'EST PAS LE TEMPS OFFICIEL. Il est calculé depuis
/// l'heure de départ de l'édition, pour se situer. Le résultat vient du serveur
/// après arbitrage entre balise et GPS, et peut en différer de quelques
/// secondes. C'est écrit sous le compteur.
library;

class EcranMontre extends StatelessWidget {
  const EcranMontre({super.key});

  @override
  Widget build(BuildContext context) {
    // `AmbientMode` : la montre passe en veille pendant la course. Sans lui,
    // l'écran s'éteint franchement et on ne voit plus rien en levant le poignet.
    return AmbientMode(
      builder: (context, mode, child) => WatchShape(
        builder: (context, forme, child) => Scaffold(
          body: Padding(
            // Sur un cadran ROND, les coins sont hors écran : sans cette marge,
            // le premier et le dernier caractère de chaque ligne disparaissent.
            padding: EdgeInsets.all(forme == WearShape.round ? 22 : 10),
            child: _Contenu(ambiant: mode == WearMode.ambient),
          ),
        ),
      ),
    );
  }
}

class _Contenu extends StatelessWidget {
  const _Contenu({required this.ambiant});

  /// En mode ambiant, on n'affiche que le strict nécessaire : pas de couleur
  /// vive, pas d'animation, pas de bouton. C'est ce qui fait tenir la batterie.
  final bool ambiant;

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);

    if (session.etat != EtatSession.connecte) {
      return const _Message(
        icone: Icons.phonelink_ring,
        texte: 'Connectez-vous depuis votre téléphone.',
      );
    }

    final info = session.infoCourse;
    final inscription = session.inscriptionActive;

    if (!(info?.chronoPret ?? false)) {
      return const _Message(
        icone: Icons.timer_off_outlined,
        texte: "Le suivi n'est pas ouvert.",
      );
    }
    if (inscription == null) {
      return const _Message(
        icone: Icons.person_off_outlined,
        texte: "Aucune inscription pour l'édition en cours.",
      );
    }

    return AnimatedBuilder(
      animation: session.suivi,
      builder: (context, _) => _Course(
        suivi: session.suivi,
        inscription: inscription,
        session: session,
        ambiant: ambiant,
      ),
    );
  }
}

class _Course extends StatelessWidget {
  const _Course({
    required this.suivi,
    required this.inscription,
    required this.session,
    required this.ambiant,
  });

  final SuiviCourse suivi;
  final Inscription inscription;
  final Session session;
  final bool ambiant;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final actif = suivi.etat == EtatSuivi.actif;

    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: <Widget>[
        FittedBox(
          child: Text(
            formaterDuree(suivi.chronoCourant),
            style: theme.textTheme.headlineMedium?.copyWith(
              fontWeight: FontWeight.w600,
              // Chasse fixe : sans elle, le compteur change de largeur à chaque
              // seconde et l'œil suit un texte qui bouge.
              fontFeatures: chiffresFixes.fontFeatures,
              color: ambiant ? theme.colorScheme.outline : null,
            ),
          ),
        ),
        if (!ambiant) ...<Widget>[
          Text('indicatif',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.outline)),
          const SizedBox(height: 2),
          Text(inscription.inscriptionNo,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.primary)),
          const SizedBox(height: 8),

          if (!actif)
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => suivi.demarrer(
                  inscription: inscription,
                  edition: session.editionActive,
                  departEffectif: session.infoCourse?.departEffectif,
                ),
                child: const Text('Démarrer'),
              ),
            )
          else ...<Widget>[
            SizedBox(
              width: double.infinity,
              child: OutlinedButton(
                onPressed: suivi.aFranchi('depart')
                    ? null
                    : () => suivi.declarerPassage('depart'),
                child: const Text('Je pars'),
              ),
            ),
            const SizedBox(height: 6),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: suivi.aFranchi('arrivee')
                    ? null
                    : () => suivi.declarerPassage('arrivee'),
                child: const Text("J'arrive"),
              ),
            ),
          ],

          // File d'attente : voir « 12 en attente » pendant une coupure, c'est
          // comprendre que rien n'est perdu.
          if (suivi.pointsEnAttente + suivi.detectionsEnAttente > 0) ...<Widget>[
            const SizedBox(height: 6),
            Text(
              '${suivi.pointsEnAttente + suivi.detectionsEnAttente} en attente',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.tertiary),
            ),
          ],
        ],
      ],
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.icone, required this.texte});

  final IconData icone;
  final String texte;

  @override
  Widget build(BuildContext context) => Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          Icon(icone, color: Theme.of(context).colorScheme.outline),
          const SizedBox(height: 8),
          Text(texte,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall),
        ],
      );
}
