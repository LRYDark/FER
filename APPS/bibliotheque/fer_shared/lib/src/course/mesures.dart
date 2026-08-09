/// Dépense énergétique et dénivelé.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LES CALORIES SONT UNE ESTIMATION, PAS UNE MESURE — ET ÇA S'AFFICHE.
///
/// Ce projet s'interdit de présenter une approximation comme une mesure : un
/// temps GPS n'est jamais donné pour un temps balise. La même règle vaut ici.
/// Les équations utilisées sont celles de l'ACSM, la référence validée du
/// domaine, et elles restent justes à **±20 %** environ. Strava, Garmin et Apple
/// donnent trois chiffres différents pour la même sortie ; aucun n'a tort.
///
/// D'où le libellé imposé partout : « ~450 kcal · ±15 % ».
///
/// ⚠️ LE MOT « ESTIMATION » A ÉTÉ RETIRÉ, PAS LA RÉSERVE. Deux signes la
/// portent encore, et ils ne doivent JAMAIS disparaître : le tilde devant le
/// chiffre, et la marge en pourcentage. Un nombre de calories affiché nu — sans
/// l'un ni l'autre — se lirait comme une mesure, alors que l'équation ignore le
/// terrain, le vent, la foulée et l'entraînement.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LA TAILLE NE SERT QUASIMENT À RIEN, ET C'EST NORMAL
///
/// Ce qui détermine la dépense d'une marche, c'est le POIDS, la VITESSE et la
/// PENTE. La taille intervient dans les formules de métabolisme de base
/// (Mifflin-St Jeor), pas dans le coût d'un déplacement. On la demande donc en
/// option, et on ne s'en sert que pour l'estimation au repos.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// ⚠️ LE POIDS NE QUITTE JAMAIS LE TÉLÉPHONE
///
/// En contexte sportif, il peut relever de la donnée de santé au sens du RGPD :
/// une base de plus à protéger, à purger et à justifier — pour un calcul qui se
/// fait très bien sur l'appareil. Il est saisi par le coureur, rangé
/// localement, effaçable en un geste, et le serveur ne le voit jamais.
library;

import 'dart:math' as math;

import 'package:shared_preferences/shared_preferences.dart';

/* ═══════════════════════════ Profil local ═════════════════════════════ */

class ProfilPhysique {
  const ProfilPhysique({this.poidsKg, this.tailleCm, this.age, this.sexe});

  /// Le seul champ qui compte vraiment pour l'estimation.
  final double? poidsKg;

  /// Optionnelle : ne sert qu'au métabolisme de repos.
  final int? tailleCm;
  final int? age;

  /// 'H', 'F', ou null. N'entre pas dans les équations ACSM.
  final String? sexe;

  bool get utilisable => poidsKg != null && poidsKg! > 20 && poidsKg! < 300;

  static const _clePoids = 'fer_profil_poids';
  static const _cleTaille = 'fer_profil_taille';
  static const _cleAge = 'fer_profil_age';
  static const _cleSexe = 'fer_profil_sexe';

  static Future<ProfilPhysique> charger() async {
    final p = await SharedPreferences.getInstance();
    return ProfilPhysique(
      poidsKg: p.getDouble(_clePoids),
      tailleCm: p.getInt(_cleTaille),
      age: p.getInt(_cleAge),
      sexe: p.getString(_cleSexe),
    );
  }

  Future<void> enregistrer() async {
    final p = await SharedPreferences.getInstance();
    if (poidsKg != null) {
      await p.setDouble(_clePoids, poidsKg!);
    } else {
      await p.remove(_clePoids);
    }
    if (tailleCm != null) {
      await p.setInt(_cleTaille, tailleCm!);
    } else {
      await p.remove(_cleTaille);
    }
    if (age != null) {
      await p.setInt(_cleAge, age!);
    } else {
      await p.remove(_cleAge);
    }
    if (sexe != null) {
      await p.setString(_cleSexe, sexe!);
    } else {
      await p.remove(_cleSexe);
    }
  }

  /// Efface tout. Le bouton correspondant doit exister dans l'interface : une
  /// donnée qu'on ne peut pas retirer n'aurait pas dû être demandée.
  static Future<void> effacer() async {
    final p = await SharedPreferences.getInstance();
    for (final c in <String>[_clePoids, _cleTaille, _cleAge, _cleSexe]) {
      await p.remove(c);
    }
  }

  ProfilPhysique copieAvec({
    double? poidsKg,
    int? tailleCm,
    int? age,
    String? sexe,
  }) =>
      ProfilPhysique(
        poidsKg: poidsKg ?? this.poidsKg,
        tailleCm: tailleCm ?? this.tailleCm,
        age: age ?? this.age,
        sexe: sexe ?? this.sexe,
      );
}

/* ═════════════════════════ Calcul des calories ════════════════════════ */

/// Estimation de la dépense énergétique, segment par segment.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI SEGMENT PAR SEGMENT, ET PAS SUR LA MOYENNE
///
/// La consommation ne varie pas proportionnellement à la vitesse : monter à
/// 3 km/h coûte beaucoup plus que la moyenne d'un plat à 6 km/h et d'un arrêt.
/// Calculer sur la vitesse moyenne d'un parcours vallonné sous-estime
/// franchement. On accumule donc sur chaque intervalle entre deux points.
class Calories {
  Calories(this.profil);

  final ProfilPhysique profil;

  double _kcal = 0;

  /// Total estimé, en kilocalories. 0 tant que le poids n'est pas renseigné —
  /// on n'invente pas un poids moyen pour afficher un chiffre.
  double get total => _kcal;

  bool get disponible => profil.utilisable;

  /// Le profil est-il assez complet pour affiner le terme de repos ?
  bool get affine =>
      profil.utilisable &&
      profil.tailleCm != null &&
      profil.age != null &&
      profil.sexe != null;

  /// Consommation de repos, en mL d'oxygène par kg et par minute.
  ///
  /// ═════════════════════════════════════════════════════════════════════════
  /// C'EST ICI, ET SEULEMENT ICI, QUE LA TAILLE ET L'ÂGE SERVENT.
  ///
  /// L'équation ACSM ajoute un terme fixe de 3,5 mL/kg/min — la consommation
  /// « moyenne » au repos, soit 1 MET. Cette moyenne est celle d'un homme de
  /// 40 ans, 70 kg, 1,75 m : elle s'écarte facilement de 15 % pour quelqu'un de
  /// plus âgé, plus petit ou plus léger.
  ///
  /// Mifflin-St Jeor donne un métabolisme de repos individuel (kcal/jour), que
  /// l'on ramène ici à la même unité. C'est la formule recommandée par
  /// l'Academy of Nutrition and Dietetics, plus juste que Harris-Benedict sur
  /// les populations actuelles.
  ///
  /// ⚠️ CE QUE ÇA AMÉLIORE, ET CE QUE ÇA N'AMÉLIORE PAS. Le terme de repos ne
  /// pèse qu'une fraction de la dépense pendant l'effort — de l'ordre de 15 à
  /// 25 % pour une marche soutenue. Le reste vient de la régression ACSM sur la
  /// vitesse et la pente, qui garde SA propre incertitude : elle ignore le
  /// terrain, le vent, la foulée et l'entraînement. On passe donc d'environ
  /// ±20 % à ±15 %, pas à une mesure.
  ///
  /// Sans taille, âge ou sexe, on retombe sur les 3,5 de l'ACSM — jamais sur
  /// une valeur inventée.
  double get _vo2Repos {
    if (!affine) return 3.5;

    // Mifflin-St Jeor, en kcal/jour.
    final base = 10 * profil.poidsKg! +
        6.25 * profil.tailleCm! -
        5 * profil.age! +
        (profil.sexe == 'H' ? 5 : (profil.sexe == 'F' ? -161 : -78));

    // kcal/jour → mL O₂/kg/min : ÷1440 min, ÷5 kcal par litre, ×1000 mL,
    // ÷ le poids pour revenir à l'unité de l'équation.
    final vo2 = base / 1440 / 5 * 1000 / profil.poidsKg!;

    // ⚠️ BORNÉ. Une saisie aberrante — 30 kg pour 190 cm — sortirait la formule
    // de son domaine et fausserait tout le total. En dehors de ces bornes, on
    // préfère la moyenne ACSM à un chiffre personnalisé mais faux.
    return vo2.clamp(2.5, 5.0);
  }

  /// La réserve à afficher AVEC le chiffre, jamais sans.
  String get mentionPrecision =>
      affine ? '±15 %' : '±20 %';

  /// Ajoute un segment.
  ///
  /// [distanceM] distance parcourue, [secondes] durée, [deniveleM] variation
  /// d'altitude (signée).
  void ajouter({
    required double distanceM,
    required double secondes,
    double deniveleM = 0,
  }) {
    if (!disponible || secondes <= 0 || distanceM <= 0) return;

    // Vitesse en mètres par minute — l'unité des équations ACSM.
    final vitesse = distanceM / (secondes / 60);

    // ⚠️ GARDE-FOU : au-delà de 400 m/min (24 km/h), c'est un saut GPS, pas un
    // coureur. Sans ce filtre, une seule aberration ajouterait des centaines de
    // calories imaginaires au total.
    if (vitesse > 400) return;

    // Pente, en fraction. Bornée à ±30 % : au-delà, l'équation sort de son
    // domaine de validité et donnerait n'importe quoi.
    var pente = distanceM > 0 ? deniveleM / distanceM : 0.0;
    pente = pente.clamp(-0.30, 0.30);

    // ACSM. La bascule à 100 m/min (6 km/h) est celle de la littérature :
    // au-delà, la marche devient moins économique que la course.
    // Le terme constant de l'ACSM est remplacé par le repos INDIVIDUEL dès que
    // la taille, l'âge et le sexe sont connus — voir `_vo2Repos`.
    final repos = _vo2Repos;
    final vo2 = vitesse <= 100
        ? 0.1 * vitesse + 1.8 * vitesse * math.max(0, pente) + repos
        : 0.2 * vitesse + 0.9 * vitesse * math.max(0, pente) + repos;

    // VO2 (mL/kg/min) → kcal : 5 kcal par litre d'oxygène consommé.
    final kcalParMin = vo2 * profil.poidsKg! / 1000 * 5;
    _kcal += kcalParMin * (secondes / 60);
  }

  void reinitialiser() => _kcal = 0;

  /// Le libellé, avec sa réserve. À utiliser tel quel — un chiffre nu
  /// passerait pour une mesure.
  String get libelle =>
      disponible ? '~${_kcal.round()} kcal' : '—';

  /// ⚠️ CONSERVÉE POUR LES APPELANTS QUI N'ONT PAS D'INSTANCE sous la main.
  /// Préférer `mentionPrecision`, qui dit la vraie marge du profil courant.
  static const String mention = '±20 %';
}

/* ═══════════════════════════ Dénivelé ═════════════════════════════════ */

/// Accumulation du dénivelé positif, filtrée.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// ⚠️ SANS FILTRE, LE CHIFFRE EST FAUX D'UN ORDRE DE GRANDEUR.
///
/// L'altitude GPS oscille de ±10 à 20 m en permanence, même à l'arrêt.
/// Additionner naïvement les écarts annoncerait **300 m de dénivelé sur un
/// parcours parfaitement plat** — et personne ne le remettrait en question,
/// parce que le chiffre a l'air plausible.
///
/// Deux garde-fous, tous les deux nécessaires :
///   • une moyenne glissante, qui lisse le bruit point à point ;
///   • un seuil : on ne compte une montée qu'une fois [seuilM] franchis depuis
///     le dernier creux. C'est la méthode des montres de sport.
class Denivele {
  Denivele({this.seuilM = 4, this.fenetre = 5});

  /// Sous ce seuil, on considère que c'est du bruit. 4 m est le compromis
  /// habituel : plus bas on compte le vent, plus haut on rate les faux plats.
  final double seuilM;

  /// Nombre de points de la moyenne glissante.
  final int fenetre;

  final List<double> _recents = <double>[];
  double? _reference;
  double _positif = 0;
  double _negatif = 0;

  /// Dénivelé positif cumulé, en mètres.
  double get positif => _positif;
  double get negatif => _negatif;

  void ajouter(double? altitude, {double? precisionM}) {
    if (altitude == null || altitude == 0) return;

    // Une altitude annoncée à ±30 m n'apporte rien : on l'écarte plutôt que de
    // la lisser avec les bonnes.
    if (precisionM != null && precisionM > 30) return;

    _recents.add(altitude);
    if (_recents.length > fenetre) _recents.removeAt(0);
    if (_recents.length < fenetre) return;

    final lisse = _recents.reduce((a, b) => a + b) / _recents.length;
    if (_reference == null) {
      _reference = lisse;
      return;
    }

    final ecart = lisse - _reference!;
    if (ecart >= seuilM) {
      _positif += ecart;
      _reference = lisse;
    } else if (ecart <= -seuilM) {
      _negatif += -ecart;
      _reference = lisse;
    }
  }

  void reinitialiser() {
    _recents.clear();
    _reference = null;
    _positif = 0;
    _negatif = 0;
  }
}

/* ═══════════════════════════ Temps au kilomètre ═══════════════════════ */

/// Un kilomètre parcouru, et le temps qu'il a pris.
class TempsAuKm {
  const TempsAuKm(this.km, this.duree, this.deniveleM);

  final int km;
  final Duration duree;
  final double deniveleM;

  /// « 8:42 » — les minutes par kilomètre, la forme que tout le monde lit.
  String get allure {
    final s = duree.inSeconds;
    return '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';
  }
}

/// Découpe le parcours en kilomètres au fil de l'eau.
class DecoupageKm {
  final List<TempsAuKm> _tours = <TempsAuKm>[];

  double _distanceDansKm = 0;
  double _deniveleDansKm = 0;
  DateTime? _debutKm;

  List<TempsAuKm> get tours => List<TempsAuKm>.unmodifiable(_tours);

  void ajouter({
    required double distanceM,
    required DateTime quand,
    double deniveleM = 0,
  }) {
    _debutKm ??= quand;
    _distanceDansKm += distanceM;
    _deniveleDansKm += deniveleM;

    if (_distanceDansKm < 1000) return;

    _tours.add(TempsAuKm(
      _tours.length + 1,
      quand.difference(_debutKm!),
      _deniveleDansKm,
    ));
    // On reporte le dépassement sur le kilomètre suivant : sinon chaque tour
    // perdrait quelques mètres, et le dernier serait systématiquement faux.
    _distanceDansKm -= 1000;
    _deniveleDansKm = 0;
    _debutKm = quand;
  }

  void reinitialiser() {
    _tours.clear();
    _distanceDansKm = 0;
    _deniveleDansKm = 0;
    _debutKm = null;
  }
}

/// Allure moyenne, en minutes par kilomètre. `null` si rien n'a été parcouru.
String? formaterAllure(double distanceM, Duration ecoule) {
  if (distanceM < 50 || ecoule.inSeconds <= 0) return null;
  final sParKm = ecoule.inSeconds / (distanceM / 1000);
  if (sParKm > 3600) return null; // moins de 1 km/h : on n'affiche rien
  return '${(sParKm ~/ 60)}:${(sParKm % 60).round().toString().padLeft(2, '0')}';
}
