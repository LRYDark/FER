import 'package:fer_rappels/chrono_vivant.dart';
import 'package:fer_rappels/push_firebase.dart';
import 'package:fer_rappels/rappel_local.dart';
import 'package:fer_shared/fer_shared.dart';
import 'package:flutter/material.dart';

/// Point d'entrée iPhone et iPad.
///
/// Le rappel avant la course vient de `fer_rappels`, partagé avec la coque
/// Android : `flutter_local_notifications` couvre les deux plateformes avec la
/// même API, et deux copies du même code finiraient par diverger.
///
/// ⚠️ L'application Apple Watch n'est PAS ici. Flutter ne compile pas pour
/// watchOS ; ses sources SwiftUI sont dans `../watchos`, à ajouter comme cible
/// dans Xcode.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  PoseurDeRappel poseur = const RappelIndisponible();
  AfficheurChrono chrono = const SansAffichageChrono();
  try {
    final rappels = await RappelLocal.creer();
    poseur = rappels;
    // Sur iPhone, ChronoVivant passe la main à ActivityKit via un canal de
    // plateforme : la Live Activity est une cible Xcode en SwiftUI, Flutter ne
    // sait pas la produire. Voir ../ios-liveactivity/.
    chrono = await ChronoVivant.creer(rappels.greffon);
  } catch (e) {
    debugPrint('[FER] notifications indisponibles : $e');
  }

  final session = await Session.ouvrir(
    urlParDefaut: 'https://jr.zerobug-57.fr/FER/api/mobile',
    poseur: poseur,
    affichageChrono: chrono,
  );

  // ⚠️ Sur iOS, le push exige un compte Apple Developer payant, une clé APNs
  // déposée dans Firebase, et un appareil RÉEL — jamais le simulateur.
  final push = await PushFirebase.demarrer(session);
  push?.ecouterAuPremierPlan();

  runApp(AppFer(session: session));
}
