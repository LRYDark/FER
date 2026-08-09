/// Suivi d'une course : position, franchissement des lignes, balises.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// L'APPLICATION OBSERVE, LE SERVEUR CALCULE.
///
/// Rien ici ne produit un temps. On envoie des observations horodatées — « la
/// balise d'arrivée vue à 10 h 42 min 17,3 s », « position à 3 m de la ligne à
/// 10 h 42 min 16 s ». Le chrono est calculé sur le serveur, à partir de ces
/// observations et de son propre arbitrage entre les sources.
///
/// Le chrono affiché par [chronoCourant] est un CONFORT D'AFFICHAGE, calculé
/// depuis l'heure de départ de l'édition. Il n'est jamais envoyé, jamais
/// enregistré, et le résultat officiel peut en différer de quelques secondes.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// DEUX SOURCES, TOUJOURS LES DEUX
///
/// Balise Bluetooth ET franchissement de la zone GPS, systématiquement. Si un
/// boîtier lâche le jour J, le GPS donne quand même un temps ; si les deux sont
/// là, le serveur retient la balise. C'est le seul moyen de ne pas se retrouver
/// avec des participants sans chrono à cause d'un boîtier à plat.
library;

import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import '../models/modeles.dart';
import 'balise.dart';
import 'file_attente.dart';
import 'mesures.dart';

enum EtatSuivi { arrete, demarrage, actif, erreur }

/// Le chrono affiché HORS de l'application : notification permanente sur
/// Android, Live Activity et Dynamic Island sur iPhone.
///
/// Interface ici, implémentation dans la coque : le cœur partagé doit rester
/// compilable sans les greffons de notification, et la montre n'a pas le même
/// mécanisme que le téléphone.
///
/// ⚠️ [demarrer] REÇOIT L'INSTANT DE DÉPART, PAS UN TEMPS ÉCOULÉ. C'est le
/// système qui anime le compteur à partir de cette date — sans quoi il faudrait
/// le rafraîchir chaque seconde, ce qui vide une batterie en deux heures et se
/// heurte à la limite de mises à jour d'iOS.
abstract class AfficheurChrono {
  Future<void> demarrer({required DateTime depart, required String dossard});
  Future<void> arreter();
}

/// Implémentation vide, pour les plateformes qui n'ont pas ce mécanisme.
class SansAffichageChrono implements AfficheurChrono {
  const SansAffichageChrono();

  @override
  Future<void> demarrer({required DateTime depart, required String dossard}) async {}

  @override
  Future<void> arreter() async {}
}

class SuiviCourse extends ChangeNotifier {
  SuiviCourse({
    required FileAttente file,
    PlanBalises plan = const PlanBalises(),
    AfficheurChrono affichage = const SansAffichageChrono(),
  })  : _file = file,
        _affichage = affichage,
        _balises = EcouteBalises(plan: plan);

  final AfficheurChrono _affichage;

  /// Rayon autour d'une ligne au-delà duquel on considère qu'on ne l'a pas
  /// franchie. 25 m : c'est l'ordre de grandeur de la précision d'un GPS de
  /// téléphone en ville. Plus serré, on raterait des passages ; plus large, on
  /// déclencherait en passant simplement à proximité.
  static const double rayonLigneM = 25;

  /// Les points sont accumulés puis envoyés par lots. Un appel réseau toutes
  /// les secondes viderait la batterie et saturerait le réseau au moment où
  /// trois mille personnes en font autant.
  static const Duration _periodeEnvoi = Duration(seconds: 30);

  final FileAttente _file;
  final EcouteBalises _balises;

  StreamSubscription<Position>? _positions;
  StreamSubscription<PassageBalise>? _passages;
  Timer? _horlogeEnvoi;
  Timer? _horlogeAffichage;

  EtatSuivi _etat = EtatSuivi.arrete;
  String? _messageErreur;
  Inscription? _inscription;
  Edition? _edition;

  final List<Map<String, dynamic>> _tampon = <Map<String, dynamic>>[];
  Position? _derniere;
  double _distanceM = 0;

  /// Mesures dérivées : dénivelé filtré, calories estimées, temps au kilomètre.
  /// Toutes calculées SUR L'APPAREIL — le poids ne part jamais au serveur.
  final Denivele _denivele = Denivele();
  final DecoupageKm _kilometres = DecoupageKm();
  Calories _calories = Calories(const ProfilPhysique());

  /// Lignes déjà franchies pendant cette session, pour ne pas réémettre une
  /// détection à chaque point reçu tant qu'on reste dans la zone.
  final Set<String> _franchies = <String>{};

  EtatSuivi get etat => _etat;
  String? get messageErreur => _messageErreur;
  Inscription? get inscription => _inscription;
  Edition? get edition => _edition;
  Position? get derniere => _derniere;
  bool get baliseActive => _balises.actif;
  int get pointsEnAttente => _file.pointsEnAttente + _tampon.length;
  int get detectionsEnAttente => _file.detectionsEnAttente;

  /// Distance parcourue depuis le démarrage, en mètres.
  ///
  /// ⚠️ INDICATIVE. Elle suit le GPS et sur-estime dès que le signal saute. La
  /// distance officielle est calculée par le serveur depuis la trace complète —
  /// c'est la même règle que pour le chrono : le serveur calcule, l'application
  /// observe.
  double get distanceM => _distanceM;

  /// Dénivelé positif, filtré du bruit GPS.
  double get denivelePositifM => _denivele.positif;

  /// Calories estimées. Vaut `—` tant que le poids n'est pas renseigné : on
  /// n'invente pas un poids moyen pour afficher un chiffre.
  Calories get calories => _calories;

  /// Temps kilomètre par kilomètre.
  List<TempsAuKm> get kilometres => _kilometres.tours;

  /// Allure moyenne « m:ss » par kilomètre, ou `null`.
  String? get allureMoyenne => formaterAllure(_distanceM, chronoCourant ?? Duration.zero);

  /// Charge le profil physique rangé sur l'appareil. À appeler avant de
  /// démarrer, sinon les calories restent indisponibles.
  Future<void> chargerProfil() async {
    _calories = Calories(await ProfilPhysique.charger());
    notifyListeners();
  }

  /// Temps écoulé depuis le départ.
  ///
  /// ⚠️ LE TOP RÉEL D'ABORD, L'HEURE PRÉVUE ENSUITE. Une course part rarement à
  /// l'heure : compter depuis la prévision afficherait cinq minutes de trop sur
  /// tous les téléphones dès que le départ est retardé.
  ///
  /// `null` si aucun des deux n'est publié — on préfère ne rien afficher plutôt
  /// qu'un compteur parti d'une heure inventée.
  Duration? get chronoCourant {
    final depart = _departEffectif ?? _edition?.heureDepart;
    if (depart == null) return null;
    final ecoule = DateTime.now().difference(depart);
    return ecoule.isNegative ? Duration.zero : ecoule;
  }

  DateTime? _departEffectif;

  /// Met à jour l'origine du chrono sans interrompre le suivi.
  ///
  /// Appelé quand le serveur annonce le top de départ pendant la course : le
  /// compteur se recale sans qu'on ait à tout redémarrer, et sans perdre la
  /// distance déjà parcourue.
  void recalerDepart(DateTime? depart) {
    if (_departEffectif == depart) return;
    _departEffectif = depart;
    notifyListeners();
  }

  /* ═══════════════════════════ Démarrage ═══════════════════════════════ */

  /// Démarre le suivi pour une inscription.
  ///
  /// [avecGps] et [avecBalise] permettent de tourner avec une seule source :
  /// Bluetooth éteint, ou consentement GPS non donné. Le suivi reste utile
  /// dans les deux cas — c'est tout l'intérêt d'avoir deux sources.
  Future<bool> demarrer({
    required Inscription inscription,
    Edition? edition,
    DateTime? departEffectif,
    bool avecGps = true,
    bool avecBalise = true,
  }) async {
    if (_etat == EtatSuivi.actif) return true;

    _inscription = inscription;
    _edition = edition;
    _departEffectif = departEffectif;
    _distanceM = 0;
    _derniere = null;
    _franchies.clear();
    _denivele.reinitialiser();
    _kilometres.reinitialiser();
    _calories.reinitialiser();
    // Le poids a pu être saisi entre deux courses : on relit le profil plutôt
    // que de garder celui du démarrage précédent.
    await chargerProfil();
    _etat = EtatSuivi.demarrage;
    _messageErreur = null;
    notifyListeners();

    var uneSourceAuMoins = false;

    if (avecGps) {
      final ok = await _demarrerGps();
      uneSourceAuMoins = uneSourceAuMoins || ok;
    }
    if (avecBalise) {
      final ok = await _balises.demarrer();
      if (ok) {
        _passages = _balises.passages.listen(_surPassageBalise);
      }
      uneSourceAuMoins = uneSourceAuMoins || ok;
    }

    if (!uneSourceAuMoins) {
      _etat = EtatSuivi.erreur;
      _messageErreur = _messageErreur ??
          'Ni la position ni le Bluetooth ne sont disponibles. '
              'Sans au moins une des deux, aucun temps ne peut être relevé.';
      notifyListeners();
      return false;
    }

    _horlogeEnvoi = Timer.periodic(_periodeEnvoi, (_) => _envoyer());
    // Rafraîchit le chrono affiché. Une seconde suffit : personne ne lit les
    // dixièmes, et un rafraîchissement plus rapide coûterait de la batterie.
    _horlogeAffichage =
        Timer.periodic(const Duration(seconds: 1), (_) => notifyListeners());

    /* Le chrono hors application. Il part de l'origine RÉELLE, pas de
       maintenant : quelqu'un qui lance le suivi cinq minutes après le départ
       doit voir 5:00, pas 0:00. */
    final origine = _departEffectif ?? _edition?.heureDepart;
    if (origine != null) {
      await _affichage.demarrer(
        depart: origine,
        dossard: inscription.inscriptionNo,
      );
    }

    _etat = EtatSuivi.actif;
    notifyListeners();
    return true;
  }

  Future<bool> _demarrerGps() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      _messageErreur = 'La localisation est désactivée sur cet appareil.';
      return false;
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      _messageErreur = "L'accès à la position a été refusé.";
      return false;
    }

    _positions = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.best,
        // Un point tous les 5 mètres : assez dense pour tracer un parcours,
        // assez espacé pour ne pas remplir la file de points immobiles quand
        // quelqu'un s'arrête boire.
        distanceFilter: 5,
      ),
    ).listen(_surPosition, onError: (Object e) {
      _messageErreur = 'Position indisponible : $e';
      notifyListeners();
    });
    return true;
  }

  /* ═══════════════════════════ Réception ═══════════════════════════════ */

  void _surPosition(Position p) {
    if (_derniere != null) {
      final pas = Geolocator.distanceBetween(
        _derniere!.latitude,
        _derniere!.longitude,
        p.latitude,
        p.longitude,
      );
      _distanceM += pas;

      // Mesures dérivées, dans l'ordre : le dénivelé d'abord, parce que les
      // calories en dépendent — monter coûte plus que marcher à plat.
      final avant = _denivele.positif;
      _denivele.ajouter(p.altitude, precisionM: p.altitudeAccuracy);
      final montee = _denivele.positif - avant;

      final secondes =
          p.timestamp.difference(_derniere!.timestamp).inMilliseconds / 1000;
      _calories.ajouter(distanceM: pas, secondes: secondes, deniveleM: montee);
      _kilometres.ajouter(distanceM: pas, quand: p.timestamp, deniveleM: montee);
    }
    _derniere = p;

    _tampon.add(<String, dynamic>{
      'lat': p.latitude,
      'lon': p.longitude,
      // ⚠️ `toUtc()` : le serveur EXIGE un décalage explicite et refuse la
      // date nue en 422. C'est aussi ce qui garantit qu'un téléphone réglé
      // sur un autre fuseau ne fausse pas le classement.
      'at': p.timestamp.toUtc().toIso8601String(),
      if (p.altitude != 0) 'alt': p.altitude,
      'precision_m': p.accuracy.round(),
    });

    _verifierLignes(p);
    notifyListeners();
  }

  /// Franchissement d'une ligne détecté au GPS.
  ///
  /// Le type envoyé est `geofence` : c'est une entrée dans une zone, pas une
  /// mesure au point exact. Le serveur le sait et lui accorde une précision
  /// moindre qu'à la balise. Prétendre `beacon` ici gonflerait artificiellement
  /// la confiance accordée à un temps qui ne la mérite pas.
  void _verifierLignes(Position p) {
    final e = _edition;
    if (e == null) return;

    void tester(String point, double? lat, double? lon) {
      if (lat == null || lon == null) return;
      if (_franchies.contains(point)) return;
      final d = Geolocator.distanceBetween(p.latitude, p.longitude, lat, lon);
      if (d > rayonLigneM) return;

      _franchies.add(point);
      sansAttendre(_file.ajouterDetection(
        _inscription!.annee,
        _inscription!.inscriptionNo,
        <String, dynamic>{
          'type': 'geofence',
          'point': point,
          'detecte_at': p.timestamp.toUtc().toIso8601String(),
        },
      ));
    }

    tester('depart', e.latDepart, e.lonDepart);
    tester('arrivee', e.latArrivee, e.lonArrivee);
  }

  void _surPassageBalise(PassageBalise passage) {
    final i = _inscription;
    if (i == null) return;
    // La détection part immédiatement — et est rangée sur le disque avant, par
    // la file. Un passage de ligne ne peut pas attendre le prochain cycle.
    sansAttendre(_file.ajouterDetection(
      i.annee,
      i.inscriptionNo,
      passage.versJson(),
    ));
    notifyListeners();
  }

  /* ════════════════════════════ Envoi ══════════════════════════════════ */

  Future<void> _envoyer() async {
    final i = _inscription;
    if (i == null) return;

    if (_tampon.isNotEmpty) {
      final lot = List<Map<String, dynamic>>.from(_tampon);
      _tampon.clear();
      await _file.ajouterPoints(i.annee, i.inscriptionNo, lot);
    }
    await _file.vidange();
    notifyListeners();
  }

  /* ═══════════════════════════ Arrêt ═══════════════════════════════════ */

  /// Arrête le suivi et envoie ce qui reste.
  ///
  /// ⚠️ L'ENVOI FINAL N'EST PAS FACULTATIF. Sans lui, les derniers points — donc
  /// souvent l'arrivée elle-même — resteraient dans le tampon mémoire jusqu'au
  /// prochain démarrage, ou disparaîtraient si l'application est fermée.
  Future<void> arreter() async {
    _horlogeEnvoi?.cancel();
    _horlogeEnvoi = null;
    _horlogeAffichage?.cancel();
    _horlogeAffichage = null;

    await _positions?.cancel();
    _positions = null;
    await _passages?.cancel();
    _passages = null;
    await _balises.arreter();

    // ⚠️ Le chrono hors application doit s'éteindre AUSSI. Un compteur qui
    // continue de tourner sur l'écran verrouillé après l'arrivée est pire que
    // pas de compteur du tout.
    await _affichage.arreter();

    await _envoyer();

    _etat = EtatSuivi.arrete;
    notifyListeners();
  }

  /// Déclaration manuelle d'un passage de ligne — le bouton de la montre.
  ///
  /// ⚠️ LE TYPE `manuel` EST REFUSÉ PAR LE SERVEUR (403) : il est réservé à
  /// l'organisation et prime sur toutes les autres sources. Un coureur qui
  /// pourrait l'émettre dicterait son propre temps. On envoie donc `geofence`,
  /// qui porte la bonne idée : « je déclare être passé là, à cet instant »,
  /// avec la confiance modérée qui va avec.
  Future<void> declarerPassage(String point) async {
    final i = _inscription;
    if (i == null) return;
    if (point != 'depart' && point != 'arrivee') return;

    _franchies.add(point);
    await _file.ajouterDetection(
      i.annee,
      i.inscriptionNo,
      <String, dynamic>{
        'type': 'geofence',
        'point': point,
        'detecte_at': DateTime.now().toUtc().toIso8601String(),
      },
    );
    notifyListeners();
  }

  /// Distance à vol d'oiseau jusqu'à la ligne d'arrivée, ou `null` si elle
  /// n'est pas publiée. Sert au repère « plus que 400 m » de la montre.
  double? get distanceArriveeM {
    final e = _edition;
    final p = _derniere;
    if (e == null || p == null || !e.aLigneArrivee) return null;
    return Geolocator.distanceBetween(
      p.latitude,
      p.longitude,
      e.latArrivee!,
      e.lonArrivee!,
    );
  }

  bool aFranchi(String point) => _franchies.contains(point);

  @override
  void dispose() {
    sansAttendre(arreter());
    sansAttendre(_balises.liberer());
    super.dispose();
  }
}

/// Formate une durée en `h:mm:ss`, la seule forme employée par le projet.
String formaterDuree(Duration? d) {
  if (d == null) return '—';
  final s = d.inSeconds;
  final h = s ~/ 3600;
  final m = (s % 3600) ~/ 60;
  final r = s % 60;
  return '$h:${m.toString().padLeft(2, '0')}:${r.toString().padLeft(2, '0')}';
}

/// Distance lisible : mètres en dessous du kilomètre, puis kilomètres.
String formaterDistance(double? m) {
  if (m == null) return '—';
  if (m < 1000) return '${m.round()} m';
  return '${(m / 1000).toStringAsFixed(2).replaceAll('.', ',')} km';
}

/// Évite d'oublier un `await` sur un futur volontairement non attendu.
void sansAttendre(Future<void> f) {
  f.catchError((Object e) {
    // Une écriture en file ne doit jamais faire tomber l'écran de course.
    debugPrint('[FER] suivi : $e');
  });
}

/// Petit utilitaire géographique, utilisé par la montre pour son cap.
double capVers(double lat1, double lon1, double lat2, double lon2) {
  final dLon = (lon2 - lon1) * math.pi / 180;
  final y = math.sin(dLon) * math.cos(lat2 * math.pi / 180);
  final x = math.cos(lat1 * math.pi / 180) * math.sin(lat2 * math.pi / 180) -
      math.sin(lat1 * math.pi / 180) *
          math.cos(lat2 * math.pi / 180) *
          math.cos(dLon);
  return (math.atan2(y, x) * 180 / math.pi + 360) % 360;
}
