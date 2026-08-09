/// Écrans qui arrêtent l'application, et pourquoi ils l'arrêtent vraiment.
library;

import 'package:flutter/material.dart';

import '../portee.dart';
import '../theme.dart';

/// Version refusée par le serveur (HTTP 426).
///
/// ═════════════════════════════════════════════════════════════════════════════
/// BLOQUANT, SANS ÉCHAPPATOIRE — ET C'EST LE BUT.
///
/// Le refus vient du SERVEUR, pas de la politesse du client : toutes les autres
/// requêtes échouent déjà en 426. Offrir un bouton « continuer quand même »
/// mènerait à une application qui affiche des écrans vides sans jamais dire
/// pourquoi. C'est aussi le seul moyen de mettre hors service une version
/// défectueuse : l'organisation relève le numéro minimal, et elle s'arrête
/// partout d'un coup.
class EcranVersionRefusee extends StatelessWidget {
  const EcranVersionRefusee({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final config = session.config;
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Icon(Icons.system_update,
                    size: 64, color: theme.colorScheme.primary),
                const SizedBox(height: 24),
                Text('Mise à jour nécessaire',
                    style: theme.textTheme.headlineSmall,
                    textAlign: TextAlign.center),
                const SizedBox(height: 12),
                Text(
                  session.erreur ??
                      "Cette version de l'application n'est plus acceptée par "
                          'le serveur. Mettez-la à jour pour continuer.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: theme.colorScheme.outline),
                ),
                if (config?.versionMinimale != null) ...<Widget>[
                  const SizedBox(height: 8),
                  Text('Version minimale : ${config!.versionMinimale}',
                      style: theme.textTheme.bodySmall),
                ],
                const SizedBox(height: 28),
                // Le lien du magasin est servi par /app/config, qui reste
                // joignable même pour une version refusée. C'est exactement à
                // ça qu'il sert : dire comment cesser d'être périmé.
                Text(
                  'Rendez-vous sur votre magasin d\'applications pour installer '
                  'la dernière version.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: session.rafraichirConfig,
                  icon: const Icon(Icons.refresh),
                  label: const Text('J\'ai mis à jour, réessayer'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// API mobile fermée par l'administration, ou migration non jouée.
///
/// Réessayable : contrairement à une version périmée, il n'y a rien à faire
/// côté coureur — l'organisation rouvrira. On le dit ainsi, plutôt que
/// d'afficher une erreur qui donnerait l'impression que le téléphone est en
/// cause.
class EcranHorsService extends StatelessWidget {
  const EcranHorsService({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);

    return Scaffold(
      body: SafeArea(
        child: RienAAfficher(
          icone: Icons.cloud_off_outlined,
          titre: 'Service momentanément indisponible',
          explication:
              "L'application est fermée par l'organisation, ou le serveur est "
              'en cours de mise à jour. Vos inscriptions et vos temps ne sont '
              'pas affectés — réessayez dans un moment.',
          action: FilledButton.icon(
            onPressed: session.rafraichirConfig,
            icon: const Icon(Icons.refresh),
            label: const Text('Réessayer'),
          ),
        ),
      ),
    );
  }
}
