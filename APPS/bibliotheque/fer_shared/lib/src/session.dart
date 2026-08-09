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

import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api/api_client.dart';
import 'api/api_erreur.dart';
import 'api/jetons.dart';
import 'course/file_attente.dart';
import 'course/mesures.dart';
import 'course/suivi_course.dart';
import 'models/course_app.dart';
import 'models/modeles.dart';
import 'pont_montre.dart';
import 'reveil.dart';

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
        'https://jr.zerobug-57.fr/FER/api/mobile';

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

    // Lu AVANT `_demarrer()` : c'est ce drapeau qui décide si le tout premier
    // écran est la présentation ou la connexion.
    s._bienvenueVue = prefs.getBool(_cleBienvenue) ?? false;

    await file.charger();
    await s._chargerEtatsMessages();
    await s._demarrer();

    // ⚠️ À CHAQUE OUVERTURE, PAS SEULEMENT À LA CONNEXION. Une montre appairée
    // des mois après coup n'aurait jamais vu passer le jeton, et resterait sur
    // « Ouvrez l'application sur votre iPhone » sans qu'on comprenne pourquoi.
    // `updateApplicationContext` ne transmet que si la valeur a changé : le
    // répéter ne coûte rien.
    await synchroniserMontre(
      jeton: await jetons.appareil(),
      profil: await ProfilPhysique.charger(),
    );

    return s;
  }

  static const _cleUrl = 'fer_url_api';

  /// Présentation du premier lancement déjà vue.
  ///
  /// ⚠️ Ce drapeau ne mémorise PAS les autorisations — c'est le système qui les
  /// détient, et lui seul. Il ne dit qu'une chose : « l'explication a déjà été
  /// montrée ». Sans lui, la présentation reviendrait à chaque ouverture ;
  /// avec lui, iOS et Android restent seuls maîtres du reste. Refuser une
  /// autorisation ici n'enferme donc personne : elle se redemande depuis les
  /// Réglages du téléphone, et l'application continue de fonctionner sans.
  static const _cleBienvenue = 'fer_bienvenue_vue';

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
  bool _bienvenueVue = false;
  StreamSubscription<List<ConnectivityResult>>? _reseau;

  EtatSession get etat => _etat;

  /// La présentation du premier lancement a-t-elle déjà été montrée ?
  bool get bienvenueVue => _bienvenueVue;

  /// Demande les autorisations, puis retient que la présentation est passée.
  ///
  /// ⚠️ ON RETIENT MÊME SI TOUT EST REFUSÉ. Redemander à chaque ouverture
  /// serait sans effet — iOS ne repose la question qu'une fois, et Android
  /// finit par la bloquer — et transformerait un refus en harcèlement. Le
  /// coureur qui change d'avis passe par les Réglages du téléphone ; l'écran
  /// « Ma course » le lui rappelle au moment où ça compte vraiment.
  ///
  /// Les autorisations sont demandées L'UNE APRÈS L'AUTRE et jamais en
  /// parallèle : iOS n'affiche qu'une boîte de dialogue à la fois, et deux
  /// demandes simultanées en font disparaître une sans que personne ne l'ait vue.
  Future<void> terminerBienvenue({
    bool position = true,
    bool notifications = true,
  }) async {
    if (notifications) {
      try {
        await reveil.demanderAutorisation();
      } catch (e) {
        debugPrint('[FER] autorisation notifications indisponible : $e');
      }
    }
    if (position) {
      try {
        var p = await Geolocator.checkPermission();
        if (p == LocationPermission.denied) {
          p = await Geolocator.requestPermission();
        }
      } catch (e) {
        debugPrint('[FER] autorisation position indisponible : $e');
      }
    }

    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_cleBienvenue, true);
    _bienvenueVue = true;
    notifyListeners();
  }
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
    // La montre n'a ni clavier ni accès au poids : c'est le téléphone qui
    // lui donne les deux. Rien ne dépend du résultat — sans montre
    // appairée, l'appel ne fait rien du tout.
    await synchroniserMontre(
      jeton: await _api.jetons.appareil(),
      profil: await ProfilPhysique.charger(),
    );
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
    // ⚠️ AVANT `_api.deconnexion()`, qui efface le jeton : après, il n'y aurait
    // plus rien à retirer, et la montre garderait le sien. Elle continuerait
    // d'envoyer des passages de ligne sous une identité qu'on croit fermée.
    await synchroniserMontre();
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

  /// Recharge tout ce qui s'affiche.
  ///
  /// ═══════════════════════════════════════════════════════════════════════
  /// LES QUATRE SOURCES SONT INDÉPENDANTES, ET LE RESTENT.
  ///
  /// Elles partent ENSEMBLE — en série, l'écran resterait vide le temps de
  /// quatre allers-retours. Mais chacune est reçue SÉPARÉMENT.
  ///
  /// ⚠️ C'EST LA CORRECTION D'UN BUG QUI RENDAIT L'APPLICATION MUETTE.
  /// Avant, un unique `Future.wait` recevait les quatre réponses puis les
  /// affectait à la suite. Une seule d'entre elles en défaut — un champ dans
  /// un type inattendu suffit — et `Future.wait` levait AVANT la première
  /// affectation : profil, inscriptions, éditions et résultats restaient tous
  /// les quatre vides. Le coureur voyait « Mon compte : — » sans même son
  /// adresse email, et « aucune inscription rattachée » alors qu'il en avait
  /// une. Deux écrans faux, une seule cause, et aucun message.
  ///
  /// Un écran vide se lit « vous n'avez rien ». Il doit donc être VRAI. Une
  /// panne se dit, elle ne se déguise pas en absence de données : les deux
  /// appellent des gestes opposés — l'un fait vérifier son adresse
  /// d'inscription, l'autre fait réessayer.
  /// ═══════════════════════════════════════════════════════════════════════
  Future<void> rafraichir() async {
    if (_etat != EtatSession.connecte || _chargement) return;
    _chargement = true;
    _erreur = null;
    notifyListeners();

    // Les quatre requêtes sont lancées ici, donc en parallèle. Ce qui suit
    // n'attend que la réponse déjà en vol.
    final fProfil = _api.profil();
    final fInscriptions = _api.inscriptions();
    final fEditions = _api.editions();
    // Les résultats sont fermés quand le chronométrage l'est : on ne les
    // demande pas, plutôt que d'essuyer un 403 attendu à chaque reprise.
    final fResultats = chronoOuvert ? _api.resultats() : null;

    final echecs = <String>[];

    /// Reçoit une source, et n'abandonne QU'ELLE si elle échoue.
    Future<void> lire(String quoi, Future<void> Function() action) async {
      try {
        await action();
      } on ApiErreur catch (e) {
        // Ces trois-là ne concernent pas une source en particulier : elles
        // disent que la session ou le service a changé d'état. On les laisse
        // remonter au bloc du dessous, qui sait quoi en faire.
        if (e.estDeconnecte || e.estChronoFerme || e.estTropAncienne) rethrow;
        // Le message du serveur est écrit POUR le coureur, en français : on le
        // reprend tel quel plutôt que d'inventer une formule générique qui
        // perdrait la seule information utile.
        echecs.add('$quoi — ${e.message}');
        debugPrint('[FER] $quoi : ${e.statut} ${e.code} ${e.message}');
      } catch (e, pile) {
        // Défaut de l'application, pas du serveur : le coureur ne peut rien en
        // faire, mais il doit savoir que c'est une panne et non un compte vide.
        echecs.add('$quoi — donnée illisible');
        debugPrint('[FER] $quoi a échoué : $e\n$pile');
      }
    }

    try {
      await lire('profil', () async {
        _profil = Profil.depuisJson(await fProfil);
      });
      await lire('inscriptions', () async {
        _inscriptions =
            (await fInscriptions).map(Inscription.depuisJson).toList();
      });
      await lire('éditions', () async {
        _editions = (await fEditions).map(Edition.depuisJson).toList();
      });
      await lire('résultats', () async {
        _resultats = fResultats == null
            ? const <Resultat>[]
            : (await fResultats).map(Resultat.depuisJson).toList();
      });

      if (echecs.isNotEmpty) {
        _erreur = 'Chargement incomplet.\n${echecs.join('\n')}';
      }

      await _completerProfilPhysique();
      await rafraichirNotifications();
    } on ApiErreur catch (e) {
      // Le chronométrage a pu se fermer entre-temps : ce n'est pas une panne,
      // on relit simplement la configuration et on masque les écrans.
      if (e.estChronoFerme) {
        await rafraichirConfig();
      } else if (!e.estDeconnecte) {
        _erreur = e.message;
      }
    } catch (e, pile) {
      // ⚠️ CE `catch` EST INDISPENSABLE, ET IL A MANQUÉ.
      //
      // Les quatre appels partent ensemble dans un `Future.wait` : si UN SEUL
      // lève autre chose qu'une [ApiErreur] — un champ que le serveur renvoie
      // dans un type inattendu suffit — l'exception traversait `rafraichir()`
      // sans être vue. `_demarrer()` ne l'attend pas : elle finissait en erreur
      // asynchrone non traitée, invisible.
      //
      // Résultat pour le coureur : profil, inscriptions, éditions ET résultats
      // vides EN MÊME TEMPS, sans le moindre message. Un écran vide se lit
      // comme « vous n'avez rien », alors qu'il faut lire « ça n'a pas pu
      // charger » — et les deux appellent des gestes opposés.
      _erreur = 'Les données du compte n\'ont pas pu être lues. '
          'Réessayez ; si cela persiste, signalez-le à l\'organisation.';
      debugPrint('[FER] rafraichir() a échoué : $e\n$pile');
    } finally {
      _chargement = false;
      notifyListeners();
    }
  }

  /// Reprend l'âge et le sexe de l'inscription, s'ils manquent localement.
  ///
  /// ═══════════════════════════════════════════════════════════════════════
  /// CE QUE LE SERVEUR SAIT DÉJÀ NE SE REDEMANDE PAS.
  ///
  /// L'âge et le sexe figurent sur l'inscription : les faire ressaisir à la
  /// présentation du premier lancement serait poser deux fois la même question,
  /// et exposerait à deux réponses différentes.
  ///
  /// ⚠️ LE POIDS ET LA TAILLE NE SONT JAMAIS REPRIS D'ICI : le serveur ne les
  /// connaît pas, et ne doit pas les connaître. Ils viennent uniquement de ce
  /// que le coureur saisit sur l'appareil.
  ///
  /// ⚠️ ON N'ÉCRASE JAMAIS UNE SAISIE LOCALE. Quelqu'un qui a corrigé son âge
  /// sur le téléphone ne doit pas le voir revenir à la valeur de l'inscription
  /// au prochain démarrage : on ne complète que ce qui est vide.
  Future<void> _completerProfilPhysique() async {
    final i = inscriptionActive;
    if (i == null) return;
    if (i.age == null && (i.sexe == null || i.sexe!.trim().isEmpty)) return;

    final actuel = await ProfilPhysique.charger();
    if (actuel.age != null && actuel.sexe != null) return;

    await ProfilPhysique(
      poidsKg: actuel.poidsKg,
      tailleCm: actuel.tailleCm,
      age: actuel.age ?? i.age,
      sexe: actuel.sexe ?? i.sexe,
    ).enregistrer();
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
  int get messagesNonLus => _notifications
      .where((n) => !_lus.contains(n.id) && !_masques.contains(n.id))
      .length;

  Future<void> marquerLu(int id) async {
    if (_lus.contains(id)) return;
    _lus.add(id);
    notifyListeners();
    final p = await SharedPreferences.getInstance();
    await p.setStringList(
        _cleLus, _lus.map((e) => e.toString()).toList());
  }

  /* ─────────────────────── Messages masqués ─────────────────────────────
   *
   * ⚠️ MASQUÉS SUR CET APPAREIL, PAS SUPPRIMÉS SUR LE SERVEUR.
   *
   * L'organisation publie pour tout le monde ; un coureur ne peut pas effacer
   * une annonce, il peut seulement la retirer de SA boîte. Le serveur n'expose
   * d'ailleurs aucune suppression — et c'est heureux : une consigne de sécurité
   * effaçable par son destinataire ne serait plus une consigne.
   *
   * ⚠️ LES ÉPINGLÉS NE SE MASQUENT PAS. Ce sont les informations qu'on relit la
   * veille — rendez-vous, parking, retrait des dossards. Les laisser balayer
   * viderait précisément la page où l'on va les rechercher, et le geste est
   * trop facile pour être délibéré à chaque fois.
   * ───────────────────────────────────────────────────────────────────────── */

  Set<int> _masques = <int>{};

  static const _cleMasques = 'fer_messages_masques';

  /// Les messages visibles : tout ce qui n'a pas été écarté sur cet appareil.
  List<NotificationCourse> get messagesVisibles =>
      _notifications.where((n) => !_masques.contains(n.id)).toList();

  bool peutMasquer(NotificationCourse n) => !n.epingle;

  Future<void> masquerMessage(int id) async {
    if (!_masques.add(id)) return;
    // L'écran répond tout de suite ; l'aller-retour réseau suit.
    notifyListeners();
    final p = await SharedPreferences.getInstance();
    await p.setStringList(
        _cleMasques, _masques.map((e) => e.toString()).toList());

    // ⚠️ ET SURTOUT : ON LE DIT AU SERVEUR.
    //
    // Le masquage n'était que local — propre à cet appareil. Un message écarté
    // sur le téléphone réapparaissait donc sur le navigateur, et l'inverse.
    // Il est désormais porté par le compte.
    //
    // Le stockage local reste, et n'est PAS redondant : il fait tenir le
    // masquage hors ligne et évite que le message réapparaisse une seconde
    // avant que le serveur ne réponde.
    try {
      await _api.masquerNotification(id);
    } on ApiErreur catch (e) {
      // Épinglé, ou réseau coupé. On rend la main : le message reparaîtra au
      // prochain rechargement, ce qui est le comportement juste — il n'a pas
      // été retiré côté serveur.
      _masques.remove(id);
      final p2 = await SharedPreferences.getInstance();
      await p2.setStringList(
          _cleMasques, _masques.map((e) => e.toString()).toList());
      debugPrint('[FER] masquage refusé : ${e.code} ${e.message}');
      notifyListeners();
      rethrow;
    }
  }

  /// Remet un message masqué. Sert au « Annuler » qui suit le balayage : sans
  /// lui, un geste de trop serait définitif.
  Future<void> demasquerMessage(int id) async {
    if (!_masques.remove(id)) return;
    notifyListeners();
    final p = await SharedPreferences.getInstance();
    await p.setStringList(
        _cleMasques, _masques.map((e) => e.toString()).toList());

    // ⚠️ ET ON LE DIT AU SERVEUR — c'est ce qui manquait.
    //
    // « Annuler » ne défaisait que la copie locale : le message revenait sur le
    // téléphone, mais le serveur le gardait masqué. Il restait donc invisible
    // sur le navigateur, et disparaissait à nouveau au rechargement suivant.
    // Un bouton qui annonce annuler doit annuler partout.
    try {
      await _api.restaurerNotification(id);
    } on ApiErreur catch (e) {
      // Réseau coupé : on remet le masque local pour rester cohérent avec le
      // serveur, qui n'a pas été prévenu. Mieux vaut un message encore masqué
      // qu'un écran qui affiche l'inverse de ce qui est enregistré.
      _masques.add(id);
      final p2 = await SharedPreferences.getInstance();
      await p2.setStringList(
          _cleMasques, _masques.map((e) => e.toString()).toList());
      debugPrint('[FER] restauration refusée : ${e.code} ${e.message}');
      notifyListeners();
      rethrow;
    }
  }

  Future<void> _chargerEtatsMessages() async {
    final p = await SharedPreferences.getInstance();
    _lus = (p.getStringList(_cleLus) ?? const <String>[])
        .map(int.tryParse)
        .whereType<int>()
        .toSet();
    _masques = (p.getStringList(_cleMasques) ?? const <String>[])
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
