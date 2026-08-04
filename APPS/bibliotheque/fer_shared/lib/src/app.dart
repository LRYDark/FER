import 'package:flutter/material.dart';

import 'session.dart';
import 'ui/ecrans/accueil.dart';
import 'ui/ecrans/bloquant.dart';
import 'ui/ecrans/connexion.dart';
import 'ui/portee.dart';
import 'ui/theme.dart';

/// Racine de l'application, commune aux trois coques.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// L'ÉTAT DÉCIDE DE L'ÉCRAN, PAS LE NAVIGATEUR.
///
/// Il n'y a aucun `Navigator.push` vers la connexion ni vers l'accueil : c'est
/// [EtatSession] qui choisit. Une déconnexion venue du serveur — appareil
/// révoqué depuis un autre téléphone — ramène donc à la connexion où qu'on soit,
/// sans qu'aucun écran ait à y penser. Et le bouton retour ne peut pas rouvrir
/// une session fermée.
library;

class AppFer extends StatelessWidget {
  const AppFer({required this.session, this.titre = 'Forbach en Rose', super.key});

  final Session session;
  final String titre;

  @override
  Widget build(BuildContext context) => PorteeSession(
        session: session,
        child: MaterialApp(
          title: titre,
          debugShowCheckedModeBanner: false,
          theme: themeFer(luminosite: Brightness.light),
          darkTheme: themeFer(luminosite: Brightness.dark),
          // Le thème suit le réglage du téléphone. Le site laisse ce choix au
          // coureur ; forcer le thème clair sur un appareil réglé en sombre
          // serait un pas en arrière.
          themeMode: ThemeMode.system,
          home: const _Routeur(),
        ),
      );
}

class _Routeur extends StatelessWidget {
  const _Routeur();

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);

    return switch (session.etat) {
      EtatSession.demarrage => const _Demarrage(),
      EtatSession.versionRefusee => const EcranVersionRefusee(),
      EtatSession.horsService => const EcranHorsService(),
      EtatSession.deconnecte => const EcranConnexion(),
      EtatSession.connecte => const EcranAccueil(),
    };
  }
}

class _Demarrage extends StatelessWidget {
  const _Demarrage();

  @override
  Widget build(BuildContext context) => Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Icon(Icons.favorite,
                  size: 56, color: Theme.of(context).colorScheme.primary),
              const SizedBox(height: 24),
              const CircularProgressIndicator(),
            ],
          ),
        ),
      );
}
