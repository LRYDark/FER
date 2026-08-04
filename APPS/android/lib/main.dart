import 'package:fer_rappels/chrono_vivant.dart';
import 'package:fer_rappels/push_firebase.dart';
import 'package:fer_rappels/rappel_local.dart';
import 'package:fer_shared/fer_shared.dart';
import 'package:flutter/material.dart';

/// Point d'entrée Android — téléphone et tablette.
///
/// Tout le contenu vient de `package:fer_shared`. Ce fichier ne fait que trois
/// choses : préparer les notifications locales, ouvrir la session, lancer
/// l'application.
///
/// ⚠️ L'ADRESSE DU SERVEUR EST UN REPLI, PAS UNE VÉRITÉ. Elle sert au premier
/// lancement ; ensuite c'est celle enregistrée sur l'appareil qui prime. C'est
/// ce qui permet de changer de domaine sans republier sur les magasins.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Une panne du greffon de notifications ne doit PAS empêcher l'application de
  // démarrer : sans rappel on peut encore consulter son dossard et courir, sans
  // application on ne peut rien.
  PoseurDeRappel poseur = const RappelIndisponible();
  AfficheurChrono chrono = const SansAffichageChrono();
  try {
    final rappels = await RappelLocal.creer();
    poseur = rappels;
    // Le chrono permanent réutilise le MÊME greffon : deux initialisations
    // écraseraient les canaux de notification l'une de l'autre.
    chrono = await ChronoVivant.creer(rappels.greffon);
  } catch (e) {
    debugPrint('[FER] notifications indisponibles : $e');
  }

  final session = await Session.ouvrir(
    urlParDefaut: 'https://jr.zerobug-57.fr/FER/api/mobile',
    poseur: poseur,
    affichageChrono: chrono,
  );

  // Notifications poussées. `null` si Firebase n'est pas configuré dans le
  // projet — l'application tourne quand même, sans sonnerie.
  final push = await PushFirebase.demarrer(session);
  push?.ecouterAuPremierPlan();

  runApp(AppFer(session: session));
}
