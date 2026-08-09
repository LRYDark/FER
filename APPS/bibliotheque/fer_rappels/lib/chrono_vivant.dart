/// Le chrono qui vit hors de l'application : notification permanente sur
/// Android, Live Activity et Dynamic Island sur iPhone.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// ⚠️ ON N'ENVOIE PAS LE TEMPS CHAQUE SECONDE. C'EST TOUTE L'ASTUCE.
///
/// La tentation est de rafraîchir l'affichage à chaque seconde depuis Dart.
/// C'est ce qui vide une batterie en deux heures, et iOS limite de toute façon
/// le nombre de mises à jour d'une Live Activity.
///
/// On donne donc au système l'INSTANT DE DÉPART, et c'est lui qui anime le
/// compteur, nativement, sans réveiller l'application :
///
///   • Android — `usesChronometer: true` avec `when` = l'instant de départ ;
///   • iOS     — `Text(timerInterval:)` dans la Live Activity.
///
/// Conséquence : le chrono continue de tourner juste, même application tuée par
/// le système, même téléphone en veille depuis une heure.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI UNE NOTIFICATION PERMANENTE SUR ANDROID
///
/// Ce n'est pas de la décoration : sans notification de premier plan, Android
/// suspend l'application dès que l'écran s'éteint, et le suivi GPS s'arrête au
/// milieu de la course sans prévenir. La notification est le contrat — on suit
/// votre position, et ça se voit.
library;

import 'package:fer_shared/fer_shared.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

class ChronoVivant implements AfficheurChrono {
  ChronoVivant(this._plugin);

  /// Identifiant réservé. Distinct des rappels (1001-1002) : une notification
  /// de course qui écraserait le rappel du départ serait un rappel perdu.
  static const int _id = 2001;

  static const _canal = AndroidNotificationChannel(
    'fer_chrono',
    'Course en cours',
    description: 'Chronomètre affiché pendant la course.',
    // Volontairement basse : elle doit rester visible en permanence sans
    // sonner ni vibrer à chaque mise à jour.
    importance: Importance.low,
  );

  /// Canal vers la couche Swift, côté iOS. Rien à faire sur Android.
  static const _versSwift = MethodChannel('fr.forbachenrose/liveactivity');

  final FlutterLocalNotificationsPlugin _plugin;

  static Future<ChronoVivant> creer(FlutterLocalNotificationsPlugin plugin) async {
    await plugin
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(_canal);
    return ChronoVivant(plugin);
  }

  /// Démarre l'affichage.
  ///
  /// [depart] est l'instant d'origine du chrono — le top réel s'il a été donné,
  /// l'heure prévue sinon.
  @override
  Future<void> demarrer({
    required DateTime depart,
    required String dossard,
  }) async {
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      await _demarrerIos(depart, dossard);
      return;
    }

    await _plugin.show(
      _id,
      'Forbach en Rose — dossard $dossard',
      'Course en cours',
      NotificationDetails(
        android: AndroidNotificationDetails(
          _canal.id,
          _canal.name,
          channelDescription: _canal.description,
          importance: Importance.low,
          priority: Priority.low,
          // Permanente et non balayable : elle disparaît à l'arrivée, pas avant.
          ongoing: true,
          autoCancel: false,
          // ⚠️ LES DEUX LIGNES QUI FONT TOUT. `when` fixe l'origine, et
          // `usesChronometer` demande au système d'animer le compteur lui-même.
          // Sans elles, il faudrait republier la notification chaque seconde.
          when: depart.millisecondsSinceEpoch,
          usesChronometer: true,
          showWhen: true,
          // Sur Android 14+, la catégorie « stopwatch » donne droit à
          // l'affichage privilégié sur l'écran de verrouillage.
          category: AndroidNotificationCategory.stopwatch,
        ),
      ),
    );
  }

  /// iOS : la Live Activity, gérée par une cible Xcode en SwiftUI.
  ///
  /// ⚠️ FLUTTER NE SAIT PAS FAIRE DE LIVE ACTIVITY. ActivityKit est une API
  /// Swift, utilisable seulement depuis une Widget Extension. Le Dart ne fait
  /// que passer l'ordre ; tout l'affichage est dans mac/ios-liveactivity/.
  Future<void> _demarrerIos(DateTime depart, String dossard) async {
    try {
      await _versSwift.invokeMethod<void>('demarrer', <String, dynamic>{
        // En secondes depuis l'époque : Swift reconstruit une Date, et
        // `Text(timerInterval:)` anime le compteur sans aucune mise à jour.
        'depart': depart.millisecondsSinceEpoch / 1000,
        'dossard': dossard,
      });
    } on PlatformException catch (e) {
      // La cible Live Activity n'est pas installée, ou iOS est trop ancien
      // (< 16.1). Le suivi continue : c'est l'affichage qui manque, pas la
      // mesure.
      debugPrint('[FER] Live Activity indisponible : ${e.message}');
    }
  }

  /// Arrête l'affichage. À appeler à l'arrivée ET à l'arrêt manuel du suivi :
  /// un chrono qui continue de tourner après la course est pire que pas de
  /// chrono du tout.
  @override
  Future<void> arreter() async {
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      try {
        await _versSwift.invokeMethod<void>('arreter');
      } on PlatformException catch (_) {
        // Rien à arrêter : sans conséquence.
      }
      return;
    }
    await _plugin.cancel(_id);
  }
}
