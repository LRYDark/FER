import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api/api_client.dart';
import 'api/api_erreur.dart';
import 'api/jetons.dart';
import 'course/file_attente.dart';
import 'course/suivi_course.dart';
import 'models/course_app.dart';
import 'models/modeles.dart';
import 'reveil.dart';

/// État global de l'application : session, données du compte, suivi de course.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UN SEUL OBJET, ET C'EST DÉLIBÉRÉ.
///
/// L'application a six écrans et un seul utilisateur connecté à la fois. Une
/// architecture à conteneurs d'injection et à dépôts par entité coûterait plus
/// de fichiers à lire qu'elle n'éviterait de bugs. Un `ChangeNotifier` unique,
/// transmis par un `InheritedNotifier`, suffit et se relit d'un trait.
///
/// ⚠️ CE QUI NE DOIT JAMAIS ÊTRE FAIT ICI : recalculer un temps, deviner un
/// statut, ou décider qu'une donnée absente vaut zéro. Le serveur est la source
/// de vérité ; cet objet la transporte, il ne l'invente pas.
library;

enum EtatSession {
  /// Au lancement : on ne sait pas encore si un jeton d'appareil existe.
  demarrage,

  /// Aucun jeton : écran de connexion.
  deconnecte,

  /// Jeton présent et accepté.
  connecte,

  /// Le serveur refuse cette version. ÉCRAN BLOQUANT, aucune autre action
  /// possible : le serveur rejette déjà tout le reste.
  versionRefusee,

  /// API mobile fermée par l'administration, ou migration non jouée.
  horsService,
}

class Session extends ChangeNotifier {
  Session._(this._api, this.file, this.suivi, this.reveil);

  /// Construit la session : lit la version du binaire, restaure l'adresse du
  /// serveur et les jetons, puis interroge /app/config.
  ///
  /// [poseur] est fourni par la coque de plateforme : c'est elle qui sait poser
  /// une notification locale. Sans lui, le réveil s'annonce indisponible plutôt
  /// que de prétendre l'avoir programmé.
  static Future<Session> ouvrir({
    String? urlParDefaut,
    PoseurDeRappel poseur = const RappelIndisponible(),
    AfficheurChrono affichageChrono = const SansAffichageChrono(),
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final info = await PackageInfo.fromPlatform();

    final url = prefs.getString(_cleUrl) ??
        urlParDefaut ??
        'https://jr.zerobug-57.fr/FER/api/v1';

    final jetons = Jetons();
    final api = ApiClient(
      baseUrl: url,
      // ⚠️ Lue du binaire. Une constante écrite à la main finirait par mentir
      // au serveur — et c'est justement ce numéro qui permet de mettre hors
      // service une version défectueuse.
      version: info.version,
      jetons: jetons,
    );

    final file = FileAttente(api);
    final s = Session._(
      api,
      file,
      SuiviCourse(file: file, affichage: affichageChrono),
      Reveil(poseur: poseur),
    );

    api.surDeconnexion = s._surDeconnexion;
    api.surVersionRefusee = s._surVersionRefusee;

    await file.charger();
    await s._chargerEtatsMessages();
    await s._demarrer();
    return s;
  }

  static const _cleUrl = 'fer_url_api';

  final ApiClient _api;
  final FileAttente file;
  final SuiviCourse suivi;
  final Reveil reveil;

  ApiClient get api => _api;

  EtatSession _etat = EtatSession.demarrage;
  ConfigApp? _config;
  Profil? _profil;
  List<Inscription> _inscriptions = const <Inscription>[];
  List<Resultat> _resultats = const <Resultat>[];
  List<Edition> _editions = const <Edition>[];
  InfoCourse? _infoCourse;
  List<NotificationCourse> _notifications = const <NotificationCourse>[];
  String? _erreur;
  bool _chargement = false;
  StreamSubscription<List<ConnectivityResult>>? _reseau;

  EtatSession get etat => _etat;
  ConfigApp? get config => _config;
  Profil? get profil => _profil;
  List<Inscription> get inscriptions => _inscriptions;
  List<Resultat> get resultats => _resultats;
  List<Edition> get editions => _editions;

  /// Informations pratiques de l'édition : date, heure, lieu, horaires.
  /// Disponibles SANS connexion — elles figurent déjà sur l'affiche.
  InfoCourse? get infoCourse => _infoCourse;

  List<NotificationCourse> get notifications => _notifications;

  /// Les épinglées d'abord : ce sont elles qu'on vient relire.
  List<NotificationCourse> get notificationsEpinglees =>
      _notifications.where((n) => n.epingle).toList();

  String? get erreur => _erreur;
  bool get chargement => _chargement;
  String get urlApi => _api.baseUrl;

  /// Le chronométrage est-il ouvert sur ce site ?
  ///
  /// ⚠️ `false` TANT QUE LA CONFIGURATION N'EST PAS ARRIVÉE. Afficher les écrans
  /// de course avant de savoir reviendrait à les faire clignoter à chaque
  /// lancement, et à proposer un suivi GPS que le serveur refusera.
  bool get chronoOuvert => _config?.chronoActif ?? false;

  /// Édition en cours, celle que le suivi de course utilise.
  Edition? get editionActive {
    for (final e in _editions) {
      if (e.active) return e;
    }
    return _editions.isEmpty ? null : _editions.first;
  }

  /// Inscription du coureur pour l'édition en cours, s'il y en a une.
  Inscription? get inscriptionActive {
    final e = editionActive;
    if (e == null) return null;
    for (final i in _inscriptions) {
      if (i.annee == e.annee) return i;
    }
    return null;
  }

  Resultat? resultatDe(Inscription i) {
    for (final r in _resultats) {
      if (r.annee == i.annee && r.inscriptionNo == i.inscriptionNo) return r;
    }
    return null;
  }

  /* ═══════════════════════════ Démarrage ═══════════════════════════════ */

  Future<void> _demarrer() async {
    await rafraichirConfig();
    if (_etat == EtatSession.versionRefusee ||
        _etat == EtatSession.horsService) {
      return;
    }

    _etat = await _api.jetons.connecte()
        ? EtatSession.connecte
        : EtatSession.deconnecte;
    notifyListeners();

    if (_etat == EtatSession.connecte) await rafraichir();

    // Le retour du réseau vide la file. C'est le mécanisme qui rattrape une
    // course entière passée hors couverture : dès que ça repasse, tout part.
    _reseau = Connectivity().onConnectivityChanged.listen((etats) {
      final enLigne = etats.any((e) => e != ConnectivityResult.none);
      if (enLigne) unawaited(file.vidange());
    });
  }

  /// Interroge /app/config. Sans jeton : c'est justement ce qui permet à une
  /// application refusée d'apprendre qu'elle doit se mettre à jour.
  Future<void> rafraichirConfig() async {
    try {
      _config = ConfigApp.depuisJson(await _api.config());

      // Les infos de course voyagent avec la configuration : les deux sont
      // publiques et l'application en a besoin dès le premier écran, avant même
      // qu'on se connecte.
      try {
        _infoCourse = InfoCourse.depuisJson(await _api.course());
      } on ApiErreur {
        // Serveur plus ancien que l'onglet Course : pas d'infos, pas d'erreur.
        // L'application affiche simplement moins de choses.
      }

      /* Le départ a pu être donné pendant qu'on marchait : on recale le
         compteur sans interrompre le suivi. Sans cette ligne, quelqu'un parti
         avec le compteur sur l'heure prévue garderait cinq minutes de trop
         affichées jusqu'à l'arrivée. */
      suivi.recalerDepart(_infoCourse?.departEffectif);

      // ⚠️ REPOSÉ À CHAQUE FOIS, et non « seulement s'il n'existe pas ». Un
      // redémarrage du téléphone efface les alarmes programmées sur plusieurs
      // versions d'Android : sans cette repose systématique, le rappel
      // disparaîtrait sans que personne ne s'en aperçoive avant le jour J.
      await reveil.reprogrammer(
        course: _infoCourse,
        minutesAvant: _config?.reveilAvantMin ?? 0,
      );

      if (_etat == EtatSession.horsService) {
        _etat = await _api.jetons.connecte()
            ? EtatSession.connecte
            : EtatSession.deconnecte;
      }
      _erreur = null;
    } on ApiErreur catch (e) {
      if (e.estTropAncienne) {
        _etat = EtatSession.versionRefusee;
      } else if (e.estHorsService) {
        _etat = EtatSession.horsService;
      } else if (!e.estReseau) {
        _erreur = e.message;
      }
      // Réseau coupé au lancement : on garde l'état précédent et on réessaiera.
      // Basculer en « hors service » ferait passer une coupure de wifi pour une
      // fermeture décidée par l'organisation.
    }
    notifyListeners();
  }

  /* ═══════════════════════════ Connexion ═══════════════════════════════ */

  /// Demande l'envoi du code à 6 chiffres.
  ///
  /// ⚠️ NE JAMAIS afficher « adresse inconnue » à partir de cette réponse : le
  /// serveur répond la même chose que l'adresse soit inscrite ou non, pour ne
  /// pas révéler qui participe. Rejouer la distinction ici réintroduirait la
  /// fuite que le serveur prend soin d'éviter.
  Future<String> demanderCode(String email) async {
    final d = await _api.demanderCode(email.trim());
    return (d['message'] as String?) ??
        "Si un compte correspond à cette adresse, un code vient d'être envoyé.";
  }

  Future<void> verifierCode(String email, String code) async {
    await _api.verifierCode(
      email: email.trim(),
      code: code.replaceAll(RegExp(r'\D'), ''),
      infosAppareil: await _infosAppareil(),
    );
    _etat = EtatSession.connecte;
    notifyListeners();
    await rafraichir();
  }

  /// Libellé et modèle envoyés au serveur. Sans eux, la liste « Mes appareils »
  /// n'affiche que des numéros — et on ne peut pas révoquer un téléphone perdu
  /// si on ne sait pas lequel c'est.
  Future<Map<String, String>> _infosAppareil() async {
    final plugin = DeviceInfoPlugin();
    try {
      if (defaultTargetPlatform == TargetPlatform.android) {
        final a = await plugin.androidInfo;
        return <String, String>{
          'libelle': '${a.manufacturer} ${a.model}',
          'plateforme': 'Android ${a.version.release}',
          'modele': a.model,
        };
      }
      if (defaultTargetPlatform == TargetPlatform.iOS) {
        final i = await plugin.iosInfo;
        return <String, String>{
          'libelle': i.name,
          'plateforme': '${i.systemName} ${i.systemVersion}',
          'modele': i.utsname.machine,
        };
      }
    } catch (_) {
      // Un plugin qui échoue ne doit pas empêcher de se connecter.
    }
    return <String, String>{'libelle': 'Application mobile'};
  }

  Future<void> deconnexion() async {
    await suivi.arreter();
    await _api.deconnexion();
    // ⚠️ La file est purgée : les données de course d'un compte ne doivent pas
    // repartir sous l'identité de la personne qui se connectera ensuite.
    await file.purger();
    _profil = null;
    _inscriptions = const <Inscription>[];
    _resultats = const <Resultat>[];
    _etat = EtatSession.deconnecte;
    notifyListeners();
  }

  void _surDeconnexion() {
    if (_etat == EtatSession.deconnecte) return;
    _etat = EtatSession.deconnecte;
    _profil = null;
    _inscriptions = const <Inscription>[];
    _resultats = const <Resultat>[];
    notifyListeners();
  }

  void _surVersionRefusee(ApiErreur e) {
    _etat = EtatSession.versionRefusee;
    _erreur = e.message;
    notifyListeners();
  }

  /* ═══════════════════════════ Données ═════════════════════════════════ */

  /// Recharge tout ce qui s'affiche. Les appels sont lancés ENSEMBLE : en
  /// série, l'écran resterait vide le temps de quatre allers-retours.
  Future<void> rafraichir() async {
    if (_etat != EtatSession.connecte || _chargement) return;
    _chargement = true;
    _erreur = null;
    notifyListeners();

    try {
      final resultats = await Future.wait<Object?>(<Future<Object?>>[
        _api.profil(),
        _api.inscriptions(),
        _api.editions(),
        // Les résultats sont fermés quand le chronométrage l'est : on ne les
        // demande pas, plutôt que d'essuyer un 403 attendu à chaque reprise.
        if (chronoOuvert) _api.resultats() else Future<Object?>.value(null),
      ]);

      _profil = Profil.depuisJson(resultats[0]! as Map<String, dynamic>);
      _inscriptions = (resultats[1]! as List<Map<String, dynamic>>)
          .map(Inscription.depuisJson)
          .toList();
      _editions = (resultats[2]! as List<Map<String, dynamic>>)
          .map(Edition.depuisJson)
          .toList();
      _resultats = resultats[3] == null
          ? const <Resultat>[]
          : (resultats[3]! as List<Map<String, dynamic>>)
              .map(Resultat.depuisJson)
              .toList();

      await rafraichirNotifications();
    } on ApiErreur catch (e) {
      // Le chronométrage a pu se fermer entre-temps : ce n'est pas une panne,
      // on relit simplement la configuration et on masque les écrans.
      if (e.estChronoFerme) {
        await rafraichirConfig();
      } else if (!e.estDeconnecte) {
        _erreur = e.message;
      }
    } finally {
      _chargement = false;
      notifyListeners();
    }
  }

  /// Recharge les messages de l'organisation.
  ///
  /// Séparé de [rafraichir] : on l'appelle aussi au retour au premier plan et
  /// après un rappel, moments où recharger tout le reste serait inutile.
  Future<void> rafraichirNotifications() async {
    if (_etat != EtatSession.connecte) return;
    if (!(_config?.notificationsActives ?? false)) {
      _notifications = const <NotificationCourse>[];
      return;
    }
    try {
      _notifications = (await _api.notifications())
          .map(NotificationCourse.depuisJson)
          .toList();
      // ⚠️ ON NE POSE PLUS DE NOTIFICATION LOCALE ICI. Les téléphones sont
      // maintenant prévenus par un vrai push, envoyé par le serveur au moment
      // où l'organisation appuie sur « Envoyer ». En reposer une à la lecture
      // ferait sonner une seconde fois pour le même message.
      notifyListeners();
    } on ApiErreur {
      // Une notification manquante n'est pas une panne : on garde ce qu'on a.
      // Vider la liste sur une coupure de réseau effacerait de l'écran une
      // consigne que le coureur était en train de lire.
    }
  }

  /* ═══════════════════════ Messages : lu / annoncé ═════════════════════ */

  Set<int> _lus = <int>{};

  static const _cleLus = 'fer_messages_lus';

  /// Ce message a-t-il déjà été ouvert ? Sert au gras de la liste, comme dans
  /// une boîte mail.
  bool messageLu(int id) => _lus.contains(id);

  /// Nombre de messages non lus — la pastille de l'onglet.
  int get messagesNonLus =>
      _notifications.where((n) => !_lus.contains(n.id)).length;

  Future<void> marquerLu(int id) async {
    if (_lus.contains(id)) return;
    _lus.add(id);
    notifyListeners();
    final p = await SharedPreferences.getInstance();
    await p.setStringList(
        _cleLus, _lus.map((e) => e.toString()).toList());
  }

  Future<void> _chargerEtatsMessages() async {
    final p = await SharedPreferences.getInstance();
    _lus = (p.getStringList(_cleLus) ?? const <String>[])
        .map(int.tryParse)
        .whereType<int>()
        .toSet();
  }

  /* ════════════════════ Jeton de notification poussée ═══════════════════ */

  /// Déclare au serveur le jeton Firebase de cet appareil.
  ///
  /// ⚠️ À APPELER À CHAQUE LANCEMENT, pas seulement à la connexion. Google
  /// renouvelle ce jeton tout seul — après une réinstallation, une restauration
  /// de sauvegarde, ou simplement au bout d'un moment. Ne l'envoyer qu'une fois
  /// garantit qu'un jour les notifications cessent d'arriver sans explication.
  ///
  /// Passer `null` retire le jeton : c'est ce qu'on fait quand les notifications
  /// sont refusées sur l'appareil. Mieux vaut ne rien envoyer que d'envoyer dans
  /// le vide et gonfler les compteurs d'échec de l'administration.
  Future<void> declarerJetonPush(String? token) async {
    if (_etat != EtatSession.connecte) return;
    try {
      await _api.declarerJetonPush(token);
    } on ApiErreur {
      // Sans conséquence : le jeton repartira au prochain lancement.
    }
  }

  /// Recharge uniquement les résultats — c'est ce qu'on veut après une arrivée,
  /// sans repayer le coût de tout le reste.
  Future<void> rafraichirResultats() async {
    if (!chronoOuvert || _etat != EtatSession.connecte) return;
    try {
      _resultats =
          (await _api.resultats()).map(Resultat.depuisJson).toList();
      notifyListeners();
    } on ApiErreur catch (e) {
      if (e.estChronoFerme) await rafraichirConfig();
    }
  }

  /* ═══════════════════════════ Réglages ════════════════════════════════ */

  /// Change l'adresse du serveur. Utile en test, et indispensable si
  /// l'association change de domaine : sans ce réglage, il faudrait republier
  /// l'application sur les deux magasins pour un simple changement d'URL.
  ///
  /// La session est refermée : les jetons de l'ancien serveur ne valent rien
  /// sur le nouveau, les garder ne produirait que des 401 incompréhensibles.
  Future<void> changerUrlApi(String url) async {
    final propre = url.trim().replaceAll(RegExp(r'/+$'), '');
    if (propre.isEmpty || propre == _api.baseUrl) return;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_cleUrl, propre);
    await deconnexion();
  }

  @override
  void dispose() {
    unawaited(_reseau?.cancel());
    suivi.dispose();
    _api.fermer();
    super.dispose();
  }
}
