/// Réception des notifications poussées (Firebase Cloud Messaging).
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UN SEUL CHEMIN POUR LES DEUX PLATEFORMES — PAS LA SEULE VOIE POSSIBLE.
///
/// Aucune application ne peut se réveiller seule : il faut passer par le service
/// de notification du système. Sur ANDROID c'est FCM, incontournable en
/// pratique. Sur iPHONE c'est APNs, celui d'Apple, que Firebase se contente de
/// RELAYER — on pourrait s'y adresser directement.
///
/// ⚠️ Ne pas écrire que Firebase est « obligatoire pour les deux ». C'est faux
/// pour iOS, et c'est ce qui a été corrigé ici.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LE JETON SE RENOUVELLE TOUT SEUL
///
/// Google le change après une réinstallation, une restauration de sauvegarde, ou
/// simplement au bout d'un moment. D'où deux choses, et pas une :
///
///   • [demarrer] l'envoie au serveur à CHAQUE lancement ;
///   • `onTokenRefresh` l'envoie dès qu'il change, application ouverte.
///
/// ⚠️ SANS LA SECONDE, les notifications cesseraient d'arriver un jour, sans
/// que rien ne l'explique — et on le découvrirait le matin de la course.
library;

import 'package:fer_shared/fer_shared.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

/// Reçoit les messages quand l'application est fermée ou en arrière-plan.
///
/// ⚠️ DOIT ÊTRE UNE FONCTION DE PREMIER NIVEAU annotée `@pragma('vm:entry-point')` :
/// elle est exécutée dans un isolat séparé, sans accès à l'état de
/// l'application. Une méthode de classe ou une fermeture ne serait jamais
/// appelée — et le silence serait la seule trace du problème.
///
/// On n'y fait volontairement RIEN : le système affiche déjà la notification
/// tout seul à partir du bloc `notification` envoyé par le serveur.
@pragma('vm:entry-point')
Future<void> pushArrierePlan(RemoteMessage message) async {
  await Firebase.initializeApp();
  debugPrint('[FER] push en arrière-plan : ${message.messageId}');
}

class PushFirebase {
  PushFirebase._(this._messaging, this.session);

  final FirebaseMessaging _messaging;
  final Session session;

  /// Prépare Firebase et déclare le jeton de cet appareil.
  ///
  /// Renvoie `null` si Firebase n'est pas configuré dans le projet — l'absence
  /// de `google-services.json` ou de `GoogleService-Info.plist` fait échouer
  /// l'initialisation. L'application doit continuer à fonctionner : sans
  /// notifications on peut encore consulter son dossard et courir, sans
  /// application on ne peut rien.
  static Future<PushFirebase?> demarrer(Session session) async {
    try {
      await Firebase.initializeApp();
    } catch (e) {
      debugPrint('[FER] Firebase absent — notifications désactivées : $e');
      return null;
    }

    final messaging = FirebaseMessaging.instance;
    FirebaseMessaging.onBackgroundMessage(pushArrierePlan);

    final push = PushFirebase._(messaging, session);
    await push._autoriser();
    await push._declarer();

    // Le jeton change tout seul : on suit le changement plutôt que de le
    // découvrir en constatant que plus rien n'arrive.
    messaging.onTokenRefresh.listen((jeton) {
      debugPrint('[FER] jeton push renouvelé');
      session.declarerJetonPush(jeton);
    });

    return push;
  }

  Future<bool> _autoriser() async {
    final r = await _messaging.requestPermission(alert: true, sound: true);
    return r.authorizationStatus == AuthorizationStatus.authorized ||
        r.authorizationStatus == AuthorizationStatus.provisional;
  }

  Future<void> _declarer() async {
    try {
      final jeton = await _messaging.getToken();
      // ⚠️ On envoie `null` si le jeton manque, plutôt que de ne rien envoyer.
      // Le serveur retire alors l'ancien : sans ça, un appareil dont les
      // notifications ont été refusées resterait compté dans « envoyée à N
      // appareils », et le chiffre mentirait un peu plus chaque année.
      await session.declarerJetonPush(jeton);
    } catch (e) {
      debugPrint('[FER] jeton push indisponible : $e');
      await session.declarerJetonPush(null);
    }
  }

  /// Messages reçus application ouverte.
  ///
  /// iOS n'affiche rien de lui-même dans ce cas : c'est à l'application de
  /// montrer quelque chose. On se contente de recharger la boîte de réception —
  /// le message y apparaît, et la pastille de l'onglet le signale.
  void ecouterAuPremierPlan() {
    FirebaseMessaging.onMessage.listen((m) {
      debugPrint('[FER] push reçu au premier plan : ${m.notification?.title}');
      session.rafraichirNotifications();
    });

    // L'utilisateur a touché la notification : on recharge, pour qu'il trouve
    // le message ouvert et à jour plutôt qu'une liste d'hier.
    FirebaseMessaging.onMessageOpenedApp.listen((m) {
      session.rafraichirNotifications();
    });
  }
}
