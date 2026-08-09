/// Écoute des balises Bluetooth posées sur les lignes de départ et d'arrivée.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LA BALISE EST LA MESURE LA PLUS PRÉCISE — ET LA PLUS FRAGILE
///
/// Un boîtier iBeacon émet en continu. Le téléphone qui passe devant le voit à
/// quelques dizaines de centimètres près : c'est la seule façon d'obtenir un
/// temps à la seconde. Mais un boîtier tombe en panne, se décharge, se fait
/// masquer par une foule. D'où la règle du projet : on enregistre TOUJOURS la
/// balise ET le franchissement GPS, et c'est le serveur qui arbitre.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// ON N'ENVOIE PAS LA PREMIÈRE DÉTECTION, ON ENVOIE LE PIC
///
/// Une balise se capte plusieurs secondes avant et après la ligne. Retenir la
/// première lecture donnerait un temps systématiquement trop tôt, de plusieurs
/// secondes — de quoi inverser un classement. On suit donc la puissance reçue
/// (RSSI) pendant toute la traversée et on retient l'INSTANT OÙ ELLE EST
/// MAXIMALE : c'est le moment du passage au plus près du boîtier.
///
/// Le serveur utilise ce même RSSI pour pondérer sa confiance : -50 dBm, on
/// était à côté ; -95 dBm, on l'a captée de loin, peut-être sans franchir quoi
/// que ce soit.
library;

import 'dart:async';

import 'package:flutter_blue_plus/flutter_blue_plus.dart';

/// Un passage devant une balise, une fois le pic déterminé.
class PassageBalise {
  const PassageBalise({
    required this.point,
    required this.instant,
    required this.rssiPic,
    this.minor,
  });

  /// `depart` ou `arrivee`.
  final String point;

  /// Instant du pic de signal. C'est LUI qui fait foi, pas l'heure d'envoi.
  final DateTime instant;

  final int rssiPic;

  /// Numéro `minor` de l'iBeacon : c'est ce qui distingue la balise de départ
  /// de celle d'arrivée quand les deux partagent le même UUID.
  final int? minor;

  /// Charge utile attendue par `POST /me/detections`.
  ///
  /// ⚠️ `toIso8601String()` sur une date LOCALE ne porte pas de décalage, et le
  /// serveur refuse alors la détection en 422. `toUtc()` produit le suffixe
  /// `Z`, qui est un décalage explicite. C'est exactement le genre d'oubli qui
  /// décale tous les chronos de deux heures sans message d'erreur.
  Map<String, dynamic> versJson() => <String, dynamic>{
        'type': 'beacon',
        'point': point,
        'detecte_at': instant.toUtc().toIso8601String(),
        'rssi_pic': rssiPic,
        if (minor != null) 'beacon_minor': minor,
      };
}

/// Correspondance entre le `minor` d'une balise et la ligne qu'elle matérialise.
class PlanBalises {
  const PlanBalises({
    this.uuid,
    this.minorDepart = 1,
    this.minorArrivee = 2,
    this.rssiMinimum = -85,
  });

  /// UUID iBeacon de l'organisation. `null` = on accepte n'importe quel UUID,
  /// utile en test ; à renseigner le jour de la course, sinon la balise d'un
  /// magasin voisin pourrait déclencher un faux passage.
  final String? uuid;

  final int minorDepart;
  final int minorArrivee;

  /// En dessous de ce seuil, on considère qu'on n'a pas franchi la ligne mais
  /// capté la balise de loin. Sans ce plancher, marcher à trente mètres de
  /// l'arrivée déclencherait une détection.
  final int rssiMinimum;

  String? pointPour(int? minor) {
    if (minor == minorDepart) return 'depart';
    if (minor == minorArrivee) return 'arrivee';
    return null;
  }
}

class EcouteBalises {
  EcouteBalises({PlanBalises plan = const PlanBalises()}) : _plan = plan;

  /// Une fois la balise perdue de vue pendant ce délai, on considère la
  /// traversée finie et on publie le pic. Trop court, on couperait un passage
  /// en deux détections ; trop long, le chrono arriverait avec du retard.
  static const _finDeTraversee = Duration(seconds: 8);

  final PlanBalises _plan;

  StreamSubscription<List<ScanResult>>? _abonnement;
  Timer? _horloge;

  /// Pic en cours de construction, par point (`depart` / `arrivee`).
  final Map<String, _PicEnCours> _pics = <String, _PicEnCours>{};

  final _sortie = StreamController<PassageBalise>.broadcast();

  /// Passages confirmés. Un événement par traversée, jamais un par lecture.
  Stream<PassageBalise> get passages => _sortie.stream;

  bool get actif => _abonnement != null;

  /// Démarre l'écoute. Renvoie `false` si le Bluetooth est absent ou éteint —
  /// l'appelant doit alors le dire clairement plutôt que de laisser croire que
  /// la course est suivie.
  Future<bool> demarrer() async {
    if (actif) return true;
    if (!await FlutterBluePlus.isSupported) return false;
    if (FlutterBluePlus.adapterStateNow != BluetoothAdapterState.on) {
      return false;
    }

    _abonnement = FlutterBluePlus.onScanResults.listen(_lire);

    // `continuousUpdates` : sans lui, un appareil déjà vu n'est plus signalé et
    // le RSSI ne bouge plus — on ne verrait jamais le pic.
    await FlutterBluePlus.startScan(
      continuousUpdates: true,
      androidScanMode: AndroidScanMode.lowLatency,
    );

    // Le pic ne se publie pas sur une lecture : il se publie quand la balise a
    // disparu. Il faut donc une horloge, les lectures ayant justement cessé.
    _horloge = Timer.periodic(const Duration(seconds: 2), (_) => _cloturer());
    return true;
  }

  Future<void> arreter() async {
    _horloge?.cancel();
    _horloge = null;
    await _abonnement?.cancel();
    _abonnement = null;
    try {
      await FlutterBluePlus.stopScan();
    } catch (_) {
      // L'adaptateur a pu être coupé entre-temps : sans conséquence.
    }
    _cloturer(tout: true);
  }

  void _lire(List<ScanResult> resultats) {
    final maintenant = DateTime.now();
    for (final r in resultats) {
      final ib = _iBeacon(r);
      if (ib == null) continue;
      if (_plan.uuid != null &&
          ib.uuid.toLowerCase() != _plan.uuid!.toLowerCase()) {
        continue;
      }
      if (r.rssi < _plan.rssiMinimum) continue;

      final point = _plan.pointPour(ib.minor);
      if (point == null) continue;

      final pic = _pics[point];
      if (pic == null) {
        _pics[point] = _PicEnCours(
          instant: maintenant,
          rssi: r.rssi,
          minor: ib.minor,
          derniereVue: maintenant,
        );
      } else {
        pic.derniereVue = maintenant;
        // Le RSSI est négatif : « plus fort » veut dire « plus proche de zéro ».
        if (r.rssi > pic.rssi) {
          pic.rssi = r.rssi;
          pic.instant = maintenant;
        }
      }
    }
  }

  void _cloturer({bool tout = false}) {
    final maintenant = DateTime.now();
    for (final point in _pics.keys.toList()) {
      final pic = _pics[point]!;
      if (!tout &&
          maintenant.difference(pic.derniereVue) < _finDeTraversee) {
        continue;
      }
      _pics.remove(point);
      _sortie.add(PassageBalise(
        point: point,
        instant: pic.instant,
        rssiPic: pic.rssi,
        minor: pic.minor,
      ));
    }
  }

  /// Extrait la trame iBeacon des données constructeur Apple (0x004C).
  /// Format : 02 15 | UUID (16) | major (2) | minor (2) | txPower (1).
  static _Trame? _iBeacon(ScanResult r) {
    final donnees = r.advertisementData.manufacturerData[0x004C];
    if (donnees == null || donnees.length < 23) return null;
    if (donnees[0] != 0x02 || donnees[1] != 0x15) return null;

    final uuid = donnees
        .sublist(2, 18)
        .map((o) => o.toRadixString(16).padLeft(2, '0'))
        .join();
    return _Trame(
      uuid: uuid,
      major: (donnees[18] << 8) | donnees[19],
      minor: (donnees[20] << 8) | donnees[21],
    );
  }

  Future<void> liberer() async {
    await arreter();
    await _sortie.close();
  }
}

class _PicEnCours {
  _PicEnCours({
    required this.instant,
    required this.rssi,
    required this.derniereVue,
    this.minor,
  });

  DateTime instant;
  int rssi;
  DateTime derniereVue;
  final int? minor;
}

class _Trame {
  const _Trame({required this.uuid, required this.major, required this.minor});

  final String uuid;
  final int major;
  final int minor;
}
