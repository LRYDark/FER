import 'package:fer_shared/fer_shared.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/data/latest.dart' as tzdata;
import 'package:timezone/timezone.dart' as tz;

/// Implémentation Android et iOS de [PoseurDeRappel].
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UNE NOTIFICATION, PAS UN DÉMARRAGE.
///
/// Ni Android ni iOS n'autorisent une application à se lancer seule à une heure
/// donnée. Ce que l'on programme, c'est une notification locale : le coureur la
/// voit, la touche, l'application s'ouvre. Le fichier partagé
/// (`src/reveil.dart`) dit la même chose — il ne faut pas que quiconque compte
/// sur un démarrage automatique le jour de la course.
///
/// ⚠️ LE FUSEAU EST INDISPENSABLE. `zonedSchedule` exige une date située dans un
/// fuseau connu. Avec un `DateTime` local nu, le rappel part à côté — de une à
/// deux heures selon la saison, exactement le genre d'écart qu'on ne remarque
/// qu'au réveil raté.
library;

class RappelLocal implements PoseurDeRappel {
  RappelLocal._(this._plugin);

  static const _canal = AndroidNotificationChannel(
    'fer_course',
    'Rappels de course',
    description: 'Rappel avant le départ de Forbach en Rose.',
    importance: Importance.high,
  );

  final FlutterLocalNotificationsPlugin _plugin;

  /// Le greffon, pour que la coque puisse aussi construire le chrono vivant
  /// sans réinitialiser une seconde instance.
  FlutterLocalNotificationsPlugin get greffon => _plugin;

  static Future<RappelLocal> creer() async {
    tzdata.initializeTimeZones();
    // Le fuseau de l'appareil n'est pas exposé par `timezone` : on le fixe sur
    // celui de la course. C'est le bon choix ici — le rappel doit tomber à
    // l'heure LOCALE DE FORBACH, y compris pour quelqu'un qui voyage.
    tz.setLocalLocation(tz.getLocation('Europe/Paris'));

    final plugin = FlutterLocalNotificationsPlugin();
    await plugin.initialize(
      const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        iOS: DarwinInitializationSettings(
          // Les autorisations sont demandées explicitement plus tard, au moment
          // où l'on peut expliquer pourquoi — pas au premier lancement, devant
          // un écran vide.
          requestAlertPermission: false,
          requestBadgePermission: false,
          requestSoundPermission: false,
        ),
      ),
    );

    await plugin
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(_canal);

    return RappelLocal._(plugin);
  }

  @override
  Future<bool> autoriser() async {
    try {
      final android = _plugin.resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin>();
      if (android != null) {
        final notifs = await android.requestNotificationsPermission() ?? false;
        // ⚠️ DEUX AUTORISATIONS DISTINCTES sur Android 12+. « Notifications »
        // ne suffit pas : sans « alarmes exactes », le système peut décaler le
        // rappel de plusieurs dizaines de minutes pour économiser la batterie.
        // Pour un départ de course, c'est un rappel qui arrive après le départ.
        final exactes = await android.requestExactAlarmsPermission() ?? false;
        return notifs && exactes;
      }

      final ios = _plugin.resolvePlatformSpecificImplementation<
          IOSFlutterLocalNotificationsPlugin>();
      return await ios?.requestPermissions(alert: true, sound: true) ?? false;
    } catch (e) {
      debugPrint('[FER] autorisation notifications : $e');
      return false;
    }
  }

  @override
  Future<void> poser({
    required int id,
    required DateTime quand,
    required String titre,
    required String message,
  }) async {
    await _plugin.zonedSchedule(
      id,
      titre,
      message,
      tz.TZDateTime.from(quand, tz.local),
      NotificationDetails(
        android: AndroidNotificationDetails(
          _canal.id,
          _canal.name,
          channelDescription: _canal.description,
          importance: Importance.high,
          priority: Priority.high,
        ),
        iOS: const DarwinNotificationDetails(),
      ),
      // `exactAllowWhileIdle` : le rappel doit sonner même si le téléphone dort
      // depuis des heures — ce qui est précisément le cas à 8 h du matin.
      androidScheduleMode: AndroidScheduleMode.exactAllowWhileIdle,
      uiLocalNotificationDateInterpretation:
          UILocalNotificationDateInterpretation.absoluteTime,
    );
  }

  @override
  Future<void> annuler(int id) => _plugin.cancel(id);
}
